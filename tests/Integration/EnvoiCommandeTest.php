<?php

namespace App\Tests\Integration;

use App\Achat\AchatException;
use App\Achat\CommandeService;
use App\Achat\EmailCommande;
use App\Achat\ExportCommandeExcel;
use App\Entity\Commande;
use App\Entity\EnvoiCommande;
use App\Entity\Utilisateur;
use App\Enum\StatutCommande;
use App\Enum\StatutEnvoi;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Tenant\TenantContext;
use App\Tests\Factory\FournisseurFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * CO-05 : un email refusé par le serveur d'envoi laisse la commande telle quelle, sans consommer de numéro (RG-02),
 * et l'échec reste dans l'historique des envois.
 */
final class EnvoiCommandeTest extends KernelTestCase
{
    use Factories;

    public function testUnEchecDEnvoiLaisseLeBrouillonEtEstHistorise(): void
    {
        self::bootKernel();
        $conteneur = self::getContainer();
        $em = $conteneur->get(EntityManagerInterface::class);
        $tenant = $conteneur->get(TenantContext::class);

        $pharmacie = PharmacieFactory::createOne();
        $fournisseur = FournisseurFactory::createOne(['pharmacie' => $pharmacie, 'email' => 'commandes@ppm.example']);
        $produit = ProduitFactory::createOne(['pharmacie' => $pharmacie, 'prixAchat' => 500]);
        $tenant->forcer($pharmacie);

        $enPanne = new class($conteneur->get(TransportInterface::class), $conteneur->get(ExportCommandeExcel::class)) extends EmailCommande {
            public bool $enPanne = true;

            public function envoyer(Commande $commande, ?Utilisateur $expediteur): void
            {
                if ($this->enPanne) {
                    throw new TransportException('Connexion au serveur SMTP impossible.');
                }
                parent::envoyer($commande, $expediteur);
            }
        };
        $service = new CommandeService($em, $conteneur->get(Numeroteur::class), $enPanne, $conteneur->get(AuditLogger::class), $tenant, $conteneur->get(ClockInterface::class));

        $commande = $service->creer($fournisseur, [[$produit, 12]]);
        try {
            $service->envoyer($commande);
            self::fail('L\'échec de l\'envoi doit être signalé.');
        } catch (AchatException $e) {
            self::assertStringContainsString('L\'email n\'a pas pu être envoyé à commandes@ppm.example : Connexion au serveur SMTP impossible.', $e->getMessage());
        }

        self::assertSame([StatutCommande::Brouillon, null], [$commande->getStatut(), $commande->getNumero()]);
        $envois = $em->getRepository(EnvoiCommande::class)->findBy(['commande' => $commande]);
        self::assertCount(1, $envois);
        self::assertSame([StatutEnvoi::Echec, 'Connexion au serveur SMTP impossible.'], [$envois[0]->getStatut(), $envois[0]->getErreur()]);

        // Le serveur répond de nouveau : le numéro attribué est le premier de l'année, sans trou.
        $enPanne->enPanne = false;
        $service->envoyer($commande);
        self::assertSame([StatutCommande::Envoyee, 'CMD-'.date('Y').'-000001'], [$commande->getStatut(), $commande->getNumero()]);
        self::assertCount(2, $em->getRepository(EnvoiCommande::class)->findBy(['commande' => $commande]));
    }
}
