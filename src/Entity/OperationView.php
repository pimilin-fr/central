<?php

namespace App\Entity;

use App\Repository\OperationViewRepository;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OperationViewRepository::class, readOnly: true)]
#[ORM\Table(name: 'v_operation')]

class OperationView {

    #[ORM\Id]
    #[ORM\Column(name: 'ope_id')]
    private int $id;

    #[ORM\Column(name: 'ope_date', type: 'date')]
    private DateTimeInterface $date;

    #[ORM\ManyToOne(targetEntity: Portefeuille::class)]
    #[ORM\JoinColumn(name: 'ope_portefeuille_id', referencedColumnName: 'id', nullable: true)]
    private Portefeuille $portefeuille;

    #[ORM\Column(name: 'ope_reel_montant', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $montant;

    #[ORM\Column(name: 'ope_reel_revenu', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $revenu;

    #[ORM\Column(name: 'ope_reel_depense', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $depense;

    #[ORM\Column(name: 'ope_commande', type: 'string', nullable: true)]
    private ?string $numCommande;

    #[ORM\ManyToOne(targetEntity: Releve::class)]
    #[ORM\JoinColumn(name: 'ope_releve_id', referencedColumnName: 'id', nullable: true)]
    private ?Releve $releve;

    #[ORM\ManyToOne(targetEntity: Categorie::class)]
    #[ORM\JoinColumn(name: 'ope_categorie_id', referencedColumnName: 'id', nullable: true)]
    private Categorie $categorie;

    #[ORM\ManyToOne(targetEntity: Tiers::class)]
    #[ORM\JoinColumn(name: 'ope_tiers_id', referencedColumnName: 'id', nullable: true)]
    private Tiers $tiers;

    #[ORM\ManyToOne(targetEntity: Projet::class)]
    #[ORM\JoinColumn(name: 'ope_projet_id', referencedColumnName: 'id', nullable: true)]
    private ?Projet $projet;

    #[ORM\Column(name: 'ope_note', nullable: true)]
    private ?string $note;

    public function getId(): int {
        return $this->id;
    }

    public function getDate(): DateTimeInterface {
        return $this->date;
    }

    public function getPortefeuille(): Portefeuille {
        return $this->portefeuille;
    }

    public function getMontant(): ?float {
        return $this->montant;
    }

    public function getRevenu(): ?float {
        return $this->revenu;
    }

    public function getDepense(): ?float {
        return $this->depense;
    }

    public function getCategorie(): Categorie {
        return $this->categorie;
    }

    public function getTiers(): Tiers {
        return $this->tiers;
    }

    public function getNote(): ?string {
        return $this->note;
    }

    public function getProjet(): ?Projet {
        return $this->projet;
    }

    public function getNumCommande(): ?string {
        return $this->numCommande;
    }

    public function getReleve(): ?Releve {
        return $this->releve;
    }
}
