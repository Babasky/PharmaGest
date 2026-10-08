<?php

namespace App\Achat;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Reception;
use App\Enum\TypeMouvement;
use App\Stock\StockException;
use App\Stock\StockService;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Réception d'une livraison fournisseur (CO-06) : chaque ligne reçue crée un lot par {@see StockService}
 * (mouvement « Réception »), la commande passe « reçue partiellement » ou « reçue ».
 */
class ReceptionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Les lignes sans quantité sont ignorées : la livraison peut être partielle. Un même produit peut arriver
     * en plusieurs lots (plusieurs lignes pour la même ligne de commande).
     *
     * @param list<array<array-key, mixed>> $saisies ligne (id de ligne de commande), quantite, lot, peremption (AAAA-MM-JJ, facultative), prix
     *
     * @return list<string> avertissements : quantités reçues au-delà de la commande (RG-16)
     *
     * @throws AchatException
     */
    public function receptionner(Commande $commande, \DateTimeImmutable $date, ?string $numeroBonLivraison, array $saisies): array
    {
        if (!$commande->estReceptionnable()) {
            throw new AchatException(\sprintf('La commande %s est %s : il n\'y a rien à réceptionner.', $commande->getLibelle(), mb_strtolower($commande->getStatut()->libelle())));
        }
        $aujourdhui = $this->stock->aujourdhui();
        if ($date > $aujourdhui) {
            throw new AchatException('La date de réception ne peut pas être dans le futur.');
        }
        if (null !== $commande->getEnvoyeeLe() && $date < $commande->getEnvoyeeLe()->setTime(0, 0)) {
            throw new AchatException('La date de réception précède la date de la commande.');
        }

        $parId = [];
        foreach ($commande->getLignes() as $ligne) {
            $parId[(int) $ligne->getId()] = $ligne;
        }

        $lignes = [];
        foreach ($saisies as $saisie) {
            $quantite = trim(\is_scalar($saisie['quantite'] ?? null) ? (string) $saisie['quantite'] : '');
            if ('' === $quantite || '0' === $quantite) {
                continue;
            }
            $ligne = $parId[(int) (\is_scalar($saisie['ligne'] ?? null) ? $saisie['ligne'] : 0)] ?? throw new AchatException('Produit inconnu sur cette commande.');
            $nom = $ligne->getProduit()->getNomCommercial();
            $quantite = CommandeService::entier($quantite);
            if (null === $quantite || $quantite > LigneCommande::QUANTITE_MAXIMALE) {
                throw new AchatException(\sprintf('%s : quantité reçue invalide.', $nom));
            }
            $numeroLot = trim(\is_scalar($saisie['lot'] ?? null) ? (string) $saisie['lot'] : '');
            if ('' === $numeroLot || mb_strlen($numeroLot) > 50) {
                throw new AchatException(\sprintf('%s : le numéro de lot est obligatoire (RG-16).', $nom));
            }
            // Date de péremption facultative : un lot sans date sort en dernier (FEFO) et n'est jamais signalé périmé.
            $peremptionSaisie = trim(\is_scalar($saisie['peremption'] ?? null) ? (string) $saisie['peremption'] : '');
            $peremption = '' === $peremptionSaisie ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $peremptionSaisie) ?: throw new AchatException(\sprintf('%s : la date de péremption est invalide.', $nom)));
            if (null !== $peremption && $peremption <= $aujourdhui) {
                throw new AchatException(\sprintf('%s : le lot %s est déjà périmé (%s), il ne peut pas entrer en stock.', $nom, $numeroLot, $peremption->format('d/m/Y')));
            }
            if (!$ligne->getProduit()->isActif()) {
                throw new AchatException(\sprintf('%s : le produit est archivé, restaurez-le avant de le réceptionner.', $nom));
            }
            $prix = CommandeService::entier($saisie['prix'] ?? null);
            if (null === $prix) {
                throw new AchatException(\sprintf('%s : indiquez le prix d\'achat unitaire réel.', $nom));
            }
            $lignes[] = [$ligne, $quantite, $numeroLot, $peremption, $prix];
        }
        if ([] === $lignes) {
            throw new AchatException('Saisissez la quantité reçue d\'au moins un produit.');
        }
        // Tout est vérifié avant la première écriture : une ligne refusée n'en laisse aucune à moitié enregistrée.

        $numeroBonLivraison = null === $numeroBonLivraison || '' === trim($numeroBonLivraison) ? null : mb_substr(trim($numeroBonLivraison), 0, 50);

        return $this->stock->transaction(function () use ($commande, $date, $numeroBonLivraison, $lignes): array {
            $reception = new Reception($commande, $date, $numeroBonLivraison, $this->tenantContext->getUtilisateur(), $this->horloge->now());
            $this->em->persist($reception);
            $motif = null === $numeroBonLivraison ? 'Réception' : 'Réception, BL '.$numeroBonLivraison;

            $recues = [];
            foreach ($lignes as [$ligne, $quantite, $numeroLot, $peremption, $prix]) {
                try {
                    $lot = $this->stock->entrer($ligne->getProduit(), $numeroLot, $peremption, $quantite, $prix, $commande->getFournisseur(), TypeMouvement::Reception, $motif, $commande->getNumero());
                } catch (StockException $e) {
                    throw new AchatException(\sprintf('%s : %s', $ligne->getProduit()->getNomCommercial(), $e->getMessage()), previous: $e);
                }
                $reception->ajouterLigne($ligne, $lot);
                $ligne->recevoir($quantite);
                $recues[spl_object_id($ligne)] = $ligne;
            }
            $commande->actualiserStatut();
            $this->em->flush();

            $avertissements = [];
            foreach ($recues as $ligne) {
                if ($ligne->getQuantiteRecue() > $ligne->getQuantite()) {
                    $avertissements[] = \sprintf('%s : %d reçu(s) pour %d commandé(s), soit %d de plus que la commande.',
                        $ligne->getProduit()->getNomCommercial(), $ligne->getQuantiteRecue(), $ligne->getQuantite(), $ligne->getQuantiteRecue() - $ligne->getQuantite());
                }
            }

            return $avertissements;
        });
    }
}
