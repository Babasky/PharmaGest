<?php

namespace App\Import;

use App\Entity\Categorie;
use App\Entity\Etagere;
use App\Entity\FormeGalenique;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Repository\CategorieRepository;
use App\Repository\EtagereRepository;
use App\Repository\FormeGaleniqueRepository;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import du catalogue. Un produit existant est mis à jour s'il a le même code-barres
 * (ou, sans code-barres, le même nom commercial et le même dosage). Les catégories, étagères
 * et fournisseurs inconnus sont créés.
 */
final class ImportProduits extends AbstractDefinitionImport
{
    /** @var array<string, Categorie|Etagere|Fournisseur> éléments créés pendant l'import */
    private array $crees = [];

    /** @var array<string, FormeGalenique>|null */
    private ?array $formes = null;

    public function __construct(
        EntityManagerInterface $em,
        ValidatorInterface $validateur,
        private readonly ProduitRepository $produits,
        private readonly CategorieRepository $categories,
        private readonly EtagereRepository $etageres,
        private readonly FournisseurRepository $fournisseurs,
        private readonly FormeGaleniqueRepository $formesRepository,
    ) {
        parent::__construct($em, $validateur);
    }

    public function code(): string
    {
        return 'produits';
    }

    public function libelle(): string
    {
        return 'Produits';
    }

    public function colonnes(): array
    {
        return [
            new Colonne('nom_commercial', 'Nom commercial', true, 'Nom sur la boîte.', 'Doliprane'),
            new Colonne('dci', 'DCI', false, 'Molécule.', 'Paracétamol'),
            new Colonne('forme', 'Forme', false, 'Une forme galénique de la liste PharmaGest.', 'Comprimé'),
            new Colonne('dosage', 'Dosage', false, '', '500 mg'),
            new Colonne('conditionnement', 'Conditionnement', false, '', 'Boîte de 16'),
            new Colonne('code_barres', 'Code-barres', false, 'Sert à reconnaître un produit déjà présent.', '3400930000000'),
            new Colonne('categorie', 'Catégorie', false, '« Principale » ou « Principale > Sous-catégorie ». Créée si elle n\'existe pas.', 'Médicaments > Antalgiques'),
            new Colonne('etagere', 'Étagère', false, 'Code de l\'étagère. Créée si elle n\'existe pas.', 'E1-R3'),
            new Colonne('fournisseur', 'Fournisseur', false, 'Nom du fournisseur habituel. Créé s\'il n\'existe pas.', 'PPM'),
            new Colonne('prix_achat', 'Prix d\'achat', false, 'En FCFA, sans décimale.', '1150'),
            new Colonne('prix_vente', 'Prix de vente', true, 'En FCFA, sans décimale.', '1500'),
            new Colonne('tva', 'TVA', false, '0 ou 18.', '0'),
            new Colonne('seuil_alerte', 'Seuil d\'alerte', false, 'En unités.', '10'),
            new Colonne('stock_max', 'Stock maximum', false, 'En unités.', '60'),
            new Colonne('ordonnance_obligatoire', 'Ordonnance obligatoire', false, 'oui ou non.', 'non'),
            new Colonne('remboursable_amo', 'Remboursable AMO', false, 'oui ou non.', 'oui'),
            new Colonne('prix_vente_amo', 'Prix de vente AMO', false, 'Prix fixé par l\'AMO, en FCFA. Vide : prix de la pharmacie.', '1400'),
        ];
    }

    public function reinitialiser(): void
    {
        parent::reinitialiser();
        $this->crees = [];
        $this->formes = null;
    }

    public function exemples(): array
    {
        return ExemplesImport::produits();
    }

