<?php

namespace App\Doctrine;

use App\Entity\JournalAudit;
use App\Entity\ParametrePharmacie;
use App\Entity\Produit;
use App\Entity\TauxAmo;
use App\Service\AuditLogger;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Journalise les modifications sensibles faites par simple formulaire ou import (AU-01), quel que soit l'écran :
 * prix d'un produit, règles de gestion de la pharmacie, nouveau taux AMO.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AuditModificationsListener
{
    private const CHAMPS_PRIX = ['prixVente', 'prixAchat'];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if ($entite instanceof Produit) {
                $changements = array_intersect_key($uow->getEntityChangeSet($entite), array_flip(self::CHAMPS_PRIX));
                if ([] !== $changements) {
                    [$avant, $apres] = self::avantApres($changements);
                    $this->tracer($em, AuditLogger::PRODUIT_PRIX_MODIFIE, $entite, ['produit' => $entite->getNomCommercial()] + $avant, $apres);
                }
            } elseif ($entite instanceof ParametrePharmacie) {
                $changements = $uow->getEntityChangeSet($entite);
                if ([] !== $changements) {
                    [$avant, $apres] = self::avantApres($changements);
                    $this->tracer($em, AuditLogger::PARAMETRES_MODIFIES, $entite, $avant, $apres);
                }
            }
        }

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof TauxAmo) {
                $this->tracer($em, AuditLogger::TAUX_AMO_AJOUTE, $entite, null, [
                    'organisme' => $entite->getOrganisme()?->getNom(),
                    'taux' => $entite->getTaux(),
                    'dateEffet' => $entite->getDateEffet()?->format('d/m/Y'),
                ]);
            }
        }
    }

    /**
     * @param array<string, array{mixed, mixed}> $changements
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private static function avantApres(array $changements): array
    {
        $avant = $apres = [];
        foreach ($changements as $champ => [$ancien, $nouveau]) {
            $avant[$champ] = $ancien instanceof \BackedEnum ? $ancien->value : $ancien;
            $apres[$champ] = $nouveau instanceof \BackedEnum ? $nouveau->value : $nouveau;
        }

        return [$avant, $apres];
    }

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>      $apres
     */
    private function tracer(EntityManagerInterface $em, string $action, Produit|ParametrePharmacie|TauxAmo $entite, ?array $avant, array $apres): void
    {
        $entree = $this->audit->journaliser($action, $entite->getPharmacie(), $entite, $avant, $apres);
        $em->getUnitOfWork()->computeChangeSet($em->getClassMetadata(JournalAudit::class), $entree);
    }
}
