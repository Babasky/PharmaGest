<?php

namespace App\Form;

use App\Entity\Offre;
use App\Form\Model\NouvellePharmacie;
use App\Repository\OffreRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<NouvellePharmacie>
 */
final class NouvellePharmacieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pharmacie', PharmacieType::class, ['label' => false])
            ->add('offre', EntityType::class, [
                'label' => 'Offre',
                'class' => Offre::class,
                'property_path' => 'pharmacie.offre',
                'query_builder' => static fn (OffreRepository $r) => $r->createQueryBuilder('o')->orderBy('o.ordre'),
                'choice_label' => static fn (Offre $o) => \sprintf('%s — %s utilisateurs, %d pharmacie(s)', $o->getNom(), $o->getMaxUtilisateurs() ?? 'illimité', $o->getMaxPharmacies()),
                'expanded' => true,
            ])
            ->add('joursEssai', IntegerType::class, [
                'label' => 'Période d\'essai (jours)',
                'help' => '0 = pas d\'essai : la pharmacie reste en lecture seule jusqu\'au premier paiement.',
                'attr' => ['min' => 0, 'max' => 90],
            ])
            ->add('nomProprietaire', TextType::class, ['label' => 'Nom du pharmacien titulaire'])
            ->add('emailProprietaire', EmailType::class, [
                'label' => 'Email du pharmacien titulaire',
                'help' => 'Il recevra un lien pour activer son compte. Si l\'email correspond déjà à un propriétaire, la pharmacie lui est ajoutée.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NouvellePharmacie::class]);
    }
}
