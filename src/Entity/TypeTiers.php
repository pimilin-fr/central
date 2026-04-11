<?php

namespace App\Entity;

use App\Repository\TypeTiersRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TypeTiersRepository::class)]
#[ORM\Table(name: 'tiers_type')]
class TypeTiers extends ColorableEntity{

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $typeN1;

    #[ORM\Column(length: 3)]
    private string $codeN1;

    #[ORM\Column(length: 100)]
    private string $typeN2;

    #[ORM\Column(length: 3)]
    private string $codeN2;

    #[ORM\Column(length: 100)]
    private string $typeN3;

    #[ORM\Column(length: 3)]
    private string $codeN3;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $libelleLiserai = null;

    public function __construct() {
        $this->id = Uuid::v7()->toRfc4122();
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

    public function getName(): string {
        return $this->name;
    }

    #[\Override]
    public function getCouleur(): ?string {
        return $this->couleur;
    }

    public function getLibelleLiserai(): ?string {
        return $this->libelleLiserai;
    }

    public function getTypeN1(): string {
        return $this->typeN1;
    }

    public function getCodeN1(): string {
        return $this->codeN1;
    }

    public function getTypeN2(): string {
        return $this->typeN2;
    }

    public function getCodeN2(): string {
        return $this->codeN2;
    }

    public function getTypeN3(): string {
        return $this->typeN3;
    }

    public function getCodeN3(): string {
        return $this->codeN3;
    }

    // ======================
    // SETTERS
    // ======================

    public function setName(string $name): self {
        $this->name = $name;
        return $this;
    }

    public function setTypeN1(string $typeN1): self {
        $this->typeN1 = $typeN1;
        return $this;
    }

    public function setCodeN1(string $codeN1): self {
        $this->codeN1 = $codeN1;
        return $this;
    }

    public function setTypeN2(string $typeN2): self {
        $this->typeN2 = $typeN2;
        return $this;
    }

    public function setCodeN2(string $codeN2): self {
        $this->codeN2 = $codeN2;
        return $this;
    }

    public function setTypeN3(string $typeN3): self {
        $this->typeN3 = $typeN3;
        return $this;
    }

    public function setCodeN3(string $codeN3): self {
        $this->codeN3 = $codeN3;
        return $this;
    }

    public function setCouleur(?string $couleur): self {
        $this->couleur = $couleur;
        return $this;
    }

    public function setLibelleLiserai(?string $libelleLiserai): self {
        $this->libelleLiserai = $libelleLiserai;
        return $this;
    }

    // ======================
    // LOGIQUE METIER
    // ======================

    public function computeFields(): void {
        $types = explode('/', $this->getName());
        if (sizeof($types) < 3) {
            throw new \Exception("invalidName");
        }
        $this->setTypeN1($types[0]);
        $this->setCodeN1($this->makeCode($types[0]));
        $this->setTypeN2($types[1]);
        $this->setCodeN2($this->makeCode($types[1]));
        $this->setTypeN3($types[2]);
        $this->setCodeN3($this->makeCode($types[2]));

        $this->code = sprintf(
                'TT-%s-%s-%s',
                $this->codeN1,
                $this->codeN2,
                $this->codeN3
        );
    }

    

    private function makeCode(?string $value): ?string {
        if (!$value) {
            return null;
        } else {
            /* 1 Normaliser les accents */
            $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

            /* 2 Supprimer tout sauf A-Z / a-z */
            $lettersOnly = preg_replace('/[^A-Za-z]/', '', $normalized);

            /* 3 Majuscules et tronquer à 3 caractères */
            return strtoupper(substr($lettersOnly, 0, 3));
        }
    }
}
