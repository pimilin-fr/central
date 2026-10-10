<?php

namespace App\Form;

use App\Entity\PrevisionTranche;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PrevisionTrancheType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
                ->add('aPartirDe', DateType::class, ['widget' => 'single_text', 'html5' => true, 'label' => 'À partir du'])
                ->add('montant', MoneyType::class, ['currency' => 'EUR', 'label' => 'Montant', 'html5' => true, 'scale' => 2])
                ->add('montantMin', MoneyType::class, ['currency' => 'EUR', 'label' => 'Min (estimé)', 'required' => false, 'html5' => true, 'scale' => 2])
                ->add('montantMax', MoneyType::class, ['currency' => 'EUR', 'label' => 'Max (estimé)', 'required' => false, 'html5' => true, 'scale' => 2]);
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults(['data_class' => PrevisionTranche::class]);
    }
}
