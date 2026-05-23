<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'v_portefeuille')]
class PortefeuilleView extends ColorableEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'ptf_id', type: 'integer')]
    private int $id;

    #[ORM\Column(name: 'ptf_code', type: 'string', length: 50, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(name: 'ptf_name', type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'ptf_type', type: 'string', length: 255, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(name: 'ptf_origine', type: 'string', length: 255, nullable: true)]
    private ?string $origine = null;

    #[ORM\Column(name: 'ptf_libelle', type: 'string', length: 255)]
    private string $libelle;

    #[ORM\Column(name: 'ptf_is_default', type: 'boolean')]
    private bool $isDefault;
    
    #[ORM\Column(name: 'ptf_is_real', type: 'boolean')]
    private bool $isReal = false;

    #[ORM\Column(name: 'ptf_couleur', type: 'string', length: 7, nullable: true)]
    private ?string $couleur = null;

    #[ORM\ManyToOne(targetEntity: Releve::class)]
    #[ORM\JoinColumn(name: 'ptf_releve_id', referencedColumnName: 'id', nullable: true)]
    private ?Releve $releve = null;

    #[ORM\Column(name: 'ptf_last_solde', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $lastSolde = null;

    // 🔹 Totaux globaux
    #[ORM\Column(name: 'ptf_total_revenu', type: 'decimal', precision: 32, scale: 2)]
    private float $totalRevenu;

    #[ORM\Column(name: 'ptf_total_depenses', type: 'decimal', precision: 32, scale: 2)]
    private float $totalDepenses;

    // 🔹 Non comptabilisé
    #[ORM\Column(name: 'ptf_revenu_non_compta', type: 'decimal', precision: 32, scale: 2)]
    private float $revenuNonCompta;

    #[ORM\Column(name: 'ptf_depenses_non_compta', type: 'decimal', precision: 32, scale: 2)]
    private float $depensesNonCompta;

    // ========================
    // 🔸 MÉTHODES MÉTIER
    // ========================

    public function getBalanceActuelle(): float
    {
        return ($this->lastSolde ?? 0)
            + $this->revenuNonCompta
            - $this->depensesNonCompta;
    }

    public function getSoldeTheoriqueGlobal(): float
    {
        return $this->totalRevenu - $this->totalDepenses;
    }

    // ========================
    // 🔸 GETTERS
    // ========================

    public function getId(): int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    #[\Override]
    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function getReleve(): ?Releve
    {
        return $this->releve;
    }

    public function getLastSolde(): ?float
    {
        return $this->lastSolde;
    }

    public function getTotalRevenu(): float
    {
        return $this->totalRevenu;
    }

    public function getTotalDepenses(): float
    {
        return $this->totalDepenses;
    }

    public function getRevenuNonCompta(): float
    {
        return $this->revenuNonCompta;
    }

    public function getDepensesNonCompta(): float
    {
        return $this->depensesNonCompta;
    }
    
    public function getIsDefault(): bool {
        return $this->isDefault;
    }

    public function getIsReal(): bool {
        return $this->isReal;
    }

    public function setIsDefault(bool $isDefault) {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function setIsReal(bool $isReal) {
        $this->isReal = $isReal;
        return $this;
    }


}