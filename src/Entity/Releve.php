<?php

namespace App\Entity;

//use Doctrine\Common\Collections\ArrayCollection;


use App\Repository\ReleveRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReleveRepository::class)]
#[ORM\Table(name: 'releve')]
class Releve {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?DateTime $date = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Portefeuille $portefeuille;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private float $totalDepense = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private float $totalRevenu = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private float $lastSolde = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private float $newSolde = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isClosed = false;

    #[ORM\OneToMany(mappedBy: 'releve', targetEntity: Depenses::class)]
    #[ORM\OrderBy(['date' => 'DESC', 'id' => 'DESC'])]
    private Collection $depenses;

    public function __construct() {
        $this->date = new DateTime();
        $this->depenses = new ArrayCollection();
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function getDate(): ?DateTime {
        return $this->date;
    }

    public function getPortefeuille(): Portefeuille {
        return $this->portefeuille;
    }

    public function getTotalDepense(): float {
        return $this->totalDepense;
    }

    public function getTotalRevenu(): float {
        return $this->totalRevenu;
    }

    public function getLastSolde(): float {
        return $this->lastSolde;
    }

    public function getNewSolde(): float {
        return $this->newSolde;
    }

    public function getLignes(): Collection {
        return $this->lignes;
    }

    public function getLabel(): ?string {
        return $this->label;
    }

    public function setLabel(?string $label) {
        $this->label = $label;
        return $this;
    }

    public function setId(int $id) {
        $this->id = $id;
        return $this;
    }

    public function setDate(?DateTime $date) {
        $this->date = $date;
        return $this;
    }

    public function setPortefeuille(Portefeuille $portefeuille) {
        $this->portefeuille = $portefeuille;
        return $this;
    }

    public function setTotalDepense(float $totalDepense) {
        $this->totalDepense = $totalDepense;
        return $this;
    }

    public function setTotalRevenu(float $totalRevenu) {
        $this->totalRevenu = $totalRevenu;
        return $this;
    }

    public function setLastSolde(float $lastSolde) {
        $this->lastSolde = $lastSolde;
        return $this;
    }

    public function setNewSolde(float $newSolde) {
        $this->newSolde = $newSolde;
        return $this;
    }

    public function getDepenses(): Collection {
        return $this->depenses;
    }

    public function setDepenses(Collection $depenses) {
        $this->depenses = $depenses;
        return $this;
    }

    public function getIsClosed(): bool {
        return $this->isClosed;
    }

    public function setIsClosed(bool $isClosed) {
        $this->isClosed = $isClosed;
        return $this;
    }
}
