<?php

namespace App\Twig;

use App\Entity\Pharmacie;
use App\Service\AbonnementService;
use App\Service\EtatAbonnement;
use App\Tenant\TenantContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Contexte de la pharmacie courante pour le layout : sélecteur de pharmacie et bandeaux d'abonnement.
 */
final class TenantExtension
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AbonnementService $abonnements,
    ) {
    }

    #[AsTwigFunction('pharmacie_courante')]
    public function pharmacieCourante(): ?Pharmacie
    {
        return $this->tenantContext->getPharmacie();
    }

    /**
     * @return list<Pharmacie>
     */
    #[AsTwigFunction('pharmacies_accessibles')]
    public function pharmaciesAccessibles(): array
    {
        return $this->tenantContext->pharmaciesAccessibles();
    }

    /** Faux quand l'abonnement est expiré (lecture seule) : les boutons d'écriture sont masqués. */
    #[AsTwigFunction('ecriture_autorisee')]
    public function ecritureAutorisee(): bool
    {
        return $this->etatAbonnement()?->statut->permetEcriture() ?? true;
    }

    #[AsTwigFunction('etat_abonnement')]
    public function etatAbonnement(): ?EtatAbonnement
    {
        $pharmacie = $this->tenantContext->getPharmacie();

        return null === $pharmacie ? null : $this->abonnements->etat($pharmacie);
    }
}
