<?php

namespace App\Form;

use App\Entity\Portefeuille;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PortefeuilleType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder->add('name', TextType::class, [
                    'label' => 'Nom',
                ])->add('libelle', TextType::class, [
                    'label' => 'Libellé',
                ])->add('type', ChoiceType::class, [
                    'label' => 'Nature',
                    'choices' => Portefeuille::TYPE_PTF,
                    'placeholder' => 'Choisir une nature',
                    'required' => false,
                ])->add('origine', TextType::class, [
                    'label' => 'Origine',
                    'required' => false
                ])->add('couleur', ColorType::class, [
                    'label' => 'Couleur',
                    'required' => false,
                ])->add('isReal', ColorType::class, [
                    'label' => 'Dépenses Réeles',
                    'required' => false,
                ])->add('isDefault', CheckboxType::class, [
                    'required' => false,
                    'label' => 'Par défaut',
//                    'help' => 'Cette adresse deviendra l’adresse principale du tiers',
        ]);
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => Portefeuille::class,
        ]);
    }
}
