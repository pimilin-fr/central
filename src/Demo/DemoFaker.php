<?php

namespace App\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Générateur de fausses données DÉTERMINISTE.
 *
 * Même (sel, nature, clé) => même résultat : la démo reste stable d'une reconstruction à l'autre
 * (mêmes noms, mêmes adresses), mais sans lien réversible avec la prod tant que DEMO_SALT reste secret.
 * Aucune dépendance externe.
 */
class DemoFaker {

    private const FIRST_NAMES = [
        'Camille', 'Louis', 'Emma', 'Hugo', 'Léa', 'Gabriel', 'Chloé', 'Lucas', 'Manon', 'Théo', 'Inès', 'Nathan',
        'Sarah', 'Jules', 'Laura', 'Adam', 'Julie', 'Maxime', 'Clara', 'Antoine', 'Zoé', 'Thomas', 'Alice', 'Paul',
        'Lola', 'Raphaël', 'Éva', 'Arthur', 'Jade', 'Victor', 'Anaïs', 'Baptiste', 'Margaux', 'Romain', 'Océane',
        'Quentin', 'Élodie', 'Mathis', 'Pauline', 'Clément', 'Marion', 'Simon', 'Charlotte', 'Alexandre', 'Mathilde',
        'Benjamin', 'Lucie', 'Nicolas', 'Sophie', 'Julien', 'Agathe', 'Étienne', 'Noémie', 'Damien', 'Justine',
        'Olivier', 'Céline', 'Fabien', 'Hélène', 'Sébastien',
    ];

    private const LAST_NAMES = [
        'Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau', 'Simon',
        'Laurent', 'Lefebvre', 'Michel', 'Garcia', 'David', 'Bertrand', 'Roux', 'Vincent', 'Fournier', 'Morel',
        'Girard', 'André', 'Mercier', 'Dupont', 'Lambert', 'Bonnet', 'François', 'Martinez', 'Legrand', 'Garnier',
        'Faure', 'Rousseau', 'Blanc', 'Guérin', 'Muller', 'Henry', 'Roussel', 'Nicolas', 'Perrin', 'Morin', 'Mathieu',
        'Clément', 'Gauthier', 'Dumont', 'Lopez', 'Fontaine', 'Chevalier', 'Robin', 'Masson', 'Sanchez', 'Gérard',
        'Nguyen', 'Boyer', 'Denis', 'Lemaire', 'Duval', 'Joly', 'Gautier', 'Roger', 'Roche', 'Renard', 'Marchand',
        'Dufour', 'Blanchard', 'Picard', 'Leclerc', 'Brun', 'Colin', 'Caron', 'Barbier', 'Arnaud', 'Meyer', 'Lucas',
        'Hubert', 'Pons', 'Vidal', 'Carpentier', 'Aubert',
    ];

    private const COMPANY_KINDS = [
        'Boulangerie', 'Garage', 'Pharmacie', 'Cabinet', 'Établissements', 'Atelier', 'Librairie', 'Restaurant',
        'Épicerie', 'Maison', 'Société', 'Entreprise', 'Clinique', 'Agence', 'Institut', 'Brasserie', 'Fromagerie',
        'Quincaillerie', 'Papeterie', 'Opticien', 'Imprimerie', 'Transports', 'Électricité', 'Menuiserie',
    ];

    private const COMPANY_SUFFIXES = [
        'du Centre', 'des Lilas', 'de la Gare', 'du Moulin', 'des Halles', 'Saint-Martin', 'du Parc', 'de la Poste',
        'des Tilleuls', 'du Château', 'de l\'Église', 'des Écoles', 'de la Mairie', 'du Marché', 'des Acacias',
        '& Fils', '& Associés', 'Lemoine', 'Dupuis', 'Vasseur', 'Perrot', 'Chauvin', 'Maillard', 'Leblanc',
    ];

