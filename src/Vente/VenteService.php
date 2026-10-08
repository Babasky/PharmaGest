<?php

namespace App\Vente;

use App\Amo\AmoException;
use App\Amo\CopieOrdonnance;
use App\Entity\Client;
use App\Entity\CreanceAmo;
use App\Entity\LigneVente;
use App\Entity\Ordonnance;
use App\Entity\OrganismeAmo;
use App\Entity\Pharmacie;
use App\Entity\Produit;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModePaiement;
use App\Enum\PolitiqueSansOrdonnance;
use App\Enum\StatutVente;
use App\Enum\TypeMouvement;
use App\Enum\TypeRemise;
use App\Enum\TypeVente;
use App\Finance\RecetteService;
use App\Repository\CreanceAmoRepository;
use App\Repository\VenteRepository;
use App\Security\CodePin;
use App\Security\CodePinException;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Service\ParametresPharmacie;
use App\Stock\StockException;
use App\Stock\StockService;
use App\Tenant\TenantContext;
use App\Util\Fcfa;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Caisse (VE-01 à VE-09, RE-01 à RE-04) : panier du vendeur, mise en attente, contrôles, encaissement
 * avec sortie FEFO du stock, et annulation le jour même. Le vendeur encaisse lui-même, ou valide la vente
 * et l'envoie à la caisse où un caissier l'encaisse.
 */
