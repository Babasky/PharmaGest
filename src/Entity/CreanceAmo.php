<?php

namespace App\Entity;

use App\Enum\StatutCreance;
use App\Repository\CreanceAmoRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Part AMO d'une vente, due par l'organisme à la pharmacie (AM-05). Elle naît « en attente » à l'encaissement
 * de la vente, rejoint un seul bordereau à la fois (RG-11), puis est réglée créance par créance (AM-08).
 * Le montant réglé et le motif de rejet suffisent à en déduire le statut.
 */
#[ORM\Entity(repositoryClass: CreanceAmoRepository::class)]
#[ORM\Index(name: 'idx_creance_amo_statut', columns: ['pharmacie_id', 'statut', 'organisme_id'])]
class CreanceAmo implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'RESTRICT')]
    private Vente $vente;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private OrganismeAmo $organisme;

    /** Part AMO figée de la vente (RG-06). */
    #[ORM\Column]
    private int $montant;

    /** Date de la vente : sert à la période des bordereaux et à l'ancienneté (AM-10). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateVente;

    #[ORM\Column(length: 25, enumType: StatutCreance::class)]
    private StatutCreance $statut = StatutCreance::EnAttente;

    #[ORM\ManyToOne(inversedBy: 'creances')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BordereauAmo $bordereau = null;

    #[ORM\Column]
    private int $montantRegle = 0;

    /** Motif du rejet : le reste non réglé est alors définitivement rejeté. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifRejet = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rejeteeLe = null;

    public function __construct(Vente $vente)
    {
        $organisme = $vente->getOrganismeAmo();
        $valideeLe = $vente->getValideeLe();
        if (null === $organisme || $vente->getPartAmo() <= 0 || null === $valideeLe) {
            throw new \LogicException('Seule une vente AMO validée avec une part AMO crée une créance.');
        }
        $this->vente = $vente;
        $this->organisme = $organisme;
        $this->montant = $vente->getPartAmo();
        $this->dateVente = $valideeLe;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVente(): Vente
    {
        return $this->vente;
    }

    public function getOrganisme(): OrganismeAmo
    {
        return $this->organisme;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getDateVente(): \DateTimeImmutable
    {
        return $this->dateVente;
    }

    public function getStatut(): StatutCreance
    {
        return $this->statut;
    }

    public function getBordereau(): ?BordereauAmo
    {
        return $this->bordereau;
    }

    public function getMontantRegle(): int
    {
        return $this->montantRegle;
    }

    /** Montant encore attendu de l'organisme (0 pour une créance soldée). */
    public function getReste(): int
    {
        return $this->statut->estSoldee() ? 0 : $this->montant - $this->montantRegle;
    }

    /** Montant perdu par le rejet (reste non réglé au moment du rejet). */
    public function getMontantRejete(): int
    {
        return StatutCreance::Rejetee === $this->statut ? $this->montant - $this->montantRegle : 0;
    }

    public function getMotifRejet(): ?string
    {
        return $this->motifRejet;
    }

    public function getRejeteeLe(): ?\DateTimeImmutable
    {
        return $this->rejeteeLe;
    }

    /** Une créance en attente qui n'est sur aucun bordereau peut y être ajoutée. */
    public function estDisponible(): bool
    {
        return StatutCreance::EnAttente === $this->statut && null === $this->bordereau;
    }

    /**
     * @internal réservé à {@see \App\Amo\GestionBordereaux}
     */
    public function rattacher(?BordereauAmo $bordereau): void
    {
        $this->bordereau?->retirerCreance($this);
        $this->bordereau = $bordereau;
        $bordereau?->ajouterCreance($this);
        $this->actualiserStatut();
    }

    /**
     * @internal réservé à {@see \App\Amo\GestionBordereaux}
     */
    public function regler(int $montant): void
    {
        if ($montant <= 0 || $montant > $this->getReste()) {
            throw new \LogicException('Montant réglé invalide pour cette créance.');
        }
        $this->montantRegle += $montant;
        $this->actualiserStatut();
    }

    /**
     * @internal réservé à {@see \App\Amo\GestionBordereaux}
     */
    public function rejeter(string $motif, \DateTimeImmutable $le): void
    {
        if ($this->statut->estSoldee()) {
            throw new \LogicException('Cette créance est déjà soldée.');
        }
        $this->motifRejet = mb_substr($motif, 0, 255);
        $this->rejeteeLe = $le;
        $this->actualiserStatut();
    }

    /**
     * La vente est annulée : la créance disparaît de l'encours et de tout bordereau brouillon.
     *
     * @internal réservé à {@see \App\Vente\VenteService}
     */
    public function annuler(): void
    {
        $this->bordereau?->retirerCreance($this);
        $this->bordereau = null;
        $this->statut = StatutCreance::Annulee;
    }

    private function actualiserStatut(): void
    {
        if (StatutCreance::Annulee === $this->statut) {
            return;
        }
        $this->statut = match (true) {
            $this->montantRegle >= $this->montant => StatutCreance::Payee,
            null !== $this->motifRejet => StatutCreance::Rejetee,
            $this->montantRegle > 0 => StatutCreance::PayeePartiellement,
            null !== $this->bordereau && $this->bordereau->estTransmis() => StatutCreance::Transmise,
            default => StatutCreance::EnAttente,
        };
    }

    /** @internal réservé à {@see BordereauAmo::transmettre()} */
    public function marquerTransmise(): void
    {
        $this->actualiserStatut();
    }
}
