<?php

namespace App\Service\DepenseGrouper;

use App\Entity\Depenses;

class DepenseGroup
{
    private string $key;
    private string $label;
    private array $depenses = [];

    private float $totalDepense = 0;
    private float $totalRevenu = 0;

    private float $previousBalance = 0;
    private float $currentBalance = 0;

    private bool $cumulative = false;

    // =========================================================
    // KEY
    // =========================================================

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    // =========================================================
    // LABEL
    // =========================================================

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    // =========================================================
    // DEPENSES
    // =========================================================

    public function getDepenses(): array
    {
        return $this->depenses;
    }

    public function addDepense(Depenses $depense): self
    {
        $this->depenses[] = $depense;

        if ($depense->getCategorie()->isDepense()) {
            $this->totalDepense += $depense->getMontant();
        } else {
            $this->totalRevenu += $depense->getMontant();
        }

        return $this;
    }

    // =========================================================
    // TOTALS
    // =========================================================

    public function getTotalDepense(): float
    {
        return $this->totalDepense;
    }

    public function getTotalRevenu(): float
    {
        return $this->totalRevenu;
    }

    public function getNet(): float
    {
        return $this->totalRevenu - $this->totalDepense;
    }

    public function getTotal(): float
    {
        return $this->getNet();
    }

    // =========================================================
    // CUMUL
    // =========================================================

    public function isCumulative(): bool
    {
        return $this->cumulative;
    }

    public function setCumulative(bool $cumulative): self
    {
        $this->cumulative = $cumulative;

        return $this;
    }

    // =========================================================
    // SOLDES
    // =========================================================

    public function setPreviousBalance(float $balance): self
    {
        $this->previousBalance = $balance;
        $this->currentBalance = $balance + $this->getNet();

        return $this;
    }

    public function getPreviousBalance(): float
    {
        return $this->previousBalance;
    }

    public function setCurrentBalance(float $balance): self
    {
        $this->currentBalance = $balance;

        return $this;
    }

    public function getCurrentBalance(): float
    {
        return $this->currentBalance;
    }
}

