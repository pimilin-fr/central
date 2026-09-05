<?php

namespace App\Entity;

use App\Entity\AdresseType;
use App\Repository\AdresseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: AdresseRepository::class)]
class Adresse {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $adresse = null;

    // 🔹 Relation optionnelle vers une autre Adresse
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: "adresse_parent_id", referencedColumnName: "id", nullable: true)]
    private ?Adresse $adresseParent = null;

    // 🔹 Relation vers une type Adresse
    #[ORM\ManyToOne(targetEntity: AdresseType::class)]
    #[ORM\JoinColumn(name: "adresse_type_id", referencedColumnName: "id", nullable: false)]
    private AdresseType $adresseType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $prefix = null;

    #[ORM\Column(nullable: true)]
    private ?int $num = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $bisTer = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $typeVoie = null;

    #[ORM\Column(length: 255)]
    private ?string $nomVoie = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 255)]
    private ?string $ville = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $cedex = null;

    #[ORM\Column(length: 255)]
    private ?string $pays = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adresseForcee = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adresseExact = null;

    #[ORM\OneToMany(mappedBy: 'adresseParent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\OneToMany(mappedBy: 'adresse', targetEntity: TiersAdresse::class)]
    private Collection $tiersAdresses;

    public function __construct() {
        $this->children = new ArrayCollection();
        $this->tiersAdresses = new ArrayCollection();
    }

    /* ------ GETTER ------    */

    public function getId(): ?int {
        return $this->id;
    }

    public function getName(): ?string {
        return $this->name;
    }

    public function getAdresse(): ?string {
        return $this->adresse;
    }

    public function getAdresseParent(): ?Adresse {
        return $this->adresseParent;
    }

    public function getAdresseType(): AdresseType {
        return $this->adresseType;
    }

    public function getPrefix(): ?string {
        return $this->prefix;
    }

    public function getNum(): ?int {
        return $this->num;
    }

    public function getBisTer(): ?string {
        return $this->bisTer;
    }

    public function getTypeVoie(): ?string {
        return $this->typeVoie;
    }

    public function getNomVoie(): ?string {
        return $this->nomVoie;
    }

    public function getCodePostal(): ?string {
        return $this->codePostal;
    }

    public function getVille(): ?string {
        return $this->ville;
    }

    public function getCedex(): ?string {
        return $this->cedex;
    }

    public function getPays(): ?string {
        return $this->pays;
    }

    public function getAdresseForcee(): ?string {
        return $this->adresseForcee;
    }

    public function getAdresseExact(): ?string {
        return $this->adresseExact;
    }

    public function getChildren(): Collection {
        return $this->children;
    }

    public function getLatitude(): ?float {
        return $this->latitude;
    }

    public function getLongitude(): ?float {
        return $this->longitude;
    }

    public function getDeletedAt(): ?\DateTimeImmutable {
        return $this->deletedAt;
    }

    /* ---- SETTER ---- */

    public function setName(?string $name) {
        $this->name = $name;
        return $this;
    }

    public function setAdresse(?string $adresse) {
        $this->adresse = $adresse;
        return $this;
    }

    public function setAdresseParent(?Adresse $adresseParent) {
        $this->adresseParent = $adresseParent;
        return $this;
    }

    public function setAdresseType(AdresseType $adresseType) {
        $this->adresseType = $adresseType;
        return $this;
    }

    public function setPrefix(?string $prefix) {
        $this->prefix = $prefix;
        return $this;
    }

    public function setNum(?int $num) {
        $this->num = $num;
        return $this;
    }

    public function setBisTer(?string $bisTer) {
        $this->bisTer = $bisTer;
        return $this;
    }

    public function setTypeVoie(?string $typeVoie) {
        $this->typeVoie = $typeVoie;
        return $this;
    }

    public function setNomVoie(?string $nomVoie) {
        $this->nomVoie = $nomVoie;
        return $this;
    }

    public function setCodePostal(?string $codePostal) {
        $this->codePostal = $codePostal;
        return $this;
    }

    public function setVille(?string $ville) {
        $this->ville = $ville;
        return $this;
    }

    public function setCedex(?string $cedex) {
        $this->cedex = $cedex;
        return $this;
    }

    public function setPays(?string $pays) {
        $this->pays = $pays;
        return $this;
    }

    public function setAdresseForcee(?string $adresseForcee) {
        $this->adresseForcee = $adresseForcee;
        return $this;
    }

    public function setAdresseExact(?string $adresseExact) {
        $this->adresseExact = $adresseExact;
        return $this;
    }

    public function setChildren(Collection $children) {
        $this->children = $children;
        return $this;
    }

    public function setLatitude(?float $latitude) {
        $this->latitude = $latitude;
        return $this;
    }

    public function setLongitude(?float $longitude) {
        $this->longitude = $longitude;
        return $this;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt) {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    /* ---- SPECIFIC ---- */

    public function isGeolocalisee(): bool {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function isDeleted(): bool {
        return $this->deletedAt !== null;
    }

    public function getVilleCourte(): string {
        $ville = trim($this->ville);

        // Normaliser les tirets
        $ville = str_replace(['–', '—'], '-', $ville);

        // Découper sur tirets
        $parts = array_filter(array_map('trim', explode('-', $ville)));

        // Mots à ignorer s'ils sont seuls
        $ignore = ['les', 'en', 'lès', 'sur', 'sous', 'de', 'du', 'des'];

        // Articles courts
        $articles = ['le', 'la', 'l\'', 'les'];

        $count = count($parts);

        for ($i = 0; $i < $count; $i++) {
            $part = $parts[$i];

            // Découper sur espaces
            $words = preg_split('/\s+/', $part);

            if (!$words || $words[0] === '') {
                continue;
            }

            $first = mb_strtolower($words[0]);

            // --- CAS SAINT / SAINTE ---
            if ($first === 'saint' || $first === 'sainte') {

                $abbr = ($first === 'saint') ? 'St' : 'Ste';

                // Mot suivant dans le même segment ?
                if (isset($words[1])) {
                    return $abbr . ' ' . $words[1];
                }

                // Sinon : mot suivant dans le segment suivant
                if (isset($parts[$i + 1])) {
                    $nextWords = preg_split('/\s+/', $parts[$i + 1]);
                    $next = $nextWords[0] ?? null;

                    if ($next) {
                        return $abbr . ' ' . $next;
                    }
                }

                // Fallback
                return $abbr;
            }

            // --- CAS APOSTROPHE (L'Isle-Adam, L'Aigle, etc.) ---
            if (preg_match('/^(l\')(.+)/ui', $part, $m)) {
                return "L'" . $m[2];
            }

            // --- CAS ARTICLE COURT (Le, La, Les, L') ---
            if (in_array($first, $articles, true)) {

                // Mot suivant dans le même segment
                if (isset($words[1])) {
                    return $words[0] . ' ' . $words[1];
                }

                // Mot suivant dans le segment suivant
                if (isset($parts[$i + 1])) {
                    $nextWords = preg_split('/\s+/', $parts[$i + 1]);
                    $next = $nextWords[0] ?? null;

                    if ($next) {
                        return $words[0] . ' ' . $next;
                    }
                }

                // Fallback
                return $words[0];
            }

            // --- CAS NORMAL ---
            $candidate = $words[0];

            // Ignorer certains mots s'ils sont seuls
            if (!in_array(mb_strtolower($candidate), $ignore, true)) {
                return $candidate;
            }
        }

        // Fallback : premier mot de la ville
        return explode(' ', $ville)[0];
    }

    public function getTiersAdresses(): Collection {
        return $this->tiersAdresses;
    }

    public function getTiers(): array {
        $tiers = [];

        $collectTiers = function (Adresse $adresse) use (&$collectTiers, &$tiers): void {

            // Tiers directement liés à cette adresse
            foreach ($adresse->getTiersAdresses() as $tiersAdresse) {
                $tiersItem = $tiersAdresse->getTiers();

                if ($tiersItem === null) {
                    continue;
                }

                $tiersId = $tiersItem->getId();

                // Évite les doublons
                if (!isset($tiers[$tiersId])) {
                    $tiers[$tiersId] = $tiersItem;
                }
            }

            // Tiers des enfants
            foreach ($adresse->getChildren() as $child) {
                $collectTiers($child);
            }
        };

        $collectTiers($this);

        return array_values($tiers);
    }

    public function __toString(): string {
        return trim(
                $this->getPrefix() . " " .
                (($this->getNum() != 0) ? $this->getNum() . ", " : "") .
                $this->getBisTer() . " " .
                $this->getTypeVoie() . " " .
                $this->getNomVoie() . " ," .
                $this->getCodePostal() . " " .
                $this->getVille() . " " .
                $this->getPays()
        );
    }
}
