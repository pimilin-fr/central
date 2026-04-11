<?php

namespace App\Form;

use App\Entity\Adresse;
use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TiersType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
                ->add('name', TextType::class, [
                    'label' => 'Nom',
                ])
                ->add('searchText', TextareaType::class, [
                    'label' => 'Texte de recherche',
                    'required' => true,
                    'attr' => [
                        'rows' => 4,
                        'placeholder' => 'Saisir le texte.'
                    ]
                ])
//                ->add('adresse_name', TextType::class, [
//                    'mapped' => false,
//                    'label' => 'Adresse principale',
//                    'required' => false,
//                    'attr' => [
//                        'data-endpoint' => '/adresse/search',
//                    ],
//                ])
//                ->add('adresse_name', HiddenType::class)
                ->add('tiersType', EntityType::class, [
                    'class' => TypeTiers::class,
                    'choice_label' => 'name',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => Tiers::class,
        ]);
    }
}
