<?php

namespace App\Story;

use App\Entity\Offre;
use App\Entity\Utilisateur;
use App\Enum\MoyenPaiement;
use App\Enum\ZoneEtagere;
use App\Service\AbonnementService;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\CategorieFactory;
use App\Tests\Factory\ClientFactory;
use App\Tests\Factory\EtagereFactory;
use App\Tests\Factory\FournisseurFactory;
use App\Tests\Factory\PharmacieFactory;
use App\Tests\Factory\ProduitFactory;
use App\Tests\Factory\UtilisateurFactory;
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
    public function __construct(private readonly AbonnementService $abonnements)
    {
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
        ]);
        $this->abonnements->enregistrerPaiement($fleuve, $fleuve->getOffre(), 180000, MoyenPaiement::OrangeMoney, 'OM-2026-55871', new \DateTimeImmutable('today'), null);
        $this->catalogue($fleuve);

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
    }

    /**
     * Petit catalogue réaliste (prix publics indicatifs en FCFA) pour la recette des lots 2 à 4.
     */
    private function catalogue(\App\Entity\Pharmacie $pharmacie): void
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
            ['Doliprane', 'Paracétamol', '500 mg', 'Comprimé', 'Boîte de 16', '3400930000011', 'Antalgiques', 'E1-R1', $ppm, 1150, 1500, false, true],
            ['Efferalgan', 'Paracétamol', '1 g', 'Comprimé effervescent', 'Boîte de 8', '3400930000028', 'Antalgiques', 'E1-R1', $laborex, 1600, 2100, false, true],
            ['Ibuprofène Biogaran', 'Ibuprofène', '400 mg', 'Comprimé', 'Boîte de 30', '3400930000035', 'Antalgiques', 'E1-R1', $laborex, 1400, 1850, false, true],
            ['Amoxicilline', 'Amoxicilline', '500 mg', 'Gélule', 'Boîte de 12', '3400930000042', 'Antibiotiques', 'E1-R2', $ppm, 1800, 2400, true, true],
            ['Augmentin', 'Amoxicilline + acide clavulanique', '1 g', 'Comprimé', 'Boîte de 8', '3400930000059', 'Antibiotiques', 'E1-R2', $laborex, 5200, 6800, true, true],
            ['Coartem', 'Artéméther + luméfantrine', '20/120 mg', 'Comprimé', 'Boîte de 24', '3400930000066', 'Antipaludiques', 'E2-R1', $ppm, 2900, 3800, true, true],
            ['Smecta', 'Diosmectite', '3 g', 'Sachet', 'Boîte de 30', '3400930000073', 'Gastro-entérologie', 'R1', $laborex, 2300, 3000, false, false],
            ['Oméprazole', 'Oméprazole', '20 mg', 'Gélule', 'Boîte de 14', '3400930000080', 'Gastro-entérologie', 'R1', $ppm, 1700, 2250, true, true],
            ['Insuline Actrapid', 'Insuline humaine', '100 UI/ml', 'Solution injectable', 'Flacon de 10 ml', '3400930000097', 'Gastro-entérologie', 'F1', $laborex, 7800, 9500, true, true],
            ['Crème solaire SPF 50', null, '50 ml', 'Crème', 'Tube', '3400930000103', 'Parapharmacie', 'R1', $laborex, 4200, 5500, false, false],
        ];
        foreach ($produits as [$nom, $dci, $dosage, $nomForme, $conditionnement, $codeBarres, $categorie, $etagere, $fournisseur, $achat, $vente, $ordonnance, $amo]) {
            ProduitFactory::createOne([
                'pharmacie' => $pharmacie, 'nomCommercial' => $nom, 'dci' => $dci, 'dosage' => $dosage, 'forme' => $forme($nomForme),
                'conditionnement' => $conditionnement, 'codeBarres' => $codeBarres, 'categorie' => $categories[$categorie], 'etagere' => $etageres[$etagere],
                'fournisseurHabituel' => $fournisseur, 'prixAchat' => $achat, 'prixVente' => $vente, 'seuilAlerte' => 10, 'stockMax' => 60,
                'ordonnanceObligatoire' => $ordonnance, 'remboursableAmo' => $amo, 'tauxTva' => 'Parapharmacie' === $categorie ? 18 : 0,
            ]);
        }

        $inps = \Zenstruck\Foundry\Persistence\repository(\App\Entity\OrganismeAmo::class)->findOneBy(['code' => 'INPS']);
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Mariam Diallo', 'telephone' => '+22376554433', 'privilegie' => true, 'organismeAmo' => $inps, 'numeroAssure' => 'INPS-0045871']);
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Sékou Traoré', 'telephone' => '+22366112233']);
        ClientFactory::createOne(['pharmacie' => $pharmacie, 'nom' => 'Aïssata Cissé', 'telephone' => '+22379887766', 'privilegie' => true]);
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
