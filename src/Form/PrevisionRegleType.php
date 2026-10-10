<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Portefeuille;
use App\Entity\PrevisionRegle;
use App\Entity\Projet;
use App\Entity\Tiers;
use App\Prevision\Certitude;
use App\Prevision\Frequence;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

class PrevisionRegleType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
                ->add('libelle', TextType::class, ['label' => 'Libellé', 'attr' => ['placeholder' => 'Ex. Abonnement internet']])
                ->add('categorie', EntityType::class, [
                    'class' => Categorie::class,
                    'choice_label' => static fn (Categorie $c): string => $c->getLibelle() ?: $c->getName(),
                    'placeholder' => 'Choisir une catégorie',
                    'label' => 'Catégorie',
                    'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('c')->andWhere('c.deletedAt IS NULL')->orderBy('c.libelle', 'ASC'),
                ])
                ->add('tiers', EntityType::class, [
                    'class' => Tiers::class,
                    'choice_label' => 'name',
                    'placeholder' => 'Choisir un tiers',
                    'label' => 'Tiers',
                    'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')->andWhere('t.deletedAt IS NULL')->orderBy('t.name', 'ASC'),
                ])
                ->add('portefeuille', EntityType::class, [
                    'class' => Portefeuille::class,
                    'choice_label' => 'libelle',
                    'placeholder' => 'Choisir un portefeuille',
                    'label' => 'Portefeuille',
                ])
                ->add('projet', EntityType::class, [
                    'class' => Projet::class,
                    'choice_label' => 'name',
                    'required' => false,
                    'placeholder' => 'Aucun projet',
                    'label' => 'Projet',
                ])
                ->add('certitude', EnumType::class, [
                    'class' => Certitude::class,
                    'label' => 'Certitude',
                    'choice_label' => static fn (Certitude $c): string => $c->label() . ' (' . (int) ($c->poids() * 100) . ' %)',
                ])
                ->add('estime', CheckboxType::class, ['required' => false, 'label' => 'Montant estimé (varie : électricité, gaz…) : une seule échéance « en cours » à la fois'])
                ->add('frequence', EnumType::class, [
                    'class' => Frequence::class,
                    'label' => 'Fréquence',
                    'choice_label' => static fn (Frequence $f): string => $f->label(),
                ])
                ->add('jour', IntegerType::class, ['label' => 'Jour du mois', 'attr' => ['min' => 1, 'max' => 31]])
                ->add('jourFin', IntegerType::class, ['label' => 'Jusqu’au (fenêtre, facultatif)', 'required' => false, 'attr' => ['min' => 1, 'max' => 31]])
                ->add('debut', DateType::class, ['widget' => 'single_text', 'html5' => true, 'label' => 'Début'])
                ->add('fin', DateType::class, ['widget' => 'single_text', 'html5' => true, 'required' => false, 'label' => 'Fin (facultatif)'])
                ->add('nbEcheances', IntegerType::class, ['required' => false, 'label' => 'Nombre d’échéances (paiement en x fois)', 'attr' => ['min' => 1]])
                ->add('actif', CheckboxType::class, ['required' => false, 'label' => 'Règle active'])
                ->add('tranches', CollectionType::class, [
                    'entry_type' => PrevisionTrancheType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                    'label' => false,
                    'constraints' => [new Count(min: 1, minMessage: 'Ajoutez au moins un montant.')],
                    'prototype_name' => '__tranche__',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults(['data_class' => PrevisionRegle::class]);
    }
}
