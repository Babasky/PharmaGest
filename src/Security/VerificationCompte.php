<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Repository\AffectationRepository;
use App\Tenant\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion d'un compte désactivé, pas encore activé, ou sans pharmacie accessible.
 */
final class VerificationCompte implements UserCheckerInterface
{
    public function __construct(
        private readonly AffectationRepository $affectations,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        if (!$user->isActif()) {
            throw new CustomUserMessageAccountStatusException('Votre compte est désactivé. Contactez le responsable de votre pharmacie.');
        }
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Votre compte n\'est pas encore activé : utilisez le lien reçu par email.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof Utilisateur || $user->isSuperAdmin()) {
            return;
        }

        $affectations = $this->tenantContext->sansFiltre(fn () => $this->affectations->activesDe($user));
        if ([] === $affectations) {
            throw new CustomUserMessageAccountStatusException('Votre accès à la pharmacie a été désactivé. Contactez son propriétaire.');
        }
    }
}
