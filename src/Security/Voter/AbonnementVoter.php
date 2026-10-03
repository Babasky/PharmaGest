<?php

namespace App\Security\Voter;

use App\Entity\Abonnement;
use App\Entity\Utilisateur;
use App\Tenant\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Facture d'abonnement : le super admin, ou le propriétaire de la pharmacie concernée.
 *
 * @extends Voter<string, Abonnement>
 */
final class AbonnementVoter extends Voter
{
    public const FACTURE = 'ABONNEMENT_FACTURE';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly TenantContext $tenantContext,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::FACTURE === $attribute && $subject instanceof Abonnement;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($this->decisions->decide($token, [Utilisateur::ROLE_SUPER_ADMIN])) {
            return true;
        }

        return $this->decisions->decide($token, [Utilisateur::ROLE_PROPRIETAIRE])
            && $subject->getPharmacie()?->getId() === $this->tenantContext->getPharmacie()?->getId();
    }
}
