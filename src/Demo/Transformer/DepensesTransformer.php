<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoStrategy;
use App\Entity\Adresse;
use App\Entity\Categorie;
use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\Projet;
use App\Entity\Releve;
use App\Entity\Tiers;
use Doctrine\ORM\QueryBuilder;

/**
 * Opérations (dépenses / revenus).
 *
 *   - exclue dès que l'un de ses liens (tiers, catégorie, portefeuille, projet) est exclu ;
 *   - anonymisée dès que l'un de ces liens est anonymisé : note supprimée, n° de commande fictif,
 *     montant légèrement modifié (option --jitter) ;
 *   - sinon copiée (note vidée si --strip-notes).
 * L'adresse et le relevé sont facultatifs : s'ils ne sont pas conservés, le lien est simplement retiré.
 */
class DepensesTransformer extends AbstractDemoTransformer {

    public function getOrder(): int { return 110; }

    public function getSourceClass(): string { return Depenses::class; }

    public function getLabel(): string { return 'Opérations'; }

    public function configureQuery(QueryBuilder $qb): void {
        $qb->addSelect('t', 'c', 'p', 'pr', 'a', 'r')
            ->join('e.tiers', 't')
            ->join('e.categorie', 'c')
            ->join('e.portefeuille', 'p')
            ->leftJoin('e.projet', 'pr')
            ->leftJoin('e.adresse', 'a')
            ->leftJoin('e.releve', 'r');
    }

    public function strategyOf(object $source, DemoContext $context): DemoStrategy {
        /** @var Depenses $source */
        $links = [
            $context->strategyOf(Tiers::class, $source->getTiers()->getId()),
            $context->strategyOf(Categorie::class, $source->getCategorie()->getId()),
            $context->strategyOf(Portefeuille::class, $source->getPortefeuille()?->getId()),
            $context->strategyOf(Projet::class, $source->getProjet()?->getId()), // peut être null : pas de projet
        ];

        if (in_array(DemoStrategy::EXCLUDE, $links, true)) {
            return DemoStrategy::EXCLUDE;
        }

        return in_array(DemoStrategy::ANONYMIZE, $links, true) ? DemoStrategy::ANONYMIZE : DemoStrategy::COPY;
    }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var Depenses $source */
        $tiers = $this->ref($context, Tiers::class, $source->getTiers()->getId());
        $categorie = $this->ref($context, Categorie::class, $source->getCategorie()->getId());
        $portefeuille = $this->ref($context, Portefeuille::class, $source->getPortefeuille()?->getId());
        if ($tiers === null || $categorie === null || $portefeuille === null) {
            return null;
        }

        $id = $source->getId();
        $montant = (string) $source->getMontant();
        $numCommande = $source->getNumCommande();
        $note = $source->getNote();

        if ($strategy === DemoStrategy::ANONYMIZE) {
            $note = null;
            $numCommande = $numCommande !== null && $numCommande !== '' ? $this->faker->orderNumber($id) : null;
            if ($context->jitterPercent > 0) {
                $spread = $context->jitterPercent / 100;
                $factor = $this->faker->ratio('amount', $id, 1 - $spread, 1 + $spread);
                $montant = number_format(round((float) $montant * $factor, 2), 2, '.', '');
            }
        } elseif ($context->stripNotes) {
            $note = null;
        }

        $target = (new Depenses())
            ->setDate($context->asDateTime($source->getDate()))
            ->setDateReleve($context->asDateTime($source->getDateReleve()))
            ->setReleveOrdre($source->getReleveOrdre())
            ->setMontant($montant)
            ->setNumCommande($numCommande)
            ->setNote($note)
            ->setTiers($tiers)
            ->setCategorie($categorie)
            ->setPortefeuille($portefeuille)
            ->setProjet($this->ref($context, Projet::class, $source->getProjet()?->getId()))
            ->setAdresse($this->ref($context, Adresse::class, $source->getAdresse()?->getId()))
            ->setReleve($this->ref($context, Releve::class, $source->getReleve()?->getId()));

        return $target;
    }
}