class VenteService
{
    public const QUANTITE_MAXIMALE = 9999;
    public const MONTANT_MAXIMAL = 100_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteRepository $ventes,
        private readonly StockService $stock,
        private readonly Numeroteur $numeroteur,
        private readonly ParametresPharmacie $parametres,
        private readonly CodePin $codePin,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
        private readonly CreanceAmoRepository $creances,
        private readonly CopieOrdonnance $copies,
        private readonly RecetteService $recettes,
    ) {
    }

    // --- Panier --------------------------------------------------------------------------------

    public function panier(Utilisateur $vendeur): ?Vente
    {
        return $this->ventes->panierDe($vendeur);
    }

    public function panierOuNouveau(Utilisateur $vendeur): Vente
    {
        $vente = $this->ventes->panierDe($vendeur);
        if (null === $vente) {
            $vente = new Vente($vendeur, $this->horloge->now());
            $this->em->persist($vente);
        }

        return $vente;
    }

    /**
     * Ajoute un produit au panier (ou augmente sa quantité). Le stock disponible est vérifié tout de suite.
     *
     * @throws VenteException
     */
    public function ajouter(Vente $vente, Produit $produit, int $quantite = 1): LigneVente
    {
        $this->exigerModifiable($vente);
        if (!$produit->isActif()) {
            throw new VenteException(\sprintf('%s est archivé : il ne se vend plus.', $produit->getNomCommercial()));
        }
        $ligne = $vente->ligneDe($produit);
        $total = $quantite + ($ligne?->getQuantite() ?? 0);
        $this->verifierQuantite($produit, $total);

        if (null === $ligne) {
            $ligne = $vente->ajouterLigne($produit, $quantite);
        } else {
            $ligne->setQuantite($total);
        }
        $this->em->flush();

        return $ligne;
    }

    /**
     * Nouvelle quantité d'une ligne ; 0 retire la ligne.
     *
     * @throws VenteException
     */
    public function modifierQuantite(LigneVente $ligne, int $quantite): void
    {
        $vente = $ligne->getVente();
        $this->exigerModifiable($vente);
        if ($quantite <= 0) {
            $vente->retirerLigne($ligne);
        } else {
            $this->verifierQuantite($ligne->getProduit(), $quantite);
            $ligne->setQuantite($quantite);
        }
        $this->em->flush();
    }

    /**
     * @throws VenteException
     */
    public function retirer(LigneVente $ligne): void
    {
        $this->modifierQuantite($ligne, 0);
    }

    /**
     * Type de vente, client et ordonnance avec sa copie éventuelle (VE-02, AM-01, AM-02).
     * Le client est facultatif, même pour une vente AMO : l'organisme et le n° d'assuré se saisissent
     * alors sur la vente. Un client assuré impose son organisme et son n° d'assuré.
     *
     * @param array{numero?: ?string, date?: ?\DateTimeImmutable, prescripteur?: ?string, structure?: ?string} $ordonnance
     *
     * @throws VenteException
     */
    public function definirVente(Vente $vente, TypeVente $type, ?Client $client, array $ordonnance = [], ?UploadedFile $copie = null, ?OrganismeAmo $organisme = null, ?string $numeroAssure = null): void
    {
        if (null !== $organisme && !$organisme->isActif()) {
            throw new VenteException(\sprintf('L\'organisme %s n\'est plus actif.', $organisme->getNom()));
        }
        $this->exigerModifiable($vente);
        if (null !== $client && !$client->isActif()) {
            throw new VenteException(\sprintf('Le client %s est archivé.', $client->getNom()));
        }
        $pharmacie = $this->tenantContext->exigerPharmacie();
        $ancienne = $vente->getOrdonnance();
        if (null !== $ancienne && !$type->avecOrdonnance()) {
            $this->copies->supprimer($pharmacie, $ancienne);
        }
        $vente->setType($type);
        $vente->setClient($client);

        if ($type->avecOrdonnance()) {
            $fiche = $vente->getOrdonnance() ?? new Ordonnance();
            $fiche->setNumero($ordonnance['numero'] ?? null)
                ->setDate($ordonnance['date'] ?? null)
                ->setPrescripteur($ordonnance['prescripteur'] ?? null)
                ->setStructure($ordonnance['structure'] ?? null);
            if (null !== $fiche->getDate() && $fiche->getDate() > $this->stock->aujourdhui()) {
                throw new VenteException('La date de l\'ordonnance ne peut pas être dans le futur.');
            }
            if (null !== $copie) {
                try {
                    $this->copies->enregistrer($pharmacie, $fiche, $copie);
                } catch (AmoException $e) {
                    throw new VenteException($e->getMessage(), 0, $e);
                }
            }
            $vente->setOrdonnance($fiche);
        }
        $this->actualiserAmo($vente, $organisme, $numeroAssure);
        $this->em->flush();
    }

    /**
     * Remise sur une ligne, en % ou en FCFA (RE-02). Réservée à un client privilégié (RE-01) et aux ventes hors AMO.
     *
     * @throws VenteException
     */
    public function remiseLigne(LigneVente $ligne, ?TypeRemise $type, int $valeur): void
    {
        $vente = $ligne->getVente();
        $this->exigerModifiable($vente);
        if ($valeur > 0) {
            $this->exigerRemisePossible($vente, $type, $valeur);
            if (TypeVente::Amo === $vente->getType()) {
                throw new VenteException('Sur une vente AMO / assurance, la remise porte sur la part assuré : utilisez la remise sur le total (RG-09).');
            }
        }
        $ligne->definirRemise($type, $valeur);
        $this->em->flush();
    }

    /**
     * Remise sur le total (ou sur la part assuré d'une vente AMO, RG-09).
     *
     * @throws VenteException
     */
    public function remiseGlobale(Vente $vente, ?TypeRemise $type, int $valeur): void
    {
        $this->exigerModifiable($vente);
        if ($valeur > 0) {
            $this->exigerRemisePossible($vente, $type, $valeur);
        }
        $vente->definirRemise($type, $valeur);
        $this->em->flush();
    }

    /**
     * Met le panier de côté pour servir un autre client (VE-07).
     *
     * @throws VenteException
     */
    public function mettreEnAttente(Vente $vente, ?string $repere): void
    {
        $this->exigerModifiable($vente);
        if ($vente->getLignes()->isEmpty()) {
            throw new VenteException('Le panier est vide : rien à mettre en attente.');
        }
        $vente->mettreEnAttente($repere ?? $vente->getClient()?->getNom());
        $this->em->flush();
    }

    /**
     * Reprend une vente en attente ; le panier en cours, s'il n'est pas vide, est mis en attente à sa place.
     *
     * @throws VenteException
     */
    public function reprendre(Vente $vente, Utilisateur $vendeur): void
    {
        if (StatutVente::EnAttente !== $vente->getStatut()) {
            throw new VenteException('Cette vente n\'est plus en attente.');
        }
        $courant = $this->ventes->panierDe($vendeur);
        if (null !== $courant) {
            if ($courant->getLignes()->isEmpty()) {
                $this->em->remove($courant);
            } else {
                $courant->mettreEnAttente($courant->getRepere() ?? $courant->getClient()?->getNom());
            }
        }
        $vente->reprendre($vendeur);
        $this->em->flush();
    }

    /**
     * Abandonne un panier non validé : rien n'a encore été numéroté ni sorti du stock.
     *
     * @throws VenteException
     */
    public function abandonner(Vente $vente): void
    {
        $this->exigerModifiable($vente);
        $this->em->remove($vente);
        $this->em->flush();
    }

    // --- Contrôles et encaissement -------------------------------------------------------------

    /**
     * Contrôles avant encaissement : ordonnance, client AMO, produits « ordonnance obligatoire » (VE-03),
     * remise et plafond (RG-08).
     */
    public function controler(Vente $vente, Utilisateur $utilisateur, Pharmacie $pharmacie): ControleVente
    {
        $controle = new ControleVente();
        $parametres = $this->parametres->pour($pharmacie);
        $proprietaire = $utilisateur->isProprietaire();

        if ($vente->getLignes()->isEmpty()) {
            $controle->blocages[] = 'Le panier est vide.';
        }
        if ($vente->getType()->avecOrdonnance() && !($vente->getOrdonnance()?->estComplete() ?? false)) {
            $controle->blocages[] = 'Renseignez la date de l\'ordonnance.';
        }
        if (TypeVente::Amo === $vente->getType() && null === $vente->getOrganismeAmo()) {
            $controle->blocages[] = 'Vente AMO / assurance : choisissez l\'organisme, ou un client assuré (AM-02).';
        }

        if (TypeVente::SansOrdonnance === $vente->getType()) {
            $exigeant = [];
            foreach ($vente->getLignes() as $ligne) {
                if ($ligne->getProduit()->isOrdonnanceObligatoire()) {
                    $exigeant[] = $ligne->getProduit()->getNomCommercial();
                }
            }
            if ([] !== $exigeant) {
                $noms = implode(', ', $exigeant);
                if (PolitiqueSansOrdonnance::Blocage === $parametres->getPolitiqueSansOrdonnance()) {
                    $controle->blocages[] = \sprintf('Ordonnance obligatoire pour %s : choisissez « Ordonnance classique » ou « Ordonnance AMO / assurance ».', $noms);
                } else {
                    $controle->sansOrdonnance = $exigeant;
                    if (!$proprietaire) {
                        $controle->autorisations[] = \sprintf('Ordonnance obligatoire pour %s : le propriétaire doit confirmer la vente.', $noms);
                    }
                }
            }
        }

        $totaux = $vente->calculer();
        if ($totaux->remiseTotale() > 0) {
            if (!$vente->remiseAutorisee()) {
                $controle->blocages[] = 'Une remise demande un client privilégié (RE-01).';
            } elseif ($totaux->tauxRemiseMaximal > $parametres->getPlafondRemise() + 1e-9) {
                $controle->remiseHorsPlafond = true;
                if (!$proprietaire) {
                    $controle->autorisations[] = \sprintf('Remise de %s %% au-delà du plafond de %d %% : code PIN du propriétaire.', self::pourcentage($totaux->tauxRemiseMaximal), $parametres->getPlafondRemise());
                }
            }
        }

        return $controle;
    }

    /**
     * Encaisse la vente : contrôles, autorisation du propriétaire si besoin, sortie FEFO des lots,
     * numéro (RG-02), montants figés (RG-06) et paiements (VE-04).
     *
     * @param array<array-key, mixed> $saisie paiements saisis, par mode : especes[remis], orange_money[montant, reference]…
     *
     * @throws VenteException
     */
    public function encaisser(Vente $vente, Utilisateur $vendeur, SessionCaisse $session, array $saisie, ?string $codePin): void
    {
        $this->exigerModifiable($vente);
        $this->exigerSessionDe($session, $vendeur);
        $pharmacie = $this->tenantContext->exigerPharmacie();
        [$controle, $autorisePar] = $this->autoriser($vente, $vendeur, $pharmacie, $codePin);
        $paiements = $this->analyserPaiements($saisie, $vente->calculer()->aEncaisser());
        $this->verifierStock($vente);

        try {
            $this->stock->transaction(function () use ($vente, $vendeur, $session, $pharmacie, $paiements, $autorisePar, $controle): void {
                $totaux = $vente->calculer();
                $numero = $this->numeroteur->suivant('V', $pharmacie);
                $this->preleverStock($vente, $numero);
                $vente->setVendeur($vendeur);
                $vente->valider($numero, $session, $this->horloge->now(), $autorisePar);
                $this->enregistrerPaiements($vente, $paiements);
                $this->em->flush();
                $this->journaliserControles($vente, $vendeur, $pharmacie, $controle, $totaux, $autorisePar);
                $this->em->flush();
            });
        } catch (StockException $e) {
            throw new VenteException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Le vendeur valide la vente sans l'encaisser et l'envoie à la caisse : mêmes contrôles et autorisations
     * qu'à l'encaissement, numéro, sortie FEFO du stock et montants figés. Le caissier n'a plus qu'à encaisser.
     *
     * @throws VenteException
     */
    public function envoyerEnCaisse(Vente $vente, Utilisateur $vendeur, ?string $codePin): void
    {
        $this->exigerModifiable($vente);
        $pharmacie = $this->tenantContext->exigerPharmacie();
        [$controle, $autorisePar] = $this->autoriser($vente, $vendeur, $pharmacie, $codePin);
        $this->verifierStock($vente);

        try {
            $this->stock->transaction(function () use ($vente, $vendeur, $pharmacie, $autorisePar, $controle): void {
                $totaux = $vente->calculer();
                $numero = $this->numeroteur->suivant('V', $pharmacie);
                $this->preleverStock($vente, $numero);
                $vente->setVendeur($vendeur);
                $vente->envoyerEnCaisse($numero, $this->horloge->now(), $autorisePar);
                $this->em->flush();
                $this->journaliserControles($vente, $vendeur, $pharmacie, $controle, $totaux, $autorisePar);
                $this->em->flush();
            });
        } catch (StockException $e) {
            throw new VenteException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Encaissement par le caissier, dans sa session, d'une vente envoyée à la caisse : seuls les paiements
     * sont saisis, le reste a été contrôlé et figé par le vendeur.
     *
     * @param array<array-key, mixed> $saisie
     *
     * @throws VenteException
     */
    public function encaisserEnCaisse(Vente $vente, Utilisateur $caissier, SessionCaisse $session, array $saisie): void
    {
        if (!$vente->estAEncaisser()) {
            throw new VenteException(\sprintf('La vente %s n\'est plus à encaisser.', $vente->getNumero()));
        }
        $this->exigerSessionDe($session, $caissier);
        $paiements = $this->analyserPaiements($saisie, $vente->getMontantEncaisse());

        $this->stock->transaction(function () use ($vente, $session, $paiements): void {
            // Deux caissiers ne peuvent pas encaisser la même vente : seul le premier change son statut.
            $pris = $this->em->createQueryBuilder()
                ->update(Vente::class, 'v')
                ->set('v.statut', ':encaissee')->setParameter('encaissee', StatutVente::Validee)
                ->andWhere('v.id = :id')->setParameter('id', $vente->getId())
                ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::AEncaisser)
                ->getQuery()->execute();
            if (1 !== $pris) {
                throw new VenteException(\sprintf('La vente %s vient d\'être encaissée ou annulée.', $vente->getNumero()));
            }
            $vente->encaisserEnCaisse($session, $this->horloge->now());
            $this->enregistrerPaiements($vente, $paiements);
            $this->em->flush();
        });
    }

    /**
     * Annule une vente le jour même, avant la clôture de sa session de caisse (VE-09, RG-12), ou une vente
     * envoyée à la caisse que le client n'a pas payée : les produits retournent dans leurs lots d'origine,
     * la vente reste numérotée avec le statut « annulée ».
     *
     * @throws VenteException
     */
    public function annuler(Vente $vente, string $motif): void
    {
        if (!$vente->estValidee() && !$vente->estAEncaisser()) {
            throw new VenteException('Seule une vente validée peut être annulée.');
        }
        $motif = trim($motif);
        if ('' === $motif) {
            throw new VenteException('Le motif de l\'annulation est obligatoire.');
        }
        // Une vente envoyée à la caisse et jamais payée s'annule tant qu'elle attend : rien n'a été encaissé.
        if ($vente->estValidee() && !($vente->getSession()?->estOuverte() ?? false) || $vente->getValideeLe()?->format('Y-m-d') !== $this->horloge->now()->format('Y-m-d')) {
            throw new VenteException('Une vente ne s\'annule que le jour même, avant la clôture de sa session de caisse. Au-delà, il faut établir un avoir.');
        }
        $creance = $this->creances->pourVente($vente);
        $bordereau = $creance?->getBordereau();
        if (null !== $bordereau && $bordereau->estTransmis()) {
            throw new VenteException(\sprintf('La créance AMO de cette vente figure sur le bordereau transmis %s : la vente ne peut plus être annulée.', $bordereau->getNumero()));
        }

        $this->stock->transaction(function () use ($vente, $motif, $creance): void {
            $numero = (string) $vente->getNumero();
            foreach ($vente->getLignes() as $ligne) {
                foreach ($ligne->getLots() as $ligneLot) {
                    $this->stock->reintegrer($ligneLot->getLot(), $ligneLot->getQuantite(), TypeMouvement::Annulation, $numero, mb_substr($motif, 0, 255));
                }
            }
            $vente->annuler($this->horloge->now(), $this->tenantContext->getUtilisateur(), mb_substr($motif, 0, 255));
            $creance?->annuler();
            $this->recettes->contrePasserVente($vente);
            $this->em->flush();
            $this->audit->journaliser(AuditLogger::VENTE_ANNULEE, $vente->getPharmacie(), $vente, null, [
                'vente' => $numero,
                'vendeur' => $vente->getVendeur()->getNom(),
                'total' => $vente->getTotalNet(),
                'encaisse' => $vente->getMontantEncaisse(),
                'motif' => $motif,
            ]);
            $this->em->flush();
        });
    }

    // --- Outils ---------------------------------------------------------------------------------

    /**
     * Contrôles avant validation et autorisation du propriétaire par code PIN si besoin. Le taux AMO
     * est celui du jour de la validation (RG-06).
     *
     * @return array{ControleVente, ?Utilisateur} contrôle et propriétaire qui a autorisé
     *
     * @throws VenteException
     */
    private function autoriser(Vente $vente, Utilisateur $vendeur, Pharmacie $pharmacie, ?string $codePin): array
    {
        $this->actualiserAmo($vente, $vente->getOrganismeAmo(), $vente->getNumeroAssure());
        $controle = $this->controler($vente, $vendeur, $pharmacie);
        if ($controle->estBloquee()) {
            throw new VenteException($controle->blocages[0]);
        }

        $autorisePar = null;
        if ($controle->exigeCodePin()) {
            try {
                $autorisePar = $this->codePin->autorisationProprietaire($pharmacie, $codePin);
            } catch (CodePinException $e) {
                throw new VenteException($e->getMessage(), 0, $e);
            }
        } elseif ($controle->remiseHorsPlafond || [] !== $controle->sansOrdonnance) {
            $autorisePar = $vendeur; // Le propriétaire lui-même.
        }

        return [$controle, $autorisePar];
    }

    /**
     * Tout est vérifié avant la première écriture : un refus ne laisse aucun lot entamé.
     *
     * @throws VenteException
     */
    private function verifierStock(Vente $vente): void
    {
        try {
            foreach ($vente->getLignes() as $ligne) {
                $this->stock->verifierDisponible($ligne->getProduit(), $ligne->getQuantite());
            }
        } catch (StockException $e) {
            throw new VenteException($e->getMessage(), 0, $e);
        }
    }

    private function preleverStock(Vente $vente, string $numero): void
    {
        foreach ($vente->getLignes() as $ligne) {
            foreach ($this->stock->prelever($ligne->getProduit(), $ligne->getQuantite(), TypeMouvement::Vente, $numero) as $prelevement) {
                $ligne->ajouterLot($prelevement->lot, $prelevement->quantite);
            }
        }
    }

    /**
     * Paiements de la vente, recette (FI-04, RG-10 : part assuré seulement pour l'AMO) et créance AMO (AM-05).
     *
     * @param list<array{ModePaiement, int, ?string, ?int}> $paiements
     */
    private function enregistrerPaiements(Vente $vente, array $paiements): void
    {
        foreach ($paiements as [$mode, $montant, $reference, $remis]) {
            $vente->ajouterPaiement($mode, $montant, $reference, $remis);
        }
        $this->recettes->enregistrerVente($vente);
        // AM-05 : la part AMO devient une créance « en attente » sur l'organisme.
        if (TypeVente::Amo === $vente->getType() && $vente->getPartAmo() > 0) {
            $this->em->persist(new CreanceAmo($vente));
        }
    }

    private function journaliserControles(Vente $vente, Utilisateur $vendeur, Pharmacie $pharmacie, ControleVente $controle, TotauxVente $totaux, ?Utilisateur $autorisePar): void
    {
        $numero = $vente->getNumero();
        if ($controle->remiseHorsPlafond) {
            // RE-04 : vendeur, client, montant, % et vente concernée.
            $this->audit->journaliser(AuditLogger::VENTE_REMISE_HORS_PLAFOND, $pharmacie, $vente, null, [
                'vente' => $numero,
                'vendeur' => $vendeur->getNom(),
                'client' => $vente->getClient()?->getNom(),
                'remise' => $totaux->remiseTotale(),
                'taux' => self::pourcentage($totaux->tauxRemiseMaximal),
                'plafond' => $this->parametres->pour($pharmacie)->getPlafondRemise(),
                'autorise_par' => $autorisePar?->getNom(),
            ]);
        }
        if ([] !== $controle->sansOrdonnance) {
            $this->audit->journaliser(AuditLogger::VENTE_SANS_ORDONNANCE, $pharmacie, $vente, null, [
                'vente' => $numero,
                'vendeur' => $vendeur->getNom(),
                'produits' => $controle->sansOrdonnance,
                'autorise_par' => $autorisePar?->getNom(),
            ]);
        }
    }

    /**
     * @throws VenteException
     */
    private function exigerSessionDe(SessionCaisse $session, Utilisateur $utilisateur): void
    {
        if (!$session->estOuverte() || $session->getUtilisateur()->getId() !== $utilisateur->getId()) {
            throw new VenteException('Ouvrez votre session de caisse avant d\'encaisser.');
        }
    }

    /**
     * @param array<array-key, mixed> $saisie
     *
     * @return list<array{ModePaiement, int, ?string, ?int}> mode, montant, référence, espèces remises
     *
     * @throws VenteException
     */
    private function analyserPaiements(array $saisie, int $du): array
    {
        $paiements = [];
        $autres = 0;
        foreach ([ModePaiement::OrangeMoney, ModePaiement::MoovMoney, ModePaiement::Carte] as $mode) {
            $champs = \is_array($saisie[$mode->value] ?? null) ? $saisie[$mode->value] : [];
            $montant = self::montant($champs['montant'] ?? null, $mode->libelle());
            if (null === $montant || 0 === $montant) {
                continue;
            }
            $reference = trim(\is_scalar($champs['reference'] ?? null) ? (string) $champs['reference'] : '');
            $paiements[] = [$mode, $montant, '' === $reference ? null : mb_substr($reference, 0, 60), null];
            $autres += $montant;
        }
        if ($autres > $du) {
            throw new VenteException(\sprintf('Les paiements hors espèces (%s) dépassent le montant à encaisser (%s).', Fcfa::format($autres), Fcfa::format($du)));
        }

        $especes = $du - $autres;
        if ($especes > 0) {
            $champs = \is_array($saisie[ModePaiement::Especes->value] ?? null) ? $saisie[ModePaiement::Especes->value] : [];
            $remis = self::montant($champs['remis'] ?? null, 'Espèces remises') ?? $especes;
            if ($remis < $especes) {
                throw new VenteException(\sprintf('Espèces insuffisantes : %s remis pour %s à payer.', Fcfa::format($remis), Fcfa::format($especes)));
            }
            $paiements[] = [ModePaiement::Especes, $especes, null, $remis];
        }

        return $paiements;
    }

    private static function montant(mixed $valeur, string $libelle): ?int
    {
        $texte = trim(\is_scalar($valeur) ? (string) $valeur : '');
        if ('' === $texte) {
            return null;
        }
        $texte = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $texte);
        if (!ctype_digit($texte) || (int) $texte > self::MONTANT_MAXIMAL) {
            throw new VenteException(\sprintf('%s : montant invalide.', $libelle));
        }

        return (int) $texte;
    }

    private static function pourcentage(float $taux): string
    {
        return rtrim(rtrim(number_format($taux, 1, ',', ''), '0'), ',');
    }

    /** Organisme, n° d'assuré et taux AMO du jour, repris du client (AM-02, H1). */
    private function actualiserAmo(Vente $vente, ?OrganismeAmo $organisme, ?string $numeroAssure): void
    {
        $client = $vente->getClient();
        if (TypeVente::Amo !== $vente->getType()) {
            $vente->definirAmo(null, null, null);
        } elseif (null !== $client && $client->isAssureAmo()) {
            $organisme = $client->getOrganismeAmo() ?? throw new \LogicException('Assuré sans organisme.');
            $vente->definirAmo($organisme, $client->getNumeroAssure(), $this->parametres->tauxAmo($organisme));
        } elseif (null !== $organisme) {
            // Sans client assuré : organisme et n° d'assuré (facultatif) saisis sur la vente.
            $numeroAssure = null === $numeroAssure || '' === trim($numeroAssure) ? null : mb_substr(trim($numeroAssure), 0, 40);
            $vente->definirAmo($organisme, $numeroAssure, $this->parametres->tauxAmo($organisme));
        } else {
            $vente->definirAmo(null, null, null);
        }
    }

    private function verifierQuantite(Produit $produit, int $quantite): void
    {
        if ($quantite > self::QUANTITE_MAXIMALE) {
            throw new VenteException(\sprintf('Quantité trop élevée (%d au maximum).', self::QUANTITE_MAXIMALE));
        }
        if (null === $produit->getPrixVente() || $produit->getPrixVente() <= 0) {
            throw new VenteException(\sprintf('%s n\'a pas de prix de vente.', $produit->getNomCommercial()));
        }
        try {
            $this->stock->verifierDisponible($produit, $quantite);
        } catch (StockException $e) {
            throw new VenteException($e->getMessage(), 0, $e);
        }
    }

    private function exigerRemisePossible(Vente $vente, ?TypeRemise $type, int $valeur): void
    {
        if (!$vente->remiseAutorisee()) {
            throw new VenteException('Une remise n\'est possible que pour un client privilégié (RE-01).');
        }
        if (null === $type || $valeur < 0 || (TypeRemise::Pourcentage === $type && $valeur > 100)) {
            throw new VenteException('Remise invalide : un pourcentage entre 0 et 100, ou un montant en FCFA.');
        }
    }

    private function exigerModifiable(Vente $vente): void
    {
        if (!$vente->estModifiable()) {
            throw new VenteException(\sprintf('La vente %s est validée : elle ne se modifie plus.', $vente->getNumero()));
        }
    }
}
