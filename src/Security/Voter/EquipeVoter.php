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
 * et jamais sur un autre propriétaire.
 *
 * @extends Voter<string, Affectation>
 */
final class EquipeVoter extends Voter
{
    public const GERER = 'EQUIPE_GERER';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly TenantContext $tenantContext,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::GERER === $attribute && $subject instanceof Affectation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->decisions->decide($token, [Utilisateur::ROLE_PROPRIETAIRE])
            && $subject->getPharmacie()?->getId() === $this->tenantContext->getPharmacie()?->getId()
            && !$subject->getUtilisateur()->isProprietaire();
    }
}
