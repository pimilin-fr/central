<?php

namespace App\Form;

use App\Demo\DemoStrategy;
use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
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
                ->add('tiersType', EntityType::class, [
                    'class' => TypeTiers::class,
                    'choice_label' => 'name',
                    'query_builder' => function (EntityRepository $er) {
                        return $er->createQueryBuilder('t')
                                ->orderBy('t.name', 'ASC');
                    }
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => Tiers::class,
        ]);
    }
}
