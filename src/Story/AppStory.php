<?php

namespace App\Story;

use App\Entity\Offre;
use App\Entity\Utilisateur;
use App\Enum\MoyenPaiement;
use App\Service\AbonnementService;
use App\Tests\Factory\AffectationFactory;
use App\Tests\Factory\PharmacieFactory;
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
