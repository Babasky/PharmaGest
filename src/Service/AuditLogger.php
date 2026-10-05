<?php

namespace App\Service;

use App\Entity\JournalAudit;
use App\Entity\Pharmacie;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Écrit dans le journal d'audit (AU-01). L'entrée est persistée ; le flush revient à l'appelant,
 * pour que la trace soit enregistrée dans la même transaction que l'action.
 */
class AuditLogger
{
    public const UTILISATEUR_CREE = 'utilisateur.cree';
    public const UTILISATEUR_MODIFIE = 'utilisateur.modifie';
    public const UTILISATEUR_DESACTIVE = 'utilisateur.desactive';
    public const UTILISATEUR_REACTIVE = 'utilisateur.reactive';
    public const PHARMACIE_CREEE = 'pharmacie.creee';
    public const PHARMACIE_SUSPENDUE = 'pharmacie.suspendue';
    public const PHARMACIE_REACTIVEE = 'pharmacie.reactivee';
    public const PHARMACIE_ARCHIVEE = 'pharmacie.archivee';
    public const ABONNEMENT_PAIEMENT = 'abonnement.paiement';
    public const STOCK_ENTREE = 'stock.entree';
    public const STOCK_AJUSTEMENT = 'stock.ajustement';
    public const STOCK_DESTRUCTION = 'stock.destruction';
    public const INVENTAIRE_VALIDE = 'inventaire.valide';
    public const VENTE_ANNULEE = 'vente.annulee';
    public const VENTE_REMISE_HORS_PLAFOND = 'vente.remise_hors_plafond';
    public const VENTE_SANS_ORDONNANCE = 'vente.sans_ordonnance';
    public const CAISSE_ECART = 'caisse.ecart';
    public const AMO_BORDEREAU_TRANSMIS = 'amo.bordereau_transmis';
    public const AMO_REGLEMENT = 'amo.reglement';
    public const AMO_REJET = 'amo.rejet';

    public const LIBELLES = [
        self::UTILISATEUR_CREE => 'Utilisateur créé',
        self::UTILISATEUR_MODIFIE => 'Utilisateur modifié',
        self::UTILISATEUR_DESACTIVE => 'Utilisateur désactivé',
        self::UTILISATEUR_REACTIVE => 'Utilisateur réactivé',
        self::PHARMACIE_CREEE => 'Pharmacie créée',
        self::PHARMACIE_SUSPENDUE => 'Pharmacie suspendue',
        self::PHARMACIE_REACTIVEE => 'Pharmacie réactivée',
        self::PHARMACIE_ARCHIVEE => 'Pharmacie archivée',
        self::ABONNEMENT_PAIEMENT => "Paiement d'abonnement",
        self::STOCK_ENTREE => 'Entrée de stock manuelle',
        self::STOCK_AJUSTEMENT => 'Ajustement de stock',
        self::STOCK_DESTRUCTION => 'Destruction de stock',
        self::INVENTAIRE_VALIDE => 'Inventaire validé',
        self::VENTE_ANNULEE => 'Vente annulée',
        self::VENTE_REMISE_HORS_PLAFOND => 'Remise au-delà du plafond',
        self::VENTE_SANS_ORDONNANCE => 'Vente sans ordonnance autorisée',
        self::CAISSE_ECART => 'Écart de caisse',
        self::AMO_BORDEREAU_TRANSMIS => 'Bordereau AMO transmis',
        self::AMO_REGLEMENT => 'Règlement AMO',
        self::AMO_REJET => 'Rejet AMO',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    public function journaliser(
        string $action,
        ?Pharmacie $pharmacie,
        ?object $entite = null,
        ?array $avant = null,
        ?array $apres = null,
    ): JournalAudit {
        $entree = new JournalAudit(
            action: $action,
            pharmacie: $pharmacie,
            utilisateur: $this->tenantContext->getUtilisateur(),
            entite: null !== $entite ? (new \ReflectionClass($entite))->getShortName() : null,
            entiteId: null !== $entite && method_exists($entite, 'getId') ? $entite->getId() : null,
            avant: $avant,
            apres: $apres,
            adresseIp: $this->requestStack->getMainRequest()?->getClientIp(),
        );
        $this->em->persist($entree);

        return $entree;
    }
}
