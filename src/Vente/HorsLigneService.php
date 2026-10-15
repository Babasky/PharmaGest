<?php

namespace App\Vente;

use App\Entity\Ordonnance;
use App\Entity\OrganismeAmo;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Entity\VenteHorsLigne;
use App\Enum\ModePaiement;
use App\Enum\TypeVente;
use App\Repository\OrganismeAmoRepository;
use App\Repository\ProduitRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\VenteHorsLigneRepository;
use App\Service\AuditLogger;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Caisse hors ligne : ventes faites sur un poste pendant une coupure d'internet, envoyées au retour du réseau.
 *
 * Chaque vente reçue est d'abord gardée telle quelle (le poste peut l'effacer), puis importée comme une vente
 * normale : numéro définitif, sortie FEFO du stock, paiements et recettes, dans la session ouverte du vendeur
 * et à la date où elle a été faite. Une vente qui ne peut pas être importée (stock insuffisant, caisse fermée,
 * produit archivé…) reste « en anomalie » : elle est réessayée à chaque envoi et à l'ouverture de la caisse
 * du vendeur, ou rejetée par un adjoint avec un motif.
 */
class HorsLigneService
{
    public const VENTES_PAR_ENVOI = 200;
    public const LIGNES_PAR_VENTE = 200;
    /** Une vente plus ancienne n'est plus acceptée (horloge du poste déréglée ou poste oublié). */
    public const JOURS_MAXIMUM = 60;

    private const EN_ATTENTE = 'Reçue, en attente d\'import.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteHorsLigneRepository $horsLigne,
        private readonly VenteService $ventes,
        private readonly SessionCaisseRepository $sessions,
        private readonly ProduitRepository $produits,
        private readonly OrganismeAmoRepository $organismes,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Reçoit les ventes envoyées par le poste du vendeur, puis importe toutes ses ventes en attente.
     * Un même envoi peut être répété sans risque : une vente déjà reçue n'est pas enregistrée deux fois.
     *
     * @param list<mixed> $envoi
     *
     * @return array<string, array{statut: string, message: ?string, numero: ?string}> résultat par identifiant de vente
     */
    public function recevoir(array $envoi, Utilisateur $vendeur): array
    {
        $resultats = [];
        $recues = [];
        foreach (\array_slice($envoi, 0, self::VENTES_PAR_ENVOI) as $brute) {
            $uuid = \is_array($brute) && \is_string($brute['uuid'] ?? null) ? $brute['uuid'] : null;
            if (null === $uuid || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
                continue;
            }
            $existante = $this->horsLigne->parUuid($uuid);
            if (null !== $existante) {
                if ($existante->getVendeur()->getId() === $vendeur->getId()) {
                    $recues[$uuid] = $existante;
                }
                continue;
            }
            try {
                $vente = $this->normaliser($brute, $vendeur);
            } catch (VenteException $e) {
                $resultats[$uuid] = ['statut' => 'refusee', 'message' => $e->getMessage(), 'numero' => null];
                continue;
            }
            $vente->signalerAnomalie(self::EN_ATTENTE, $this->horloge->now());
            $this->em->persist($vente);
            $recues[$uuid] = $vente;
        }
        $this->em->flush();

        $this->importerEnAttente($vendeur);

        foreach ($recues as $uuid => $vente) {
            $resultats[$uuid] = [
                'statut' => $vente->getStatut()->value,
                'message' => $vente->getMessage(),
                'numero' => $vente->getVente()?->getNumero(),
            ];
        }

        return $resultats;
    }

    /**
     * Importe les ventes hors ligne en anomalie d'un vendeur, dans l'ordre où elles ont été faites.
     *
     * @return int nombre de ventes importées
     */
    public function importerEnAttente(Utilisateur $vendeur): int
    {
        $importees = 0;
        foreach ($this->horsLigne->enAnomalieDe($vendeur) as $vente) {
            try {
                $importees += $this->importer($vente) ? 1 : 0;
            } catch (ImportInterrompuException) {
                break;
            }
        }

        return $importees;
    }

