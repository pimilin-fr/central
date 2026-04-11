<?php

namespace App\Entity;

use App\Repository\CategorieRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategorieRepository::class)]
#[ORM\Table(name: 'categorie')]
class Categorie {

    private const NATURE_DEP = 'N.DEP';
    public const NATURE_MAP = [
//        null => "N/A",
        'Dépense' => self::NATURE_DEP,
        'Revenu' => 'N.REV',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private ?string $libelle = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $natureLibelle = null; // DEP / REV

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $natureCode = null; // N.DEP / N.REV

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function __construct() {
        $this->children = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function __toString(): string {
        if ($this->parent) {
            return $this->parent->__toString() . " / " . $this->name;
        }

        return $this->name;
    }

    // --------------------
    // Getters / setters
    // --------------------

    public function getId(): ?int {
        return $this->id;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): self {
        $this->name = $name;
        return $this;
    }

    public function getLibelle(): ?string {
        return $this->libelle;
    }

    public function setLibelle(string $libelle) {
        $this->libelle = $libelle;
        return $this;
    }

    public function getParent(): ?self {
        return $this->parent;
    }

    public function setParent(?self $parent): self {
        $this->parent = $parent;
        return $this;
    }

    public function getChildren(): Collection {
        return $this->children;
    }

    public function isLeaf(): bool {
        return $this->children->isEmpty();
    }

    public function isDepense(): bool {
        return self::NATURE_DEP == $this->getNature();
    }

    public function setNature(?string $nature): self {
//        var_dump(self::NATURE_MAP,$nature);die;
        $this->natureLibelle = array_flip(self::NATURE_MAP)[$nature];
        $this->natureCode = $nature;
        return $this;
    }

    public function getNature(): ?string {
        return $this->natureCode;
    }

    public function getNatureLibelle(): ?string {
        return $this->natureLibelle;
    }

    public function getNatureCode(): ?string {
        return $this->natureCode;
    }

    public function setNatureCode(?string $natureCode): self {
        $this->natureCode = $natureCode;
        return $this;
    }

    public function getDeletedAt(): ?DateTimeImmutable {
        return $this->deletedAt;
    }

    public function getCreatedAt(): DateTimeImmutable {
        return $this->createdAt;
    }

    public function softDelete(): self {
        $this->deletedAt = new DateTimeImmutable();
        return $this;
    }
}
