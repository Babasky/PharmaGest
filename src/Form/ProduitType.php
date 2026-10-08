<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Etagere;
use App\Entity\FormeGalenique;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Repository\CategorieRepository;
use App\Repository\EtagereRepository;
use App\Repository\FormeGaleniqueRepository;
use App\Repository\FournisseurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Les listes de choix ne proposent que les éléments actifs de la pharmacie courante (filtre tenant + RG-15).
 *
 * @extends AbstractType<Produit>
 */
final class ProduitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $montant = ['attr' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']];
        $builder
            ->add('nomCommercial', TextType::class, [
                'label' => 'Nom commercial',
                'attr' => [
                    'placeholder' => 'Nom commercial',
                ],
            ]
            )
            ->add('dci', TextType::class, [
                'label' => 'DCI (molécule)',
                'required' => false,
                'attr' => [
                    'placeholder' => 'DCI(molécule)',
                ],
            ]
            )
            ->add('forme', EntityType::class, [
                'label' => 'Forme',
                'class' => FormeGalenique::class,
                'required' => false,
                'placeholder' => 'forme du produit',
                'query_builder' => static fn (FormeGaleniqueRepository $r) => $r->createQueryBuilder('f')->andWhere('f.actif = true')->orderBy('f.nom'),
            ])
            ->add('dosage', TextType::class, [
                'label' => 'Dosage',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Dosage (500 mg)',
                ],
            ]
            )
            ->add('conditionnement', TextType::class, [
                'label' => 'Conditionnement',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Boîte de 16 comprimés',
                ],
            ]
            )
            ->add('codeBarres', TextType::class, [
                'label' => 'Code-barres(Facultatif)',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Code-barres(Facultatif)',
                ],
            ]
            )
            ->add('categorie', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Categorie::class,
                'required' => false,
                'placeholder' => 'Catégorie',
                'query_builder' => static fn (CategorieRepository $r) => $r->choixActifs(), 'choice_label' => 'nomComplet',
            ])
            ->add('etagere', EntityType::class, [
                'label' => 'Étagère',
                'class' => Etagere::class,
                'required' => false,
                'placeholder' => 'Etagère',
                'query_builder' => static fn (EtagereRepository $r) => $r->choixActifs(),
            ])
            ->add('fournisseurHabituel', EntityType::class, [
                'label' => 'Fournisseur habituel', 'class' => Fournisseur::class, 'required' => false, 'placeholder' => '—',
                'query_builder' => static fn (FournisseurRepository $r) => $r->choixActifs(),
            ])
            ->add('prixAchat', IntegerType::class, ['label' => 'Prix d\'achat (FCFA)', ...$montant])
            ->add('prixVente', IntegerType::class, ['label' => 'Prix de vente (FCFA)', ...$montant])
            ->add('tauxTva', ChoiceType::class, ['label' => 'TVA', 'choices' => Produit::TAUX_TVA])
            ->add('seuilAlerte', IntegerType::class, ['label' => 'Seuil d\'alerte (unités)', 'help' => 'En dessous, le produit est signalé en rupture.', 'attr' => ['min' => 0]])
            ->add('stockMax', IntegerType::class, ['label' => 'Stock maximum (unités)', 'required' => false, 'help' => 'Sert à proposer les quantités à commander.', 'attr' => ['min' => 0]])
            ->add('ordonnanceObligatoire', CheckboxType::class, ['label' => 'Ordonnance obligatoire', 'required' => false])
            ->add('remboursableAmo', CheckboxType::class, ['label' => 'Remboursable (AMO et assurances)', 'required' => false])
            ->add('prixVenteAmo', IntegerType::class, [
                'label' => 'Prix de vente AMO (FCFA)', 'required' => false, ...$montant,
                'help' => 'Prix fixé par l\'AMO : le taux AMO s\'applique sur ce prix. Vide : prix de vente de la pharmacie.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Produit::class,
        ]);
    }
}
