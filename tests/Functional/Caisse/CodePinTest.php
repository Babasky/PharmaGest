<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\Utilisateur;
use App\Security\CodePin;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Code PIN personnel (PH-04) : choix, refus des codes faibles, blocage après 5 erreurs.
 */
final class CodePinTest extends CaisseTestCase
{
    public function testChoixDuCodePin(): void
    {
        $this->connecter($this->officine->vendeur)->request('GET', '/mon-code-pin');
        $this->client->submitForm('Enregistrer le code PIN', [
            'code_pin[motDePasse]' => 'mauvais', 'code_pin[code][first]' => '2468', 'code_pin[code][second]' => '2468',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Mot de passe incorrect');

        $this->client->submitForm('Enregistrer le code PIN', [
            'code_pin[motDePasse]' => 'motdepasse', 'code_pin[code][first]' => '1234', 'code_pin[code][second]' => '1234',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'trop facile à deviner');

        $this->client->submitForm('Enregistrer le code PIN', [
            'code_pin[motDePasse]' => 'motdepasse', 'code_pin[code][first]' => '2468', 'code_pin[code][second]' => '2468',
        ]);
        self::assertResponseRedirects('/caisse');

        $this->sansFiltre(function (EntityManagerInterface $em): void {
            $vendeur = $em->find(Utilisateur::class, $this->officine->vendeur->getId());
            self::assertInstanceOf(Utilisateur::class, $vendeur);
            self::assertNotSame('2468', $vendeur->getCodePin(), 'Le code est haché.');
            self::assertTrue(self::getContainer()->get(CodePin::class)->verifier($vendeur, '2468'));
        });
    }

    public function testLeCodeEstBloqueApresCinqErreurs(): void
    {
        $collegue = UtilisateurFactory::createOne(['role' => Utilisateur::ROLE_VENDEUR]);
        AffectationFactory::createOne(['utilisateur' => $collegue, 'pharmacie' => $this->officine->pharmacie]);
        $this->definirPin($collegue, '2580');
        $produit = ProduitFactory::createOne(['pharmacie' => $this->officine->pharmacie, 'prixVente' => 1000]);
        LotFactory::createOne(['produit' => $produit]);
        $this->ouvrirCaisse($this->officine->vendeur);
        $this->ajouter($produit);

        for ($i = 0; $i < 5; ++$i) {
            $this->poster('/caisse/encaisser', ['vendeur' => $collegue->getId(), 'pin_vendeur' => '000'.$i]);
            self::assertStringContainsString('Code PIN du vendeur incorrect', $this->erreur());
        }
        $this->poster('/caisse/encaisser', ['vendeur' => $collegue->getId(), 'pin_vendeur' => '2580']);
        self::assertStringContainsString('Trop d\'essais', $this->erreur(), 'Même le bon code est refusé pendant le blocage.');
    }
}
