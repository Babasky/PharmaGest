<?php

namespace App\Service;

use App\Entity\Compteur;
use App\Entity\Pharmacie;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Numéros séquentiels sans trou, par portée et par année (RG-02) : V-2026-000001, FAC-2026-000001….
 *
 * Le compteur est verrouillé (SELECT … FOR UPDATE) jusqu'à la fin de la transaction en cours :
 * l'appelant doit donc numéroter et enregistrer le document dans la même transaction.
 */
class Numeroteur
{
    public const PORTEE_PLATEFORME = 'plateforme';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function suivant(string $prefixe, ?Pharmacie $pharmacie = null, int $chiffres = 6): string
    {
        $connexion = $this->em->getConnection();
        if (!$connexion->isTransactionActive()) {
            throw new \LogicException('La numérotation doit se faire dans une transaction.');
        }

        $annee = (int) $this->horloge->now()->format('Y');
        $portee = null === $pharmacie ? self::PORTEE_PLATEFORME : 'pharmacie-'.$pharmacie->getId();

        $compteur = $this->verrouiller($portee, $prefixe, $annee);
        if (null === $compteur) {
            // Création concurrente possible : l'INSERT IGNORE laisse gagner le premier, puis on verrouille.
            $connexion->executeStatement(
                'INSERT IGNORE INTO compteur (portee, prefixe, annee, valeur) VALUES (?, ?, ?, 0)',
                [$portee, $prefixe, $annee],
            );
            $compteur = $this->verrouiller($portee, $prefixe, $annee) ?? throw new \RuntimeException('Compteur introuvable.');
        }

        $numero = $compteur->suivant();
        $this->em->flush();

        return \sprintf('%s-%d-%s', $prefixe, $annee, str_pad((string) $numero, $chiffres, '0', \STR_PAD_LEFT));
    }

    private function verrouiller(string $portee, string $prefixe, int $annee): ?Compteur
    {
        /** @var Compteur|null */
        return $this->em->createQueryBuilder()
            ->select('c')
            ->from(Compteur::class, 'c')
            ->where('c.portee = :portee AND c.prefixe = :prefixe AND c.annee = :annee')
            ->setParameters(new \Doctrine\Common\Collections\ArrayCollection([
                new \Doctrine\ORM\Query\Parameter('portee', $portee),
                new \Doctrine\ORM\Query\Parameter('prefixe', $prefixe),
                new \Doctrine\ORM\Query\Parameter('annee', $annee),
            ]))
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
