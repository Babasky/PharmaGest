<?php

namespace App\Entity;

use App\Enum\PerimetreInventaire;
use App\Enum\StatutInventaire;
use App\Repository\InventaireRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inventaire complet ou tournant (ST-06). Les quantités théoriques sont figées à l'ouverture ;
 * à la validation, chaque écart (compté − théorique) devient un ajustement du lot.
 */
#[ORM\Entity(repositoryClass: InventaireRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_inventaire_numero', columns: ['pharmacie_id', 'numero'])]
class Inventaire implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StatutInventaire::class)]
    private StatutInventaire $statut = StatutInventaire::EnCours;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $clotureLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $cloturePar = null;

    /** @var Collection<int, LigneInventaire> */
    #[ORM\OneToMany(targetEntity: LigneInventaire::class, mappedBy: 'inventaire', cascade: ['persist'])]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $lignes;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $numero,
        #[ORM\Column(length: 20, enumType: PerimetreInventaire::class)]
        private PerimetreInventaire $perimetre,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $ouvertLe,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $ouvertPar,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        private ?Etagere $etagere = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        private ?Categorie $categorie = null,
    ) {
        $this->lignes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getPerimetre(): PerimetreInventaire
    {
        return $this->perimetre;
    }

    public function getEtagere(): ?Etagere
    {
        return $this->etagere;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    /** Ex. « Par étagère : E1-R1 ». */
    public function getLibellePerimetre(): string
    {
        return match ($this->perimetre) {
            PerimetreInventaire::Complet => 'Complet',
            PerimetreInventaire::Etagere => 'Étagère '.$this->etagere?->getCode(),
            PerimetreInventaire::Categorie => 'Catégorie '.$this->categorie?->getNomComplet(),
        };
    }

    public function getStatut(): StatutInventaire
    {
        return $this->statut;
    }

    public function estEnCours(): bool
    {
        return StatutInventaire::EnCours === $this->statut;
    }

    public function cloturer(StatutInventaire $statut, \DateTimeImmutable $le, ?Utilisateur $par): void
    {
        if (!$this->estEnCours()) {
            throw new \LogicException('Cet inventaire est déjà clôturé.');
        }
        $this->statut = $statut;
        $this->clotureLe = $le;
        $this->cloturePar = $par;
    }

    public function getOuvertLe(): \DateTimeImmutable
    {
        return $this->ouvertLe;
    }

    public function getOuvertPar(): ?Utilisateur
    {
        return $this->ouvertPar;
    }

    public function getClotureLe(): ?\DateTimeImmutable
    {
        return $this->clotureLe;
    }

    public function getCloturePar(): ?Utilisateur
    {
        return $this->cloturePar;
    }

    /**
     * @return Collection<int, LigneInventaire>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ajouterLigne(Lot $lot): LigneInventaire
    {
        $ligne = new LigneInventaire($this, $lot, $this->lignes->count() + 1);
        $this->lignes->add($ligne);

        return $ligne;
    }

    public function nombreComptees(): int
    {
        return $this->lignes->filter(static fn (LigneInventaire $l) => null !== $l->getQuantiteComptee())->count();
    }

    public function nombreEcarts(): int
    {
        return $this->lignes->filter(static fn (LigneInventaire $l) => 0 !== ($l->getEcart() ?? 0))->count();
    }

    /** Valeur des écarts au prix d'achat des lots (négative = manquants). */
    public function valeurEcarts(): int
    {
        $total = 0;
        foreach ($this->lignes as $ligne) {
            $total += ($ligne->getEcart() ?? 0) * $ligne->getLot()->getPrixAchat();
        }

        return $total;
    }
}
