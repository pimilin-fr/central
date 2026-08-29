<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['tiers_id', 'adresse_id'])]
class TiersAdresse {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tiersAdresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tiers $tiers;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Adresse $adresse;

    #[ORM\Column]
    private bool $isPrincipale = false;

    public function getId(): ?int {
        return $this->id;
    }

    public function getTiers(): Tiers {
        return $this->tiers;
    }

    public function getAdresse(): Adresse {
        return $this->adresse;
    }

    public function getIsPrincipale(): bool {
        return $this->isPrincipale;
    }

    public function isPrincipale(): bool {
        return $this->getIsPrincipale();
    }

    public function getType(): ?string {
        return $this->type;
    }

    public function setId(?int $id) {
        $this->id = $id;
        return $this;
    }

    public function setTiers(Tiers $tiers) {
        $this->tiers = $tiers;
        return $this;
    }

    public function setAdresse(Adresse $adresse) {
        $this->adresse = $adresse;
        return $this;
    }

    public function setIsPrincipale(bool $isPrincipale) {
        $this->isPrincipale = $isPrincipale;
        return $this;
    }

    public function setType(?string $type) {
        $this->type = $type;
        return $this;
    }
}
