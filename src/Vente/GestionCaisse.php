<?php

namespace App\Vente;

use App\Entity\Pharmacie;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Repository\SessionCaisseRepository;
use App\Repository\VenteRepository;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Stock\StockService;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Sessions de caisse (FI-06) : chaque utilisateur ouvre la sienne avec un fond de caisse et la clôture
 * en comptant ses espèces par coupure. Un écart non nul exige une justification et est tracé (RG-13, AU-01).
 */
class GestionCaisse
{
    public const FOND_MAXIMUM = 10_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SessionCaisseRepository $sessions,
        private readonly VenteRepository $ventes,
        private readonly StockService $stock,
        private readonly Numeroteur $numeroteur,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function sessionOuverte(Utilisateur $utilisateur): ?SessionCaisse
    {
        return $this->sessions->ouverteDe($utilisateur);
    }

    /**
     * @throws VenteException
     */
    public function ouvrir(Pharmacie $pharmacie, Utilisateur $utilisateur, int $fondCaisse): SessionCaisse
    {
        if (null !== $this->sessions->ouverteDe($utilisateur)) {
            throw new VenteException('Votre caisse est déjà ouverte.');
        }
        if ($fondCaisse < 0 || $fondCaisse > self::FOND_MAXIMUM) {
            throw new VenteException('Le fond de caisse doit être un montant positif ou nul.');
        }

        return $this->stock->transaction(function () use ($pharmacie, $utilisateur, $fondCaisse): SessionCaisse {
            $session = new SessionCaisse($this->numeroteur->suivant('SC', $pharmacie), $utilisateur, $this->horloge->now(), $fondCaisse);
            $session->setPharmacie($pharmacie);
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
