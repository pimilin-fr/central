<?php

namespace App\Form;

use App\Entity\Adresse;
use App\Entity\AdresseType;
use App\Repository\AdresseRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AdresseFormType extends AbstractType {

    const MAP_VOIE = [
        // Classiques
        'Rue' => 'Rue',
        'Av.' => 'Avenue',
        'Bd.' => 'Boulevard',
        'Pl.' => 'Place',
        'Chem' => 'Chemin',
        'Imp' => 'Impasse',
        'All' => 'Allée',
        'Rés' => 'Résidence',
        'Lot' => 'Lotissement',
        'Sq' => 'Square',
        'Pas' => 'Passage',
        'Rte' => 'Route',
        'Quart' => 'Quartier',
        'Voie' => 'Voie',
        // Fréquents
        'Cr' => 'Cours',
        'Quai' => 'Quai',
        'Sent' => 'Sentier',
        'Ter' => 'Terrain',
        'Trav' => 'Traverse',
        'Vla' => 'Villa',
        'Ham' => 'Hameau',
        'Parc' => 'Parc',
        'Prom' => 'Promenade',
        'Espl' => 'Esplanade',
        // Moins courants
        'Fg' => 'Faubourg',
        'Rle' => 'Ruelle',
        'Cité' => 'Cité',
        'Dom' => 'Domaine',
        'Chs' => 'Chaussée',
        'Corn' => 'Corniche',
        'Digue' => 'Digue',
        'Écart' => 'Écart',
        'Plaine' => 'Plaine',
        'Val' => 'Val',
        'Mont' => 'Montée',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
                ->add('name', TextType::class, [
                    'label' => 'Nom',
                ])
                ->add('adresseType', EntityType::class, [
                    'class' => AdresseType::class,
                    'label' => 'Type',
                    'choice_label' => 'name',
                    'placeholder' => 'Choisir un type',
                    'required' => true, // l'astérisque s'affiche automatiquement
                    'attr' => ['data-entity-select' => ''],
                    'choice_attr' => static fn(AdresseType $type): array => [
                        'data-color' => $type->getCssColor(),
                        'data-text-color' => $type->getTextColor(),
                    ],
                ])

                // Coordonnées forcées (texte "lat, lon") — validation serveur recommandée :
                ->add('adresseForcee', TextType::class, [
                    'label' => 'Coordonnées forcées',
                    'required' => false,
                    'constraints' => [new Assert\Regex(
                                pattern: '/^-?\d{1,2}(?:[.,]\d+)?\s*[;,\s]\s*-?\d{1,3}(?:[.,]\d+)?$/',
                                message: 'Format attendu : latitude, longitude (ex. 48.8566, 2.3522).',
                        )],
                ])
                ->add('prefix', TextType::class, [
                    'label' => 'Préfix',
                    'required' => false,
                ])
                ->add('num', IntegerType::class, [
                    'label' => 'Num',
                    'required' => false,
                ])
                ->add('bisTer', TextType::class, [
                    'label' => 'Bis / Ter',
                    'required' => false,
                ])
                ->add('typeVoie', ChoiceType::class, [
                    'label' => 'Type de voie',
                    'choices' => AdresseFormType::MAP_VOIE,
                    'placeholder' => 'Choisir un type',
                    'required' => false,
                ])
                ->add('nomVoie', TextType::class, [
                    'label' => 'Voie',
                    'required' => false,
                ])
                ->add('codePostal', TextType::class, [
                    'label' => 'Code postal',
                    'required' => false,
                ])
                ->add('ville', TextType::class, [
                    'label' => 'Ville',
                ])
                ->add('cedex', TextType::class, [
                    'label' => 'Cedex',
                    'required' => false,
                ])
                ->add('pays', TextType::class, [
                    'label' => 'Pays',
                ])
                ->add('adresse', TextareaType::class, [
                    'label' => 'Adresse complète',
                    'required' => true,
                    'attr' => [
                        'rows' => 4,
                        'placeholder' => 'Saisir l’adresse complète...'
                    ]
                ])
                ->add('adresseParent', EntityType::class, [
                    'class' => Adresse::class,
                    'label' => 'Parent',
                    'required' => false,
                    'choice_label' => 'name',
                    'query_builder' => function (AdresseRepository $repo) {
                        return $repo->findAllOrdreredQueryBuilder(); // 👈 idéal
                    },
                ])

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => Adresse::class,
        ]);
    }
}
