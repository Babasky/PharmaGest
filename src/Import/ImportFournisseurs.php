<?php

namespace App\Import;

use App\Entity\Fournisseur;
use App\Repository\FournisseurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import des fournisseurs ; un fournisseur de même nom est mis à jour.
 */
final class ImportFournisseurs extends AbstractDefinitionImport
{
    public function __construct(
        EntityManagerInterface $em,
        ValidatorInterface $validateur,
        private readonly FournisseurRepository $fournisseurs,
    ) {
        parent::__construct($em, $validateur);
    }

    public function code(): string
    {
        return 'fournisseurs';
    }

    public function libelle(): string
    {
        return 'Fournisseurs';
    }

    public function colonnes(): array
    {
        return [
            new Colonne('nom', 'Nom', true, 'Sert à reconnaître un fournisseur déjà présent.', 'PPM'),
            new Colonne('contact', 'Contact', false, 'Personne à joindre.', 'Service commandes'),
            new Colonne('telephone', 'Téléphone', false, 'Numéro malien.', '20 22 50 50'),
            new Colonne('email', 'Email', false, 'Les bons de commande y seront envoyés.', 'commandes@ppm.ml'),
            new Colonne('adresse', 'Adresse', false, '', 'Bamako, Quinzambougou'),
            new Colonne('delai_livraison', 'Délai de livraison', false, 'En jours.', '2'),
            new Colonne('conditions_paiement', 'Conditions de paiement', false, '', 'Comptant'),
        ];
    }

    public function preparer(int $numero, array $valeurs, bool $simulation): array
    {
        $erreurs = [];
        $nom = Valeurs::texte($valeurs, 'nom');
        if (null === $nom) {
            $erreurs[] = 'Le nom est obligatoire.';
        }
        $this->dejaVu('nom:'.$nom, $numero, 'Ce fournisseur', $erreurs);

        $fournisseur = (null !== $nom ? $this->fournisseurs->findOneBy(['nom' => $nom]) : null) ?? new Fournisseur();
        $fournisseur->setNom((string) $nom)
            ->setContact(Valeurs::texte($valeurs, 'contact'))
            ->setTelephone(Valeurs::texte($valeurs, 'telephone'))
            ->setEmail(Valeurs::texte($valeurs, 'email'))
            ->setAdresse(Valeurs::texte($valeurs, 'adresse'))
            ->setDelaiLivraison(Valeurs::entier($valeurs, 'delai_livraison', 'Délai de livraison', $erreurs))
            ->setConditionsPaiement(Valeurs::texte($valeurs, 'conditions_paiement'));

        $this->valider($fournisseur, $erreurs);

        return $this->resultat($numero, (string) $nom, $fournisseur, $erreurs, $simulation);
    }
}
