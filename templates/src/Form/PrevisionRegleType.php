<?php

namespace App\Form;

use App\Entity\Portefeuille;
use App\Entity\PrevisionRegle;
use App\Prevision\Certitude;
use App\Prevision\Frequence;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

class PrevisionRegleType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        /** @var PrevisionRegle|null $regle */
        $regle = $options['data'] ?? null;
        $builder
                ->add('libelle', TextType::class, ['label' => 'Libellé', 'attr' => ['placeholder' => 'Ex. Abonnement internet']])
                // Champs à autocomplétion (comme sur les opérations) : libellé visible + identifiant caché ; résolus par le contrôleur.
                ->add('categorie', TextType::class, [
                    'mapped' => false,
                    'label' => 'Catégorie',
                    'data' => $regle?->getCategorie()?->getLibelle() ?: $regle?->getCategorie()?->getName(),
                    'attr' => ['class' => 'autocomplete', 'data-endpoint' => '/categories/search', 'autocomplete' => 'off'],
                ])
                ->add('categorie_id', HiddenType::class, ['mapped' => false, 'data' => $regle?->getCategorie()?->getId()])
                ->add('tiers', TextType::class, [
                    'mapped' => false,
                    'label' => 'Tiers',
                    'data' => $regle?->getTiers()?->getName(),
                    'attr' => ['class' => 'autocomplete', 'data-endpoint' => '/tiers/search', 'autocomplete' => 'off'],
                ])
                ->add('tiers_id', HiddenType::class, ['mapped' => false, 'data' => $regle?->getTiers()?->getId()])
                ->add('portefeuille', EntityType::class, [
                    'class' => Portefeuille::class,
                    'choice_label' => 'libelle',
                    'placeholder' => 'Choisir un portefeuille',
                    'label' => 'Portefeuille',
                ])
                ->add('projet', TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'Projet',
                    'data' => $regle?->getProjet()?->getName(),
                    'attr' => ['class' => 'autocomplete', 'data-endpoint' => '/projet/search', 'autocomplete' => 'off'],
                ])
                ->add('projet_id', HiddenType::class, ['mapped' => false, 'data' => $regle?->getProjet()?->getId()])
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
