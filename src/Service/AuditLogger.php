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
