<?php

namespace App\Tests\Factory;

use App\Entity\Offre;
use App\Entity\Pharmacie;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Par défaut : offre Standard, abonnement payé encore valable 200 jours.
 *
 * @extends PersistentObjectFactory<Pharmacie>
 */
final class PharmacieFactory extends PersistentObjectFactory
{
    private const VILLES = ['Bamako', 'Ségou', 'Sikasso', 'Mopti', 'Kayes', 'Koutiala'];

    public static function class(): string
    {
        return Pharmacie::class;
    }

    protected function defaults(): array
    {
        return [
            'offre' => OffreFactory::parCode(Offre::STANDARD),
            'nom' => 'Pharmacie '.self::faker()->unique()->lastName(),
            'ville' => self::faker()->randomElement(self::VILLES),
            'adresse' => 'Rue '.self::faker()->numberBetween(1, 600).', porte '.self::faker()->numberBetween(1, 90),
            'telephone' => '+2237'.self::faker()->numerify('#######'),
            'numeroAutorisation' => 'AUT-'.self::faker()->unique()->numerify('####'),
            'finAbonnement' => new \DateTimeImmutable('today +200 days'),
        ];
    }

    public function enEssai(int $joursRestants = 20): self
    {
        return $this->with(['finAbonnement' => null, 'finEssai' => new \DateTimeImmutable(\sprintf('today %+d days', $joursRestants))]);
    }

    /** Abonnement échu depuis $jours jours. */
    public function echue(int $jours): self
    {
        return $this->with(['finAbonnement' => new \DateTimeImmutable(\sprintf('today -%d days', $jours))]);
    }

    public function offre(string $code): self
    {
        return $this->with(['offre' => OffreFactory::parCode($code)]);
    }
}
