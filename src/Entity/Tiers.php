<?php

namespace App\Entity;

use App\Repository\TiersRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TiersRepository::class)]
class Tiers {

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $searchText = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private TypeTiers $tiersType;

    #[ORM\OneToMany(
                mappedBy: 'tiers',
                targetEntity: TiersAdresse::class,
                orphanRemoval: true,
                cascade: ['persist']
        )]
    private Collection $tiersAdresses;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function __construct() {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new DateTimeImmutable();
        $this->tiersAdresses = new ArrayCollection();
    }

    // ======================
    // GETTERS
    // ======================

    public function getId(): string {
        return $this->id;
    }

    public function getCode(): string {
        return $this->code ?? $this->id;
    }

    public function getName(): ?string {
        return $this->name;
    }

    public function getSearchText(): ?string {
        return $this->searchText;
    }

    public function getTiersType(): TypeTiers {
        return $this->tiersType;
    }

    public function getCreatedAt(): DateTimeImmutable {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable {
        return $this->deletedAt;
    }

    public function getTiersAdresses(): Collection {
        return $this->tiersAdresses;
    }

    // ======================
    // SETTERS
    // ======================

    public function setName(string $name): self {
        $this->name = $name;
        return $this;
    }

    public function setSearchText(?string $searchText): self {
        $this->searchText = $searchText;
        return $this;
    }

    public function setTiersType(TypeTiers $tiersType): self {
        $this->tiersType = $tiersType;
        return $this;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt) {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function setDeletedAt(?DateTimeImmutable $deletedAt): self {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    // ======================
    // LOGIQUE METIER
    // ======================

    public function regenerateCode(): void {
        $this->code = sprintf(
                'T-%s-%s-%s',
                $this->tiersType->getCode(),
                $this->createdAt->format('ymd'),
                $this->makeCode($this->name)
        );
    }

    private function makeCode(?string $value, int $length = 10): ?string {
        if (!$value) {
            return null;
        }

        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $lettersOnly = preg_replace('/[^A-Za-z ]/', '', $normalized);
        $underscored = preg_replace('/\s+/', '_', trim($lettersOnly));

        return strtoupper(substr($underscored, 0, $length));
    }
}
