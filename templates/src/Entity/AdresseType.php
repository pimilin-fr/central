<?php

namespace App\Entity;

use App\Demo\DemoEntityInterface;
use App\Demo\DemoStrategy;
use App\Repository\AdresseTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Override;

#[ORM\Entity(repositoryClass: AdresseTypeRepository::class)]
class AdresseType extends ColorableEntity implements DemoEntityInterface 
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 10)]
    private ?string $color = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    #[Override]
    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    #[Override]
    public function getCouleur(): ?string {
        return $this->color;
    }

    #[\Override]
    public function getDemoStrategy(): DemoStrategy {
        return DemoStrategy::COPY;
    }
}
