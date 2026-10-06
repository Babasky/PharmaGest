<?php

namespace App\Service;

use App\Entity\Pharmacie;
use App\Mailer\PlateformeMailer;
use App\Repository\AffectationRepository;
use App\Repository\JournalAuditRepository;
use App\Stockage\StockageFichiers;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archivage automatique (§ 3.2 étape 8) : 12 mois après l'échéance sans renouvellement, la pharmacie est archivée
 * et son export complet est envoyé au propriétaire. Le propriétaire est prévenu 30 jours avant.
 *
 * Une pharmacie suspendue n'est pas concernée : sa situation relève d'une décision manuelle du super admin.
 */
class ArchivageAutomatique
{
    public const MOIS_AVANT_ARCHIVAGE = 12;
    public const JOURS_ANNONCE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbonnementService $abonnements,
        private readonly AffectationRepository $affectations,
        private readonly JournalAuditRepository $journal,
        private readonly ExportDonnees $export,
        private readonly StockageFichiers $stockage,
        private readonly PlateformeMailer $mailer,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function dateArchivage(Pharmacie $pharmacie): ?\DateTimeImmutable
    {
        $echeance = $pharmacie->getFinAbonnement() ?? $pharmacie->getFinEssai();

        return null === $echeance ? null : AbonnementService::ajouterMois($echeance, self::MOIS_AVANT_ARCHIVAGE);
    }

    /**
     * @return 'archivee'|'annoncee'|null ce qui a été fait pour cette pharmacie
     */
    public function traiter(Pharmacie $pharmacie): ?string
    {
        $date = $this->dateArchivage($pharmacie);
        $echeance = $pharmacie->getFinAbonnement() ?? $pharmacie->getFinEssai();
        if (null === $date || null === $echeance || $pharmacie->isArchivee() || $pharmacie->isSuspendue()) {
            return null;
        }
        $aujourdhui = $this->abonnements->aujourdhui();

        if ($aujourdhui >= $date) {
            $this->archiver($pharmacie);

            return 'archivee';
        }

        $annonce = $date->modify(\sprintf('-%d days', self::JOURS_ANNONCE));
        if ($aujourdhui >= $annonce && !$this->tenantContext->sansFiltre(fn () => $this->journal->existe(AuditLogger::PHARMACIE_ARCHIVAGE_ANNONCE, $pharmacie, $echeance))) {
            foreach ($this->affectations->proprietaires($pharmacie) as $proprietaire) {
                $this->mailer->annonceArchivage($proprietaire, $pharmacie, $echeance, $date);
            }
            $this->audit->journaliser(AuditLogger::PHARMACIE_ARCHIVAGE_ANNONCE, $pharmacie, $pharmacie, null, ['archivage' => $date->format('d/m/Y')]);
            $this->em->flush();

            return 'annoncee';
        }

        return null;
    }

    private function archiver(Pharmacie $pharmacie): void
    {
        // L'export est conservé dans les fichiers de la pharmacie : la pièce jointe est lue au moment de l'envoi.
        $nomArchive = $this->export->nomArchive($pharmacie);
        $fichier = $this->stockage->deplacer($pharmacie, 'archives', $this->export->creerArchive($pharmacie), 'zip');
        $chemin = (string) $this->stockage->chemin($pharmacie, 'archives', $fichier);
        foreach ($this->affectations->proprietaires($pharmacie) as $proprietaire) {
            $this->mailer->exportArchivage($proprietaire, $pharmacie, $chemin, $nomArchive);
        }
        $this->abonnements->archiver($pharmacie);
    }
}
