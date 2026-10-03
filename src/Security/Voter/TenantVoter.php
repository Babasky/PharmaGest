<?php

namespace App\Security\Voter;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seconde ligne de défense derrière le filtre Doctrine : une donnée tenant n'est accessible
 * que si elle appartient à la pharmacie courante.
 *
 * Les contrôleurs transforment un refus en 404 ({@see \App\Controller\AbstractAppController::exigerMemePharmacie()}).
 *
 * @extends Voter<string, TenantAwareInterface>
 */
final class TenantVoter extends Voter
{
    public const MEME_PHARMACIE = 'MEME_PHARMACIE';

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MEME_PHARMACIE === $attribute && $subject instanceof TenantAwareInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $courante = $this->tenantContext->getPharmacie();

        return null !== $courante && null !== $subject->getPharmacie() && $subject->getPharmacie()->getId() === $courante->getId();
    }
}
