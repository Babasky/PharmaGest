<?php

namespace App\Security\Voter;

use App\Entity\Affectation;
use App\Entity\Utilisateur;
use App\Tenant\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Gestion d'un membre de l'équipe : réservée au propriétaire de la même pharmacie,
 * et jamais sur un autre propriétaire (un associé ne coupe pas l'accès d'un autre associé).
 * Le lien d'activation peut être renvoyé à tout autre membre, associés compris.
 *
 * @extends Voter<string, Affectation>
 */
final class EquipeVoter extends Voter
{
    public const GERER = 'EQUIPE_GERER';
    public const RELANCER = 'EQUIPE_RELANCER';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly TenantContext $tenantContext,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::GERER, self::RELANCER], true) && $subject instanceof Affectation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$this->decisions->decide($token, [Utilisateur::ROLE_PROPRIETAIRE])
            || $subject->getPharmacie()?->getId() !== $this->tenantContext->getPharmacie()?->getId()) {
            return false;
        }

        return self::RELANCER === $attribute
            ? $subject->getUtilisateur()->getUserIdentifier() !== $token->getUserIdentifier()
            : !$subject->getUtilisateur()->isProprietaire();
    }
}
