<?php

namespace App\Entity;

use App\Repository\ProjetTypeRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjetTypeRepository::class)]
#[ORM\Table(name: 'projet_type')]
class ProjetType {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    public function __construct() {
        $this->children = new ArrayCollection();
    }

    public function getFullname(): string {
        return $this->__toString();
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

    public function getLibelle(): string {
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

    public function getAllDescendants(ProjetType $type): array {
        $types = [$type];

        foreach ($type->getChildren() as $child) {
            $types = array_merge(
                    $types,
                    $this->getAllDescendants($child)
            );
        }

        return $types;
    }
}
