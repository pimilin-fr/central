<?php

namespace App\Service\DepenseGrouper;

use App\Entity\Depenses;

class DepenseGroup {

    private string $key;
    private string $label;
    private array $depenses = [];
    private float $totalDepense = 0;
    private float $totalRevenu = 0;
    private float $previousBalance = 0;
    private float $currentBalance = 0;
    private bool $cumulative = false;
    private mixed $sortValue = null;
    private bool $nullGroup = false;
    private bool $statementOrder = false;
    /** @var array<string, mixed> */
    private array $extra = [];
    // =========================================================
    // KEY
    // =========================================================

    public function getKey(): string {
        return $this->key;
    }

    public function setKey(string $key): self {
        $this->key = $key;

        return $this;
    }

    // =========================================================
    // LABEL
    // =========================================================

    public function getLabel(): string {
        return $this->label;
    }

    public function setLabel(string $label): self {
        $this->label = $label;

        return $this;
    }

    // =========================================================
    // DEPENSES
    // =========================================================

    public function getDepenses(): array {
        return $this->depenses;
    }

    /** Remplace la liste (réordonnée) : les totaux ne changent pas. */
    public function setDepenses(array $depenses): self {
        $this->depenses = array_values($depenses);

        return $this;
    }

    /** Les opérations sont dans l'ordre du relevé de compte (affichage numéroté). */
    public function isStatementOrder(): bool {
        return $this->statementOrder;
    }

    public function setStatementOrder(bool $statementOrder): self {
        $this->statementOrder = $statementOrder;

        return $this;
    }

    /** Informations complémentaires de la stratégie (voir GroupExtraInterface). */
    public function getExtra(): array {
        return $this->extra;
    }

    public function setExtra(array $extra): self {
        $this->extra = $extra;

        return $this;
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

    // =========================================================
    // TOTALS
    // =========================================================

    public function getTotalDepense(): float {
        return $this->totalDepense;
    }

    public function getTotalRevenu(): float {
        return $this->totalRevenu;
    }

    public function getNet(): float {
        return $this->totalRevenu - $this->totalDepense;
    }

    public function getTotal(): float {
        return $this->getNet();
    }

    // =========================================================
    // CUMUL
    // =========================================================

    public function isCumulative(): bool {
        return $this->cumulative;
    }

    public function setCumulative(bool $cumulative): self {
        $this->cumulative = $cumulative;

        return $this;
    }

    // =========================================================
    // SOLDES
    // =========================================================

    public function setPreviousBalance(float $balance): self {
        $this->previousBalance = $balance;
        $this->currentBalance = $balance + $this->getNet();

        return $this;
    }

    public function getPreviousBalance(): float {
        return $this->previousBalance;
    }

    public function setCurrentBalance(float $balance): self {
        $this->currentBalance = $balance;

        return $this;
    }

    public function getCurrentBalance(): float {
        return $this->currentBalance;
    }

    
    // =========================================================
    // SORTING
    // =========================================================
    public function getSortValue(): mixed {
        return $this->sortValue;
    }

    public function setSortValue(mixed $sortValue): self {
        $this->sortValue = $sortValue;

        return $this;
    }
    
    // =========================================================
    // NULL
    // =========================================================
    public function isNullGroup(): bool
{
    return $this->nullGroup;
}

public function setNullGroup(bool $nullGroup): self
{
    $this->nullGroup = $nullGroup;

    return $this;
}

}