    /**
     * @return bool vrai si la vente est importée, faux si elle reste en anomalie
     *
     * @throws ImportInterrompuException l'enregistrement a échoué en cours de route : rien n'est écrit,
     *                                   la vente reste en attente et sera réessayée
     */
    public function importer(VenteHorsLigne $horsLigne): bool
    {
        if (!$horsLigne->estEnAnomalie()) {
            return false;
        }
        $vendeur = $horsLigne->getVendeur();
        $vente = null;
        try {
            $session = $this->sessions->ouverteDe($vendeur);
            if (null === $session) {
                throw new VenteException(\sprintf('La caisse de %s est fermée : la vente sera importée à l\'ouverture de sa caisse.', $vendeur->getNom()));
            }
            $vente = $this->construire($horsLigne);
            /** @var array<array-key, mixed> $paiements */
            $paiements = $horsLigne->getDonnees()['paiements'] ?? [];
            $le = min($horsLigne->getVenduLe(), $this->horloge->now());
            $ecart = $this->ventes->encaisserHorsLigne($vente, $vendeur, $session, $paiements, $le, $horsLigne->getMontant());
        } catch (VenteException $e) {
            if (null !== $vente && $this->em->contains($vente)) {
                // Échec dans la transaction (stock pris entre-temps…) : les lots en mémoire ne sont plus fiables.
                $this->em->clear();
                throw new ImportInterrompuException($e->getMessage(), 0, $e);
            }
            $horsLigne->signalerAnomalie($e->getMessage(), $this->horloge->now());
            $this->em->flush();

            return false;
        }

        $horsLigne->importer($vente, $ecart, $this->horloge->now());
        if (0 !== $ecart) {
            $this->audit->journaliser(AuditLogger::VENTE_HORS_LIGNE_ECART, $vente->getPharmacie(), $vente, null, [
                'vente' => $vente->getNumero(),
                'ticket' => $horsLigne->getNumeroProvisoire(),
                'vendeur' => $vendeur->getNom(),
                'montant_ticket' => $horsLigne->getMontant(),
                'montant_recalcule' => $vente->getMontantEncaisse(),
                'ecart' => $ecart,
            ]);
        }
        $this->em->flush();

        return true;
    }

    /**
     * Abandon motivé d'une vente hors ligne impossible à importer (par exemple un produit déjà compté
     * à l'inventaire) : elle reste consultable, et l'argent encaissé apparaîtra en écart à la clôture.
     *
     * @throws VenteException
     */
    public function rejeter(VenteHorsLigne $horsLigne, string $motif): void
    {
        if (!$horsLigne->estEnAnomalie()) {
            throw new VenteException('Cette vente hors ligne n\'est plus en anomalie.');
        }
        $motif = trim($motif);
        if ('' === $motif) {
            throw new VenteException('Le motif du rejet est obligatoire.');
        }
        $utilisateur = $this->tenantContext->getUtilisateur() ?? throw new \LogicException('Utilisateur requis.');
        $horsLigne->rejeter($utilisateur, $motif, $this->horloge->now());
        $this->em->flush();
        $this->audit->journaliser(AuditLogger::VENTE_HORS_LIGNE_REJETEE, $horsLigne->getPharmacie(), $horsLigne, null, [
            'ticket' => $horsLigne->getNumeroProvisoire(),
            'vendeur' => $horsLigne->getVendeur()->getNom(),
            'montant' => $horsLigne->getMontant(),
            'motif' => mb_substr($motif, 0, 255),
        ]);
        $this->em->flush();
    }

