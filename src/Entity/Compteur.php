<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur de numérotation séquentielle sans trou (RG-02), par portée (plateforme ou pharmacie),
 * préfixe et année. Incrémenté sous verrou par {@see \App\Service\Numeroteur}.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_compteur', columns: ['portee', 'prefixe', 'annee'])]
class Compteur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $valeur = 0;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $portee,
        #[ORM\Column(length: 10)]
        private string $prefixe,
        #[ORM\Column]
        private int $annee,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortee(): string
    {
        return $this->portee;
    }

    public function getPrefixe(): string
    {
        return $this->prefixe;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function suivant(): int
    {
        return ++$this->valeur;
    }

    public function getValeur(): int
    {
        return $this->valeur;
    }
}
