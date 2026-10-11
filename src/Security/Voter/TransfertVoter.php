<?php

namespace App\Security\Voter;

use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Repository\AffectationRepository;
use App\Repository\TransfertStockRepository;
use App\Tenant\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Module Transferts (menu et pages) : pour le propriétaire et l'adjoint, seulement si un propriétaire de l'officine
 * courante possède plusieurs pharmacies. Un transfert encore à traiter (en préparation ici, ou expédié vers ici)
 * garde le module ouvert, pour qu'il puisse être annulé ou réceptionné.
 *
 * @extends Voter<string, null>
 */
final class TransfertVoter extends Voter
{
    public const ACCES = 'TRANSFERT_ACCES';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly TenantContext $tenantContext,
        private readonly AffectationRepository $affectations,
        private readonly TransfertStockRepository $transferts,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCES === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $courante = $this->tenantContext->getPharmacie();
        if (null === $courante || !$this->decisions->decide($token, [Utilisateur::ROLE_ADJOINT])) {
            return false;
        }

        return $this->tenantContext->sansFiltre(fn (): bool => $this->proprietairePossedePlusieursPharmacies($courante) || $this->transferts->aTraiter($courante));
    }

    private function proprietairePossedePlusieursPharmacies(Pharmacie $courante): bool
    {
        foreach ($this->affectations->proprietaires($courante) as $proprietaire) {
            $pharmacies = array_filter(
                $this->affectations->activesDe($proprietaire),
                static fn ($affectation) => !($affectation->getPharmacie()?->isArchivee() ?? true),
            );
            if (\count($pharmacies) > 1) {
                return true;
            }
        }

        return false;
    }
}
