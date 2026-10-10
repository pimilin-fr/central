<?php

namespace App\Entity;

use App\Demo\DemoEntityInterface;
use App\Demo\DemoStrategy;
use App\Prevision\Certitude;
use App\Prevision\Frequence;
use App\Repository\PrevisionRegleRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Règle de dépense (ou revenu) récurrente : abonnement, LOA, paiement en x fois, facture variable…
 * Le montant est porté par des TRANCHES datées (le montant change à partir d'une date) ;
 * les ÉCHÉANCES (PrevisionEcheance) en sont déduites.
 */
#[ORM\Entity(repositoryClass: PrevisionRegleRepository::class)]
#[ORM\Table(name: 'prevision_regle')]
class PrevisionRegle implements DemoEntityInterface {

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $libelle = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tiers $tiers = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Portefeuille $portefeuille = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Projet $projet = null;

    #[ORM\Column(length: 20, enumType: Frequence::class)]
    private Frequence $frequence = Frequence::MENSUELLE;

    /** Jour du mois attendu (1-31 ; borné à la fin du mois). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $jour = 1;

    /** Fin de la fenêtre (« entre le 3 et le 8 ») : null = date précise. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $jourFin = null;

    #[ORM\Column(length: 20, enumType: Certitude::class)]
    private Certitude $certitude = Certitude::CERTAIN;

    /** Montant seulement estimé (électricité, gaz…) : une seule échéance « en cours » à la fois. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $estime = false;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?DateTime $debut = null;

    /** Dernière date possible d'une échéance (null = sans fin). */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTime $fin = null;

    /** Nombre total d'échéances (paiement en x fois) ; null = illimité. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $nbEcheances = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $actif = true;

    #[ORM\OneToMany(mappedBy: 'regle', targetEntity: PrevisionTranche::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['aPartirDe' => 'ASC'])]
    private Collection $tranches;

    #[ORM\OneToMany(mappedBy: 'regle', targetEntity: PrevisionEcheance::class, cascade: ['remove'])]
    #[ORM\OrderBy(['rang' => 'ASC'])]
    private Collection $echeances;

    public function __construct() {
        $this->tranches = new ArrayCollection();
        $this->echeances = new ArrayCollection();
        $this->debut = new DateTime('today');
    }

    public function getId(): ?int { return $this->id; }
    public function getLibelle(): string { return $this->libelle; }
    public function setLibelle(string $libelle): static { $this->libelle = $libelle; return $this; }
    public function getCategorie(): ?Categorie { return $this->categorie; }
    public function setCategorie(?Categorie $categorie): static { $this->categorie = $categorie; return $this; }
    public function getTiers(): ?Tiers { return $this->tiers; }
    public function setTiers(?Tiers $tiers): static { $this->tiers = $tiers; return $this; }
    public function getPortefeuille(): ?Portefeuille { return $this->portefeuille; }
    public function setPortefeuille(?Portefeuille $portefeuille): static { $this->portefeuille = $portefeuille; return $this; }
    public function getProjet(): ?Projet { return $this->projet; }
    public function setProjet(?Projet $projet): static { $this->projet = $projet; return $this; }
    public function getFrequence(): Frequence { return $this->frequence; }
    public function setFrequence(Frequence $frequence): static { $this->frequence = $frequence; return $this; }
    public function getJour(): int { return $this->jour; }
    public function setJour(int $jour): static { $this->jour = max(1, min(31, $jour)); return $this; }
    public function getJourFin(): ?int { return $this->jourFin; }
    public function setJourFin(?int $jourFin): static { $this->jourFin = $jourFin === null ? null : max(1, min(31, $jourFin)); return $this; }
    public function getCertitude(): Certitude { return $this->certitude; }
    public function setCertitude(Certitude $certitude): static { $this->certitude = $certitude; return $this; }
    public function isEstime(): bool { return $this->estime; }
    public function setEstime(bool $estime): static { $this->estime = $estime; return $this; }
    public function getDebut(): ?DateTime { return $this->debut; }
    public function setDebut(?DateTime $debut): static { $this->debut = $debut; return $this; }
    public function getFin(): ?DateTime { return $this->fin; }
    public function setFin(?DateTime $fin): static { $this->fin = $fin; return $this; }
    public function getNbEcheances(): ?int { return $this->nbEcheances; }
    public function setNbEcheances(?int $nbEcheances): static { $this->nbEcheances = $nbEcheances ?: null; return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }

    /** @return Collection<int, PrevisionTranche> */
    public function getTranches(): Collection { return $this->tranches; }

    public function addTranche(PrevisionTranche $tranche): static {
        if (!$this->tranches->contains($tranche)) {
            $this->tranches->add($tranche);
            $tranche->setRegle($this);
        }

        return $this;
    }

    public function removeTranche(PrevisionTranche $tranche): static {
        $this->tranches->removeElement($tranche);

        return $this;
    }

    /** @return Collection<int, PrevisionEcheance> */
    public function getEcheances(): Collection { return $this->echeances; }

    public function getDemoStrategy(): DemoStrategy {
        $strategies = array_filter([
            $this->tiers?->getDemoStrategy(),
            $this->categorie?->getDemoStrategy(),
            $this->portefeuille?->getDemoStrategy(),
            $this->projet?->getDemoStrategy(),
        ]);

        return in_array(DemoStrategy::EXCLUDE, $strategies, true) ? DemoStrategy::EXCLUDE : DemoStrategy::COPY;
    }
}
