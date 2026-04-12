<?php

namespace App\Entity;

use App\Repository\PortefeuilleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortefeuilleRepository::class)]
class Portefeuille extends ColorableEntity {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $origine = null;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column(type: 'integer', length: 5)]
    private int $ordre = 1;
    
    #[ORM\Column(type: 'datetime_immutable', default: null)]
    private ?DateTimeImmutable $deleted;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    const TYPE_PTF = [
        "BANQ" => "Banque",
        "ESP." => "Espèce",
        "JUS." => "Justice",
        "EPAR" => "Epargne",
        "PRET" => "Prêt",
        "SPEC" => "Spécifique"
    ];

    public function getId(): int {
        return $this->id;
    }

    public function getCode(): ?string {
        return $this->code;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getType(): ?string {
        return $this->type;
    }

    public function getOrigine(): ?string {
        return $this->origine;
    }

    public function getLibelle(): string {
        return $this->libelle;
    }

    public function setId(int $id) {
        $this->id = $id;
        return $this;
    }

    public function regenerateCode() {
        $this->code = "PTF-" .
                array_flip(self::TYPE_PTF)[$this->type] . '-' .
                $this->makeCode($this->name);
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

    public function setCode(?string $code) {
        $this->code = $code;
        return $this;
    }

    public function setName(string $name) {
        $this->name = $name;
        return $this;
    }

    public function setType(?string $type) {
        $this->type = $type;
        return $this;
    }

    public function setOrigine(?string $origine) {
        $this->origine = $origine;
        return $this;
    }

    public function setLibelle(string $libelle) {
        $this->libelle = $libelle;
        return $this;
    }

    public function getOrdre(): int {
        return $this->ordre;
    }

    public function setOrdre(int $ordre) {
        $this->ordre = $ordre;
        return $this;
    }

    public function getIsDefault(): bool {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault) {
        $this->isDefault = $isDefault;
        return $this;
    }

    #[\Override]
    public function getCouleur(): ?string {
        return $this->couleur;
    }

    public function isEspece(): bool {
        return(self::TYPE_PTF["ESP."] === $this->type);
//        var_dump($this->type);die;
    }

    public function setCouleur(?string $couleur) {
        $this->couleur = $couleur;
        return $this;
    }
    public function getDeleted(): ?DateTimeImmutable {
        return $this->deleted;
    }

    public function setDeleted(?DateTimeImmutable $deleted) {
        $this->deleted = $deleted;
        return $this;
    }


}
