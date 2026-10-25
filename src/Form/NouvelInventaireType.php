<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Entrepot;
use App\Entity\Etagere;
use App\Enum\PerimetreInventaire;
use App\Repository\CategorieRepository;
use App\Repository\EntrepotRepository;
use App\Repository\EtagereRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Choix de l'emplacement (officine ou entrepôt) et du périmètre d'un nouvel inventaire (ST-06).
 *
 * @extends AbstractType<array{perimetre: PerimetreInventaire|null, etagere: Etagere|null, categorie: Categorie|null, entrepot?: Entrepot|null}>
 */
final class NouvelInventaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['avec_entrepots']) {
            $builder->add('entrepot', EntityType::class, [
                'label' => 'Emplacement',
                'class' => Entrepot::class,
                'query_builder' => static fn (EntrepotRepository $r) => $r->createQueryBuilder('e')->andWhere('e.actif = true')->orderBy('e.nom'),
                'choice_label' => static fn (Entrepot $e) => 'Entrepôt '.$e->getNom(),
                'required' => false,
                'placeholder' => 'Officine',
            ]);
        }
        $builder
            ->add('perimetre', EnumType::class, [
                'label' => 'Périmètre',
                'class' => PerimetreInventaire::class,
                'choice_label' => static fn (PerimetreInventaire $p) => $p->libelle(),
                'expanded' => true,
            ])
            ->add('etagere', EntityType::class, [
                'label' => 'Étagère (inventaire tournant)',
                'class' => Etagere::class,
                'query_builder' => static fn (EtagereRepository $r) => $r->choixActifs(),
                'choice_label' => static fn (Etagere $e) => $e->getCode().' — '.$e->getLibelle(),
                'required' => false,
                'placeholder' => "Choisir l'étagère",
            ])
            ->add('categorie', EntityType::class, [
                'label' => 'Catégorie (inventaire tournant)',
                'class' => Categorie::class,
                'query_builder' => static fn (CategorieRepository $r) => $r->choixActifs(),
                'choice_label' => static fn (Categorie $c) => $c->getNomComplet(),
                'required' => false,
                'placeholder' => 'Choisir la catégorie',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['avec_entrepots' => false]);
        $resolver->setAllowedTypes('avec_entrepots', 'bool');
    }
}