    public function preparer(int $numero, array $valeurs, bool $simulation): array
    {
        $erreurs = [];
        $remarques = [];
        $nom = Valeurs::texte($valeurs, 'nom_commercial');
        $dosage = Valeurs::texte($valeurs, 'dosage');
        $codeBarres = Valeurs::texte($valeurs, 'code_barres');
        $codeBarres = null === $codeBarres ? null : str_replace(' ', '', $codeBarres);
        $libelle = trim(($nom ?? '(sans nom)').' '.($dosage ?? ''));

        if (null === $nom) {
            $erreurs[] = 'Le nom commercial est obligatoire.';
        }
        $this->dejaVu(null !== $codeBarres ? 'cb:'.$codeBarres : 'nom:'.$nom.'|'.$dosage, $numero, null !== $codeBarres ? 'Ce code-barres' : 'Ce produit', $erreurs);

        $produit = (null !== $codeBarres ? $this->produits->parCodeBarres($codeBarres) : null)
            ?? (null !== $nom ? $this->produits->findOneBy(['nomCommercial' => $nom, 'dosage' => $dosage]) : null)
            ?? new Produit();

        $produit->setNomCommercial((string) $nom)
            ->setDci(Valeurs::texte($valeurs, 'dci'))
            ->setDosage($dosage)
            ->setConditionnement(Valeurs::texte($valeurs, 'conditionnement'))
            ->setCodeBarres($codeBarres)
            ->setOrdonnanceObligatoire(Valeurs::booleen($valeurs, 'ordonnance_obligatoire', 'Ordonnance obligatoire', $erreurs))
            ->setRemboursableAmo(Valeurs::booleen($valeurs, 'remboursable_amo', 'Remboursable AMO', $erreurs));

        $produit->setPrixVente(Valeurs::entier($valeurs, 'prix_vente', 'Prix de vente', $erreurs));
        if (null !== ($prixAchat = Valeurs::entier($valeurs, 'prix_achat', 'Prix d\'achat', $erreurs))) {
            $produit->setPrixAchat($prixAchat);
        }
        if (null !== ($seuil = Valeurs::entier($valeurs, 'seuil_alerte', 'Seuil d\'alerte', $erreurs))) {
            $produit->setSeuilAlerte($seuil);
        }
        $produit->setStockMax(Valeurs::entier($valeurs, 'stock_max', 'Stock maximum', $erreurs));
        if (\array_key_exists('prix_vente_amo', $valeurs)) {
            // Colonne absente du fichier : le prix AMO déjà saisi est conservé.
            $produit->setPrixVenteAmo(Valeurs::entier($valeurs, 'prix_vente_amo', 'Prix de vente AMO', $erreurs));
        }

        $tva = Valeurs::entier($valeurs, 'tva', 'TVA', $erreurs) ?? 0;
        if (!\in_array($tva, Produit::TAUX_TVA, true)) {
            $erreurs[] = \sprintf('TVA : %d %% n\'est pas un taux accepté (0 ou 18).', $tva);
        } else {
            $produit->setTauxTva($tva);
        }

        if (null !== ($forme = Valeurs::texte($valeurs, 'forme'))) {
            $trouvee = $this->formes()[mb_strtolower($forme)] ?? null;
            if (null === $trouvee) {
                $erreurs[] = \sprintf('Forme inconnue : « %s ». Formes acceptées : %s.', $forme, implode(', ', array_map(static fn (FormeGalenique $f) => $f->getNom(), $this->formes())));
            }
            $produit->setForme($trouvee);
        }

        if (null !== ($categorie = Valeurs::texte($valeurs, 'categorie'))) {
            $produit->setCategorie($this->categorie($categorie, $simulation, $remarques));
        }
        if (null !== ($etagere = Valeurs::texte($valeurs, 'etagere'))) {
            $produit->setEtagere($this->etagere($etagere, $simulation, $remarques));
        }
        if (null !== ($fournisseur = Valeurs::texte($valeurs, 'fournisseur'))) {
            $produit->setFournisseurHabituel($this->fournisseur($fournisseur, $simulation, $remarques));
        }

        $this->valider($produit, $erreurs);

        return $this->resultat($numero, $libelle, $produit, $erreurs, $simulation, $remarques);
    }

    /**
     * @param list<string> $remarques
     */
    private function categorie(string $nomComplet, bool $simulation, array &$remarques): Categorie
    {
        $morceaux = array_values(array_filter(array_map('trim', preg_split('/\s*(?:›|>|\/)\s*/u', $nomComplet) ?: [])));
        $parent = null;
        foreach (\array_slice($morceaux, 0, 2) as $niveau => $nom) {
            $cle = 'categorie:'.mb_strtolower((null === $parent ? '' : $parent->getNom().'>').$nom);
            $existante = $this->crees[$cle] ?? (0 === $niveau
                ? $this->categories->findOneBy(['nom' => $nom, 'parent' => null])
                : (null !== $parent->getId() ? $this->categories->findOneBy(['nom' => $nom, 'parent' => $parent]) : null));
            if (!$existante instanceof Categorie) {
                $existante = (new Categorie())->setNom($nom)->setParent($parent);
                $this->creer($cle, $existante, $simulation);
                $remarques[] = \sprintf('Catégorie « %s » créée.', $existante->getNomComplet());
            }
            $parent = $existante;
        }

        return $parent ?? throw new \LogicException();
    }

    /**
     * @param list<string> $remarques
     */
    private function etagere(string $code, bool $simulation, array &$remarques): Etagere
    {
        $code = mb_strtoupper(trim($code));
        $cle = 'etagere:'.$code;
        $etagere = $this->crees[$cle] ?? $this->etageres->findOneBy(['code' => $code]);
        if (!$etagere instanceof Etagere) {
            $etagere = (new Etagere())->setCode($code)->setLibelle($code);
            $this->creer($cle, $etagere, $simulation);
            $remarques[] = \sprintf('Étagère %s créée.', $code);
        }

        return $etagere;
    }

    /**
     * @param list<string> $remarques
     */
    private function fournisseur(string $nom, bool $simulation, array &$remarques): Fournisseur
    {
        $cle = 'fournisseur:'.mb_strtolower($nom);
        $fournisseur = $this->crees[$cle] ?? $this->fournisseurs->findOneBy(['nom' => $nom]);
        if (!$fournisseur instanceof Fournisseur) {
            $fournisseur = (new Fournisseur())->setNom($nom);
            $this->creer($cle, $fournisseur, $simulation);
            $remarques[] = \sprintf('Fournisseur « %s » créé.', $nom);
        }

        return $fournisseur;
    }

    private function creer(string $cle, Categorie|Etagere|Fournisseur $entite, bool $simulation): void
    {
        $this->crees[$cle] = $entite;
        if (!$simulation) {
            $this->em->persist($entite);
        }
    }

    /**
     * @return array<string, FormeGalenique>
     */
    private function formes(): array
    {
        if (null === $this->formes) {
            $this->formes = [];
            foreach ($this->formesRepository->actifs() as $forme) {
                $this->formes[mb_strtolower($forme->getNom())] = $forme;
            }
        }

        return $this->formes;
    }
}
