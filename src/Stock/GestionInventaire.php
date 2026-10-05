<?php

namespace App\Stock;

use App\Entity\Categorie;
use App\Entity\Etagere;
use App\Entity\Inventaire;
use App\Entity\Pharmacie;
use App\Enum\PerimetreInventaire;
use App\Enum\StatutInventaire;
use App\Repository\InventaireRepository;
use App\Repository\LotRepository;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Inventaire complet ou tournant (ST-06) : ouverture (quantités théoriques figées), comptage par lot,
 * puis validation par le propriétaire ou l'adjoint, qui transforme chaque écart en ajustement.
 *
 * Un seul inventaire en cours à la fois par pharmacie, pour qu'un lot ne soit jamais ajusté deux fois.
 */
class GestionInventaire
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventaireRepository $inventaires,
        private readonly LotRepository $lots,
        private readonly StockService $stock,
        private readonly Numeroteur $numeroteur,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * @throws StockException
     */
    public function ouvrir(Pharmacie $pharmacie, PerimetreInventaire $perimetre, ?Etagere $etagere, ?Categorie $categorie): Inventaire
    {
        $enCours = $this->inventaires->enCours();
        if (null !== $enCours) {
            throw new StockException(\sprintf('L\'inventaire %s est en cours : validez-le ou annulez-le avant d\'en ouvrir un autre.', $enCours->getNumero()));
        }
        $etagere = PerimetreInventaire::Etagere === $perimetre ? $etagere : null;
        $categorie = PerimetreInventaire::Categorie === $perimetre ? $categorie : null;
        if (PerimetreInventaire::Etagere === $perimetre && null === $etagere) {
            throw new StockException('Choisissez l\'étagère à inventorier.');
        }
        if (PerimetreInventaire::Categorie === $perimetre && null === $categorie) {
            throw new StockException('Choisissez la catégorie à inventorier.');
        }

        $lots = $this->lots->pourInventaire($etagere, $categorie);
        if ([] === $lots) {
            throw new StockException('Aucun lot en stock dans ce périmètre : il n\'y a rien à compter.');
        }

        return $this->stock->transaction(function () use ($pharmacie, $perimetre, $etagere, $categorie, $lots): Inventaire {
            $inventaire = new Inventaire(
                $this->numeroteur->suivant('INV', $pharmacie),
                $perimetre,
                $this->horloge->now(),
                $this->tenantContext->getUtilisateur(),
                $etagere,
                $categorie,
            );
            $inventaire->setPharmacie($pharmacie);
            foreach ($lots as $lot) {
                $inventaire->ajouterLigne($lot);
            }
            $this->em->persist($inventaire);
            $this->em->flush();

            return $inventaire;
        });
    }

    /**
     * Enregistre les quantités comptées (un champ vide = lot pas encore compté).
     *
     * @param array<int|string, mixed> $quantites quantité comptée par id de ligne
     *
     * @throws StockException
     */
    public function enregistrerComptage(Inventaire $inventaire, array $quantites): void
    {
        $this->exigerEnCours($inventaire);
        foreach ($inventaire->getLignes() as $ligne) {
            if (!\array_key_exists((int) $ligne->getId(), $quantites)) {
                continue;
            }
            $saisie = $quantites[(int) $ligne->getId()];
            $valeur = trim(\is_scalar($saisie) ? (string) $saisie : '');
            if ('' === $valeur) {
                $ligne->setQuantiteComptee(null);
                continue;
            }
            if (!ctype_digit($valeur)) {
                throw new StockException(\sprintf('Quantité invalide pour le lot %s de %s : saisissez un nombre entier positif ou nul.', $ligne->getLot()->getNumero(), $ligne->getLot()->getProduit()->getNomCommercial()));
            }
            $ligne->setQuantiteComptee((int) $valeur);
        }
        $this->em->flush();
    }

    /**
     * Valide l'inventaire : chaque écart devient un ajustement du lot, appliqué à sa quantité actuelle
     * (les ventes faites pendant le comptage restent prises en compte).
     *
     * @throws StockException
     */
    public function valider(Inventaire $inventaire): void
    {
        $this->exigerEnCours($inventaire);
        $restants = $inventaire->getLignes()->count() - $inventaire->nombreComptees();
        if ($restants > 0) {
            throw new StockException(\sprintf('%d lot(s) ne sont pas encore comptés. Saisissez 0 pour un lot introuvable.', $restants));
        }

        $this->stock->transaction(function () use ($inventaire): void {
            $ecarts = [];
            foreach ($inventaire->getLignes() as $ligne) {
                if (0 !== $ligne->getEcart()) {
                    $this->lots->verrouiller($ligne->getLot());
                    $ecarts[] = $ligne;
                }
            }
            // Tout est vérifié avant la première écriture : un refus ne laisse aucun lot à moitié ajusté.
            foreach ($ecarts as $ligne) {
                if ($ligne->getLot()->getQuantiteRestante() + (int) $ligne->getEcart() < 0) {
                    throw new StockException(\sprintf('Le lot %s de %s a été vendu pendant le comptage : son stock actuel (%d) ne permet pas un écart de %d. Recomptez-le.', $ligne->getLot()->getNumero(), $ligne->getLot()->getProduit()->getNomCommercial(), $ligne->getLot()->getQuantiteRestante(), $ligne->getEcart()));
                }
            }
            foreach ($ecarts as $ligne) {
                $this->stock->appliquerEcartInventaire($ligne->getLot(), (int) $ligne->getEcart(), $inventaire->getNumero());
            }

            $inventaire->cloturer(StatutInventaire::Valide, $this->horloge->now(), $this->tenantContext->getUtilisateur());
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::INVENTAIRE_VALIDE, $inventaire->getPharmacie(), $inventaire, null, [
                'numero' => $inventaire->getNumero(),
                'perimetre' => $inventaire->getLibellePerimetre(),
                'lots' => $inventaire->getLignes()->count(),
                'ecarts' => \count($ecarts),
                'valeur_ecarts' => $inventaire->valeurEcarts(),
            ]);
            $this->em->flush();
        });
    }

    /**
     * @throws StockException
     */
    public function annuler(Inventaire $inventaire): void
    {
        $this->exigerEnCours($inventaire);
        $inventaire->cloturer(StatutInventaire::Annule, $this->horloge->now(), $this->tenantContext->getUtilisateur());
        $this->em->flush();
    }

    private function exigerEnCours(Inventaire $inventaire): void
    {
        if (!$inventaire->estEnCours()) {
            throw new StockException(\sprintf('L\'inventaire %s est %s : il ne se modifie plus.', $inventaire->getNumero(), mb_strtolower($inventaire->getStatut()->libelle())));
        }
    }
}
