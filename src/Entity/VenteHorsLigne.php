<?php

namespace App\Entity;

use App\Enum\StatutVenteHorsLigne;
use App\Repository\VenteHorsLigneRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vente saisie sur un poste pendant une coupure d'internet, telle que le poste l'a envoyée au retour du réseau.
 *
 * Elle est gardée telle quelle dès sa réception (le poste peut alors l'effacer), puis importée : elle devient
 * une vente numérotée, encaissée dans la session du vendeur, avec sortie FEFO du stock. Si l'import échoue
 * (stock insuffisant, caisse fermée…), elle reste « en anomalie » jusqu'à un nouvel essai ou un rejet motivé.
 */
#[ORM\Entity(repositoryClass: VenteHorsLigneRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_vente_hors_ligne_uuid', columns: ['pharmacie_id', 'uuid'])]
#[ORM\Index(name: 'idx_vente_hors_ligne_statut', columns: ['pharmacie_id', 'statut'])]
class VenteHorsLigne implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StatutVenteHorsLigne::class)]
    private StatutVenteHorsLigne $statut = StatutVenteHorsLigne::Anomalie;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $message = null;

    /** Écart entre le ticket remis hors ligne et le montant recalculé en ligne (prix ou taux modifié entre-temps). */
    #[ORM\Column]
    private int $ecart = 0;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vente $vente = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $traiteeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $rejeteePar = null;

    /**
     * @param array<string, mixed> $donnees vente telle que saisie sur le poste (lignes, type, paiements…)
     */
    public function __construct(
        #[ORM\Column(length: 36)]
        private string $uuid,
        /** Numéro provisoire imprimé sur le ticket hors ligne (HL-…). */
        #[ORM\Column(length: 30)]
        private string $numeroProvisoire,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Utilisateur $vendeur,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $venduLe,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $recueLe,
        #[ORM\Column]
        private int $montant,
        #[ORM\Column(type: Types::JSON)]
        private array $donnees,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getNumeroProvisoire(): string
    {
        return $this->numeroProvisoire;
    }

    public function getVendeur(): Utilisateur
    {
        return $this->vendeur;
    }

    public function getVenduLe(): \DateTimeImmutable
    {
        return $this->venduLe;
    }

    public function getRecueLe(): \DateTimeImmutable
    {
        return $this->recueLe;
    }

    /** Montant payé par le client d'après le ticket hors ligne. */
    public function getMontant(): int
    {
        return $this->montant;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDonnees(): array
    {
        return $this->donnees;
    }

    public function getStatut(): StatutVenteHorsLigne
    {
        return $this->statut;
    }

    public function estEnAnomalie(): bool
    {
        return StatutVenteHorsLigne::Anomalie === $this->statut;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getEcart(): int
    {
        return $this->ecart;
    }

    public function getVente(): ?Vente
    {
        return $this->vente;
    }

    public function getTraiteeLe(): ?\DateTimeImmutable
    {
        return $this->traiteeLe;
    }

    public function getRejeteePar(): ?Utilisateur
    {
        return $this->rejeteePar;
    }

    public function importer(Vente $vente, int $ecart, \DateTimeImmutable $le): void
    {
        $this->statut = StatutVenteHorsLigne::Importee;
        $this->vente = $vente;
        $this->ecart = $ecart;
        $this->message = 0 === $ecart ? null : 'Montant recalculé en ligne différent du ticket : prix ou taux modifié entre-temps.';
        $this->traiteeLe = $le;
    }

    public function signalerAnomalie(string $message, \DateTimeImmutable $le): void
    {
        $this->statut = StatutVenteHorsLigne::Anomalie;
        $this->message = mb_substr($message, 0, 255);
        $this->traiteeLe = $le;
    }

    public function rejeter(Utilisateur $par, string $motif, \DateTimeImmutable $le): void
    {
        if (!$this->estEnAnomalie()) {
            throw new \LogicException('Seule une vente hors ligne en anomalie peut être rejetée.');
        }
        $this->statut = StatutVenteHorsLigne::Rejetee;
        $this->rejeteePar = $par;
        $this->message = mb_substr($motif, 0, 255);
        $this->traiteeLe = $le;
    }
}
