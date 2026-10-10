<?php

namespace App\Entity;

use App\Prevision\Certitude;
use App\Prevision\StatutEcheance;
use App\Repository\PrevisionEcheanceRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une occurrence prévue d'une règle. « Concrétisée » = une vraie opération (Depenses) a été créée. */
#[ORM\Entity(repositoryClass: PrevisionEcheanceRepository::class)]
#[ORM\Table(name: 'prevision_echeance')]
#[ORM\UniqueConstraint(name: 'uniq_prevision_regle_rang', columns: ['regle_id', 'rang'])]
class PrevisionEcheance {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'echeances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PrevisionRegle $regle = null;

    /** Numéro de l'occurrence dans la règle (0 = la première) : identifiant stable de l'échéance. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $rang = 0;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?DateTime $datePrevue = null;

    /** Fin de fenêtre éventuelle. */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTime $dateFin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montant = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantMin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantMax = null;

    #[ORM\Column(length: 20, enumType: Certitude::class)]
    private Certitude $certitude = Certitude::CERTAIN;

    #[ORM\Column(length: 20, enumType: StatutEcheance::class)]
    private StatutEcheance $statut = StatutEcheance::PREVUE;

    /** Modifiée à la main : la régénération de la règle n'y touche plus. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $ajustee = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Depenses $depense = null;

    public function getId(): ?int { return $this->id; }
    public function getRegle(): ?PrevisionRegle { return $this->regle; }
    public function setRegle(?PrevisionRegle $regle): static { $this->regle = $regle; return $this; }
    public function getRang(): int { return $this->rang; }
    public function setRang(int $rang): static { $this->rang = $rang; return $this; }
    public function getDatePrevue(): ?DateTime { return $this->datePrevue; }
    public function setDatePrevue(?DateTime $d): static { $this->datePrevue = $d; return $this; }
    public function getDateFin(): ?DateTime { return $this->dateFin; }
    public function setDateFin(?DateTime $d): static { $this->dateFin = $d; return $this; }
    public function getMontant(): string { return $this->montant; }
    public function setMontant(string|float|int $montant): static { $this->montant = number_format((float) $montant, 2, '.', ''); return $this; }
    public function getMontantMin(): ?string { return $this->montantMin; }
    public function setMontantMin(?string $v): static { $this->montantMin = $v; return $this; }
    public function getMontantMax(): ?string { return $this->montantMax; }
    public function setMontantMax(?string $v): static { $this->montantMax = $v; return $this; }
    public function getCertitude(): Certitude { return $this->certitude; }
    public function setCertitude(Certitude $c): static { $this->certitude = $c; return $this; }
    public function getStatut(): StatutEcheance { return $this->statut; }
    public function setStatut(StatutEcheance $s): static { $this->statut = $s; return $this; }
    public function isPrevue(): bool { return $this->statut === StatutEcheance::PREVUE; }
    public function isAjustee(): bool { return $this->ajustee; }
    public function setAjustee(bool $a): static { $this->ajustee = $a; return $this; }
    public function getDepense(): ?Depenses { return $this->depense; }
    public function setDepense(?Depenses $d): static { $this->depense = $d; return $this; }

    /** Montant avec le signe comptable (dépense négative). */
    public function getMontantSigne(): float {
        $m = (float) $this->montant;

        return $this->regle?->getCategorie()?->isDepense() ? -$m : $m;
    }
}
