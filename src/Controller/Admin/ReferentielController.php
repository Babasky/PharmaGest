<?php

namespace App\Controller\Admin;

use App\Entity\CategorieDepenseModele;
use App\Entity\FormeGalenique;
use App\Entity\OrganismeAmo;
use App\Entity\ReferentielCommun;
use App\Form\ReferentielCommunType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Référentiels communs à toutes les pharmacies (SA-07) : organismes AMO, formes galéniques,
 * catégories de dépenses proposées par défaut. Rien n'est supprimé : on désactive (RG-15).
 */
#[Route('/admin/referentiels/{type}', requirements: ['type' => 'organismes-amo|formes-galeniques|categories-depenses'])]
final class ReferentielController extends AbstractController
{
    /** @var array<string, array{classe: class-string<ReferentielCommun>, titre: string, singulier: string}> */
    public const TYPES = [
        'organismes-amo' => ['classe' => OrganismeAmo::class, 'titre' => 'Organismes AMO', 'singulier' => 'organisme'],
        'formes-galeniques' => ['classe' => FormeGalenique::class, 'titre' => 'Formes galéniques', 'singulier' => 'forme'],
        'categories-depenses' => ['classe' => CategorieDepenseModele::class, 'titre' => 'Catégories de dépenses par défaut', 'singulier' => 'catégorie'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'admin_referentiel_index', defaults: ['type' => 'organismes-amo'], methods: ['GET', 'POST'])]
    public function index(string $type, Request $requete): Response
    {
        $config = self::TYPES[$type];
        $nouveau = new $config['classe']();
        $formulaire = $this->createForm(ReferentielCommunType::class, $nouveau);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->em->persist($nouveau);
            $this->em->flush();
            $this->addFlash('success', \sprintf('« %s » ajouté.', $nouveau->getNom()));

            return $this->redirectToRoute('admin_referentiel_index', ['type' => $type]);
        }

        return $this->render('admin/referentiel/index.html.twig', [
            'type' => $type,
            'types' => self::TYPES,
            'config' => $config,
            'valeurs' => $this->em->getRepository($config['classe'])->findBy([], ['actif' => 'DESC', 'nom' => 'ASC']),
            'formulaire' => $formulaire,
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/modifier', name: 'admin_referentiel_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(string $type, int $id, Request $requete): Response
    {
        $valeur = $this->trouver($type, $id);
        $formulaire = $this->createForm(ReferentielCommunType::class, $valeur);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Modification enregistrée.');

            return $this->redirectToRoute('admin_referentiel_index', ['type' => $type]);
        }

        return $this->render('referentiel/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => 'Modifier « '.$valeur->getNom().' »',
            'retour' => $this->generateUrl('admin_referentiel_index', ['type' => $type]),
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/activer', name: 'admin_referentiel_activer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function activer(string $type, int $id, Request $requete): Response
    {
        if (!$this->isCsrfTokenValid('referentiel-'.$type.'-'.$id, $requete->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $valeur = $this->trouver($type, $id);
        $valeur->setActif(!$valeur->isActif());
        $this->em->flush();
        $this->addFlash('success', \sprintf('« %s » %s.', $valeur->getNom(), $valeur->isActif() ? 'réactivé' : 'désactivé : il n\'est plus proposé aux pharmacies'));

        return $this->redirectToRoute('admin_referentiel_index', ['type' => $type]);
    }

    private function trouver(string $type, int $id): ReferentielCommun
    {
        return $this->em->find(self::TYPES[$type]['classe'], $id) ?? throw $this->createNotFoundException();
    }
}
