<?php

namespace App\Story;

use App\Achat\CommandeService;
use App\Achat\ReceptionService;
use App\Amo\GestionBordereaux;
use App\Entity\Offre;
use App\Entity\Utilisateur;
use App\Enum\ModeReglement;
use App\Enum\MoyenPaiement;
use App\Enum\TypeRemise;
use App\Enum\TypeVente;
use App\Enum\ZoneEtagere;
use App\Finance\GestionDepenses;
use App\Finance\RecetteService;
use App\Form\Model\SaisieDepense;
use App\Security\CodePin;
use App\Service\AbonnementService;
use App\Service\GenerateurNotifications;
use App\Stock\StockService;
use App\Stock\TransfertService;
use App\Tenant\TenantContext;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\CategorieFactory;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\EtagereFactory;
use App\Tests\Factory\FournisseurFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
use App\Vente\GestionCaisse;
use App\Vente\VenteService;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Données de démonstration (développement et recette uniquement) :
 *   php bin/console foundry:load-fixtures main
 *
 * Tous les comptes ont le mot de passe « motdepasse ».
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    public function __construct(
        private readonly AbonnementService $abonnements,
        private readonly StockService $stock,
        private readonly CodePin $codePin,
        private readonly TenantContext $tenantContext,
        private readonly GestionCaisse $caisse,
        private readonly VenteService $ventes,
        private readonly GestionBordereaux $bordereaux,
        private readonly CommandeService $commandes,
        private readonly ReceptionService $receptions,
        private readonly GestionDepenses $depenses,
        private readonly RecetteService $recettes,
        private readonly GenerateurNotifications $notifications,
        private readonly TransfertService $transferts,
        private readonly TokenStorageInterface $jetons,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    public function build(): void
    {
        UtilisateurFactory::new()->superAdmin()->create(['email' => 'admin@pharmagest.ml', 'nom' => 'Éditeur PharmaGest']);

        // Officine active, offre Standard, équipe complète, deux paiements.
        $fleuve = PharmacieFactory::createOne([
            'nom' => 'Pharmacie du Fleuve', 'ville' => 'Bamako', 'adresse' => 'Badalabougou, rue 12, porte 40',
            'telephone' => '+22376123456', 'numeroAutorisation' => 'AUT-BKO-0117', 'finAbonnement' => null,
        ]);
        $this->equipe($fleuve, 'a.traore@fleuve.ml', 'Aminata Traoré', [
            ['f.keita@fleuve.ml', 'Fatoumata Keïta', Utilisateur::ROLE_ADJOINT],
            ['m.coulibaly@fleuve.ml', 'Moussa Coulibaly', Utilisateur::ROLE_VENDEUR],
            ['s.diarra@fleuve.ml', 'Seydou Diarra', Utilisateur::ROLE_VENDEUR],
            ['a.konate@fleuve.ml', 'Awa Konaté', Utilisateur::ROLE_CAISSIER],
        ]);
        $this->abonnements->enregistrerPaiement($fleuve, $fleuve->getOffre(), 180000, MoyenPaiement::OrangeMoney, 'OM-2026-55871', new \DateTimeImmutable('today'), null);
        // Les actions de la démo sont faites au nom de la titulaire : le journal d'audit les lui attribue.
        $aminata = \Zenstruck\Foundry\Persistence\repository(Utilisateur::class)->findOneBy(['email' => 'a.traore@fleuve.ml']);
        \assert($aminata instanceof Utilisateur);
        $this->jetons->setToken(new UsernamePasswordToken($aminata, 'main', $aminata->getRoles()));
        $catalogue = $this->catalogue($fleuve);
        $this->caisse($fleuve, $catalogue);
        $this->commandes($fleuve, $catalogue);
        $this->finances($fleuve);
        $this->finition($fleuve, $catalogue);
        $this->jetons->setToken(null);

        // Officine dont l'abonnement expire bientôt (bandeau d'alerte).
        $kanaga = PharmacieFactory::createOne([
            'nom' => 'Pharmacie Kanaga', 'ville' => 'Mopti', 'telephone' => '+22366234578',
            'numeroAutorisation' => 'AUT-MPT-0042', 'finAbonnement' => new \DateTimeImmutable('today +12 days'),
            'offre' => \App\Tests\Factory\OffreFactory::parCode(Offre::ESSENTIEL),
        ]);
        $this->equipe($kanaga, 'o.guindo@kanaga.ml', 'Ousmane Guindo', [['b.kone@kanaga.ml', 'Bintou Koné', Utilisateur::ROLE_VENDEUR]]);

        // Officine en période d'essai.
        $djoliba = PharmacieFactory::new()->enEssai(21)->create([
            'nom' => 'Officine Djoliba', 'ville' => 'Ségou', 'telephone' => '+22379451203', 'numeroAutorisation' => 'AUT-SGU-0008',
        ]);
        $this->equipe($djoliba, 'k.sangare@djoliba.ml', 'Kadiatou Sangaré', []);

        // Officine expirée (lecture seule).
        $paix = PharmacieFactory::new()->echue(20)->create([
            'nom' => 'Pharmacie de la Paix', 'ville' => 'Sikasso', 'telephone' => '+22320214455', 'numeroAutorisation' => 'AUT-SKO-0031',
        ]);
        $this->equipe($paix, 'i.ouattara@paix.ml', 'Ibrahim Ouattara', []);

        // Propriétaire Premium avec deux officines (sélecteur de pharmacie).
        $premium = \App\Tests\Factory\OffreFactory::parCode(Offre::PREMIUM);
        $kati = PharmacieFactory::createOne(['nom' => 'Pharmacie de Kati', 'ville' => 'Kati', 'offre' => $premium, 'numeroAutorisation' => 'AUT-KTI-0003']);
        $koulikoro = PharmacieFactory::createOne(['nom' => 'Pharmacie de Koulikoro', 'ville' => 'Koulikoro', 'offre' => $premium, 'numeroAutorisation' => 'AUT-KLK-0005']);
        $proprietaire = $this->equipe($kati, 'm.dembele@groupe-dembele.ml', 'Mariam Dembélé', [['y.toure@groupe-dembele.ml', 'Yacouba Touré', Utilisateur::ROLE_VENDEUR]]);
        AffectationFactory::createOne(['utilisateur' => $proprietaire, 'pharmacie' => $koulikoro]);
        AffectationFactory::createOne(['utilisateur' => UtilisateurFactory::createOne(['email' => 's.cisse@groupe-dembele.ml', 'nom' => 'Salif Cissé', 'role' => Utilisateur::ROLE_ADJOINT]), 'pharmacie' => $koulikoro]);
        $this->transferts($kati, $koulikoro, $proprietaire);

        // Centre de notifications (Lot 8) : alertes du jour pour chaque pharmacie, comme chaque matin.
        foreach ([$fleuve, $kanaga, $djoliba, $paix, $kati, $koulikoro] as $pharmacie) {
            $this->notifications->generer($pharmacie);
        }
    }

    /**
     * Finition (Lot 8) : quelques actions sensibles de plus dans le journal d'audit (prix, ajustement de stock) ;
     * le bordereau INPS est daté d'il y a 40 jours pour illustrer la notification d'impayé.
     *
     * @param array<string, \App\Entity\Produit> $catalogue
     */
    private function finition(\App\Entity\Pharmacie $pharmacie, array $catalogue): void
    {
        $this->tenantContext->forcer($pharmacie);
        // Le prix est enregistré avec l'ajustement (même flush) : le journal trace les deux.
        $catalogue['Doliprane']->setPrixVente(1600);
        $lot = \Zenstruck\Foundry\Persistence\repository(\App\Entity\Lot::class)->findOneBy(['numero' => 'IB772']);
        \assert($lot instanceof \App\Entity\Lot);
        $this->stock->ajuster($lot, $lot->getQuantiteRestante() - 2, 'Deux boîtes abîmées à la réception');

        $bordereau = \Zenstruck\Foundry\Persistence\repository(\App\Entity\BordereauAmo::class)->findOneBy([]);
        \assert($bordereau instanceof \App\Entity\BordereauAmo);
        $this->em->getConnection()->executeStatement(
            'UPDATE bordereau_amo SET transmis_le = ? WHERE id = ?',
            [(new \DateTimeImmutable('today -40 days 10:00'))->format('Y-m-d H:i:s'), $bordereau->getId()],
        );
        $this->em->refresh($bordereau);
        $this->tenantContext->forcer(null);
    }

    /**
     * Petit catalogue réaliste (prix publics indicatifs en FCFA) pour la recette des lots 2 à 4.
     *
     * @return array<string, \App\Entity\Produit>
     */
    private function catalogue(\App\Entity\Pharmacie $pharmacie): array
    {
        $em = \Zenstruck\Foundry\Persistence\repository(\App\Entity\FormeGalenique::class);
        $forme = static fn (string $nom) => $em->findOneBy(['nom' => $nom]);

        $medicaments = CategorieFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Médicaments']);
        $categories = [];
        foreach (['Antalgiques', 'Antibiotiques', 'Antipaludiques', 'Gastro-entérologie'] as $nom) {
            $categories[$nom] = CategorieFactory::createOne(['pharmacie' => $pharmacie, 'nom' => $nom, 'parent' => $medicaments]);
        }
        $categories['Parapharmacie'] = CategorieFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Parapharmacie']);

        $etageres = [];
        foreach ([['E1-R1', 'Antalgiques', ZoneEtagere::Comptoir], ['E1-R2', 'Antibiotiques', ZoneEtagere::Comptoir], ['E2-R1', 'Antipaludiques', ZoneEtagere::Comptoir], ['R1', 'Réserve principale', ZoneEtagere::Reserve], ['F1', 'Réfrigérateur', ZoneEtagere::Refrigerateur]] as [$code, $libelle, $zone]) {
            $etageres[$code] = EtagereFactory::createOne(['pharmacie' => $pharmacie, 'code' => $code, 'libelle' => $libelle, 'zone' => $zone]);
        }

        $ppm = FournisseurFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'PPM', 'contact' => 'Service commandes', 'telephone' => '+22320225050', 'email' => 'commandes@ppm.example', 'delaiLivraison' => 2, 'conditionsPaiement' => 'Comptant']);
        $laborex = FournisseurFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Laborex Mali', 'telephone' => '+22344901010', 'email' => 'bamako@laborex.example', 'delaiLivraison' => 1, 'conditionsPaiement' => '30 jours fin de mois']);

        $produits = [
            ['Doliprane', 'Paracétamol', '500 mg', 'Comprimé', 'Boîte de 16', 'Antalgiques', 'E1-R1', $ppm, 1150, 1500, false, true],
            ['Efferalgan', 'Paracétamol', '1 g', 'Comprimé effervescent', 'Boîte de 8', 'Antalgiques', 'E1-R1', $laborex, 1600, 2100, false, true],
            ['Ibuprofène Biogaran', 'Ibuprofène', '400 mg', 'Comprimé', 'Boîte de 30', 'Antalgiques', 'E1-R1', $laborex, 1400, 1850, false, true],
            ['Amoxicilline', 'Amoxicilline', '500 mg', 'Gélule', 'Boîte de 12', 'Antibiotiques', 'E1-R2', $ppm, 1800, 2400, true, true],
            ['Augmentin', 'Amoxicilline + acide clavulanique', '1 g', 'Comprimé', 'Boîte de 8', 'Antibiotiques', 'E1-R2', $laborex, 5200, 6800, true, true],
            ['Coartem', 'Artéméther + luméfantrine', '20/120 mg', 'Comprimé', 'Boîte de 24', 'Antipaludiques', 'E2-R1', $ppm, 2900, 3800, true, true],
            ['Smecta', 'Diosmectite', '3 g', 'Sachet', 'Boîte de 30', 'Gastro-entérologie', 'R1', $laborex, 2300, 3000, false, false],
            ['Oméprazole', 'Oméprazole', '20 mg', 'Gélule', 'Boîte de 14', 'Gastro-entérologie', 'R1', $ppm, 1700, 2250, true, true],
            ['Insuline Actrapid', 'Insuline humaine', '100 UI/ml', 'Solution injectable', 'Flacon de 10 ml', 'Gastro-entérologie', 'F1', $laborex, 7800, 9500, true, true],
            ['Crème solaire SPF 50', null, '50 ml', 'Crème', 'Tube', 'Parapharmacie', 'R1', $laborex, 4200, 5500, false, false],
        ];
        // Prix de vente fixés par l'AMO, différents du prix de la pharmacie : le taux AMO s'applique sur ces prix.
        $prixAmo = ['Doliprane' => 1350, 'Amoxicilline' => 2100, 'Coartem' => 3500];
        $catalogue = [];
        foreach ($produits as [$nom, $dci, $dosage, $nomForme, $conditionnement, $categorie, $etagere, $fournisseur, $achat, $vente, $ordonnance, $amo]) {
            $catalogue[$nom] = ProduitFactory::createOne([
                'pharmacie' => $pharmacie, 'nomCommercial' => $nom, 'dci' => $dci, 'dosage' => $dosage, 'forme' => $forme($nomForme),
                'conditionnement' => $conditionnement, 'categorie' => $categories[$categorie], 'etagere' => $etageres[$etagere],
                'fournisseurHabituel' => $fournisseur, 'prixAchat' => $achat, 'prixVente' => $vente, 'seuilAlerte' => 10, 'stockMax' => 60,
                'ordonnanceObligatoire' => $ordonnance, 'remboursableAmo' => $amo, 'prixVenteAmo' => $prixAmo[$nom] ?? null, 'tauxTva' => 'Parapharmacie' === $categorie ? 18 : 0,
            ]);
        }

        // Stock (Lot 3) : de quoi voir chaque alerte. Augmentin reste en rupture.
        foreach ([
            ['Doliprane', 'DP24A', '+60 days', 30], ['Doliprane', 'DP24B', '+20 months', 20],
            ['Efferalgan', 'EF311', '+14 months', 8], ['Ibuprofène Biogaran', 'IB772', '+2 years', 40],
            ['Amoxicilline', 'AX905', '+11 months', 25], ['Coartem', 'CO118', '+18 months', 35],
            ['Oméprazole', 'OM450', '+9 months', 30], ['Insuline Actrapid', 'IN027', '+45 days', 12],
            ['Crème solaire SPF 50', 'CS2026', '+2 years', 15],
        ] as [$nom, $numero, $peremption, $quantite]) {
            $produit = $catalogue[$nom];
            $this->stock->entrer($produit, $numero, new \DateTimeImmutable('today '.$peremption), $quantite, (int) $produit->getPrixAchat(), $produit->getFournisseurHabituel(), motif: 'Stock initial');
        }
        // Lot périmé à détruire, et produit dormant (reçu il y a 4 mois, jamais vendu).
        LotFactory::createOne(['produit' => $catalogue['Amoxicilline'], 'numero' => 'AX601', 'datePeremption' => new \DateTimeImmutable('today -15 days'), 'quantiteInitiale' => 6, 'prixAchat' => 1750, 'fournisseur' => $ppm]);
        LotFactory::createOne(['produit' => $catalogue['Smecta'], 'numero' => 'SM118', 'datePeremption' => new \DateTimeImmutable('today +1 year'), 'quantiteInitiale' => 20, 'prixAchat' => 2300, 'dateReception' => new \DateTimeImmutable('today -4 months'), 'fournisseur' => $laborex]);

        $inps = \Zenstruck\Foundry\Persistence\repository(\App\Entity\OrganismeAmo::class)->findOneBy(['code' => 'INPS']);
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Mariam Diallo', 'telephone' => '+22376554433', 'privilegie' => true, 'organismeAmo' => $inps, 'numeroAssure' => 'INPS-0045871']);
        $cmss = \Zenstruck\Foundry\Persistence\repository(\App\Entity\OrganismeAmo::class)->findOneBy(['code' => 'CMSS']);
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Oumar Sidibé', 'telephone' => '+22365443322', 'organismeAmo' => $cmss, 'numeroAssure' => 'CMSS-1187-22']);
        // Autre assurance : une ONG inscrit ses employés auprès d'une mutuelle qui prend en charge 80 % du prix de la pharmacie.
        $mutuelle = (new \App\Entity\OrganismeAmo())->setNom('Mutuelle Santé Sahel (démo)')->setCode('MSS')->setType(\App\Enum\TypeOrganisme::Assurance);
        $this->em->persist($mutuelle);
        $this->em->persist((new \App\Entity\TauxAmo())->setOrganisme($mutuelle)->setTaux(80)->setDateEffet(new \DateTimeImmutable('first day of january this year'))->setPharmacie($pharmacie));
        $this->em->flush();
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Fatoumata Keïta', 'telephone' => '+22370334455', 'organismeAmo' => $mutuelle, 'numeroAssure' => 'MSS-2041', 'entreprise' => 'ONG Santé pour tous']);
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Sékou Traoré', 'telephone' => '+22366112233']);
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Aïssata Cissé', 'telephone' => '+22379887766', 'privilegie' => true]);

        return $catalogue;
    }

    /**
     * Caisse (Lot 4) : codes PIN de l'équipe et une session clôturée de Moussa avec cinq ventes,
     * dont trois ventes AMO et une vente annulée. Rôle caissier : la session ouverte d'Awa avec les ventes
     * que Seydou lui a envoyées.
     * AMO (Lot 5) : un bordereau INPS transmis et réglé en partie ; la créance CMSS reste en attente.
     *
     * @param array<string, \App\Entity\Produit> $catalogue
     */
    private function caisse(\App\Entity\Pharmacie $pharmacie, array $catalogue): void
    {
        $equipe = [];
        foreach (['a.traore@fleuve.ml' => '2580', 'f.keita@fleuve.ml' => '3690', 'm.coulibaly@fleuve.ml' => '1470', 's.diarra@fleuve.ml' => '1590', 'a.konate@fleuve.ml' => '4826'] as $email => $pin) {
            $utilisateur = \Zenstruck\Foundry\Persistence\repository(Utilisateur::class)->findOneBy(['email' => $email]);
            \assert($utilisateur instanceof Utilisateur);
            $this->codePin->definir($utilisateur, $pin);
            $equipe[$email] = $utilisateur;
        }
        $moussa = $equipe['m.coulibaly@fleuve.ml'];
        $mariam = \Zenstruck\Foundry\Persistence\repository(\App\Entity\Client::class)->findOneBy(['nom' => 'Mariam Diallo']);
        \assert($mariam instanceof \App\Entity\Client);

        $this->tenantContext->forcer($pharmacie);
        $session = $this->caisse->ouvrir($pharmacie, $moussa, 10000);

        $vente = $this->ventes->panierOuNouveau($moussa);
        $this->ventes->ajouter($vente, $catalogue['Doliprane'], 2);
        $this->ventes->ajouter($vente, $catalogue['Ibuprofène Biogaran']);
        $this->ventes->encaisser($vente, $moussa, $session, ['especes' => ['remis' => '5000']], null);

        $vente = $this->ventes->panierOuNouveau($moussa);
        $this->ventes->ajouter($vente, $catalogue['Coartem']);
        $this->ventes->ajouter($vente, $catalogue['Crème solaire SPF 50']);
        $this->ventes->definirVente($vente, TypeVente::Amo, $mariam, ['date' => new \DateTimeImmutable('today'), 'prescripteur' => 'Dr Sangaré', 'structure' => 'CSCOM de Badalabougou']);
        $this->ventes->remiseGlobale($vente, TypeRemise::Pourcentage, 5);
        $this->ventes->encaisser($vente, $moussa, $session, ['orange_money' => ['montant' => '6000', 'reference' => 'OM-DEMO-1']], null);

        $vente = $this->ventes->panierOuNouveau($moussa);
        $this->ventes->ajouter($vente, $catalogue['Coartem'], 2);
        $this->ventes->definirVente($vente, TypeVente::Amo, $mariam, ['numero' => 'ORD-5521', 'date' => new \DateTimeImmutable('today'), 'prescripteur' => 'Dr Sangaré', 'structure' => 'CSCOM de Badalabougou']);
        $this->ventes->encaisser($vente, $moussa, $session, ['especes' => ['remis' => '5000']], null);

        $oumar = \Zenstruck\Foundry\Persistence\repository(\App\Entity\Client::class)->findOneBy(['nom' => 'Oumar Sidibé']);
        \assert($oumar instanceof \App\Entity\Client);
        $vente = $this->ventes->panierOuNouveau($moussa);
        $this->ventes->ajouter($vente, $catalogue['Amoxicilline'], 2);
        $this->ventes->definirVente($vente, TypeVente::Amo, $oumar, ['date' => new \DateTimeImmutable('today -1 day'), 'prescripteur' => 'Dr Keïta', 'structure' => 'CHU du Point G']);
        $this->ventes->encaisser($vente, $moussa, $session, ['especes' => ['remis' => '2000']], null);

        $vente = $this->ventes->panierOuNouveau($moussa);
        $this->ventes->ajouter($vente, $catalogue['Efferalgan']);
        $this->ventes->encaisser($vente, $moussa, $session, [], null);
        $this->ventes->annuler($vente, 'Le client a changé d\'avis');

        // Comptage juste, au franc près, avec les plus grosses coupures possibles.
        $reste = $this->caisse->synthese($session)->especesAttendues();
        $comptage = [];
        foreach (\App\Entity\SessionCaisse::COUPURES as $cle => [, $valeur]) {
            if ($reste >= $valeur) {
                $comptage[$cle] = intdiv($reste, $valeur);
                $reste %= $valeur;
            }
        }
        $this->caisse->cloturer($session, $comptage, null);

        // Rôle caissier : Seydou valide ses ventes et les envoie à la caisse ; Awa, la caissière, les encaisse
        // dans sa session (encore ouverte). Une vente attend toujours, une autre a été annulée (client parti).
        $seydou = $equipe['s.diarra@fleuve.ml'];
        $awa = $equipe['a.konate@fleuve.ml'];
        $sessionAwa = $this->caisse->ouvrir($pharmacie, $awa, 15000);
        $envoyer = function (array $produits, ?\App\Entity\Client $client = null, array $ordonnance = []) use ($seydou, $catalogue): \App\Entity\Vente {
            $vente = $this->ventes->panierOuNouveau($seydou);
            foreach ($produits as $nom => $quantite) {
                $this->ventes->ajouter($vente, $catalogue[$nom], $quantite);
            }
            if (null !== $client) {
                $this->ventes->definirVente($vente, TypeVente::Amo, $client, $ordonnance);
            }
            $this->ventes->envoyerEnCaisse($vente, $seydou, null);

            return $vente;
        };

        $vente = $envoyer(['Doliprane' => 2, 'Smecta' => 1]);
        $this->ventes->encaisserEnCaisse($vente, $awa, $sessionAwa, ['especes' => ['remis' => '10000']]);

        // Assurance autre que l'AMO : la mutuelle de l'ONG prend 80 % en charge.
        $assuree = \Zenstruck\Foundry\Persistence\repository(\App\Entity\Client::class)->findOneBy(['nom' => 'Fatoumata Keïta', 'pharmacie' => $pharmacie]);
        \assert($assuree instanceof \App\Entity\Client);
        $vente = $envoyer(['Coartem' => 1, 'Oméprazole' => 1], $assuree, ['date' => new \DateTimeImmutable('today'), 'prescripteur' => 'Dr Coulibaly', 'structure' => 'Clinique Pasteur']);
        $this->ventes->encaisserEnCaisse($vente, $awa, $sessionAwa, ['orange_money' => ['montant' => (string) $vente->getMontantEncaisse(), 'reference' => 'OM-DEMO-2']]);

        $vente = $envoyer(['Ibuprofène Biogaran' => 1]);
        $this->ventes->annuler($vente, 'Client reparti sans payer');

        $envoyer(['Doliprane' => 1, 'Efferalgan' => 1]);

        $inps = $mariam->getOrganismeAmo();
        \assert($inps instanceof \App\Entity\OrganismeAmo);
        $bordereau = $this->bordereaux->creer($inps, new \DateTimeImmutable('first day of this month'), new \DateTimeImmutable('today'));
        $this->bordereaux->transmettre($bordereau);
        $premiere = $bordereau->getCreances()->first();
        \assert($premiere instanceof \App\Entity\CreanceAmo);
        $this->bordereaux->enregistrerReglement($bordereau, new \DateTimeImmutable('today'), $premiere->getMontant(), 'VIR-INPS-0912', [(int) $premiere->getId() => $premiere->getMontant()], []);
        $this->tenantContext->forcer(null);
    }

    /**
     * Commandes (Lot 6) : une commande Laborex reçue en partie (l'Augmentin reste attendu) et un brouillon PPM.
     *
     * @param array<string, \App\Entity\Produit> $catalogue
     */
    private function commandes(\App\Entity\Pharmacie $pharmacie, array $catalogue): void
    {
        $this->tenantContext->forcer($pharmacie);
        $laborex = $catalogue['Augmentin']->getFournisseurHabituel();
        $ppm = $catalogue['Coartem']->getFournisseurHabituel();
        \assert(null !== $laborex && null !== $ppm);

        $commande = $this->commandes->creer($laborex, [[$catalogue['Augmentin'], 40], [$catalogue['Efferalgan'], 30]]);
        $this->commandes->passer($commande);
        $efferalgan = $commande->ligneDe($catalogue['Efferalgan']);
        \assert(null !== $efferalgan);
        $this->receptions->receptionner($commande, new \DateTimeImmutable('today'), 'BL-LBX-2291', [
            ['ligne' => $efferalgan->getId(), 'quantite' => 30, 'lot' => 'EF412', 'peremption' => (new \DateTimeImmutable('today +20 months'))->format('Y-m-d'), 'prix' => 1580],
        ]);

        $this->commandes->creer($ppm, [[$catalogue['Coartem'], 25], [$catalogue['Amoxicilline'], 20]]);
        $this->tenantContext->forcer(null);
    }

    /**
     * Finances (Lot 7) : dépenses courantes des deux derniers mois et une recette hors ventes. Les recettes des ventes
     * et du règlement AMO sont déjà enregistrées par la caisse et l'AMO.
     */
    private function finances(\App\Entity\Pharmacie $pharmacie): void
    {
        $this->tenantContext->forcer($pharmacie);
        $categories = [];
        foreach ($this->depenses->categories() as $categorie) {
            $categories[$categorie->getNom()] = $categorie;
        }
        $debutMois = new \DateTimeImmutable('first day of this month');
        $aujourdhui = new \DateTimeImmutable('today');
        foreach ([
            [$debutMois->modify('-1 month'), 'Loyer', 'Loyer du local', 150000, ModeReglement::Virement, 'SCI Badalabougou'],
            [$debutMois->modify('-1 month +4 days'), 'Salaires', 'Salaires de l\'équipe', 420000, ModeReglement::Virement, null],
            [$debutMois->modify('-1 month +11 days'), 'Électricité', 'Facture EDM', 38500, ModeReglement::OrangeMoney, 'EDM-SA'],
            [$debutMois, 'Loyer', 'Loyer du local', 150000, ModeReglement::Virement, 'SCI Badalabougou'],
            [$aujourdhui, 'Transport', 'Course livraison grossiste', 3500, ModeReglement::Especes, 'Taxi'],
            [$aujourdhui, 'Eau', 'Facture SOMAGEP', 12800, ModeReglement::MoovMoney, 'SOMAGEP'],
        ] as [$date, $categorie, $libelle, $montant, $mode, $beneficiaire]) {
            $saisie = new SaisieDepense();
            $saisie->date = $date;
            $saisie->categorie = $categories[$categorie];
            $saisie->libelle = $libelle;
            $saisie->montant = $montant;
            $saisie->mode = $mode;
            $saisie->beneficiaire = $beneficiaire;
            $this->depenses->enregistrer($saisie);
        }
        $this->recettes->enregistrerManuelle($aujourdhui, 'Location de la vitrine à un laboratoire', 25000, ModeReglement::Especes);
        $this->tenantContext->forcer(null);
    }

    /**
     * Transferts de stock (ST-11) entre les deux officines de Mariam Dembélé : un transfert Kati → Koulikoro reçu
     * (le Coartem, inconnu à Koulikoro, a été ajouté à son catalogue), un expédié qui attend la confirmation de
     * Koulikoro et un en préparation.
     */
    private function transferts(\App\Entity\Pharmacie $kati, \App\Entity\Pharmacie $koulikoro, Utilisateur $mariam): void
    {
        $this->jetons->setToken(new UsernamePasswordToken($mariam, 'main', $mariam->getRoles()));
        $forme = static fn (string $nom) => \Zenstruck\Foundry\Persistence\repository(\App\Entity\FormeGalenique::class)->findOneBy(['nom' => $nom]);
        $produits = [
            'Doliprane' => ['Paracétamol', '500 mg', 'Comprimé', 'Boîte de 16', 1150, 1500, false],
            'Coartem' => ['Artéméther + luméfantrine', '20/120 mg', 'Comprimé', 'Boîte de 24', 2900, 3800, true],
            'Amoxicilline' => ['Amoxicilline', '500 mg', 'Gélule', 'Boîte de 12', 1800, 2400, true],
            'Smecta' => ['Diosmectite', '3 g', 'Sachet', 'Boîte de 30', 2300, 3000, false],
        ];
        $catalogue = static function (\App\Entity\Pharmacie $pharmacie, array $noms) use ($produits, $forme): array {
            $catalogue = [];
            foreach ($noms as $nom) {
                [$dci, $dosage, $nomForme, $conditionnement, $achat, $vente, $ordonnance] = $produits[$nom];
                $catalogue[$nom] = ProduitFactory::createOne([
                    'pharmacie' => $pharmacie, 'nomCommercial' => $nom, 'dci' => $dci, 'dosage' => $dosage, 'forme' => $forme($nomForme),
                    'conditionnement' => $conditionnement, 'prixAchat' => $achat, 'prixVente' => $vente, 'seuilAlerte' => 10, 'stockMax' => 60,
                    'ordonnanceObligatoire' => $ordonnance,
                ]);
            }

            return $catalogue;
        };

        $this->tenantContext->forcer($kati);
        $stockKati = $catalogue($kati, ['Doliprane', 'Coartem', 'Amoxicilline', 'Smecta']);
        foreach ([['Doliprane', 'DK101', '+5 months', 40], ['Doliprane', 'DK102', '+2 years', 60], ['Coartem', 'CK210', '+14 months', 50], ['Amoxicilline', 'AK330', '+10 months', 45], ['Smecta', 'SK440', '+18 months', 30]] as [$nom, $numero, $peremption, $quantite]) {
            $this->stock->entrer($stockKati[$nom], $numero, new \DateTimeImmutable('today '.$peremption), $quantite, (int) $stockKati[$nom]->getPrixAchat(), motif: 'Stock initial');
        }
        $this->tenantContext->forcer($koulikoro);
        $stockKoulikoro = $catalogue($koulikoro, ['Doliprane', 'Amoxicilline']);
        $this->stock->entrer($stockKoulikoro['Doliprane'], 'DL901', new \DateTimeImmutable('today +8 months'), 4, 1150, motif: 'Stock initial');

        $this->tenantContext->forcer($kati);
        $recu = $this->transferts->creer($koulikoro, 'Dépannage : Koulikoro en rupture de Coartem');
        $this->transferts->ajouter($recu, $stockKati['Coartem'], 12);
        $this->transferts->ajouter($recu, $stockKati['Doliprane'], 20);
        $this->transferts->expedier($recu);
        $this->tenantContext->forcer($koulikoro);
        $this->transferts->receptionner($recu);

        $this->tenantContext->forcer($kati);
        $attendu = $this->transferts->creer($koulikoro);
        $this->transferts->ajouter($attendu, $stockKati['Amoxicilline'], 15);
        $this->transferts->expedier($attendu);
        $prepare = $this->transferts->creer($koulikoro);
        $this->transferts->ajouter($prepare, $stockKati['Smecta'], 6);

        $this->tenantContext->forcer(null);
        $this->jetons->setToken(null);
    }

    /**
     * @param list<array{string, string, string}> $membres
     */
    private function equipe(\App\Entity\Pharmacie $pharmacie, string $email, string $nom, array $membres): Utilisateur
    {
        $proprietaire = UtilisateurFactory::createOne(['email' => $email, 'nom' => $nom, 'role' => Utilisateur::ROLE_PROPRIETAIRE]);
        AffectationFactory::createOne(['utilisateur' => $proprietaire, 'pharmacie' => $pharmacie]);
        foreach ($membres as [$emailMembre, $nomMembre, $role]) {
            AffectationFactory::createOne(['utilisateur' => UtilisateurFactory::createOne(['email' => $emailMembre, 'nom' => $nomMembre, 'role' => $role]), 'pharmacie' => $pharmacie]);
        }

        return $proprietaire;
    }
}
