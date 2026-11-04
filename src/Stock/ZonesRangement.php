<?php

namespace App\Stock;

use App\Entity\Pharmacie;
use App\Entity\ZoneRangement;
use App\Repository\ZoneRangementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zones de rangement de la pharmacie courante. Une pharmacie qui n'en a encore aucune reçoit les trois zones
 * d'origine ; le pharmacien les renomme, les archive ou en ajoute d'autres.
 */
class ZonesRangement
{
    public const COMPTOIR = 'Comptoir';
    public const PAR_DEFAUT = [self::COMPTOIR, 'Réserve', 'Réfrigérateur'];

    public function __construct(
        private readonly ZoneRangementRepository $zones,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<ZoneRangement> toutes les zones (en service d'abord), créées au besoin
     */
    public function assurer(Pharmacie $pharmacie): array
    {
        $zones = $this->zones->toutes();
        if ([] !== $zones) {
            return $zones;
        }
        foreach (self::PAR_DEFAUT as $nom) {
            $zone = (new ZoneRangement())->setNom($nom)->setPharmacie($pharmacie);
            $this->em->persist($zone);
            $zones[] = $zone;
        }
        $this->em->flush();

        return $this->zones->toutes();
    }

    /** Zone d'une étagère créée sans zone choisie (import) : le comptoir s'il est en service. */
    public function parDefaut(Pharmacie $pharmacie): ?ZoneRangement
    {
        $this->assurer($pharmacie);

        return $this->zones->findOneBy(['nom' => self::COMPTOIR, 'actif' => true]);
    }
}
