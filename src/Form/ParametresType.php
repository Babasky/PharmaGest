<?php

namespace App\Form;

use App\Entity\ParametrePharmacie;
use App\Enum\PolitiqueSansOrdonnance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
                'help' => 'Au-delà, la vente attend l\'accord du propriétaire, donné depuis son compte. Seuls les clients privilégiés ont droit à une remise.',
                'attr' => ['placeholder' => 'Ex. : 10', 'min' => 0, 'max' => 100],
            ])
            ->add('delaiAlertePeremption', IntegerType::class, [
                'label' => 'Alerte de péremption (jours avant la date)',
                'attr' => ['placeholder' => 'Ex. : 90', 'min' => 1, 'max' => 730],
            ])
            ->add('joursAvantRupture', IntegerType::class, [
                'label' => 'Badge « Rupture » après (jours à zéro)',
                'help' => 'Un produit à zéro a d\'abord un fond rouge ; le badge « Rupture » s\'ajoute après ce nombre de jours sans stock. Chaque produit peut avoir son propre délai (fiche produit).',
                'attr' => ['placeholder' => 'Ex. : 5', 'min' => 1, 'max' => 365],
            ])
            ->add('politiqueSansOrdonnance', EnumType::class, [
                'label' => 'Produit « ordonnance obligatoire » vendu sans ordonnance',
                'class' => PolitiqueSansOrdonnance::class,
                'choice_label' => static fn (PolitiqueSansOrdonnance $p) => $p->libelle(),
                'expanded' => true,
            ])
            ->add('delaiAnnulationVente', IntegerType::class, [
                'label' => 'Délai d\'annulation d\'une vente (minutes après la validation)',
                'required' => false,
                'attr' => ['placeholder' => 'Vide : le jour même', 'min' => 1, 'max' => ParametrePharmacie::DELAI_ANNULATION_MAXIMAL],
                'help' => 'Laissez vide pour permettre l\'annulation jusqu\'à la fin de la journée de la vente. Passé ce délai, ou après la clôture de la session de caisse, la vente ne s\'annule plus : il faut établir un avoir. Une vente envoyée à la caisse et pas encore payée s\'annule toujours.',
            ])
            ->add('inactiviteCaisse', IntegerType::class, [
                'label' => 'Déconnexion de la caisse après inactivité (minutes)',
                'help' => 'S\'applique tant qu\'une session de caisse est ouverte. Ailleurs dans l\'application, la déconnexion intervient après 30 minutes d\'inactivité.',
                'attr' => ['placeholder' => 'Ex. : 15', 'min' => 5, 'max' => 720],
            ])
            ->add('mentionsTicket', TextareaType::class, [
                'label' => 'Mentions en bas du ticket',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 500, 'placeholder' => 'Ex. : Merci de votre visite. Pharmacie de garde le dimanche.'],
            ])
            ->add('joursAnalyseVentes', IntegerType::class, [
                'label' => 'Commandes : ventes analysées (derniers jours)',
                'help' => 'Les ventes moyennes par jour et la rotation des produits se calculent sur cette période.',
                'attr' => ['placeholder' => 'Ex. : 30', 'min' => 7, 'max' => 365],
            ])
            ->add('joursCouverture', IntegerType::class, [
                'label' => 'Commandes : jours de ventes à couvrir après la livraison',
                'help' => 'La quantité suggérée couvre le délai de livraison du grossiste, puis ce nombre de jours de ventes.',
                'attr' => ['placeholder' => 'Ex. : 15', 'min' => 1, 'max' => 180],
            ])
            ->add('heureCommandesAutomatiques', ChoiceType::class, [
                'label' => 'Commandes automatiques : heure d\'envoi',
                'choices' => array_combine(array_map(static fn (int $h) => \sprintf('%d h', $h), range(0, 23)), range(0, 23)),
                'help' => 'Pour les fournisseurs qui n\'ont pas leur propre heure sur leur fiche.',
            ])
            ->add('envoiAutomatiqueCommandes', CheckboxType::class, [
                'label' => 'Envoyer automatiquement les commandes suggérées aux grossistes',
                'help' => 'À l\'heure ci-dessus, ou à celle choisie sur la fiche du fournisseur (il lui faut un email ou Pharma-ML). Une commande avec une quantité inhabituelle (plus de trois fois le dernier achat) reste en brouillon, à vérifier. Sans cette case, un responsable vérifie les commandes préparées avant de les envoyer.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ParametrePharmacie::class]);
    }
}
