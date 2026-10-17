<?php

namespace App\Import;

/**
 * Lignes d'exemple des fichiers modèles d'import : une pharmacie peut s'en servir telles quelles pour démarrer,
 * ou les remplacer par ses propres données. Toutes s'importent sans erreur.
 *
 * Les noms de fournisseurs, de clients et les numéros de téléphone sont fictifs.
 */
final class ExemplesImport
{
    /** Liste AMO convertie par bin/liste-amo.py (voir docs/hypotheses.md). */
    private const LISTE_AMO = 'liste-amo-2021-08.csv';

    /** @var list<string> */
    private const FOURNISSEURS = [
        'Sahel Pharma Distribution', 'Djoliba Médical', 'Niger Santé Grossiste', 'Kayes Pharma', 'Sikasso Médicaments',
        'Bamako Répartition', 'Mopti Pharma Services', 'Ségou Santé Distribution', 'Koulikoro Médical', 'Tombouctou Pharma',
    ];

    /**
     * Nom, téléphone, privilégié, organisme (code), n° d'assuré, entreprise.
     *
     * @var list<array{string, string, bool, ?string, ?string, ?string}>
     */
    private const CLIENTS = [
        ['Mariam Diallo', '76 12 34 56', true, 'INPS', 'INPS-0045871', null],
        ['Oumar Sidibé', '65 44 33 22', false, 'CMSS', 'CMSS-1187-22', null],
        ['Aïssata Cissé', '79 88 77 66', true, null, null, null],
        ['Sékou Traoré', '66 11 22 33', false, null, null, null],
        ['Fatoumata Keïta', '70 33 44 55', false, 'INPS', 'INPS-0052310', 'Société Sahel Coton'],
        ['Moussa Coulibaly', '76 20 31 42', false, 'CMSS', 'CMSS-2034-17', null],
        ['Awa Konaté', '77 45 12 98', true, 'INPS', 'INPS-0061284', 'Banque du Fleuve'],
        ['Bakary Dembélé', '69 50 61 72', false, null, null, null],
        ['Kadiatou Sangaré', '78 14 25 36', false, 'CMSS', 'CMSS-0912-08', null],
        ['Ibrahim Touré', '75 63 74 85', false, null, null, null],
        ['Djénéba Doumbia', '71 96 85 74', true, null, null, null],
        ['Mamadou Kané', '73 21 43 65', false, 'INPS', 'INPS-0047755', 'Transports Niger'],
        ['Hawa Maïga', '74 32 54 76', false, null, null, null],
        ['Adama Sylla', '60 87 65 43', false, 'CMSS', 'CMSS-1450-11', null],
        ['Rokia Bagayoko', '62 13 57 91', false, null, null, null],
        ['Souleymane Diarra', '63 24 68 02', false, 'INPS', 'INPS-0073129', 'Mines de Kéniéba'],
        ['Assétou Samaké', '64 35 79 13', true, null, null, null],
        ['Youssouf Camara', '67 46 80 24', false, null, null, null],
        ['Nana Kouyaté', '68 57 91 35', false, 'CMSS', 'CMSS-0388-05', null],
        ['Boubacar Ba', '90 68 02 46', false, null, null, null],
        ['Salimata Fofana', '91 79 13 57', false, 'INPS', 'INPS-0080466', 'Hôtel Djoliba'],
        ['Cheick Tounkara', '92 80 24 68', false, null, null, null],
        ['Oumou Sacko', '93 91 35 79', true, null, null, null],
        ['Lassana Diakité', '94 02 46 80', false, 'CMSS', 'CMSS-1999-14', null],
        ['Mariam Coulibaly', '95 13 57 91', false, null, null, null],
        ['Drissa Togola', '96 24 68 02', false, null, null, null],
        ['Bintou Sanogo', '97 35 79 13', false, 'INPS', 'INPS-0091203', 'ONG Santé pour tous'],
        ['Modibo Keïta', '98 46 80 24', false, null, null, null],
        ['Fanta Haïdara', '99 57 91 35', false, null, null, null],
        ['Abdoulaye Guindo', '89 68 02 46', false, 'CMSS', 'CMSS-0754-09', null],
    ];

    /**
     * Le modèle produits reprend la liste des médicaments et dispositifs médicaux admis à l'AMO (CANAM, août 2021,
     * arrêté n°2021-4622/MSDS-SG), convertie depuis le fichier Excel de la CANAM : prix public en prix de vente, prix
     * base de remboursement en prix de vente AMO. Étagère, fournisseur, prix d'achat et seuils sont à compléter.
     *
     * @return list<array<string, string>>
     */
    public static function produits(): array
    {
        $fichier = new \SplFileObject(__DIR__.'/'.self::LISTE_AMO);
        $fichier->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD | \SplFileObject::DROP_NEW_LINE);
        $fichier->setCsvControl(',', '"', '');
        $entetes = null;
        $lignes = [];
        foreach ($fichier as $ligne) {
            /** @var list<string> $ligne */
            if (null === $entetes) {
                $entetes = $ligne;
                continue;
            }
            $lignes[] = array_combine($entetes, $ligne);
        }

        return $lignes;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function fournisseurs(): array
    {
        $villes = ['Bamako, ACI 2000', 'Bamako, Quinzambougou', 'Bamako, Hamdallaye', 'Kayes, centre-ville', 'Sikasso, Wayerma',
            'Bamako, Badalabougou', 'Mopti, Sévaré', 'Ségou, Pélengana', 'Koulikoro, Souban', 'Tombouctou, Abaradjou'];
        $lignes = [];
        foreach (self::FOURNISSEURS as $i => $nom) {
            $lignes[] = [
                'nom' => $nom,
                'contact' => 0 === $i % 2 ? 'Service commandes' : 'Responsable des ventes',
                'telephone' => \sprintf('20 2%d %02d %02d', $i % 10, 10 + 7 * $i, 30 + 3 * $i),
                'email' => 'commandes@'.strtolower((string) preg_replace('/[^a-z]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $nom) ?: $nom)).'.example',
                'adresse' => $villes[$i],
                'delai_livraison' => (string) (1 + $i % 4),
                'conditions_paiement' => ['Comptant', '30 jours fin de mois', '15 jours', 'Comptant à la livraison'][$i % 4],
            ];
        }

        return $lignes;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function clients(): array
    {
        $lignes = [];
        foreach (self::CLIENTS as $client) {
            $lignes[] = [
                'nom' => $client[0],
                'telephone' => $client[1],
                'privilegie' => $client[2] ? 'oui' : 'non',
                'organisme_amo' => (string) $client[3],
                'numero_assure' => (string) $client[4],
                'entreprise' => (string) $client[5],
            ];
        }

        return $lignes;
    }
}
