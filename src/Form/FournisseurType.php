<?php

namespace App\Form;

use App\Entity\Fournisseur;
use Symfony\Component\Form\AbstractType;
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
            ->add('nom', TextType::class, ['label' => 'Nom', 'attr' => ['placeholder' => 'PPM, Laborex, Copharma…']])
            ->add('contact', TextType::class, ['label' => 'Personne à contacter', 'required' => false])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false, 'attr' => ['placeholder' => '+223 XX XX XX XX']])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false, 'help' => 'Les bons de commande y seront envoyés (Lot 6).'])
            ->add('adresse', TextType::class, ['label' => 'Adresse', 'required' => false])
            ->add('delaiLivraison', IntegerType::class, ['label' => 'Délai de livraison habituel (jours)', 'required' => false, 'attr' => ['min' => 0]])
            ->add('conditionsPaiement', TextType::class, ['label' => 'Conditions de paiement', 'required' => false, 'attr' => ['placeholder' => 'Ex. : 30 jours fin de mois']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Fournisseur::class]);
    }
}
