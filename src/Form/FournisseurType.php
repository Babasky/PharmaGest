<?php

namespace App\Form;

use App\Entity\Fournisseur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Fournisseur>
 */
final class FournisseurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Entrez le nom du fournisseur',
                'attr' => [
                    'placeholder' => 'PPM, Laborex, Copharma…',
                ],
            ]
            )
            ->add('contact', TextType::class, [
                'label' => 'Personne à contacter',
                'required' => false,
                'attr' => [
                    'placeholder' => 'La personne à contacter',
                ],
            ]
            )
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => [
                    'placeholder' => '+223 XX XX XX XX',
                ],
            ]
            )
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
                'attr' => [
                    'placeholder' => "Entrez l'email du fournisseur",
                ],
                'help' => 'Les bons de commande y seront envoyés (Lot 6).',
            ]
            )
            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Adresse du fournisseur',
                ],
            ]
            )
            ->add('delaiLivraison', IntegerType::class, [
                'label' => 'Délai de livraison habituel (jours)',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'Délai de livraison',
                ],
            ]
            )
            ->add('conditionsPaiement', TextType::class, [
                'label' => 'Conditions de paiement',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ex. : 15 jours',
                ],
            ]
            )
            ->add('heureCommande', ChoiceType::class, [
                'label' => 'Commande automatique à',
                'required' => false,
                'placeholder' => 'Pas de commande automatique',
                'choices' => array_combine(array_map(static fn (int $h) => \sprintf('%d h', $h), range(0, 23)), range(0, 23)),
                'help' => 'Si l\'envoi automatique est activé dans les paramètres, la commande suggérée part chez ce fournisseur à cette heure (il lui faut une adresse email).',
            ])
            ->add('joursCommande', ChoiceType::class, [
                'label' => 'Jours de commande',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => ['Lun' => 1, 'Mar' => 2, 'Mer' => 3, 'Jeu' => 4, 'Ven' => 5, 'Sam' => 6, 'Dim' => 7],
                'help' => 'Aucun jour coché : tous les jours.',
                'label_attr' => ['class' => 'checkbox-inline'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Fournisseur::class]);
    }
}
