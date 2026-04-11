<?php

namespace App\Entity;

use App\Repository\ProjetRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjetRepository::class)]
#[ORM\Table(name: 'projet')]
class Projet extends ColorableEntity {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id=null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    // 🔹 Relation vers une type Adresse
    #[ORM\ManyToOne(targetEntity: ProjetType::class)]
    #[ORM\JoinColumn(name: "projet_type_id", referencedColumnName: "id", nullable: false)]
    private ProjetType $type;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $beginAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $endAt = null;

    public function __construct() {
        $this->beginAt = new DateTimeImmutable();
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function getName(): ?string {
        return $this->name;
    }

    public function getType(): ProjetType {
        return $this->type;
    }

    public function getBeginAt(): DateTimeImmutable {
        return $this->beginAt;
    }

    public function getEndAt(): ?DateTimeImmutable {
        return $this->endAt;
    }

    #[\Override]
    public function getCouleur(): ?string {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur) {
        $this->couleur = $couleur;
        return $this;
    }

    public function setId(int $id) {
        $this->id = $id;
        return $this;
    }

    public function setName(?string $name) {
        $this->name = $name;
        return $this;
    }

    public function setType(ProjetType $type) {
        $this->type = $type;
        return $this;
    }

    public function setBeginAt(DateTimeImmutable $beginAt) {
        $this->beginAt = $beginAt;
        return $this;
    }

    public function setEndAt(?DateTimeImmutable $endAt) {
        $this->endAt = $endAt;
        return $this;
    }
    
    public function __toString(): string {
        return $this->name." (".$this->getBeginAt()->format('Y').")";
//        return "Projet[id=" . $this->id
//                . ", name=" . $this->name
//                . ", couleur=" . $this->couleur
//                . ", type=" . $this->type
//                . ", beginAt=" . $this->beginAt
//                . ", endAt=" . $this->endAt
//                . "]";
    }
}
