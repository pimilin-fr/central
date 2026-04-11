<?php

namespace App\Entity;

use App\Repository\DepensesRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DepensesRepository::class)]
class Depenses {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?DateTime $date = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $numCommande = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $montant = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTime $dateReleve = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Categorie $categorie;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Projet $projet = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Tiers $tiers;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Portefeuille $portefeuille = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Releve $releve = null;

    public function getId(): ?int {
        return $this->id;
    }

    public function getDate(): ?DateTime {
        return $this->date;
    }

    public function setDate(DateTime $date): static {
        $this->date = $date;

        return $this;
    }

    public function getNumCommande(): ?string {
        return $this->numCommande;
    }

    public function setNumCommande(?string $numCommande): static {
        $this->numCommande = $numCommande;

        return $this;
    }

    public function getMontant(): ?string {
        return $this->montant;
    }

    public function setMontant(string $montant): static {
        $this->montant = $montant;

        return $this;
    }

    public function getDateReleve(): ?DateTime {
        return $this->dateReleve;
    }

    public function setDateReleve(?DateTime $dateReleve): static {
        $this->dateReleve = $dateReleve;

        return $this;
    }

    public function getNote(): ?string {
        return $this->note;
    }

    public function setNote(?string $note): static {
        $this->note = $note;

        return $this;
    }

    public function getCategorie(): Categorie {
        return $this->categorie;
    }

    public function getProjet(): ?Projet {
        return $this->projet;
    }

    public function getTiers(): Tiers {
        return $this->tiers;
    }

    public function getPortefeuille(): ?Portefeuille {
        return $this->portefeuille;
    }

    public function setCategorie(Categorie $categorie) {
        $this->categorie = $categorie;
        return $this;
    }

    public function setProjet(?Projet $projet) {
        $this->projet = $projet;
        return $this;
    }

    public function setTiers(Tiers $tiers) {
        $this->tiers = $tiers;
        return $this;
    }

    public function setPortefeuille(Portefeuille $portefeuille) {
        $this->portefeuille = $portefeuille;
        return $this;
    }

    public function getReleve(): ?Releve {
        return $this->releve;
    }

    public function setReleve(?Releve $releve) {
        $this->releve = $releve;
        return $this;
    }
}