    /**
     * Contrôle de forme de la vente envoyée par le poste ; le fond (stock, prix, contrôles de la caisse)
     * est vérifié à l'import.
     *
     * @throws VenteException
     */
    private function normaliser(mixed $brute, Utilisateur $vendeur): VenteHorsLigne
    {
        if (!\is_array($brute)) {
            throw new VenteException('Vente illisible.');
        }
        $numero = self::texte($brute['numero'] ?? null, 30);
        if (null === $numero || 1 !== preg_match('/^HL-[A-Z0-9]{4}-\d{1,6}$/', $numero)) {
            throw new VenteException('Numéro de ticket hors ligne invalide.');
        }
        $type = TypeVente::tryFrom((string) self::texte($brute['type'] ?? null, 20)) ?? throw new VenteException('Type de vente invalide.');

        $maintenant = $this->horloge->now();
        try {
            $venduLe = new \DateTimeImmutable(self::texte($brute['venduLe'] ?? null, 40) ?? throw new VenteException('Date de vente manquante.'));
        } catch (\Exception) {
            throw new VenteException('Date de vente invalide.');
        }
        $venduLe = $venduLe->setTimezone($maintenant->getTimezone());
        if ($venduLe < $maintenant->modify(\sprintf('-%d days', self::JOURS_MAXIMUM))) {
            throw new VenteException(\sprintf('Vente de plus de %d jours : vérifiez la date et l\'heure du poste.', self::JOURS_MAXIMUM));
        }
        $venduLe = min($venduLe, $maintenant);

        $lignes = [];
        $brutes = \is_array($brute['lignes'] ?? null) ? $brute['lignes'] : [];
        if ([] === $brutes || \count($brutes) > self::LIGNES_PAR_VENTE) {
            throw new VenteException('Lignes de vente invalides.');
        }
        foreach ($brutes as $ligne) {
            $produit = \is_array($ligne) ? self::entier($ligne['produit'] ?? null) : null;
            $quantite = \is_array($ligne) ? self::entier($ligne['quantite'] ?? null) : null;
            if (null === $produit || null === $quantite || $produit <= 0 || $quantite <= 0 || $quantite > VenteService::QUANTITE_MAXIMALE) {
                throw new VenteException('Lignes de vente invalides.');
            }
            $lignes[] = ['produit' => $produit, 'quantite' => $quantite];
        }

        $montant = self::entier($brute['montant'] ?? null);
        if (null === $montant || $montant < 0 || $montant > VenteService::MONTANT_MAXIMAL) {
            throw new VenteException('Montant invalide.');
        }

        $ordonnance = \is_array($brute['ordonnance'] ?? null) ? $brute['ordonnance'] : [];
        $date = self::texte($ordonnance['date'] ?? null, 10);
        if (null !== $date && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new VenteException('Date d\'ordonnance invalide.');
        }

        $paiements = [];
        $saisis = \is_array($brute['paiements'] ?? null) ? $brute['paiements'] : [];
        foreach (ModePaiement::horsEspeces() as $mode) {
            $champs = \is_array($saisis[$mode->value] ?? null) ? $saisis[$mode->value] : [];
            $montantMode = self::entier($champs['montant'] ?? null);
            if (null !== $montantMode && $montantMode > 0) {
                $paiements[$mode->value] = ['montant' => (string) $montantMode, 'reference' => self::texte($champs['reference'] ?? null, 60)];
            }
        }
        $especes = \is_array($saisis[ModePaiement::Especes->value] ?? null) ? $saisis[ModePaiement::Especes->value] : [];
        $remis = self::entier($especes['remis'] ?? null);
        if (null !== $remis) {
            $paiements[ModePaiement::Especes->value] = ['remis' => (string) $remis];
        }

        return new VenteHorsLigne(
            uuid: (string) $brute['uuid'],
            numeroProvisoire: $numero,
            vendeur: $vendeur,
            venduLe: $venduLe,
            recueLe: $maintenant,
            montant: $montant,
            donnees: [
                'type' => $type->value,
                'lignes' => $lignes,
                'ordonnance' => [
                    'numero' => self::texte($ordonnance['numero'] ?? null, 50),
                    'date' => $date,
                    'prescripteur' => self::texte($ordonnance['prescripteur'] ?? null, 100),
                ],
                'organisme' => self::entier($brute['organisme'] ?? null),
                'numeroAssure' => self::texte($brute['numeroAssure'] ?? null, 40),
                'paiements' => $paiements,
            ],
        );
    }

