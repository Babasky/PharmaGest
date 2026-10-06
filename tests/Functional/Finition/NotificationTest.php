<?php

namespace App\Tests\Functional\Finition;

use App\Entity\Notification;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Enum\TypeNotification;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Functional\Amo\AmoTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Centre de notifications (NO-01) : ruptures, péremptions, échéance d'abonnement, bordereaux impayés ;
 * cloche de la barre supérieure, lecture et isolation.
 */
final class NotificationTest extends AmoTestCase
{
    use ClockSensitiveTrait;

    public function testNotificationsDuJourSansRepetitionPuisLecture(): void
    {
        $p = $this->officine->pharmacie;
        $this->sansFiltre(static function (EntityManagerInterface $em) use ($p): void {
            $em->find(Pharmacie::class, $p->getId())?->setFinAbonnement(new \DateTimeImmutable('today +10 days'));
            $em->flush();
        });
        ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Augmentin', 'seuilAlerte' => 5]);
        $doliprane = ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Doliprane']);
        LotFactory::createOne(['produit' => $doliprane, 'datePeremption' => new \DateTimeImmutable('today +20 days'), 'quantiteInitiale' => 10]);
        LotFactory::createOne(['produit' => $doliprane, 'datePeremption' => new \DateTimeImmutable('today -3 days'), 'quantiteInitiale' => 2]);

        // Bordereau transmis il y a 35 jours et toujours impayé.
        $this->ouvrirCaisse($this->officine->adjoint);
        $this->vendreAmo();
        $this->creerBordereau();
        $this->client->followRedirect();
        $this->cliquer('Transmettre');
        $bordereau = $this->bordereau();
        $this->sansFiltre(static fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement(
            'UPDATE bordereau_amo SET transmis_le = ? WHERE id = ?', [(new \DateTimeImmutable('today -35 days'))->format('Y-m-d H:i:s'), $bordereau->getId()]));

        self::assertStringContainsString('9 notification(s) créée(s)', $this->generer());
        $proprietaire = $this->notifications($this->officine->proprietaire);
        self::assertSame(['rupture', 'peremption', 'perime', 'abonnement', 'bordereau'], array_keys($proprietaire));
        self::assertSame('1 produit(s) en rupture ou sous le seuil d\'alerte.', $proprietaire['rupture']);
        self::assertSame('1 lot(s) périment dans les 90 prochains jours.', $proprietaire['peremption']);
        self::assertStringContainsString('Votre abonnement prend fin le', $proprietaire['abonnement']);
        self::assertStringContainsString('(dans 10 jours)', $proprietaire['abonnement']);
        self::assertStringContainsString('Bordereau '.$bordereau->getNumero().' (INPS)', $proprietaire['bordereau']);
        self::assertStringContainsString('impayés depuis plus de 30 jours', $proprietaire['bordereau']);
        self::assertSame(['rupture', 'peremption', 'perime', 'bordereau'], array_keys($this->notifications($this->officine->adjoint)), 'L\'abonnement ne concerne que le propriétaire.');
        self::assertSame([], $this->notifications($this->officine->vendeur));

        // Rien n'a changé : pas de répétition. Une nouvelle rupture : nouvelle notification.
        self::assertStringContainsString('0 notification(s) créée(s)', $this->generer());
        ProduitFactory::createOne(['pharmacie' => $p, 'nomCommercial' => 'Smecta', 'seuilAlerte' => 5]);
        self::assertStringContainsString('2 notification(s) créée(s)', $this->generer());

        // Cloche : compteur, ouverture (lue + page concernée), tout marquer comme lu.
        $crawler = $this->connecter($this->officine->proprietaire)->request('GET', '/');
        self::assertSelectorTextSame('#cloche-compteur', '6');
        self::assertStringContainsString('2 produit(s) en rupture', $crawler->filter('#cloche')->text());
        $rupture = $this->sansFiltre(fn (EntityManagerInterface $em) => $em->getRepository(Notification::class)->findOneBy(
            ['utilisateur' => $this->officine->proprietaire->getId(), 'message' => '2 produit(s) en rupture ou sous le seuil d\'alerte.']));
        self::assertInstanceOf(Notification::class, $rupture);
        $this->client->request('GET', '/notifications/'.$rupture->getId());
        self::assertResponseRedirects('/stock/alertes?type=seuil');
        $this->client->request('GET', '/notifications', ['nonLues' => 1]);
        self::assertSame(5, $this->client->getCrawler()->filter('#notifications a')->count());
        $this->client->submitForm('Tout marquer comme lu');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '5 notification(s) marquée(s) comme lue(s)');
        self::assertSelectorNotExists('#cloche-compteur');

        // La notification d'un autre utilisateur est introuvable ; le vendeur n'a pas de cloche.
        $this->connecter($this->officine->adjoint)->request('GET', '/notifications/'.$rupture->getId());
        self::assertResponseStatusCodeSame(404);
        $this->connecter($this->officine->vendeur)->request('GET', '/');
        self::assertSelectorNotExists('#cloche');
    }

    public function testEcheancesDAbonnementParPalier(): void
    {
        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $em->find(Pharmacie::class, $this->officine->pharmacie->getId())?->setFinAbonnement(new \DateTimeImmutable('today +20 days'));
            $em->flush();
        });

        $this->generer();
        self::mockTime('+2 days');
        $this->generer();
        self::assertCount(1, $this->toutes(TypeNotification::Abonnement), 'Même palier (J-30) : pas de nouveau message.');
        self::mockTime('+4 days');
        $this->generer();
        self::mockTime('+17 days');
        $this->generer();
        $messages = $this->toutes(TypeNotification::Abonnement);
        self::assertCount(3, $messages, 'J-30, J-15, puis période de grâce.');
        self::assertStringContainsString('(dans 14 jours)', $messages[1]);
        self::assertStringContainsString('passera en lecture seule dans 4 jour(s)', $messages[2]);
    }

    private function generer(): string
    {
        $this->viderGestionnaire();
        \assert(null !== self::$kernel);
        $commande = new CommandTester((new Application(self::$kernel))->find('app:notifications:generer'));
        $commande->execute([]);
        $commande->assertCommandIsSuccessful();

        return $commande->getDisplay(true);
    }

    /**
     * @return array<string, string> message par type
     */
    private function notifications(Utilisateur $utilisateur): array
    {
        return $this->sansFiltre(static function (EntityManagerInterface $em) use ($utilisateur): array {
            $messages = [];
            foreach ($em->getRepository(Notification::class)->findBy(['utilisateur' => $utilisateur->getId()], ['id' => 'ASC']) as $n) {
                $messages[$n->getType()->value] = $n->getMessage();
            }

            return $messages;
        });
    }

    /**
     * @return list<string>
     */
    private function toutes(TypeNotification $type): array
    {
        return $this->sansFiltre(fn (EntityManagerInterface $em): array => array_map(
            static fn (Notification $n) => $n->getMessage(),
            $em->getRepository(Notification::class)->findBy(['utilisateur' => $this->officine->proprietaire->getId(), 'type' => $type], ['id' => 'ASC']),
        ));
    }
}
