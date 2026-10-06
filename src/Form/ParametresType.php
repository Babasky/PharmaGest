<?php

namespace App\Form;

use App\Entity\ParametrePharmacie;
use App\Enum\PolitiqueSansOrdonnance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ParametrePharmacie>
 */
final class ParametresType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plafondRemise', IntegerType::class, [
                'label' => 'Plafond de remise (%)',
                'help' => 'Au-delà, le code PIN du propriétaire est exigé à la caisse. Seuls les clients privilégiés ont droit à une remise.',
                'attr' => ['min' => 0, 'max' => 100],
            ])
            ->add('delaiAlertePeremption', IntegerType::class, [
                'label' => 'Alerte de péremption (jours avant la date)',
                'attr' => ['min' => 1, 'max' => 730],
            ])
            ->add('politiqueSansOrdonnance', EnumType::class, [
                'label' => 'Produit « ordonnance obligatoire » vendu sans ordonnance',
                'class' => PolitiqueSansOrdonnance::class,
                'choice_label' => static fn (PolitiqueSansOrdonnance $p) => $p->libelle(),
                'expanded' => true,
            ])
            ->add('inactiviteCaisse', IntegerType::class, [
                'label' => 'Déconnexion de la caisse après inactivité (minutes)',
                'help' => 'S\'applique tant qu\'une session de caisse est ouverte. Ailleurs dans l\'application, la déconnexion intervient après 30 minutes d\'inactivité.',
                'attr' => ['min' => 5, 'max' => 720],
            ])
            ->add('mentionsTicket', TextareaType::class, [
                'label' => 'Mentions en bas du ticket',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 500, 'placeholder' => 'Ex. : Merci de votre visite. Pharmacie de garde le dimanche.'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ParametrePharmacie::class]);
    }
}
