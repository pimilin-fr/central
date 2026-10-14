<?php

namespace App\Service\Settings;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paramètres de l'application, stockés dans var/settings.json (aucun changement de base de données).
 * Seules les valeurs modifiées sont écrites : le reste retombe sur les valeurs par défaut ci-dessous.
 * Chaque paramètre : groupe, libellé, aide, type (int), unité d'affichage, défaut, min, max.
 */
final class AppSettings {

    /** @var array<string,array<string,mixed>> */
    public const DEFINITIONS = [
        'adresse.tolerance.quartier' => ['group' => 'Proposition de rattachement (adresses)', 'label' => 'Quartier : distance max proposée', 'unit' => 'km', 'default' => 1, 'min' => 0, 'max' => 20000, 'float' => true,
            'help' => 'Au-delà de cette distance à la zone, un quartier n\'est proposé que via « Voir des zones plus éloignées ».'],
        'adresse.tolerance.ville' => ['group' => 'Proposition de rattachement (adresses)', 'label' => 'Ville : distance max proposée', 'unit' => 'km', 'default' => 8, 'min' => 0, 'max' => 20000, 'float' => true, 'help' => ''],
        'adresse.tolerance.departement' => ['group' => 'Proposition de rattachement (adresses)', 'label' => 'Département : distance max proposée', 'unit' => 'km', 'default' => 30, 'min' => 0, 'max' => 20000, 'float' => true, 'help' => ''],
        'adresse.tolerance.region' => ['group' => 'Proposition de rattachement (adresses)', 'label' => 'Région : distance max proposée', 'unit' => 'km', 'default' => 100, 'min' => 0, 'max' => 20000, 'float' => true, 'help' => ''],
        'adresse.tolerance.pays' => ['group' => 'Proposition de rattachement (adresses)', 'label' => 'Pays : distance max proposée', 'unit' => 'km', 'default' => 500, 'min' => 0, 'max' => 20000, 'float' => true, 'help' => ''],
        'adresse.tolerance.ratio' => ['group' => 'Proposition de rattachement (adresses)', 'label' => 'Tolérance liée à la taille de la zone', 'unit' => '% du diamètre', 'default' => 50, 'min' => 0, 'max' => 1000,
            'help' => 'La distance max est le plus grand de : la valeur du niveau ci-dessus, ou ce pourcentage du diamètre de la zone (une grande zone tolère plus).'],
        'adresse.margin.many' => ['group' => 'Zones (adresses)', 'label' => 'Marge autour d\'une zone (3 points ou plus)', 'unit' => 'm', 'default' => 100, 'min' => 0, 'max' => 100000,
            'help' => 'Même logique que la carte de visualisation des adresses.'],
        'adresse.margin.few' => ['group' => 'Zones (adresses)', 'label' => 'Rayon minimal d\'une zone (1 ou 2 points)', 'unit' => 'm', 'default' => 250, 'min' => 0, 'max' => 100000, 'help' => ''],
        'adresse.limit' => ['group' => 'Affichage (adresses)', 'label' => 'Nombre de zones proposées', 'unit' => '', 'default' => 5, 'min' => 1, 'max' => 12, 'help' => ''],
        'adresse.limit_all' => ['group' => 'Affichage (adresses)', 'label' => 'Nombre de zones avec « plus éloignées »', 'unit' => '', 'default' => 12, 'min' => 1, 'max' => 30, 'help' => ''],
    ];

    private ?array $stored = null;

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {
    }

    private function file(): string {
        return $this->projectDir . '/var/settings.json';
    }

    private function load(): array {
        if ($this->stored === null) {
            $data = is_file($this->file()) ? json_decode((string) @file_get_contents($this->file()), true) : null;
            $this->stored = is_array($data) ? $data : [];
        }
        return $this->stored;
    }

    public function get(string $key): int|float {
        $def = self::DEFINITIONS[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Paramètre inconnu : ' . $key);
        }
        $v = $this->load()[$key] ?? $def['default'];
        $v = ($def['float'] ?? false) ? round((float) $v, 3) : (int) $v;

        return max($def['min'], min($def['max'], $v));
    }

    /** @return array<string,int|float> toutes les valeurs effectives */
    public function all(): array {
        $out = [];
        foreach (array_keys(self::DEFINITIONS) as $k) {
            $out[$k] = $this->get($k);
        }
        return $out;
    }

    /**
     * Enregistre les valeurs postées (clé « adresse.limit » postée sous « adresse_limit » : les points sont mal gérés par PHP).
     * Valeur vide = retour au défaut. @return list<string> erreurs
     */
    public function save(array $posted): array {
        $errors = [];
        $new = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $raw = $posted[str_replace('.', '_', $key)] ?? '';
            $raw = trim(str_replace([' ', ','], ['', '.'], (string) $raw));
            if ($raw === '') {
                continue;
            }
            if (!is_numeric($raw)) {
                $errors[] = $def['label'] . ' : nombre attendu.';
                continue;
            }
            $v = ($def['float'] ?? false) ? round((float) $raw, 3) : (int) round((float) $raw);
            if ($v < $def['min'] || $v > $def['max']) {
                $errors[] = sprintf('%s : entre %s et %s.', $def['label'], $def['min'], $def['max']);
                continue;
            }
            if ($v !== $def['default']) {
                $new[$key] = $v;
            }
        }
        if ($errors === []) {
            $dir = dirname($this->file());
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $tmp = $this->file() . '.tmp';
            if (@file_put_contents($tmp, json_encode($new, JSON_PRETTY_PRINT)) === false || !@rename($tmp, $this->file())) {
                $errors[] = 'Écriture impossible dans var/settings.json (droits du dossier var).';
            } else {
                $this->stored = $new;
            }
        }
        return $errors;
    }

    public function reset(): void {
        @unlink($this->file());
        $this->stored = [];
    }
}
