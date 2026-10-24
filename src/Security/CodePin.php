<?php

namespace App\Security;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Code PIN à 4 chiffres (PH-04) : il valide chaque vente au nom de celui qui le saisit. Il n'autorise plus
 * les ventes qui demandent l'accord du propriétaire : celui-ci répond depuis son compte (Modibo, 23/10/2026).
 *
 * Le code est haché comme un mot de passe. Quatre chiffres se devinent vite : après 5 erreurs en 15 minutes,
 * le code est bloqué (par utilisateur).
 */
class CodePin
{
    public const FORMAT = '/^\d{4}$/';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hashers,
        #[Target('code_pin.limiter')]
        private readonly RateLimiterFactoryInterface $limiteur,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws CodePinException
     */
    public function definir(Utilisateur $utilisateur, string $code): void
    {
        if (1 !== preg_match(self::FORMAT, $code)) {
            throw new CodePinException('Le code PIN doit comporter exactement 4 chiffres.');
        }
        if (\in_array($code, ['0000', '1111', '1234', '4321', '2222', '9999'], true)) {
            throw new CodePinException('Ce code est trop facile à deviner : choisissez-en un autre.');
        }
        $utilisateur->setCodePin($this->hasher()->hash($code));
        $this->em->flush();
    }

    /**
     * @throws CodePinException si le code est bloqué après trop d'essais
     */
    public function verifier(Utilisateur $utilisateur, ?string $code): bool
    {
        return $this->essai('utilisateur-'.$utilisateur->getId(), fn () => $this->correspond($utilisateur, $code));
    }

    /**
     * @param callable(): bool $verification
     */
    private function essai(string $cle, callable $verification): bool
    {
        // L'essai est compté AVANT la vérification : des requêtes envoyées en même temps ne peuvent pas passer
        // toutes entre le contrôle du compteur et son incrément (et tester ainsi bien plus de 5 codes).
        $limite = $this->limiteur->create($cle);
        if (!$limite->consume()->isAccepted()) {
            throw new CodePinException('Trop d\'essais de code PIN : réessayez dans 15 minutes.');
        }
        if ($verification()) {
            $limite->reset();

            return true;
        }

        return false;
    }

    private function correspond(Utilisateur $utilisateur, ?string $code): bool
    {
        return null !== $code && null !== $utilisateur->getCodePin() && 1 === preg_match(self::FORMAT, $code)
            && $this->hasher()->verify($utilisateur->getCodePin(), $code);
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->hashers->getPasswordHasher(Utilisateur::class);
    }
}
