<?php

namespace App\Entity;

use App\Repository\SessionCaisseRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Session de caisse d'un utilisateur (FI-06) : ouverte avec un fond de caisse, clôturée avec le comptage
 * des espèces par coupure. L'écart (RG-13) et les montants attendus sont figés à la clôture (rapport Z, FI-07).
 */
#[ORM\Entity(repositoryClass: SessionCaisseRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_session_caisse_numero', columns: ['pharmacie_id', 'numero'])]
#[ORM\Index(name: 'idx_session_caisse_ouverture', columns: ['pharmacie_id', 'ouverte_le'])]
class SessionCaisse implements TenantAwareInterface
{
    use TenantAwareTrait;

    /**
     * Coupures du franc CFA (BCEAO) proposées au comptage : clé => [libellé, valeur].
     */
    public const COUPURES = [
        'b10000' => ['Billet de 10 000', 10000],
        'b5000' => ['Billet de 5 000', 5000],
        'b2000' => ['Billet de 2 000', 2000],
        'b1000' => ['Billet de 1 000', 1000],
        'b500' => ['Billet de 500', 500],
        'p500' => ['Pièce de 500', 500],
        'p250' => ['Pièce de 250', 250],
        'p200' => ['Pièce de 200', 200],
        'p100' => ['Pièce de 100', 100],
        'p50' => ['Pièce de 50', 50],
        'p25' => ['Pièce de 25', 25],
        'p10' => ['Pièce de 10', 10],
        'p5' => ['Pièce de 5', 5],
        'p1' => ['Pièce de 1', 1],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $clotureeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $clotureePar = null;

    /** Espèces comptées à la clôture. */
    #[ORM\Column(nullable: true)]
    private ?int $especesComptees = null;

    /**
     * Nombre de billets et pièces comptés, par clé de {@see self::COUPURES}.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $comptage = null;

    /** Fond + encaissements espèces − décaissements espèces, figé à la clôture (RG-13). */
    #[ORM\Column(nullable: true)]
    private ?int $especesAttendues = null;

    #[ORM\Column(nullable: true)]
    private ?int $ecart = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $justification = null;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $numero,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Utilisateur $utilisateur,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $ouverteLe,
        #[ORM\Column]
        private int $fondCaisse,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getOuverteLe(): \DateTimeImmutable
    {
        return $this->ouverteLe;
    }

    public function getFondCaisse(): int
    {
        return $this->fondCaisse;
    }

    public function estOuverte(): bool
    {
        return null === $this->clotureeLe;
    }

    /**
     * @param array<string, int> $comptage
     */
    public function cloturer(\DateTimeImmutable $le, ?Utilisateur $par, array $comptage, int $especesAttendues, ?string $justification): void
    {
        if (!$this->estOuverte()) {
            throw new \LogicException('Cette session de caisse est déjà clôturée.');
        }
        $this->clotureeLe = $le;
        $this->clotureePar = $par;
        $this->comptage = $comptage;
        $this->especesComptees = self::totalComptage($comptage);
        $this->especesAttendues = $especesAttendues;
        $this->ecart = $this->especesComptees - $especesAttendues;
        $this->justification = $justification;
    }

    /**
     * @param array<string, int> $comptage
     */
    public static function totalComptage(array $comptage): int
    {
        $total = 0;
        foreach ($comptage as $cle => $nombre) {
            $total += (self::COUPURES[$cle][1] ?? 0) * $nombre;
        }

        return $total;
    }

    public function getClotureeLe(): ?\DateTimeImmutable
    {
        return $this->clotureeLe;
    }

    public function getClotureePar(): ?Utilisateur
    {
        return $this->clotureePar;
    }

    public function getEspecesComptees(): ?int
    {
        return $this->especesComptees;
    }

    /**
     * @return array<string, int>
     */
    public function getComptage(): array
    {
        return $this->comptage ?? [];
    }

    public function getEspecesAttendues(): ?int
    {
        return $this->especesAttendues;
    }

    public function getEcart(): ?int
    {
        return $this->ecart;
    }

    public function getJustification(): ?string
    {
        return $this->justification;
    }
}
