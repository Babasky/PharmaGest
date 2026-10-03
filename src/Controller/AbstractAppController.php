<?php

namespace App\Controller;

use App\Entity\Pharmacie;
use App\Security\Voter\TenantVoter;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base des contrôleurs de l'application. Les contrôleurs restent minces : la logique est dans les services.
 */
abstract class AbstractAppController extends AbstractController
{
    protected TenantContext $tenantContext;

    #[Required]
    public function setTenantContext(TenantContext $tenantContext): void
    {
        $this->tenantContext = $tenantContext;
    }

    protected function pharmacie(): Pharmacie
    {
        return $this->tenantContext->exigerPharmacie();
    }

    /**
     * Une donnée d'une autre pharmacie est « introuvable » (404), jamais « interdite » (403) :
     * on ne révèle pas son existence.
     */
    protected function exigerMemePharmacie(TenantAwareInterface $entite): void
    {
        if (!$this->isGranted(TenantVoter::MEME_PHARMACIE, $entite)) {
            throw $this->createNotFoundException();
        }
    }
}
