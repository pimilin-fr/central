<?php

namespace App\Service\DepenseGrouper;

use App\Entity\Depenses;
use DateTime;

class DepenseGroup {

    private string $key;
    private string $label;
    private array $depenses = [];
    private float $totalDepense = 0;
    private float $totalRevenu = 0;
    private float $previousBalance = 0;
    private float $currentBalance = 0;

    // --- KEY ---
    public function getKey(): string {
        return $this->key;
    }

    public function setKey(string $key): self {
        $this->key = $key;
        return $this;
    }

    // --- LABEL ---
    public function getLabel(): string {
        return $this->label;
    }

    public function setLabel(string $label): self {
        $this->label = $label;
        return $this;
    }

    // --- DEPENSES ---
    public function getDepenses(): array {
        return $this->depenses;
    }

    public function addDepense(Depenses $depense): self {
        $this->depenses[] = $depense;

        if ($depense->getCategorie()->isDepense()) {
            $this->totalDepense += $depense->getMontant();
        } else {
            $this->totalRevenu += $depense->getMontant();
        }

        return $this;
    }

    // --- TOTALS ---
    public function getTotalDepense(): float {
        return $this->totalDepense;
    }

    public function getTotalRevenu(): float {
        return $this->totalRevenu;
    }

    public function getNet(): float {
        return $this->totalRevenu - $this->totalDepense;
    }

    // --- SOLDES ---
    public function setPreviousBalance(float $balance): self {
        $this->previousBalance = $balance;
        $this->currentBalance = $balance + $this->getNet();
        return $this;
    }

    public function getPreviousBalance(): float {
        return $this->previousBalance;
    }

    public function getCurrentBalance(): float {
        return $this->currentBalance;
    }
    
    public function setCurrentBalance(float $currentBalance) {
        $this->currentBalance = $currentBalance;
        return $this;
    }

   

    // --- TOTAL GLOBAL ---
    public function getTotal(): float {
        return $this->getNet(); // plus logique ici 👍
    }
}
