<?php

namespace App\Entity;

use App\Enum\ModePaiement;
use App\Enum\StatutVente;
use App\Enum\TypeRemise;
use App\Enum\TypeVente;
use App\Repository\VenteRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use App\Util\Fcfa;
use App\Vente\TotauxVente;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vente au comptoir (VE-01 à VE-09). Tant qu'elle n'est pas validée, c'est le panier d'un vendeur :
 * ses montants se calculent à la volée. À la validation, elle reçoit son numéro (RG-02) et ses montants,
 * prix, taux AMO et remises sont figés (RG-06).
 */
#[ORM\Entity(repositoryClass: VenteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_vente_numero', columns: ['pharmacie_id', 'numero'])]
#[ORM\Index(name: 'idx_vente_statut', columns: ['pharmacie_id', 'statut', 'validee_le'])]
class Vente implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Attribué à la validation seulement : un panier abandonné ne laisse pas de trou (RG-02). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(length: 20, enumType: StatutVente::class)]
    private StatutVente $statut = StatutVente::EnCours;

    #[ORM\Column(length: 20, enumType: TypeVente::class)]
    private TypeVente $type = TypeVente::SansOrdonnance;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Client $client = null;

    #[ORM\OneToOne(cascade: ['persist'], orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Ordonnance $ordonnance = null;

    /** Session de caisse où la vente a été encaissée. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?SessionCaisse $session = null;

    /** Repère libre pour retrouver une vente mise en attente (ex. « dame au pagne bleu »). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $repere = null;

    #[ORM\Column(length: 20, nullable: true, enumType: TypeRemise::class)]
    private ?TypeRemise $remiseType = null;

    #[ORM\Column]
    private int $remiseValeur = 0;

    // Vente AMO : organisme, n° d'assuré et taux figés (AM-02, RG-06).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?OrganismeAmo $organismeAmo = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $numeroAssure = null;

    #[ORM\Column(nullable: true)]
    private ?int $tauxAmo = null;

    // Montants figés à la validation.
    #[ORM\Column]
    private int $totalBrut = 0;

    #[ORM\Column]
    private int $remise = 0;

    #[ORM\Column]
    private int $totalNet = 0;

    #[ORM\Column]
    private int $partAmo = 0;

    /** Encaissé auprès du client (part assuré après remise pour l'AMO). */
    #[ORM\Column]
    private int $montantEncaisse = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valideeLe = null;

    /** Propriétaire qui a autorisé, par son code PIN, une remise hors plafond ou une vente sans ordonnance. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $autorisePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $annuleePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** @var Collection<int, LigneVente> */
    #[ORM\OneToMany(targetEntity: LigneVente::class, mappedBy: 'vente', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    /** @var Collection<int, Paiement> */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'vente', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $paiements;

    public function __construct(
        /** Vendeur du panier, puis vendeur qui a encaissé. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Utilisateur $vendeur,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
    ) {
        $this->lignes = new ArrayCollection();
        $this->paiements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function getStatut(): StatutVente
    {
        return $this->statut;
    }

    public function estModifiable(): bool
    {
        return \in_array($this->statut, [StatutVente::EnCours, StatutVente::EnAttente], true);
    }

    public function estValidee(): bool
    {
        return StatutVente::Validee === $this->statut;
    }

    public function getType(): TypeVente
    {
        return $this->type;
    }

    public function setType(TypeVente $type): static
    {
        $this->type = $type;
        if (!$type->avecOrdonnance()) {
            $this->ordonnance = null;
        }
        if (TypeVente::Amo !== $type) {
            $this->definirAmo(null, null, null);
        } else {
            // RG-09 : sur une vente AMO, seule une remise globale sur la part assuré est possible.
            foreach ($this->lignes as $ligne) {
                $ligne->definirRemise(null, 0);
            }
        }

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;
        if (!$this->remiseAutorisee()) {
            $this->supprimerRemises();
        }

        return $this;
    }

    /** RE-01 / RG-08 : remise possible seulement pour un client privilégié. */
    public function remiseAutorisee(): bool
    {
        return null !== $this->client && $this->client->isPrivilegie();
    }

    public function supprimerRemises(): void
    {
        $this->definirRemise(null, 0);
        foreach ($this->lignes as $ligne) {
            $ligne->definirRemise(null, 0);
        }
    }

    public function getOrdonnance(): ?Ordonnance
    {
        return $this->ordonnance;
    }

    public function setOrdonnance(?Ordonnance $ordonnance): static
    {
        $this->ordonnance = $ordonnance;

        return $this;
    }

    public function getSession(): ?SessionCaisse
    {
        return $this->session;
    }

    public function getVendeur(): Utilisateur
    {
        return $this->vendeur;
    }

    public function setVendeur(Utilisateur $vendeur): static
    {
        $this->vendeur = $vendeur;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getRepere(): ?string
    {
        return $this->repere;
    }

    public function mettreEnAttente(?string $repere): void
    {
        $this->statut = StatutVente::EnAttente;
        $this->repere = null === $repere || '' === trim($repere) ? null : mb_substr(trim($repere), 0, 60);
    }

    public function reprendre(Utilisateur $vendeur): void
    {
        $this->statut = StatutVente::EnCours;
        $this->vendeur = $vendeur;
    }

    public function getRemiseType(): ?TypeRemise
    {
        return $this->remiseType;
    }

    public function getRemiseValeur(): int
    {
        return $this->remiseValeur;
    }

    public function definirRemise(?TypeRemise $type, int $valeur): static
    {
        $this->remiseType = $valeur > 0 ? $type : null;
        $this->remiseValeur = null === $this->remiseType ? 0 : $valeur;

        return $this;
    }

    public function getOrganismeAmo(): ?OrganismeAmo
    {
        return $this->organismeAmo;
    }

    public function getNumeroAssure(): ?string
    {
        return $this->numeroAssure;
    }

    public function getTauxAmo(): ?int
    {
        return $this->tauxAmo;
    }

    public function definirAmo(?OrganismeAmo $organisme, ?string $numeroAssure, ?int $taux): void
    {
        $this->organismeAmo = $organisme;
        $this->numeroAssure = $numeroAssure;
        $this->tauxAmo = $taux;
    }

    /**
     * @return Collection<int, LigneVente>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ligneDe(Produit $produit): ?LigneVente
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->getProduit() === $produit) {
                return $ligne;
            }
        }

        return null;
    }

    public function ajouterLigne(Produit $produit, int $quantite): LigneVente
    {
        $ligne = new LigneVente($this, $produit, $quantite, (int) $produit->getPrixVente(), $produit->isRemboursableAmo());
        $this->lignes->add($ligne);

        return $ligne;
    }

    public function retirerLigne(LigneVente $ligne): void
    {
        $this->lignes->removeElement($ligne);
    }

    public function nombreArticles(): int
    {
        return array_sum($this->lignes->map(static fn (LigneVente $l) => $l->getQuantite())->toArray());
    }

    /**
     * Montants calculés à partir des lignes, des remises et du taux AMO (RG-07 à RG-09).
     */
    public function calculer(): TotauxVente
    {
        $amo = TypeVente::Amo === $this->type;
        $totalBrut = 0;
        $baseAmo = 0;
        $remiseLignes = 0;
        $tauxMax = 0.0;

        foreach ($this->lignes as $ligne) {
            $brut = $ligne->getMontantBrut();
            $totalBrut += $brut;
            if ($amo && $ligne->isRemboursable()) {
                $baseAmo += $brut;
            }
            if (!$amo) {
                $remise = $ligne->calculerRemise();
                $remiseLignes += $remise;
                $tauxMax = max($tauxMax, self::taux($remise, $brut));
            }
        }

        $partAmo = $amo ? Fcfa::arrondir($baseAmo * ($this->tauxAmo ?? 0) / 100) : 0;
        $baseRemise = $totalBrut - $partAmo;
        $baseRemiseGlobale = $baseRemise - $remiseLignes;
        $remiseGlobale = $this->remiseType?->montantSur($baseRemiseGlobale, $this->remiseValeur) ?? 0;
        $tauxMax = max($tauxMax, self::taux($remiseGlobale, $baseRemiseGlobale), self::taux($remiseLignes + $remiseGlobale, $baseRemise));

        return new TotauxVente($totalBrut, $remiseLignes, $remiseGlobale, $baseAmo, $partAmo, $baseRemise, $tauxMax);
    }

    /**
     * Valide la vente : numéro, session, montants et remises figés (RG-02, RG-06).
     *
     * @internal réservé à {@see \App\Vente\VenteService}
     */
    public function valider(string $numero, SessionCaisse $session, \DateTimeImmutable $le, ?Utilisateur $autorisePar): void
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Cette vente est déjà validée.');
        }
        $totaux = $this->calculer();
        foreach ($this->lignes as $ligne) {
            $ligne->figerRemise(TypeVente::Amo === $this->type ? 0 : $ligne->calculerRemise());
        }
        $this->numero = $numero;
        $this->session = $session;
        $this->valideeLe = $le;
        $this->autorisePar = $autorisePar;
        $this->statut = StatutVente::Validee;
        $this->repere = null;
        $this->totalBrut = $totaux->totalBrut;
        $this->remise = $totaux->remiseTotale();
        $this->totalNet = $totaux->totalNet();
        $this->partAmo = $totaux->partAmo;
        $this->montantEncaisse = $totaux->aEncaisser();
    }

    public function ajouterPaiement(ModePaiement $mode, int $montant, ?string $reference = null, ?int $montantRemis = null): Paiement
    {
        $paiement = new Paiement($this, $mode, $montant, $reference, $montantRemis);
        $this->paiements->add($paiement);

        return $paiement;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function montantPaye(ModePaiement $mode): int
    {
        $total = 0;
        foreach ($this->paiements as $paiement) {
            if ($paiement->getMode() === $mode) {
                $total += $paiement->getMontant();
            }
        }

        return $total;
    }

    public function getMonnaieRendue(): int
    {
        return array_sum($this->paiements->map(static fn (Paiement $p) => $p->getMonnaieRendue())->toArray());
    }

    public function getTotalBrut(): int
    {
        return $this->totalBrut;
    }

    public function getRemise(): int
    {
        return $this->remise;
    }

    public function getTotalNet(): int
    {
        return $this->totalNet;
    }

    public function getPartAmo(): int
    {
        return $this->partAmo;
    }

    public function getMontantEncaisse(): int
    {
        return $this->montantEncaisse;
    }

    public function getValideeLe(): ?\DateTimeImmutable
    {
        return $this->valideeLe;
    }

    public function getAutorisePar(): ?Utilisateur
    {
        return $this->autorisePar;
    }

    /**
     * @internal réservé à {@see \App\Vente\VenteService}
     */
    public function annuler(\DateTimeImmutable $le, ?Utilisateur $par, string $motif): void
    {
        if (!$this->estValidee()) {
            throw new \LogicException('Seule une vente validée peut être annulée.');
        }
        $this->statut = StatutVente::Annulee;
        $this->annuleeLe = $le;
        $this->annuleePar = $par;
        $this->motifAnnulation = $motif;
    }

    public function getAnnuleeLe(): ?\DateTimeImmutable
    {
        return $this->annuleeLe;
    }

    public function getAnnuleePar(): ?Utilisateur
    {
        return $this->annuleePar;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    /** Marge brute de la vente : total net − coût d'achat des lots consommés (RG-17). */
    public function getMarge(): int
    {
        $cout = 0;
        foreach ($this->lignes as $ligne) {
            $cout += $ligne->getCoutAchat();
        }

        return $this->totalNet - $cout;
    }

    private static function taux(int $remise, int $base): float
    {
        return $base > 0 ? $remise * 100 / $base : 0.0;
    }
}
