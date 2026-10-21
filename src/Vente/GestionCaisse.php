<?php

namespace App\Vente;

use App\Entity\Caisse;
use App\Entity\Pharmacie;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Repository\CaisseRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\VenteRepository;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Stock\StockService;
use App\Tenant\TenantContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Caisses et sessions de caisse (FI-06). La pharmacie a une caisse par défaut et peut en ajouter ; chaque caisse
 * s'ouvre avec un fond de caisse et toute l'équipe y encaisse jusqu'à la clôture, où l'on compte les espèces
 * par coupure. Un écart non nul exige une justification et est tracé (RG-13, AU-01).
 */
class GestionCaisse
{
    public const FOND_MAXIMUM = 10_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SessionCaisseRepository $sessions,
        private readonly CaisseRepository $caisses,
        private readonly VenteRepository $ventes,
        private readonly StockService $stock,
        private readonly Numeroteur $numeroteur,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Caisses en service de la pharmacie courante, dans l'ordre de création. La « Caisse principale » est créée
     * d'office : une pharmacie a toujours au moins une caisse.
     *
     * @return non-empty-list<Caisse>
     */
    public function caisses(Pharmacie $pharmacie): array
    {
        $actives = $this->caisses->actives();
        if ([] !== $actives) {
            return $actives;
        }
        $caisse = $this->caisses->toutes()[0] ?? (new Caisse(Caisse::PRINCIPALE))->setPharmacie($pharmacie);
        $caisse->setActif(true);
        $this->em->persist($caisse);
        $this->em->flush();

        return [$caisse];
    }

    /**
     * Ajoute une caisse à la pharmacie.
     *
     * @throws VenteException
     */
    public function ajouter(Pharmacie $pharmacie, string $libelle): Caisse
    {
        $this->caisses($pharmacie);
        $libelle = mb_substr(trim($libelle), 0, 60);
        if ('' === $libelle) {
            throw new VenteException('Donnez un nom à la caisse (ex. Caisse 2).');
        }
        if (null !== $this->caisses->findOneBy(['libelle' => $libelle])) {
            throw new VenteException(\sprintf('Une caisse s\'appelle déjà « %s ».', $libelle));
        }
        $caisse = (new Caisse($libelle))->setPharmacie($pharmacie);
        $this->em->persist($caisse);
        $this->em->flush();

        return $caisse;
    }

    /**
     * Retire une caisse du service (ou l'y remet). La dernière caisse en service et une caisse ouverte restent.
     *
     * @throws VenteException
     */
    public function basculer(Pharmacie $pharmacie, Caisse $caisse): void
    {
        if ($caisse->isActif()) {
            if (null !== $this->sessions->ouverteDeLaCaisse($caisse)) {
                throw new VenteException(\sprintf('%s est ouverte : clôturez-la avant de la retirer.', $caisse->getLibelle()));
            }
            if (\count($this->caisses($pharmacie)) < 2) {
                throw new VenteException('La pharmacie doit garder au moins une caisse.');
            }
        }
        $caisse->setActif(!$caisse->isActif());
        $this->em->flush();
    }

    public function sessionOuverte(Caisse $caisse): ?SessionCaisse
    {
        return $this->sessions->ouverteDeLaCaisse($caisse);
    }

    /**
     * Ouvre une caisse (la première caisse de la pharmacie si aucune n'est précisée).
     *
     * @throws VenteException
     */
    public function ouvrir(Pharmacie $pharmacie, Utilisateur $utilisateur, int $fondCaisse, ?Caisse $caisse = null): SessionCaisse
    {
        $caisse ??= $this->caisses($pharmacie)[0];
        if (!$caisse->isActif()) {
            throw new VenteException(\sprintf('%s n\'est plus en service.', $caisse->getLibelle()));
        }
        $ouverte = $this->sessions->ouverteDeLaCaisse($caisse);
        if (null !== $ouverte) {
            throw new VenteException(\sprintf('%s est déjà ouverte (session %s, par %s).', $caisse->getLibelle(), $ouverte->getNumero(), $ouverte->getUtilisateur()->getNom()));
        }
        if ($fondCaisse < 0 || $fondCaisse > self::FOND_MAXIMUM) {
            throw new VenteException('Le fond de caisse doit être un montant positif ou nul.');
        }

        return $this->stock->transaction(function () use ($pharmacie, $utilisateur, $fondCaisse, $caisse): SessionCaisse {
            // Deux ouvertures simultanées de la même caisse : la seconde attend la première, puis est refusée.
            $this->em->lock($caisse, LockMode::PESSIMISTIC_WRITE);
            if (null !== $this->sessions->ouverteDeLaCaisse($caisse)) {
                throw new VenteException(\sprintf('%s vient d\'être ouverte.', $caisse->getLibelle()));
            }
            $session = new SessionCaisse($this->numeroteur->suivant('SC', $pharmacie), $utilisateur, $this->horloge->now(), $fondCaisse);
            $session->setPharmacie($pharmacie);
            $session->setCaisse($caisse);
            $this->em->persist($session);
            $this->em->flush();

            return $session;
        });
    }

    public function synthese(SessionCaisse $session): SyntheseSession
    {
        return new SyntheseSession($session, $this->ventes->deLaSession($session));
    }

    /**
     * @param array<array-key, mixed> $saisie nombre de billets et pièces par clé de {@see SessionCaisse::COUPURES}
     *
     * @throws VenteException
     */
    public function cloturer(SessionCaisse $session, array $saisie, ?string $justification): SyntheseSession
    {
        if (!$session->estOuverte()) {
            throw new VenteException(\sprintf('La session %s est déjà clôturée.', $session->getNumero()));
        }

        $comptage = [];
        foreach (SessionCaisse::COUPURES as $cle => [$libelle]) {
            $valeur = trim(\is_scalar($saisie[$cle] ?? null) ? (string) $saisie[$cle] : '');
            if ('' === $valeur || '0' === $valeur) {
                continue;
            }
            if (!ctype_digit($valeur) || \strlen($valeur) > 6) {
                throw new VenteException(\sprintf('%s : saisissez un nombre entier positif.', $libelle));
            }
            $comptage[$cle] = (int) $valeur;
        }

        $justification = null === $justification || '' === trim($justification) ? null : mb_substr(trim($justification), 0, 1000);
        $synthese = $this->synthese($session);
        $ecart = SessionCaisse::totalComptage($comptage) - $synthese->especesAttendues();
        if (0 !== $ecart && null === $justification) {
            throw new VenteException(\sprintf('Écart de %s : la justification est obligatoire.', \App\Util\Fcfa::format($ecart)));
        }

        $this->stock->transaction(function () use ($session, $comptage, $synthese, $justification): void {
            $session->cloturer($this->horloge->now(), $this->tenantContext->getUtilisateur(), $comptage, $synthese->especesAttendues(), $justification);
            $this->em->flush();
            if (0 !== $session->getEcart()) {
                $this->audit->journaliser(AuditLogger::CAISSE_ECART, $session->getPharmacie(), $session, null, [
                    'session' => $session->getNumero(),
                    'caisse' => $session->getCaisse()?->getLibelle(),
                    'caissier' => $session->getUtilisateur()->getNom(),
                    'attendu' => $session->getEspecesAttendues(),
                    'compte' => $session->getEspecesComptees(),
                    'ecart' => $session->getEcart(),
                    'justification' => $justification,
                ]);
                $this->em->flush();
            }
        });

        return $synthese;
    }
}
