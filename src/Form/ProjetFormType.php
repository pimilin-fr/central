<?php

namespace App\Form;

use App\Entity\Projet;
use App\Entity\ProjetType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjetFormType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
                ->add('name', TextType::class, [
                    'label' => 'Nom',
                ])
                ->add('type', EntityType::class, [
                    'class' => ProjetType::class,
                    'choice_label' => 'fullname',
                    'placeholder' => 'Choisir un type',
                    'query_builder' => function (EntityRepository $er) {
                        return $er->createQueryBuilder('t')
                                ->leftJoin('t.children', 'c')
                                ->groupBy('t.id')
                                ->having('COUNT(c.id) = 0') // 👈 uniquement les feuilles
                                ->orderBy('c.name', 'ASC');
                    },
                ])
                ->add('beginAt', DateType::class, [
                    'widget' => 'single_text', // 👈 important
                    'label' => 'Début',
                    'html5' => true
                ])
                ->add('couleur', ColorType::class, [
                    'label' => 'Couleur',
                    'required' => false,
                ])
                ->add('endAt', DateType::class, [
                    'widget' => 'single_text', // 👈 important
                    'label' => 'Fin',
                    'required' => false,
                    'html5' => true
                ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => Projet::class,
        ]);
    }
}
