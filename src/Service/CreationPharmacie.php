<?php

namespace App\Service;

use App\Entity\Affectation;
use App\Entity\Offre;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Mailer\PlateformeMailer;
use App\Repository\AffectationRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Création d'une pharmacie et de son propriétaire par le super admin (SA-01, § 3.2 étape 1).
 */
class CreationPharmacie
{
    public const JOURS_ESSAI_PAR_DEFAUT = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly AffectationRepository $affectations,
        private readonly PlateformeMailer $mailer,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Si l'email désigne déjà un propriétaire, la pharmacie lui est ajoutée (offre multi-pharmacies) ;
     * sinon un compte est créé et reçoit un email d'activation.
     *
     * @throws CreationPharmacieException
     */
    public function creer(Pharmacie $pharmacie, string $emailProprietaire, string $nomProprietaire, int $joursEssai): Utilisateur
    {
        $proprietaire = $this->utilisateurs->parEmail($emailProprietaire);
        $nouveauCompte = null === $proprietaire;

        if (null !== $proprietaire) {
            if (!$proprietaire->isProprietaire()) {
                throw new CreationPharmacieException('Cet email appartient déjà à un compte qui n\'est pas propriétaire.');
            }
            $this->verifierLimitePharmacies($proprietaire, $pharmacie->getOffre());
        } else {
            $proprietaire = (new Utilisateur())
                ->setEmail($emailProprietaire)
                ->setNom($nomProprietaire)
                ->setRole(Utilisateur::ROLE_PROPRIETAIRE);
            $this->em->persist($proprietaire);
        }

        if ($joursEssai > 0) {
            $pharmacie->setFinEssai($this->horloge->now()->setTime(0, 0)->modify(\sprintf('+%d days', $joursEssai)));
        }

        $this->em->wrapInTransaction(function () use ($pharmacie, $proprietaire, $nouveauCompte): void {
            $this->em->persist($pharmacie);
            $this->em->persist(new Affectation($proprietaire, $pharmacie));
            $this->em->flush();

            $this->audit->journaliser(AuditLogger::PHARMACIE_CREEE, $pharmacie, $pharmacie, null, [
                'nom' => $pharmacie->getNom(),
                'offre' => $pharmacie->getOffre()->getCode(),
                'proprietaire' => $proprietaire->getEmail(),
                'nouveau_compte' => $nouveauCompte,
            ]);
            $this->em->flush();
        });

        if ($nouveauCompte || !$proprietaire->isActive()) {
            $this->mailer->activation($proprietaire, $pharmacie);
        }

        return $proprietaire;
    }

    private function verifierLimitePharmacies(Utilisateur $proprietaire, Offre $offre): void
    {
        $nombre = $this->affectations->compterPharmaciesDe($proprietaire);
        if ($nombre >= $offre->getMaxPharmacies()) {
            throw new CreationPharmacieException(\sprintf('L\'offre %s permet %d pharmacie(s) par propriétaire ; %s en a déjà %d. Choisissez l\'offre Premium.', $offre->getNom(), $offre->getMaxPharmacies(), $proprietaire->getNom(), $nombre));
        }
    }
}
