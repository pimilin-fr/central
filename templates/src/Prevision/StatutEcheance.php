<?php

namespace App\Prevision;

enum StatutEcheance: string {

    case PREVUE = 'prevue';
    case REALISEE = 'realisee';
    case IGNOREE = 'ignoree';
}
