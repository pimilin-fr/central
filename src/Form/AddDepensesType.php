<?php

namespace App\Form;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Repository\PortefeuilleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddDepensesType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
                ->add('date', DateType::class, [
                    'widget' => 'single_text',
                    'label' => 'Date',
                ])
                ->add('montant', MoneyType::class, [
                    'currency' => 'EUR',
                    'scale' => 2,
                ])
                ->add('numCommande', TextType::class, [
                    'required' => false,
                ])
                ->add('note', TextareaType::class, [
                    'required' => false,
                    'attr' => ['rows' => 3],
                ])

                /* =========================
                  PORTEFEUILLE
                  ========================= */
                ->add('portefeuille', EntityType::class, [
                    'class' => Portefeuille::class,
                    'choice_label' => 'name',
                    'expanded' => true,
                    'data' => $options['portefeuille_entity']
                ])

                /* =========================
                  CATEGORIE
                  ========================= */
                ->add('categorie', TextType::class, [
                    'mapped' => false,
                    'required' => true,
                    'data' => $options['categorie_label'],
                    'attr' => [
                        'class' => 'autocomplete',
                        'data-endpoint' => '/categories/search',
                    ],
                ])
                ->add('categorie_id', HiddenType::class, [
                    'mapped' => false,
                    'data' => $options['categorie_id'],
                ])

                /* =========================
                  PROJET
                  ========================= */
                ->add('projet', TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'data' => $options['projet_label'],
                    'attr' => [
                        'class' => 'autocomplete',
                        'data-endpoint' => '/projet/search',
                    ],
                ])
                ->add('projet_id', HiddenType::class, [
                    'mapped' => false,
                    'data' => $options['projet_id'],
                ])

                /* =========================
                  TIERS
                  ========================= */
                ->add('tiers', TextType::class, [
                    'mapped' => false,
                    'required' => true,
                    'data' => $options['tiers_label'],
                    'attr' => [
                        'class' => 'autocomplete',
                        'data-endpoint' => '/tiers/search',
                    ],
                ])
                ->add('tiers_id', HiddenType::class, [
                    'mapped' => false,
                    'data' => $options['tiers_id'],
                ])
                /* =========================
                  Adresse
                  ========================= */
                ->add('adresse', TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'data' => $options['adresse_label'],
                ])
                ->add('adresse_id', HiddenType::class, [
                    'mapped' => false,
                    'data' => $options['adresse_id'],
                ])

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => Depenses::class,
            'csrf_protection' => false,
            'portefeuille_entity' => null,
            'categorie_id' => null,
            'categorie_label' => null,
            'projet_id' => null,
            'projet_label' => null,
            'tiers_id' => null,
            'tiers_label' => null,
            'adresse_label' => null,
            'adresse_id' => null
        ]);
    }
}
