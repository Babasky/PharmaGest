<?php

namespace App\Finance;

use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Entity\Pharmacie;
use App\Form\Model\SaisieDepense;
use App\Repository\CategorieDepenseModeleRepository;
use App\Repository\CategorieDepenseRepository;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Dépenses (FI-01) et catégories de dépenses de la pharmacie (FI-02). Réservé au propriétaire.
 */
class GestionDepenses
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategorieDepenseRepository $categories,
        private readonly CategorieDepenseModeleRepository $modeles,
        private readonly Numeroteur $numeroteur,
        private readonly JustificatifDepense $justificatifs,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Catégories de la pharmacie. À la première utilisation, la liste proposée par la plateforme est copiée
     * (Lot 2, SA-07) : la pharmacie l'adapte ensuite sans toucher aux autres.
     *
     * @return list<CategorieDepense>
     */
    public function categories(): array
    {
        $categories = $this->categories->toutes();
        if ([] === $categories) {
            foreach ($this->modeles->actifs() as $modele) {
                $this->em->persist(new CategorieDepense($modele->getNom()));
            }
            $this->em->flush();
            $categories = $this->categories->toutes();
        }

        return $categories;
    }

    /**
     * @throws FinanceException
     */
    public function ajouterCategorie(string $nom): CategorieDepense
    {
        $nom = trim($nom);
        if ('' === $nom || mb_strlen($nom) > 100) {
            throw new FinanceException('Le nom de la catégorie est obligatoire (100 caractères au plus).');
        }
        foreach ($this->categories() as $categorie) {
            if (0 === strcasecmp($categorie->getNom(), $nom)) {
                throw new FinanceException(\sprintf('La catégorie « %s » existe déjà.', $categorie->getNom()));
            }
        }
        $categorie = new CategorieDepense($nom);
        $this->em->persist($categorie);
        $this->em->flush();

        return $categorie;
    }

    /**
     * Enregistre une dépense et lui attribue son numéro DEP-AAAA-NNNNNN (RG-02).
     *
     * @throws FinanceException
     */
    public function enregistrer(SaisieDepense $saisie): Depense
    {
        $pharmacie = $this->tenantContext->exigerPharmacie();
        [$date, $categorie, $libelle, $montant] = $this->valider($saisie);
        if (null !== $saisie->justificatif) {
            $this->justificatifs->verifier($saisie->justificatif);
        }

        $depense = $this->em->wrapInTransaction(function () use ($pharmacie, $saisie, $date, $categorie, $libelle, $montant): Depense {
            $depense = new Depense(
                $this->numeroteur->suivant('DEP', $pharmacie),
                $date,
                $categorie,
                $libelle,
                $montant,
                $saisie->mode ?? throw new FinanceException('Choisissez le mode de paiement.'),
                self::texte($saisie->beneficiaire, 120),
                $this->tenantContext->getUtilisateur(),
                $this->horloge->now(),
            );
            $this->em->persist($depense);
            $this->em->flush();

            return $depense;
        });
        $this->joindre($pharmacie, $depense, $saisie);

        return $depense;
    }

    /**
     * Corrige une dépense ; l'ancienne et la nouvelle version sont journalisées.
     *
     * @throws FinanceException
     */
    public function modifier(Depense $depense, SaisieDepense $saisie): void
    {
        if ($depense->estAnnulee()) {
            throw new FinanceException('Cette dépense est annulée : elle ne se modifie plus.');
        }
        $pharmacie = $this->tenantContext->exigerPharmacie();
        [$date, $categorie, $libelle, $montant] = $this->valider($saisie);
        if (null !== $saisie->justificatif) {
            $this->justificatifs->verifier($saisie->justificatif);
        }
        $avant = $depense->etat();
        $depense->modifier($date, $categorie, $libelle, $montant, $saisie->mode ?? $depense->getMode(), self::texte($saisie->beneficiaire, 120));
        if ($avant !== $depense->etat()) {
            $this->audit->journaliser(AuditLogger::DEPENSE_MODIFIEE, $pharmacie, $depense, $avant, ['numero' => $depense->getNumero(), ...$depense->etat()]);
        }
        $this->em->flush();
        $this->joindre($pharmacie, $depense, $saisie);
    }

    /**
     * Annule une dépense (saisie par erreur) : elle garde son numéro et sort des totaux.
     *
     * @throws FinanceException
     */
    public function annuler(Depense $depense, string $motif): void
    {
        if ($depense->estAnnulee()) {
            throw new FinanceException('Cette dépense est déjà annulée.');
        }
        $motif = trim($motif);
        if ('' === $motif) {
            throw new FinanceException('Le motif de l\'annulation est obligatoire.');
        }
        $depense->annuler($this->horloge->now(), $this->tenantContext->getUtilisateur(), mb_substr($motif, 0, 255));
        $this->audit->journaliser(AuditLogger::DEPENSE_ANNULEE, $depense->getPharmacie(), $depense, ['numero' => $depense->getNumero(), ...$depense->etat()], ['motif' => $depense->getMotifAnnulation()]);
        $this->em->flush();
    }

    /**
     * @return array{\DateTimeImmutable, CategorieDepense, string, int}
     *
     * @throws FinanceException
     */
    private function valider(SaisieDepense $saisie): array
    {
        $date = $saisie->date ?? throw new FinanceException('La date est obligatoire.');
        if ($date > $this->horloge->now()->setTime(0, 0)) {
            throw new FinanceException('La date de la dépense ne peut pas être dans le futur.');
        }
        $categorie = $saisie->categorie ?? throw new FinanceException('Choisissez la catégorie.');
        $libelle = self::texte($saisie->libelle, 150) ?? throw new FinanceException('Le libellé est obligatoire.');
        $montant = $saisie->montant ?? 0;
        if ($montant <= 0 || $montant > RecetteService::MONTANT_MAXIMAL) {
            throw new FinanceException('Le montant doit être supérieur à zéro.');
        }

        return [$date->setTime(0, 0), $categorie, $libelle, $montant];
    }

    private function joindre(Pharmacie $pharmacie, Depense $depense, SaisieDepense $saisie): void
    {
        if (null !== $saisie->justificatif) {
            $this->justificatifs->enregistrer($pharmacie, $depense, $saisie->justificatif);
            $this->em->flush();
        }
    }

    private static function texte(?string $valeur, int $longueur): ?string
    {
        $valeur = trim((string) $valeur);

        return '' === $valeur ? null : mb_substr($valeur, 0, $longueur);
    }
}
