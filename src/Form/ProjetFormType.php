<?php

namespace App\Form;

use App\Demo\DemoStrategy;
use App\Entity\Projet;
use App\Entity\ProjetType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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
                ->add('demoStrategy', EnumType::class, [
                    'class' => DemoStrategy::class,
                    'label' => 'Traitement pour la démo',
                    'placeholder' => 'Utiliser la règle par défaut',
                    'required' => false,
                    'choice_label' => static function (DemoStrategy $strategy): string {
                        return match ($strategy) {
                            DemoStrategy::COPY => 'Copier',
                            DemoStrategy::ANONYMIZE => 'Anonymiser',
                            DemoStrategy::EXCLUDE => 'Exclure',
                        };
                    },
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