    /**
     * La vente telle qu'elle aurait été saisie à la caisse, non enregistrée.
     *
     * @throws VenteException
     */
    private function construire(VenteHorsLigne $horsLigne): Vente
    {
        $donnees = $horsLigne->getDonnees();
        $vente = new Vente($horsLigne->getVendeur(), $horsLigne->getVenduLe());
        $vente->setNumeroHorsLigne($horsLigne->getNumeroProvisoire());

        /** @var list<array{produit: int, quantite: int}> $lignes */
        $lignes = $donnees['lignes'] ?? [];
        foreach ($lignes as ['produit' => $id, 'quantite' => $quantite]) {
            $produit = $this->produits->find($id);
            if (!$produit instanceof Produit) {
                throw new VenteException(\sprintf('Produit n° %d introuvable.', $id));
            }
            if (!$produit->isActif()) {
                throw new VenteException(\sprintf('%s est archivé : il ne se vend plus.', $produit->getNomCommercial()));
            }
            if (null === $produit->getPrixVente() || $produit->getPrixVente() <= 0) {
                throw new VenteException(\sprintf('%s n\'a pas de prix de vente.', $produit->getNomCommercial()));
            }
            $ligne = $vente->ligneDe($produit);
            $total = $quantite + ($ligne?->getQuantite() ?? 0);
            if ($total > VenteService::QUANTITE_MAXIMALE) {
                throw new VenteException(\sprintf('Quantité trop élevée (%d au maximum).', VenteService::QUANTITE_MAXIMALE));
            }
            null === $ligne ? $vente->ajouterLigne($produit, $quantite) : $ligne->setQuantite($total);
        }

        $type = TypeVente::from((string) $donnees['type']);
        $vente->setType($type);
        if ($type->avecOrdonnance()) {
            /** @var array{numero: ?string, date: ?string, prescripteur: ?string} $saisie */
            $saisie = $donnees['ordonnance'];
            $date = null === $saisie['date'] ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie['date'], $horsLigne->getVenduLe()->getTimezone());
            if (false === $date || (null !== $date && $date->format('Y-m-d') > $horsLigne->getVenduLe()->format('Y-m-d'))) {
                throw new VenteException('La date de l\'ordonnance est invalide ou postérieure à la vente.');
            }
            $vente->setOrdonnance((new Ordonnance())->setNumero($saisie['numero'])->setDate($date)->setPrescripteur($saisie['prescripteur']));
        }
        if (TypeVente::Amo === $type) {
            $id = $donnees['organisme'] ?? null;
            $organisme = \is_int($id) ? $this->organismes->find($id) : null;
            if (!$organisme instanceof OrganismeAmo || !$organisme->isActif()) {
                throw new VenteException('Vente AMO / assurance : organisme introuvable ou inactif.');
            }
            $vente->definirAmo($organisme, \is_string($donnees['numeroAssure'] ?? null) ? $donnees['numeroAssure'] : null, null);
        }

        return $vente;
    }

    private static function texte(mixed $valeur, int $longueur): ?string
    {
        if (!\is_scalar($valeur)) {
            return null;
        }
        $texte = trim((string) $valeur);

        return '' === $texte ? null : mb_substr($texte, 0, $longueur);
    }

    private static function entier(mixed $valeur): ?int
    {
        if (\is_int($valeur)) {
            return $valeur;
        }
        $texte = \is_string($valeur) ? str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($valeur)) : '';

        return '' !== $texte && ctype_digit($texte) && \strlen($texte) <= 12 ? (int) $texte : null;
    }
}
