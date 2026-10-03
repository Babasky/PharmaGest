<?php

namespace App\Controller;

use App\Entity\Pharmacie;
use App\Security\Voter\TenantVoter;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base des contrôleurs de l'application. Les contrôleurs restent minces : la logique est dans les services.
 */
abstract class AbstractAppController extends AbstractController
{
    protected TenantContext $tenantContext;
    protected EntityManagerInterface $entityManager;

    #[Required]
    public function setTenantContext(TenantContext $tenantContext): void
    {
        $this->tenantContext = $tenantContext;
    }

    #[Required]
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    protected function pharmacie(): Pharmacie
    {
        return $this->tenantContext->exigerPharmacie();
    }

    /**
     * Un formulaire envoyé qui n'aboutit pas (règle métier refusée) répond 422 : Turbo n'affiche
     * la réponse d'un formulaire que si c'est une redirection ou une erreur.
     *
     * @param FormInterface<mixed> $formulaire
     */
    protected static function reponseRefusSiSoumis(FormInterface $formulaire): ?Response
    {
        return $formulaire->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null;
    }

    /**
     * Archive ou restaure une donnée de référence (RG-15 : rien n'est supprimé).
     */
    protected function basculerArchivage(TenantAwareInterface $entite, string $libelle): void
    {
        if (!method_exists($entite, 'isActif') || !method_exists($entite, 'setActif')) {
            throw new \LogicException(\sprintf('%s n\'est pas archivable.', $entite::class));
        }
        $this->exigerMemePharmacie($entite);
        $entite->setActif(!$entite->isActif());
        $this->entityManager->flush();
        $this->addFlash('success', $entite->isActif()
            ? \sprintf('%s est de nouveau disponible.', $libelle)
            : \sprintf('%s est archivé : il n\'apparaît plus dans les listes de choix, son historique est conservé.', $libelle));
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
