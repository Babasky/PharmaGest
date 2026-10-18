<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\ClientAssurance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Le champ « privilégié » n'existe que pour le propriétaire et l'adjoint (option peut_privilegier).
 *
 * @extends AbstractType<Client>
 */
final class ClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Prénom et nom',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'Entrez le prénom et le nom du client',
                ],
            ],
            )
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => [
                    'placeholder' => 'N° de télephone du client',
                ],
            ]
            )
            ->add('assurances', CollectionType::class, [
                'label' => 'Assurances (AMO ou autres)',
                'entry_type' => ClientAssuranceType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                // Une ligne ajoutée puis laissée vide est ignorée.
                'delete_empty' => static fn (?ClientAssurance $a = null): bool => null === $a || (null === $a->getOrganisme() && '' === $a->getNumeroAssure()),
                'required' => false,
                'block_prefix' => 'assurances_client',
                'help' => 'Un client peut avoir plusieurs assurances ; à la caisse, on choisit celle qui s\'applique à la vente.',
            ])
            ->add('entreprise', TextType::class, [
                    'label' => 'Entreprise ou mutuelle', 
                    'required' => false,
                    'attr' => [
                        'placeholder' => 'Entreprise ou mutuelle'
                    ] , 
                ]
            );

        if ($options['peut_privilegier']) {
            $builder->add('privilegie', CheckboxType::class, [
                'label' => 'Client privilégié',
                'required' => false,
                'help' => 'Seuls les clients privilégiés peuvent recevoir une remise.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Client::class, 'peut_privilegier' => false]);
        $resolver->setAllowedTypes('peut_privilegier', 'bool');
    }
}
