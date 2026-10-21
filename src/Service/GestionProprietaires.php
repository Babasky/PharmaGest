<?php

namespace App\Service;

use App\Entity\Affectation;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Mailer\PlateformeMailer;
use App\Repository\AffectationRepository;
use App\Repository\UtilisateurRepository;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Propriétaires associés d'une pharmacie : plusieurs pharmaciens peuvent s'associer pour ouvrir une officine.
 * Tous les propriétaires ont les mêmes droits ; la pharmacie garde toujours au moins un propriétaire actif.
 *
 * Un associé s'ajoute depuis l'équipe (par un propriétaire) ou depuis la fiche de la pharmacie (super admin).
 * Seul le super admin retire un associé : un propriétaire ne peut pas couper l'accès d'un autre.
 */
class GestionProprietaires
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AffectationRepository $affectations,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PlateformeMailer $mailer,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly GestionEquipe $equipe,
    ) {
    }

    /**
     * Ajoute un propriétaire à la pharmacie :
     * - email inconnu : un compte propriétaire est créé (mot de passe initial ou lien d'activation) ;
     * - email d'un propriétaire existant : la pharmacie s'ajoute à ses pharmacies (dans la limite de l'offre) ;
     * - email d'un membre de cette seule pharmacie (adjoint, vendeur…) : il devient propriétaire.
     *
     * @throws GestionEquipeException
     */
    public function ajouter(Pharmacie $pharmacie, string $email, string $nom, ?string $motDePasseInitial = null): Affectation
    {
        return $this->tenantContext->sansFiltre(function () use ($pharmacie, $email, $nom, $motDePasseInitial): Affectation {
            $compte = $this->utilisateurs->parEmail($email);
            $affectation = null === $compte ? null : $this->affectations->findOneBy(['utilisateur' => $compte, 'pharmacie' => $pharmacie]);

            if (null !== $affectation && $affectation->isActif() && $compte->isProprietaire()) {
                throw new GestionEquipeException(\sprintf('%s est déjà propriétaire de cette pharmacie.', $compte->getNom()));
            }
            if (null === $affectation || !$affectation->isAccesOuvert()) {
                $this->verifierPlaceDisponible($pharmacie);
            }

            $promu = false;
            if (null === $compte) {
                $compte = (new Utilisateur())->setEmail($email)->setNom($nom)->setRole(Utilisateur::ROLE_PROPRIETAIRE);
                if (null !== $motDePasseInitial && '' !== $motDePasseInitial) {
                    $compte->setPassword($this->hasher->hashPassword($compte, $motDePasseInitial));
                }
                $this->em->persist($compte);
            } elseif ($compte->isProprietaire()) {
                if (null === $affectation) {
                    $this->verifierLimitePharmacies($compte, $pharmacie);
                }
            } else {
                $this->verifierPromotionPossible($compte, $pharmacie);
                $compte->setRole(Utilisateur::ROLE_PROPRIETAIRE);
                $promu = true;
            }

            if (null === $affectation) {
                $affectation = new Affectation($compte, $pharmacie);
                $this->em->persist($affectation);
            }
            $affectation->setActif(true);
            $this->em->flush();

            $this->audit->journaliser(AuditLogger::PROPRIETAIRE_AJOUTE, $pharmacie, $compte, null, [
                'email' => $compte->getEmail(),
                'promu' => $promu,
            ]);
            $this->em->flush();

            if (!$compte->isActive()) {
                $this->mailer->activation($compte, $pharmacie);
            }

            return $affectation;
        });
    }

    /**
     * Retire l'accès d'un propriétaire à la pharmacie (super admin). Ses autres pharmacies ne sont pas touchées.
     *
     * @throws GestionEquipeException
     */
    public function retirer(Affectation $affectation): void
    {
        $proprietaire = $affectation->getUtilisateur();
        $pharmacie = $affectation->getPharmacie() ?? throw new \LogicException();
        if (!$proprietaire->isProprietaire() || !$affectation->isActif()) {
            throw new GestionEquipeException('Ce compte n\'est pas un propriétaire actif de la pharmacie.');
        }

        $autres = array_filter(
            $this->tenantContext->sansFiltre(fn () => $this->affectations->proprietaires($pharmacie)),
            static fn (Utilisateur $u) => $u->getId() !== $proprietaire->getId(),
        );
        if ([] === $autres) {
            throw new GestionEquipeException('La pharmacie doit garder au moins un propriétaire actif : ajoutez d\'abord un autre propriétaire.');
        }

        $affectation->setActif(false);
        $this->audit->journaliser(AuditLogger::PROPRIETAIRE_RETIRE, $pharmacie, $proprietaire, null, ['email' => $proprietaire->getEmail()]);
        $this->em->flush();
    }

    /**
     * Rend l'accès à un propriétaire retiré (super admin).
     *
     * @throws GestionEquipeException
     */
    public function reactiver(Affectation $affectation): void
    {
        if (!$affectation->getUtilisateur()->isProprietaire() || $affectation->isActif()) {
            throw new GestionEquipeException('Ce compte n\'est pas un propriétaire retiré de la pharmacie.');
        }
        $this->verifierPlaceDisponible($affectation->getPharmacie() ?? throw new \LogicException());

        $affectation->setActif(true);
        $this->audit->journaliser(AuditLogger::PROPRIETAIRE_AJOUTE, $affectation->getPharmacie(), $affectation->getUtilisateur(), null, [
            'email' => $affectation->getUtilisateur()->getEmail(),
            'promu' => false,
        ]);
        $this->em->flush();
    }

    private function verifierPlaceDisponible(Pharmacie $pharmacie): void
    {
        if (0 === $this->tenantContext->sansFiltre(fn () => $this->equipe->placesRestantes($pharmacie))) {
            throw new GestionEquipeException(\sprintf('L\'offre %s est limitée à %d utilisateurs actifs, propriétaires compris. Désactivez un compte ou changez d\'offre.', $pharmacie->getOffre()->getNom(), $pharmacie->getOffre()->getMaxUtilisateurs()));
        }
    }

    /** Même règle qu'à la création d'une pharmacie : l'offre fixe le nombre de pharmacies par propriétaire. */
    private function verifierLimitePharmacies(Utilisateur $proprietaire, Pharmacie $pharmacie): void
    {
        $offre = $pharmacie->getOffre();
        $nombre = $this->affectations->compterPharmaciesDe($proprietaire);
        if ($nombre >= $offre->getMaxPharmacies()) {
            throw new GestionEquipeException(\sprintf('L\'offre %s permet %d pharmacie(s) par propriétaire ; %s en a déjà %d. Passez à l\'offre Premium pour l\'associer à cette pharmacie.', $offre->getNom(), $offre->getMaxPharmacies(), $proprietaire->getNom(), $nombre));
        }
    }

    /** Un membre devient propriétaire seulement s'il ne travaille dans aucune autre pharmacie (le rôle est porté par le compte). */
    private function verifierPromotionPossible(Utilisateur $compte, Pharmacie $pharmacie): void
    {
        if ($compte->isSuperAdmin()) {
            throw new GestionEquipeException('Cet email appartient à un compte de la plateforme.');
        }
        foreach ($this->affectations->findBy(['utilisateur' => $compte]) as $autre) {
            if ($autre->getPharmacie()?->getId() !== $pharmacie->getId()) {
                throw new GestionEquipeException('Cet email appartient déjà à un membre d\'une autre pharmacie.');
            }
        }
    }
}
