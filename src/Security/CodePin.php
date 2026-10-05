<?php

namespace App\Security;

use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Repository\AffectationRepository;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Code PIN à 4 chiffres (PH-04) : changement rapide de vendeur à la caisse et autorisations du propriétaire
 * (remise au-delà du plafond, RE-03 ; vente sans ordonnance d'un produit qui l'exige, VE-03).
 *
 * Le code est haché comme un mot de passe. Quatre chiffres se devinent vite : après 5 erreurs en 15 minutes,
 * le code est bloqué (par utilisateur, ou par pharmacie pour les autorisations du propriétaire).
 */
class CodePin
{
    public const FORMAT = '/^\d{4}$/';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hashers,
        #[Target('code_pin.limiter')]
        private readonly RateLimiterFactoryInterface $limiteur,
        private readonly AffectationRepository $affectations,
        private readonly TenantContext $tenantContext,
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
     * Propriétaire actif de la pharmacie dont c'est le code PIN.
     *
     * @throws CodePinException code absent, faux ou bloqué
     */
    public function autorisationProprietaire(Pharmacie $pharmacie, ?string $code): Utilisateur
    {
        if (null === $code || '' === trim($code)) {
            throw new CodePinException('Cette vente demande le code PIN du propriétaire.');
        }
        $proprietaires = $this->tenantContext->sansFiltre(fn () => $this->affectations->proprietaires($pharmacie));
        $autorise = null;
        $this->essai('proprietaire-'.$pharmacie->getId(), function () use ($proprietaires, $code, &$autorise): bool {
            foreach ($proprietaires as $proprietaire) {
                if ($this->correspond($proprietaire, $code)) {
                    $autorise = $proprietaire;

                    return true;
                }
            }

            return false;
        });

        return $autorise ?? throw new CodePinException('Code PIN du propriétaire incorrect.');
    }

    /**
     * @param callable(): bool $verification
     */
    private function essai(string $cle, callable $verification): bool
    {
        $limite = $this->limiteur->create($cle);
        if (0 === $limite->consume(0)->getRemainingTokens()) {
            throw new CodePinException('Trop d\'essais de code PIN : réessayez dans 15 minutes.');
        }
        if ($verification()) {
            $limite->reset();

            return true;
        }
        $limite->consume();

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
