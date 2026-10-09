<?php

namespace App;

use App\Demo\DbMode;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Bascule prod / démo : var/db_mode fixe DATABASE_URL avant que le conteneur ne lise l'environnement.
        if (!$this->booted) {
            DbMode::apply($this->getProjectDir());
        }

        parent::boot();
    }
}
