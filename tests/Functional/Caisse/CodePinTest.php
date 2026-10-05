<?php

namespace App\Tests\Functional\Caisse;

use App\Entity\Utilisateur;
use App\Security\CodePin;
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
        self::getContainer()->get(CodePin::class)->definir($this->officine->adjoint, '2580');
        $this->ouvrirCaisse($this->officine->vendeur);

        for ($i = 0; $i < 5; ++$i) {
            $this->poster('/caisse/changer-vendeur', ['utilisateur' => $this->officine->adjoint->getId(), 'code_pin' => '000'.$i]);
            self::assertStringContainsString('Code PIN incorrect', $this->erreur());
        }
        $this->poster('/caisse/changer-vendeur', ['utilisateur' => $this->officine->adjoint->getId(), 'code_pin' => '2580']);
        self::assertStringContainsString('Trop d\'essais', $this->erreur(), 'Même le bon code est refusé pendant le blocage.');
    }
}
