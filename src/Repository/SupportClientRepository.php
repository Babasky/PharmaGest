<?php

namespace App\Repository;

use App\Entity\SupportClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @extends ServiceEntityRepository<SupportClient>
 */
class SupportClientRepository extends ServiceEntityRepository
{
    /**
     * @param array{nom: string, adresse: string, telephone: string, email: string} $editeur
     */
    public function __construct(
        ManagerRegistry $registry,
        #[Autowire('%app.editeur%')] private readonly array $editeur,
    ) {
        parent::__construct($registry, SupportClient::class);
    }

    /**
     * Réglages du support. Tant que le super admin ne les a pas enregistrés, les coordonnées de l'éditeur
     * (téléphone et email) sont proposées ; la ligne n'est créée qu'au premier enregistrement.
     */
    public function courant(): SupportClient
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? (new SupportClient())
            ->setTelephone($this->editeur['telephone'])
            ->setTelephoneActif(true)
            ->setEmail($this->editeur['email'])
            ->setEmailActif(true);
    }
}
