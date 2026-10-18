<?php

namespace App\Form;

use App\Entity\ClientAssurance;
use App\Entity\OrganismeAmo;
use App\Repository\OrganismeAmoRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une ligne « assurance + n° d'assuré » de la fiche client.
 *
 * @extends AbstractType<ClientAssurance>
 */
final class ClientAssuranceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organisme', EntityType::class, [
                'label' => 'Assurance (AMO ou autre)', 'class' => OrganismeAmo::class, 'placeholder' => '— Choisir —',
                'query_builder' => static fn (OrganismeAmoRepository $r) => $r->createQueryBuilder('o')->andWhere('o.actif = true')->orderBy('o.nom'),
                'group_by' => static fn (OrganismeAmo $o) => $o->getType()->libelle(),
            ])
            ->add('numeroAssure', TextType::class, ['label' => 'N° d\'assuré', 'empty_data' => ''])
            ->add('taux', IntegerType::class, ['label' => 'Taux (%)', 'required' => false, 'attr' => ['min' => 0, 'max' => 100]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClientAssurance::class, 'block_prefix' => 'assurance_client']);
    }
}
