<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Utilisateur;
use App\Form\ClientType;
use App\Repository\ClientRepository;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients (RF-06) : toute l'équipe crée et modifie ; seuls le propriétaire et l'adjoint
 * marquent un client « privilégié » et archivent.
 */
#[Route('/clients')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class ClientController extends AbstractAppController
{
    #[Route('', name: 'app_client_index', methods: ['GET'])]
    public function index(
        ClientRepository $clients,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] bool $privilegies = false,
        #[MapQueryParameter] bool $archives = false,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->render('client/index.html.twig', [
            'clients' => $clients->rechercher($q, $privilegies, $archives, $page),
            'q' => $q,
            'privilegies' => $privilegies,
            'archives' => $archives,
        ]);
    }

    #[Route('/nouveau', name: 'app_client_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $requete): Response
    {
        return $this->formulaire($requete, new Client(), 'Nouveau client');
    }

    #[Route('/{id}/modifier', name: 'app_client_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Client $client, Request $requete): Response
    {
        $this->exigerMemePharmacie($client);

        return $this->formulaire($requete, $client, 'Modifier '.$client->getNom());
    }

    #[Route('/{id}/archiver', name: 'app_client_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression('"archiver-" ~ args["client"].getId()'))]
    public function archiver(Client $client): Response
    {
        $this->basculerArchivage($client, \sprintf('Le client %s', $client->getNom()));

        return $this->redirectToRoute('app_client_index');
    }

    private function formulaire(Request $requete, Client $client, string $titre): Response
    {
        $formulaire = $this->createForm(ClientType::class, $client, ['peut_privilegier' => $this->isGranted(Utilisateur::ROLE_ADJOINT)]);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->persist($client);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Client %s enregistré.', $client->getNom()));

            return $this->redirectToRoute('app_client_index');
        }

        return $this->render('referentiel/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_client_index'),
            'entite' => $client->getId() && $this->isGranted(Utilisateur::ROLE_ADJOINT) ? $client : null,
            'route_archiver' => 'app_client_archiver',
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }
}
