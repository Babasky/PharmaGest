<?php

namespace App\Repository;

use App\Entity\Client;
use App\Util\Page;
use App\Util\Telephone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * Recherche par nom, téléphone (saisi sous n'importe quelle forme) ou n° d'assuré.
     *
     * @return Page<Client>
     */
    public function rechercher(?string $recherche, bool $privilegiesSeulement, bool $archives, int $page): Page
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('o')
            ->leftJoin('c.organismeAmo', 'o')
            ->andWhere('c.actif = :actif')->setParameter('actif', !$archives)
            ->orderBy('c.nom', 'ASC');

        if (null !== $recherche && '' !== trim($recherche)) {
            $q = trim($recherche);
            $qb->andWhere('c.nom LIKE :q OR c.numeroAssure LIKE :q OR c.telephone = :tel')
                ->setParameter('q', '%'.addcslashes($q, '%_').'%')
                ->setParameter('tel', Telephone::normaliser($q) ?? $q);
        }
        if ($privilegiesSeulement) {
            $qb->andWhere('c.privilegie = true');
        }

        /** @var Page<Client> */
        return Page::depuis($qb, $page);
    }
}
