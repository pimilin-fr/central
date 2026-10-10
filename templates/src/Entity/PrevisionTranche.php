<?php

namespace App\Entity;

use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Montant applicable à partir d'une date (jusqu'à la tranche suivante). Ex. LOA : 39 € puis, après la fin, 15 €. */
#[ORM\Entity]
#[ORM\Table(name: 'prevision_tranche')]
class PrevisionTranche {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tranches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PrevisionRegle $regle = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?DateTime $aPartirDe = null;

    /** Montant positif (le signe vient de la catégorie : dépense / revenu). */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montant = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantMin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantMax = null;

    public function getId(): ?int { return $this->id; }
    public function getRegle(): ?PrevisionRegle { return $this->regle; }
    public function setRegle(?PrevisionRegle $regle): static { $this->regle = $regle; return $this; }
    public function getAPartirDe(): ?DateTime { return $this->aPartirDe; }
    public function setAPartirDe(?DateTime $d): static { $this->aPartirDe = $d; return $this; }
    public function getMontant(): string { return $this->montant; }
    public function setMontant(string|float|int|null $montant): static { $this->montant = number_format((float) $montant, 2, '.', ''); return $this; }
    public function getMontantMin(): ?string { return $this->montantMin; }
    public function setMontantMin(string|float|int|null $v): static { $this->montantMin = $v === null || $v === '' ? null : number_format((float) $v, 2, '.', ''); return $this; }
    public function getMontantMax(): ?string { return $this->montantMax; }
    public function setMontantMax(string|float|int|null $v): static { $this->montantMax = $v === null || $v === '' ? null : number_format((float) $v, 2, '.', ''); return $this; }
}
