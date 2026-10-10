<?php

namespace App\Prevision;

enum Frequence: string {

    case MENSUELLE = 'mensuelle';
    case BIMESTRIELLE = 'bimestrielle';
    case TRIMESTRIELLE = 'trimestrielle';
    case SEMESTRIELLE = 'semestrielle';
    case ANNUELLE = 'annuelle';

    public function mois(): int {
        return match ($this) {
            self::MENSUELLE => 1,
            self::BIMESTRIELLE => 2,
            self::TRIMESTRIELLE => 3,
            self::SEMESTRIELLE => 6,
            self::ANNUELLE => 12,
        };
    }

    public function label(): string {
        return match ($this) {
            self::MENSUELLE => 'Chaque mois',
            self::BIMESTRIELLE => 'Tous les 2 mois',
            self::TRIMESTRIELLE => 'Chaque trimestre',
            self::SEMESTRIELLE => 'Chaque semestre',
            self::ANNUELLE => 'Chaque année',
        };
    }
}
