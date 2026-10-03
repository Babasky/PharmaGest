<?php

namespace App\Service;

use App\Entity\Affectation;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Mailer\PlateformeMailer;
use App\Repository\AffectationRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gestion de l'équipe d'une pharmacie par son propriétaire (PH-03) : adjoints et vendeurs,
 * dans la limite d'utilisateurs de l'offre.
 */
class GestionEquipe
{
    /** Rôles que le propriétaire peut attribuer. */
    public const ROLES_ATTRIBUABLES = [
        'Pharmacien adjoint' => Utilisateur::ROLE_ADJOINT,
        'Vendeur' => Utilisateur::ROLE_VENDEUR,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AffectationRepository $affectations,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PlateformeMailer $mailer,
        private readonly AuditLogger $audit,
    ) {
    }

    public function placesRestantes(Pharmacie $pharmacie): ?int
    {
        $max = $pharmacie->getOffre()->getMaxUtilisateurs();

        return null === $max ? null : max(0, $max - $this->affectations->compterActives($pharmacie));
    }

    /**
     * Crée un membre de l'équipe. Sans mot de passe initial, un lien d'activation lui est envoyé.
     *
     * @throws GestionEquipeException
     */
    public function ajouter(Pharmacie $pharmacie, Utilisateur $membre, ?string $motDePasseInitial): Affectation
    {
        $this->verifierPlaceDisponible($pharmacie);
        $this->verifierRoleAttribuable($membre->getRole());

        if (null !== $this->utilisateurs->parEmail($membre->getEmail())) {
            throw new GestionEquipeException('Un compte existe déjà avec cet email.');
        }

        if (null !== $motDePasseInitial && '' !== $motDePasseInitial) {
            $membre->setPassword($this->hasher->hashPassword($membre, $motDePasseInitial));
        }

        $affectation = new Affectation($membre, $pharmacie);
        $this->em->persist($membre);
        $this->em->persist($affectation);
        $this->em->flush();

        $this->audit->journaliser(AuditLogger::UTILISATEUR_CREE, $pharmacie, $membre, null, [
            'email' => $membre->getEmail(),
            'role' => $membre->getRole(),
        ]);
        $this->em->flush();

        if (!$membre->isActive()) {
            $this->mailer->activation($membre, $pharmacie);
        }

        return $affectation;
    }

    /**
     * @param array{nom: string, email: string, role: string|null} $avant
     *
     * @throws GestionEquipeException
     */
    public function modifier(Affectation $affectation, array $avant): void
    {
        $membre = $affectation->getUtilisateur();
        $this->verifierRoleAttribuable($membre->getRole());

        $existant = $this->utilisateurs->parEmail($membre->getEmail());
        if (null !== $existant && $existant !== $membre) {
            throw new GestionEquipeException('Un compte existe déjà avec cet email.');
        }

        $apres = ['nom' => $membre->getNom(), 'email' => $membre->getEmail(), 'role' => $membre->getRole()];
        if ($apres !== $avant) {
            $this->audit->journaliser(AuditLogger::UTILISATEUR_MODIFIE, $affectation->getPharmacie(), $membre, $avant, $apres);
        }
        $this->em->flush();
    }

    public function desactiver(Affectation $affectation): void
    {
        $this->verifierModifiable($affectation);
        $affectation->setActif(false);
        $this->audit->journaliser(AuditLogger::UTILISATEUR_DESACTIVE, $affectation->getPharmacie(), $affectation->getUtilisateur(), null, [
            'email' => $affectation->getUtilisateur()->getEmail(),
        ]);
        $this->em->flush();
    }

    /**
     * @throws GestionEquipeException
     */
    public function reactiver(Affectation $affectation): void
    {
        $this->verifierModifiable($affectation);
        $this->verifierPlaceDisponible($affectation->getPharmacie() ?? throw new \LogicException());
        $affectation->setActif(true);
        $this->audit->journaliser(AuditLogger::UTILISATEUR_REACTIVE, $affectation->getPharmacie(), $affectation->getUtilisateur());
        $this->em->flush();
    }

    public function renvoyerLienActivation(Affectation $affectation): void
    {
        $this->mailer->activation($affectation->getUtilisateur(), $affectation->getPharmacie());
    }

    private function verifierPlaceDisponible(Pharmacie $pharmacie): void
    {
        if (0 === $this->placesRestantes($pharmacie)) {
            throw new GestionEquipeException(\sprintf('L\'offre %s est limitée à %d utilisateurs actifs. Désactivez un compte ou changez d\'offre.', $pharmacie->getOffre()->getNom(), $pharmacie->getOffre()->getMaxUtilisateurs()));
        }
    }

    private function verifierRoleAttribuable(?string $role): void
    {
        if (!\in_array($role, self::ROLES_ATTRIBUABLES, true)) {
            throw new GestionEquipeException('Ce rôle ne peut pas être attribué depuis la gestion de l\'équipe.');
        }
    }

    private function verifierModifiable(Affectation $affectation): void
    {
        if ($affectation->getUtilisateur()->isProprietaire()) {
            throw new GestionEquipeException('Le compte du propriétaire ne se gère pas depuis cette page.');
        }
    }
}
