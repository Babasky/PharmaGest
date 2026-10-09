<?php

namespace App\Form;

use App\Entity\CategorieDepense;
use App\Enum\ModeReglement;
use App\Form\Model\SaisieDepense;
use App\Repository\CategorieDepenseRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SaisieDepense>
 */
final class DepenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                    'label' => 'Date', 
                    'widget' => 'single_text', 
                    'input' => 'datetime_immutable'
                ]
            )
            ->add('categorie', EntityType::class, [
                'label' => 'Catégorie',
                'class' => CategorieDepense::class,
                'query_builder' => static fn (CategorieDepenseRepository $r) => $r->choixActifs(),
                'placeholder' => 'Choisir…',
            ])
            ->add('libelle', TextType::class, [
                    'label' => 'Libellé', 
                    'attr' => [
                        'placeholder' => "Ex. : loyer d'octobre"
                    ]
                ]
            )
            ->add('montant', IntegerType::class, [
                'label' => 'Montant (FCFA)', 
                'attr' => [
                        'min' => 1, 
                        'inputmode' => 'numeric',
                        'placeholder' => "Montant de la dépense"
                    ]
                ]
            )
            ->add('mode', EnumType::class, [
                'label' => 'Mode de paiement',
                'class' => ModeReglement::class,
                'choice_label' => static fn (ModeReglement $m) => $m->libelle(),
            ])
            ->add('beneficiaire', TextType::class, [
                    'label' => 'Bénéficiaire', 
                    'required' => false,
                    'attr' => [
                        'placeholder' => "Entrez le nom du bénéficiaire"
                    ]
                ]
            )
            ->add('justificatif', FileType::class, [
                'label' => 'Justificatif',
                'required' => false,
                'help' => 'Photo (JPEG, PNG, WebP) ou PDF, 5 Mo au plus.',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp,application/pdf'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaisieDepense::class]);
    }
}
