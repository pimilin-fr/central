<?php

namespace App\Demo;

use App\Entity\TypeTiers;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Fabrique le faux nom d'un tiers anonymisé en tenant compte de son TYPE (hiérarchie N1 / N2 / N3).
 *
 * Les règles vivent dans config/demo/tiers_naming.yaml (modifiable sans toucher au code) :
 *   - rules        : mots-clés → modèles de noms (« Boulangerie {nom} », « Dr {prenom} {nom} »…).
 *
 * Résolution : on cherche d'abord au niveau le plus fin (N3), puis N2, puis N1 ; dans un niveau,
 * la première règle du fichier qui correspond l'emporte. Sans règle : « Prénom Nom » pour une personne,
 * nom de société générique sinon.
 *
 * Personne = case « Personne physique » cochée sur le tiers (aucune déduction à partir du type).
 * Variables des modèles : {prenom} {nom} {lieu}. Tout est déterministe (même tiers => même nom).
 */
class TiersNamer {

    private const PLACES = [
        'du Centre', 'des Lilas', 'de la Gare', 'du Moulin', 'des Halles', 'Saint-Martin', 'du Parc', 'de la Poste',
        'des Tilleuls', 'du Château', 'de l\'Église', 'des Écoles', 'de la Mairie', 'du Marché', 'des Acacias',
        'de la Fontaine', 'du Port', 'des Vignes', 'du Pont', 'de la Place', 'des Roses', 'du Lac', 'de Provence',
        'des Platanes', 'du Clos', 'de la République', 'des Champs', 'de l\'Avenir', 'du Soleil', 'du Vieux Bourg',
    ];

    /** @var array{rules: list<array{keywords: list<list<string>>, names: list<string>, person: bool, label: string}>}|null */
    private ?array $config = null;

    public function __construct(
        private readonly DemoFaker $faker,
        #[Autowire('%kernel.project_dir%/config/demo/tiers_naming.yaml')]
        private readonly string $file
    ) {
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    /** Nom factice unique et stable pour ce tiers. */
    public function name(TypeTiers $type, bool $flaggedPerson, string|int $key): string {
        $resolution = $this->resolve($type, $flaggedPerson);

        if ($resolution['patterns'] === []) {
            return $resolution['person'] ? $this->faker->personName($key) : $this->faker->companyName($key);
        }

        return $this->faker->unique('tiers', fn (DemoFaker $f, int $attempt): string => $this->build($resolution['patterns'], $key, $attempt));
    }

    /** Exemple de nom (non réservé : n'affecte pas l'unicité), pour la commande app:demo:types. */
    public function preview(TypeTiers $type, bool $flaggedPerson, string|int $key): string {
        $resolution = $this->resolve($type, $flaggedPerson);

        return $resolution['patterns'] === []
            ? ($resolution['person'] ? sprintf('%s %s', $this->faker->firstName($key, 0), $this->faker->lastName($key, 0)) : '(nom de société générique)')
            : $this->build($resolution['patterns'], $key, 0);
    }

    /**
     * @return array{person: bool, level: ?string, rule: ?string, patterns: list<string>}
     */
    public function resolve(TypeTiers $type, bool $flaggedPerson): array {
        $config = $this->config();
        $person = $flaggedPerson;

        $levels = ['N3' => $type->getTypeN3(), 'N2' => $type->getTypeN2(), 'N1' => $type->getTypeN1()];
        foreach ($levels as $level => $label) {
            $words = self::words($label);
            foreach ($config['rules'] as $rule) {
                // Une personne n'adopte que des modèles « personne » ; sinon : « Prénom Nom » par défaut.
                if ($person && !$rule['person']) {
                    continue;
                }
                foreach ($rule['keywords'] as $keyword) {
                    if (self::contains($words, $keyword)) {
                        return ['person' => $person, 'level' => $level, 'rule' => $rule['label'], 'patterns' => $rule['names']];
                    }
                }
            }
        }

        return ['person' => $person, 'level' => null, 'rule' => null, 'patterns' => []];
    }

    // ------------------------------------------------------------------
    // Construction du nom
    // ------------------------------------------------------------------

    /** @param list<string> $patterns */
    private function build(array $patterns, string|int $key, int $attempt): string {
        $pattern = $this->faker->pick($patterns, 'tn_pattern', $key, $attempt);

        return strtr($pattern, [
            '{prenom}' => $this->faker->firstName($key, $attempt),
            '{nom}' => $this->faker->lastName($key, $attempt),
            '{lieu}' => $this->faker->pick(self::PLACES, 'tn_place', $key, $attempt),
        ]);
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /** @return array{rules: list<array{keywords: list<list<string>>, names: list<string>, person: bool, label: string}>} */
    private function config(): array {
        if ($this->config !== null) {
            return $this->config;
        }

        $data = is_file($this->file) ? (Yaml::parseFile($this->file) ?: []) : [];
        $rules = [];
        foreach ((array) ($data['rules'] ?? []) as $rule) {
            $keywords = [];
            foreach ((array) ($rule['keywords'] ?? []) as $keyword) {
                $words = self::words((string) $keyword, true);
                if ($words !== []) {
                    $keywords[] = $words;
                }
            }
            $names = array_values(array_filter(array_map('strval', (array) ($rule['names'] ?? [])), static fn (string $n): bool => trim($n) !== ''));
            if ($keywords === [] || $names === []) {
                continue;
            }
            $rules[] = [
                'keywords' => $keywords,
                'names' => $names,
                'person' => (bool) ($rule['person'] ?? false),
                'label' => implode(', ', array_map(static fn (string $k): string => (string) $k, (array) $rule['keywords'])),
            ];
        }

        return $this->config = ['rules' => $rules];
    }

    // ------------------------------------------------------------------
    // Normalisation / comparaison
    // ------------------------------------------------------------------

    /**
     * « Travail & Ressources » => ['travail', 'ressource'] : minuscules, sans accents ni ponctuation,
     * « et » ignoré, pluriels simplifiés. Avec $keepStar, le « * » final d'un mot (préfixe) est conservé.
     *
     * @return list<string>
     */
    public static function words(string $text, bool $keepStar = false): array {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n',
        ]);
        $text = preg_replace($keepStar ? '/[^a-z0-9*]+/' : '/[^a-z0-9]+/', ' ', $text) ?? '';

        $words = [];
        foreach (explode(' ', trim($text)) as $word) {
            if ($word === '' || $word === 'et') {
                continue;
            }
            $star = str_ends_with($word, '*');
            $word = rtrim($word, '*');
            if (strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
                $word = substr($word, 0, -1);
            }
            $words[] = $star ? $word . '*' : $word;
        }

        return $words;
    }

    /** La suite de mots $needle figure-t-elle (consécutive) dans $haystack ? Un mot en « xxx* » est un préfixe. */
    private static function contains(array $haystack, array $needle): bool {
        $n = count($needle);
        for ($i = 0, $max = count($haystack) - $n; $i <= $max; $i++) {
            foreach ($needle as $j => $expected) {
                $actual = $haystack[$i + $j];
                $ok = str_ends_with($expected, '*') ? str_starts_with($actual, rtrim($expected, '*')) : $actual === $expected;
                if (!$ok) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
