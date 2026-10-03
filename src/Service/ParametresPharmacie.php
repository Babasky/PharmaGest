<?php

namespace App\Service;

use App\Entity\OrganismeAmo;
use App\Entity\ParametrePharmacie;
use App\Entity\Pharmacie;
use App\Entity\TauxAmo;
use App\Repository\ParametrePharmacieRepository;
use App\Repository\TauxAmoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Paramètres de la pharmacie courante (PH-02), créés avec les valeurs par défaut au premier accès.
 */
class ParametresPharmacie
{
    public function __construct(
        private readonly ParametrePharmacieRepository $parametres,
        private readonly TauxAmoRepository $taux,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function pour(Pharmacie $pharmacie): ParametrePharmacie
    {
        $parametres = $this->parametres->findOneBy(['pharmacie' => $pharmacie]);
        if (null === $parametres) {
            $parametres = (new ParametrePharmacie())->setPharmacie($pharmacie);
            $this->em->persist($parametres);
            $this->em->flush();
        }

        return $parametres;
    }

    /**
     * Taux AMO (en %) applicable à une date : dernier taux saisi par la pharmacie, sinon 70 % (H1).
     */
    public function tauxAmo(OrganismeAmo $organisme, ?\DateTimeImmutable $date = null): int
    {
        return $this->taux->enVigueur($organisme, $date ?? $this->horloge->now())?->getTaux() ?? TauxAmo::TAUX_PAR_DEFAUT;
    }
}
