<?php

namespace App\Form;

use App\Entity\TiersAdresse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddAdresseType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder->add('adresse', TextType::class, [
                    'mapped' => false,
                    'label' => 'Adresse',
                    'required' => false,
                    'attr' => [
                        'class' => 'autocomplete',
                        'data-endpoint' => '/adresse/search',
                    ],
                ])
                ->add('adresse_id', HiddenType::class, [
                    'mapped' => false,
                ])
                ->add('isPrincipale', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Principale',
//                    'help' => 'Cette adresse deviendra l’adresse principale du tiers',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => TiersAdresse::class,
        ]);
    }
}
