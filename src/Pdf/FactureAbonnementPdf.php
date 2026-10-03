<?php

namespace App\Pdf;

use App\Entity\Abonnement;
use App\Repository\AffectationRepository;
use App\Tenant\TenantContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Facture d'abonnement numérotée (SA-06).
 */
class FactureAbonnementPdf
{
    /**
     * @param array{nom: string, adresse: string, telephone: string, email: string} $editeur
     */
    public function __construct(
        private readonly GenerateurPdf $generateur,
        private readonly AffectationRepository $affectations,
        private readonly TenantContext $tenantContext,
        #[Autowire('%app.editeur%')]
        private readonly array $editeur,
    ) {
    }

    public function rendre(Abonnement $abonnement): string
    {
        $pharmacie = $abonnement->getPharmacie() ?? throw new \LogicException('Abonnement sans pharmacie.');

        return $this->generateur->rendre('pdf/facture_abonnement.html.twig', [
            'abonnement' => $abonnement,
            'pharmacie' => $pharmacie,
            'proprietaires' => $this->tenantContext->sansFiltre(fn () => $this->affectations->proprietaires($pharmacie)),
            'editeur' => $this->editeur,
        ]);
    }

    public function reponse(Abonnement $abonnement): Response
    {
        return GenerateurPdf::reponse($this->rendre($abonnement), $abonnement->getNumeroFacture().'.pdf');
    }
}
