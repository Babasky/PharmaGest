<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\OrganismeAmo;
use App\Repository\OrganismeAmoRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
                    'attr' =>[
                        'placeholder' => "Entrez le prénom et le nom du client"
                    ]

                ],
                
            )
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone', 
                'required' => false, 
                'attr' => [
                    'placeholder' => 'N° de télephone du client'
                    ]
                ]
            )
            ->add('organismeAmo', EntityType::class, [
                'label' => 'Assurance (AMO ou autre)', 'class' => OrganismeAmo::class, 'required' => false, 'placeholder' => '— Non assuré —',
                'query_builder' => static fn (OrganismeAmoRepository $r) => $r->createQueryBuilder('o')->andWhere('o.actif = true')->orderBy('o.nom'),
                'group_by' => static fn (OrganismeAmo $o) => $o->getType()->libelle(),
            ])
            ->add('numeroAssure', TextType::class, ['label' => 'N° d\'assuré', 'required' => false])
            ->add('entreprise', TextType::class, ['label' => 'Entreprise ou mutuelle', 'required' => false]);

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
