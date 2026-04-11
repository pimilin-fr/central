<?php

namespace App\Form;

use App\Entity\TypeTiers;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;

class TypeTiersType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
                ->add('name', TextType::class, [
                    'label' => 'Nom',
                ])
                ->add('couleur', ColorType::class, [
                    'label' => 'Couleur',
                    'required' => false,
                ])
                ->add('libelleLiserai', TextType::class, [
                    'label' => 'Libellé du liserai',
                    'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => TypeTiers::class,
        ]);
    }
}
