<?php

namespace App\Prevision;

enum Certitude: string {

    case CERTAIN = 'certain';
    case PROBABLE = 'probable';
    case ESTIME = 'estime';

    /** Pondération appliquée aux totaux prévisionnels. */
    public function poids(): float {
        return match ($this) {
            self::CERTAIN => 1.0,
            self::PROBABLE => 0.7,
            self::ESTIME => 0.4,
        };
    }

    public function label(): string {
        return match ($this) {
            self::CERTAIN => 'Certain',
            self::PROBABLE => 'Probable',
            self::ESTIME => 'Estimé',
        };
    }
}
