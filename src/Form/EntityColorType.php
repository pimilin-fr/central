<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Champ « couleur » d'une entité : valeur vide (accent), emplacement de palette (@1…@12)
 * ou couleur libre (#rrggbb). Rendu avec le sélecteur
 * templates/layout/modern/components/_color_picker.html.twig.
 */
class EntityColorType extends AbstractType {

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'label' => 'Couleur',
            'required' => false,
            'attr' => ['data-entity-color' => '1'],
            'constraints' => [
                new Regex(
                        pattern: '/^(@([1-9]|1[0-2]|n[1-5]|info|ok|warn|danger|text|soft|muted|line)|#[0-9A-Fa-f]{6})$/',
                        message: 'Couleur invalide : choisissez une couleur du thème ou une couleur libre.'
                ),
            ],
        ]);
    }

    public function getParent(): string {
        return TextType::class;
    }

    public function getBlockPrefix(): string {
        return 'entity_color';
    }
}