    private const STREET_NAMES = [
        'des Lilas', 'de la République', 'Victor Hugo', 'de la Gare', 'du Moulin', 'des Écoles', 'Pasteur',
        'de la Liberté', 'Jean Jaurès', 'du Général de Gaulle', 'des Tilleuls', 'de la Paix', 'du Stade',
        'des Champs', 'de l\'Église', 'du Château', 'des Acacias', 'Gambetta', 'de la Mairie', 'des Vignes',
        'du Commerce', 'de Verdun', 'des Peupliers', 'Jules Ferry', 'du Lavoir', 'des Roses', 'de la Fontaine',
        'Voltaire', 'des Platanes', 'de Provence', 'du Bois', 'des Sources', 'Anatole France', 'des Chênes',
        'de la Croix', 'du Clos', 'Léon Blum', 'des Jardins', 'de la Forêt', 'du Pont',
    ];

    private const STREET_TYPES = ['rue', 'avenue', 'boulevard', 'impasse', 'allée', 'chemin', 'place'];

    /** @var array<string, array<string, true>> */
    private array $used = [];

    public function __construct(
        #[Autowire(env: 'DEMO_SALT')]
        private readonly string $salt
    ) {
    }

    public function reset(): void {
        $this->used = [];
    }

    // ------------------------------------------------------------------
    // Primitives
    // ------------------------------------------------------------------

    /** Entier pseudo-aléatoire stable dans [min, max]. */
    public function int(string $kind, string|int $key, int $min, int $max, int $attempt = 0): int {
        $hash = hash('sha256', $this->salt . '|' . $kind . '|' . $key . '|' . $attempt);

        return $min + (int) (hexdec(substr($hash, 0, 12)) % ($max - $min + 1));
    }

    /** Flottant stable dans [min, max]. */
    public function ratio(string $kind, string|int $key, float $min, float $max): float {
        $unit = $this->int($kind, $key, 0, 1_000_000) / 1_000_000;

        return $min + ($max - $min) * $unit;
    }

    /** @template T @param list<T> $pool @return T */
    public function pick(array $pool, string $kind, string|int $key, int $attempt = 0): mixed {
        return $pool[$this->int($kind, $key, 0, count($pool) - 1, $attempt)];
    }

    // ------------------------------------------------------------------
    // Noms
    // ------------------------------------------------------------------

    public function personName(string|int $key): string {
        return $this->unique('person', static fn (self $f, int $i) => sprintf(
            '%s %s',
            $f->pick(self::FIRST_NAMES, 'pf', $key, $i),
            $f->pick(self::LAST_NAMES, 'pl', $key, $i)
        ));
    }

    public function companyName(string|int $key): string {
        return $this->unique('company', static fn (self $f, int $i) => sprintf(
            '%s %s',
            $f->pick(self::COMPANY_KINDS, 'ck', $key, $i),
            $f->pick(self::COMPANY_SUFFIXES, 'cs', $key, $i)
        ));
    }

    /** Nom de voie sans le type (« des Lilas »). */
    public function streetName(string|int $key): string {
        return $this->pick(self::STREET_NAMES, 'street', $key);
    }

    public function streetType(string|int $key): string {
        return $this->pick(self::STREET_TYPES, 'streetType', $key);
    }

    /** Numéro de commande fictif, stable. */
    public function orderNumber(string|int $key): string {
        return sprintf('CMD-%06d', $this->int('order', $key, 0, 999_999));
    }

    /**
     * Génère un libellé unique (en cas de collision on retente avec une autre graine, puis on suffixe).
     *
     * @param callable(self,int):string $generate
     */
    private function unique(string $kind, callable $generate): string {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $value = $generate($this, $attempt);
            if (!isset($this->used[$kind][$value])) {
                $this->used[$kind][$value] = true;

                return $value;
            }
        }
        $n = 2;
        while (isset($this->used[$kind][$value . ' ' . $n])) {
            $n++;
        }
        $value .= ' ' . $n;
        $this->used[$kind][$value] = true;

        return $value;
    }
}
