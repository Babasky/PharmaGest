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
    /** Code de l'étagère proposée pour chaque catégorie. */
    private const ETAGERES = [
        'Médicaments > Antalgiques' => 'E1-R1',
        'Médicaments > Antibiotiques' => 'E1-R2',
        'Médicaments > Antipaludiques' => 'E1-R3',
        'Médicaments > Gastro-entérologie' => 'E2-R1',
        'Médicaments > Cardiologie' => 'E2-R2',
        'Médicaments > Diabétologie' => 'F1',
        'Médicaments > ORL et respiratoire' => 'E2-R3',
        'Médicaments > Dermatologie' => 'E3-R1',
        'Médicaments > Vitamines et minéraux' => 'E3-R2',
        'Parapharmacie' => 'P1',
    ];

    /** @var list<string> */
    private const FOURNISSEURS = [
        'Sahel Pharma Distribution', 'Djoliba Médical', 'Niger Santé Grossiste', 'Kayes Pharma', 'Sikasso Médicaments',
        'Bamako Répartition', 'Mopti Pharma Services', 'Ségou Santé Distribution', 'Koulikoro Médical', 'Tombouctou Pharma',
    ];

    /**
     * Nom, DCI, forme, dosage, conditionnement, catégorie (clé de {@see self::ETAGERES}), n° de fournisseur,
     * prix d'achat, prix de vente, ordonnance obligatoire, remboursable, prix de vente AMO (0 : aucun).
     *
     * @var list<array{string, ?string, string, string, string, string, int, int, int, bool, bool, int}>
     */
    private const PRODUITS = [
        // Antalgiques
        ['Paracétamol Denk', 'Paracétamol', 'Comprimé', '500 mg', 'Boîte de 20', 'Médicaments > Antalgiques', 0, 450, 650, false, true, 600],
        ['Doliprane', 'Paracétamol', 'Comprimé', '1 g', 'Boîte de 8', 'Médicaments > Antalgiques', 1, 1150, 1500, false, true, 1350],
        ['Efferalgan', 'Paracétamol', 'Comprimé effervescent', '500 mg', 'Boîte de 16', 'Médicaments > Antalgiques', 1, 1300, 1750, false, true, 1600],
        ['Paracétamol sirop enfant', 'Paracétamol', 'Sirop', '120 mg/5 ml', 'Flacon de 100 ml', 'Médicaments > Antalgiques', 0, 700, 1000, false, true, 900],
        ['Ibuprofène Biogaran', 'Ibuprofène', 'Comprimé', '400 mg', 'Boîte de 30', 'Médicaments > Antalgiques', 2, 1400, 1850, false, true, 1700],
        ['Advil', 'Ibuprofène', 'Comprimé', '200 mg', 'Boîte de 20', 'Médicaments > Antalgiques', 1, 1700, 2300, false, false, 0],
        ['Diclofénac Denk', 'Diclofénac', 'Comprimé', '50 mg', 'Boîte de 30', 'Médicaments > Antalgiques', 0, 600, 900, true, true, 800],
        ['Voltarène Emulgel', 'Diclofénac', 'Gel', '1 %', 'Tube de 50 g', 'Médicaments > Antalgiques', 2, 2600, 3400, false, false, 0],
        ['Tramadol Arrow', 'Tramadol', 'Gélule', '50 mg', 'Boîte de 30', 'Médicaments > Antalgiques', 3, 1900, 2600, true, true, 2400],
        ['Aspirine du Rhône', 'Acide acétylsalicylique', 'Comprimé', '500 mg', 'Boîte de 20', 'Médicaments > Antalgiques', 1, 900, 1250, false, true, 1100],
        ['Spasfon', 'Phloroglucinol', 'Comprimé', '80 mg', 'Boîte de 30', 'Médicaments > Antalgiques', 2, 1800, 2400, false, true, 2200],
        ['Indométacine', 'Indométacine', 'Gélule', '25 mg', 'Boîte de 30', 'Médicaments > Antalgiques', 0, 800, 1150, true, true, 1000],
        // Antibiotiques
        ['Amoxicilline Denk', 'Amoxicilline', 'Gélule', '500 mg', 'Boîte de 12', 'Médicaments > Antibiotiques', 0, 1100, 1550, true, true, 1400],
        ['Amoxicilline suspension', 'Amoxicilline', 'Poudre pour suspension', '250 mg/5 ml', 'Flacon de 60 ml', 'Médicaments > Antibiotiques', 0, 900, 1300, true, true, 1150],
        ['Augmentin', 'Amoxicilline + acide clavulanique', 'Comprimé', '1 g', 'Boîte de 8', 'Médicaments > Antibiotiques', 1, 5200, 6800, true, true, 6200],
        ['Augmentin enfant', 'Amoxicilline + acide clavulanique', 'Poudre pour suspension', '100 mg/12,5 mg', 'Flacon de 60 ml', 'Médicaments > Antibiotiques', 1, 3900, 5100, true, true, 4700],
        ['Ciprofloxacine Denk', 'Ciprofloxacine', 'Comprimé', '500 mg', 'Boîte de 10', 'Médicaments > Antibiotiques', 0, 1300, 1800, true, true, 1600],
        ['Métronidazole', 'Métronidazole', 'Comprimé', '500 mg', 'Boîte de 20', 'Médicaments > Antibiotiques', 0, 500, 750, true, true, 700],
        ['Flagyl suspension', 'Métronidazole', 'Suspension buvable', '125 mg/5 ml', 'Flacon de 120 ml', 'Médicaments > Antibiotiques', 2, 1900, 2500, true, true, 2300],
        ['Cotrimoxazole', 'Sulfaméthoxazole + triméthoprime', 'Comprimé', '400/80 mg', 'Boîte de 20', 'Médicaments > Antibiotiques', 0, 350, 550, true, true, 500],
        ['Azithromycine Sandoz', 'Azithromycine', 'Comprimé', '500 mg', 'Boîte de 3', 'Médicaments > Antibiotiques', 3, 2100, 2900, true, true, 2600],
        ['Doxycycline', 'Doxycycline', 'Comprimé', '100 mg', 'Boîte de 10', 'Médicaments > Antibiotiques', 0, 700, 1000, true, true, 900],
        ['Ceftriaxone', 'Ceftriaxone', 'Poudre pour suspension', '1 g', 'Flacon + solvant', 'Médicaments > Antibiotiques', 3, 1200, 1700, true, true, 1500],
        ['Érythromycine', 'Érythromycine', 'Comprimé', '500 mg', 'Boîte de 20', 'Médicaments > Antibiotiques', 0, 1600, 2200, true, true, 2000],
        ['Gentamicine', 'Gentamicine', 'Solution injectable', '80 mg/2 ml', 'Boîte de 1 ampoule', 'Médicaments > Antibiotiques', 4, 300, 450, true, true, 400],
        // Antipaludiques
        ['Coartem', 'Artéméther + luméfantrine', 'Comprimé', '20/120 mg', 'Boîte de 24', 'Médicaments > Antipaludiques', 1, 2900, 3800, true, true, 3500],
        ['Coartem dispersible', 'Artéméther + luméfantrine', 'Comprimé', '20/120 mg', 'Boîte de 6', 'Médicaments > Antipaludiques', 1, 1100, 1500, true, true, 1350],
        ['Artesunate + amodiaquine', 'Artésunate + amodiaquine', 'Comprimé', '100/270 mg', 'Boîte de 6', 'Médicaments > Antipaludiques', 0, 1000, 1400, true, true, 1250],
        ['Artesunate injectable', 'Artésunate', 'Solution injectable', '60 mg', 'Flacon + solvant', 'Médicaments > Antipaludiques', 0, 2200, 2900, true, true, 2700],
        ['Quinine Lafran', 'Quinine', 'Comprimé', '300 mg', 'Boîte de 20', 'Médicaments > Antipaludiques', 4, 1500, 2000, true, true, 1850],
        ['Quinine injectable', 'Quinine', 'Solution injectable', '400 mg/4 ml', 'Boîte de 1 ampoule', 'Médicaments > Antipaludiques', 4, 400, 600, true, true, 550],
        ['Sulfadoxine-pyriméthamine', 'Sulfadoxine + pyriméthamine', 'Comprimé', '500/25 mg', 'Boîte de 3', 'Médicaments > Antipaludiques', 0, 250, 400, false, true, 350],
        ['Malarone', 'Atovaquone + proguanil', 'Comprimé', '250/100 mg', 'Boîte de 12', 'Médicaments > Antipaludiques', 2, 14500, 18500, true, false, 0],
        ['Dihydroartémisinine-pipéraquine', 'Dihydroartémisinine + pipéraquine', 'Comprimé', '40/320 mg', 'Boîte de 9', 'Médicaments > Antipaludiques', 3, 2600, 3500, true, true, 3200],
        ['Moustiquaire imprégnée', null, 'Dispositif médical', '2 places', 'Unité', 'Médicaments > Antipaludiques', 5, 2500, 3500, false, false, 0],
        // Gastro-entérologie
        ['Smecta', 'Diosmectite', 'Sachet', '3 g', 'Boîte de 30', 'Médicaments > Gastro-entérologie', 1, 2300, 3000, false, true, 2800],
        ['SRO Orasel', 'Sels de réhydratation orale', 'Sachet', '20,5 g', 'Boîte de 10', 'Médicaments > Gastro-entérologie', 0, 600, 850, false, true, 800],
        ['Zinc Denk', 'Zinc', 'Comprimé', '20 mg', 'Boîte de 10', 'Médicaments > Gastro-entérologie', 0, 400, 600, false, true, 550],
        ['Oméprazole', 'Oméprazole', 'Gélule', '20 mg', 'Boîte de 14', 'Médicaments > Gastro-entérologie', 2, 1700, 2250, true, true, 2000],
        ['Maalox', 'Hydroxyde d\'aluminium + magnésium', 'Suspension buvable', '230/400 mg', 'Flacon de 250 ml', 'Médicaments > Gastro-entérologie', 1, 2100, 2800, false, false, 0],
        ['Gaviscon', 'Alginate de sodium', 'Suspension buvable', '500 mg', 'Flacon de 250 ml', 'Médicaments > Gastro-entérologie', 2, 3000, 3900, false, false, 0],
        ['Motilium', 'Dompéridone', 'Comprimé', '10 mg', 'Boîte de 40', 'Médicaments > Gastro-entérologie', 2, 1900, 2500, true, true, 2300],
        ['Imodium', 'Lopéramide', 'Gélule', '2 mg', 'Boîte de 20', 'Médicaments > Gastro-entérologie', 1, 1800, 2400, false, false, 0],
        ['Albendazole', 'Albendazole', 'Comprimé', '400 mg', 'Boîte de 1', 'Médicaments > Gastro-entérologie', 0, 200, 350, false, true, 300],
        ['Mébendazole', 'Mébendazole', 'Comprimé', '100 mg', 'Boîte de 6', 'Médicaments > Gastro-entérologie', 0, 250, 400, false, true, 350],
        ['Ranitidine', 'Ranitidine', 'Comprimé', '150 mg', 'Boîte de 20', 'Médicaments > Gastro-entérologie', 3, 900, 1300, true, true, 1150],
        // Cardiologie
        ['Amlodipine Denk', 'Amlodipine', 'Comprimé', '5 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 0, 1200, 1700, true, true, 1500],
        ['Amlodipine 10', 'Amlodipine', 'Comprimé', '10 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 0, 1500, 2100, true, true, 1900],
        ['Captopril', 'Captopril', 'Comprimé', '25 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 3, 800, 1150, true, true, 1000],
        ['Énalapril', 'Énalapril', 'Comprimé', '10 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 3, 1100, 1550, true, true, 1400],
        ['Hydrochlorothiazide', 'Hydrochlorothiazide', 'Comprimé', '25 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 0, 700, 1000, true, true, 900],
        ['Furosémide', 'Furosémide', 'Comprimé', '40 mg', 'Boîte de 20', 'Médicaments > Cardiologie', 0, 500, 750, true, true, 700],
        ['Aténolol', 'Aténolol', 'Comprimé', '50 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 3, 900, 1300, true, true, 1150],
        ['Losartan', 'Losartan', 'Comprimé', '50 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 2, 2400, 3200, true, true, 2900],
        ['Nifédipine LP', 'Nifédipine', 'Comprimé', '20 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 2, 1800, 2400, true, true, 2200],
        ['Aspirine Protect', 'Acide acétylsalicylique', 'Comprimé', '100 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 1, 1300, 1800, true, true, 1600],
        ['Atorvastatine', 'Atorvastatine', 'Comprimé', '20 mg', 'Boîte de 30', 'Médicaments > Cardiologie', 2, 3100, 4100, true, true, 3700],
        // Diabétologie
        ['Metformine Denk', 'Metformine', 'Comprimé', '500 mg', 'Boîte de 30', 'Médicaments > Diabétologie', 0, 900, 1300, true, true, 1150],
        ['Metformine 850', 'Metformine', 'Comprimé', '850 mg', 'Boîte de 30', 'Médicaments > Diabétologie', 0, 1100, 1550, true, true, 1400],
        ['Glibenclamide', 'Glibenclamide', 'Comprimé', '5 mg', 'Boîte de 30', 'Médicaments > Diabétologie', 0, 500, 750, true, true, 700],
        ['Gliclazide LM', 'Gliclazide', 'Comprimé', '30 mg', 'Boîte de 30', 'Médicaments > Diabétologie', 2, 2200, 2950, true, true, 2700],
        ['Insuline Actrapid', 'Insuline humaine', 'Solution injectable', '100 UI/ml', 'Flacon de 10 ml', 'Médicaments > Diabétologie', 1, 7800, 9500, true, true, 9000],
        ['Insuline Mixtard', 'Insuline humaine biphasique', 'Solution injectable', '100 UI/ml', 'Flacon de 10 ml', 'Médicaments > Diabétologie', 1, 7900, 9600, true, true, 9100],
        ['Bandelettes glycémie', null, 'Dispositif médical', '50 bandelettes', 'Boîte de 50', 'Médicaments > Diabétologie', 5, 9000, 12000, false, false, 0],
        ['Lecteur de glycémie', null, 'Dispositif médical', 'Unité', 'Coffret', 'Médicaments > Diabétologie', 5, 14000, 19000, false, false, 0],
        // ORL et respiratoire
        ['Salbutamol inhalateur', 'Salbutamol', 'Inhalateur', '100 µg/dose', 'Flacon de 200 doses', 'Médicaments > ORL et respiratoire', 2, 2100, 2800, true, true, 2600],
        ['Ventoline sirop', 'Salbutamol', 'Sirop', '2 mg/5 ml', 'Flacon de 150 ml', 'Médicaments > ORL et respiratoire', 2, 1800, 2400, true, true, 2200],
        ['Prednisolone', 'Prednisolone', 'Comprimé', '20 mg', 'Boîte de 20', 'Médicaments > ORL et respiratoire', 3, 1400, 1900, true, true, 1750],
        ['Carbocistéine sirop', 'Carbocistéine', 'Sirop', '5 %', 'Flacon de 200 ml', 'Médicaments > ORL et respiratoire', 1, 1500, 2000, false, false, 0],
        ['Toplexil', 'Oxomémazine', 'Sirop', '0,33 mg/ml', 'Flacon de 150 ml', 'Médicaments > ORL et respiratoire', 2, 2000, 2700, false, false, 0],
        ['Loratadine', 'Loratadine', 'Comprimé', '10 mg', 'Boîte de 10', 'Médicaments > ORL et respiratoire', 0, 600, 900, false, true, 800],
        ['Cétirizine', 'Cétirizine', 'Comprimé', '10 mg', 'Boîte de 15', 'Médicaments > ORL et respiratoire', 0, 700, 1000, false, true, 900],
        ['Sérum physiologique', 'Chlorure de sodium', 'Solution buvable', '0,9 %', 'Boîte de 30 unidoses', 'Médicaments > ORL et respiratoire', 4, 1200, 1700, false, false, 0],
        ['Rhinathiol', 'Carbocistéine', 'Sirop', '2 %', 'Flacon de 125 ml', 'Médicaments > ORL et respiratoire', 2, 1700, 2300, false, false, 0],
        ['Collyre Gentamicine', 'Gentamicine', 'Collyre', '0,3 %', 'Flacon de 5 ml', 'Médicaments > ORL et respiratoire', 4, 800, 1150, true, true, 1050],
        ['Otipax', 'Phénazone + lidocaïne', 'Gouttes', '4 %/1 %', 'Flacon de 16 g', 'Médicaments > ORL et respiratoire', 2, 2300, 3000, false, false, 0],
        // Dermatologie
        ['Bétaméthasone crème', 'Bétaméthasone', 'Crème', '0,05 %', 'Tube de 30 g', 'Médicaments > Dermatologie', 3, 900, 1300, true, true, 1150],
        ['Miconazole crème', 'Miconazole', 'Crème', '2 %', 'Tube de 30 g', 'Médicaments > Dermatologie', 3, 1000, 1400, false, true, 1250],
        ['Pommade Tétracycline', 'Tétracycline', 'Pommade', '3 %', 'Tube de 15 g', 'Médicaments > Dermatologie', 4, 500, 750, false, true, 700],
        ['Biseptine', 'Chlorhexidine', 'Solution buvable', '0,25 %', 'Flacon de 250 ml', 'Médicaments > Dermatologie', 2, 2200, 2900, false, false, 0],
        ['Bétadine dermique', 'Povidone iodée', 'Solution buvable', '10 %', 'Flacon de 125 ml', 'Médicaments > Dermatologie', 1, 1900, 2500, false, true, 2300],
        ['Benzoate de benzyle', 'Benzoate de benzyle', 'Solution buvable', '25 %', 'Flacon de 125 ml', 'Médicaments > Dermatologie', 4, 700, 1000, false, true, 900],
        ['Griséofulvine', 'Griséofulvine', 'Comprimé', '500 mg', 'Boîte de 30', 'Médicaments > Dermatologie', 3, 1800, 2400, true, true, 2200],
        ['Fucidine', 'Acide fusidique', 'Crème', '2 %', 'Tube de 15 g', 'Médicaments > Dermatologie', 2, 2500, 3300, true, false, 0],
        // Vitamines et minéraux
        ['Fer + acide folique', 'Fer + acide folique', 'Comprimé', '200/0,25 mg', 'Boîte de 30', 'Médicaments > Vitamines et minéraux', 0, 300, 500, false, true, 450],
        ['Acide folique', 'Acide folique', 'Comprimé', '5 mg', 'Boîte de 30', 'Médicaments > Vitamines et minéraux', 0, 250, 400, false, true, 350],
        ['Vitamine C', 'Acide ascorbique', 'Comprimé effervescent', '1 g', 'Tube de 20', 'Médicaments > Vitamines et minéraux', 1, 1400, 1900, false, false, 0],
        ['Vitamine A', 'Rétinol', 'Gélule', '200 000 UI', 'Boîte de 2', 'Médicaments > Vitamines et minéraux', 0, 150, 250, false, true, 200],
        ['Multivitamines sirop', 'Vitamines', 'Sirop', '—', 'Flacon de 150 ml', 'Médicaments > Vitamines et minéraux', 2, 1600, 2200, false, false, 0],
        ['Calcium + vitamine D3', 'Calcium + colécalciférol', 'Comprimé', '500 mg/400 UI', 'Boîte de 60', 'Médicaments > Vitamines et minéraux', 2, 3200, 4200, false, false, 0],
        ['Magnésium B6', 'Magnésium + pyridoxine', 'Comprimé', '48/5 mg', 'Boîte de 50', 'Médicaments > Vitamines et minéraux', 1, 2400, 3200, false, false, 0],
        ['Vitamine B complexe', 'Vitamines B1, B6, B12', 'Comprimé', '—', 'Boîte de 30', 'Médicaments > Vitamines et minéraux', 0, 600, 900, false, true, 800],
        // Parapharmacie
        ['Crème solaire SPF 50', null, 'Crème', '50 ml', 'Tube', 'Parapharmacie', 6, 4200, 5500, false, false, 0],
        ['Gel hydroalcoolique', null, 'Gel', '100 ml', 'Flacon', 'Parapharmacie', 6, 700, 1000, false, false, 0],
        ['Préservatifs', null, 'Dispositif médical', 'Standard', 'Boîte de 12', 'Parapharmacie', 7, 1200, 1700, false, false, 0],
        ['Test de grossesse', null, 'Dispositif médical', 'Unité', 'Boîte de 1', 'Parapharmacie', 7, 800, 1200, false, false, 0],
        ['Thermomètre digital', null, 'Dispositif médical', 'Unité', 'Étui', 'Parapharmacie', 7, 1800, 2500, false, false, 0],
        ['Compresses stériles', null, 'Dispositif médical', '10 x 10 cm', 'Boîte de 50', 'Parapharmacie', 8, 1300, 1800, false, false, 0],
        ['Seringues 5 ml', null, 'Dispositif médical', '5 ml', 'Boîte de 100', 'Parapharmacie', 8, 3500, 4800, false, false, 0],
        ['Lait de toilette bébé', null, 'Crème', '500 ml', 'Flacon', 'Parapharmacie', 9, 2600, 3500, false, false, 0],
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
     * @return list<array<string, string>>
     */
    public static function produits(): array
    {
        $lignes = [];
        foreach (self::PRODUITS as [$nom, $dci, $forme, $dosage, $conditionnement, $categorie, $fournisseur, $achat, $vente, $ordonnance, $remboursable, $prixAmo]) {
            $lignes[] = [
                'nom_commercial' => $nom,
                'dci' => $dci ?? '',
                'forme' => $forme,
                'dosage' => $dosage,
                'conditionnement' => $conditionnement,
                'categorie' => $categorie,
                'etagere' => self::ETAGERES[$categorie],
                'fournisseur' => self::FOURNISSEURS[$fournisseur],
                'prix_achat' => (string) $achat,
                'prix_vente' => (string) $vente,
                'tva' => 'Parapharmacie' === $categorie ? '18' : '0',
                'seuil_alerte' => $vente < 2000 ? '20' : '10',
                'stock_max' => $vente < 2000 ? '100' : '40',
                'ordonnance_obligatoire' => $ordonnance ? 'oui' : 'non',
                'remboursable_amo' => $remboursable ? 'oui' : 'non',
                'prix_vente_amo' => $prixAmo > 0 ? (string) $prixAmo : '',
            ];
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
