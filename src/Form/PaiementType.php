<?php

namespace App\Form;

use App\Entity\Offre;
use App\Enum\MoyenPaiement;
use App\Form\Model\NouveauPaiement;
use App\Repository\OffreRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<NouveauPaiement>
 */
final class PaiementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('offre', EntityType::class, [
                'label' => 'Offre',
                'class' => Offre::class,
                'query_builder' => static fn (OffreRepository $r) => $r->createQueryBuilder('o')->orderBy('o.ordre'),
                'choice_label' => 'nom',
            ])
            ->add('montant', IntegerType::class, ['label' => 'Montant reçu (FCFA)', 'attr' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']])
            ->add('moyen', EnumType::class, [
                'label' => 'Moyen de paiement',
                'class' => MoyenPaiement::class,
                'choice_label' => static fn (MoyenPaiement $m) => $m->libelle(),
            ])
            ->add('reference', TextType::class, ['label' => 'Référence (n° de transaction, de virement…)', 'required' => false])
            ->add('datePaiement', DateType::class, ['label' => 'Date du paiement', 'widget' => 'single_text', 'input' => 'datetime_immutable']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NouveauPaiement::class]);
    }
}
