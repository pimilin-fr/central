<?php

namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Demo\DemoFaker;
use App\Demo\DemoStrategy;
use App\Entity\Adresse;
use App\Entity\AdresseType;
use App\Entity\TiersAdresse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adresses (arbre).
 *
 * Une adresse n'a pas de champ demoStrategy : sa stratégie est DÉDUITE de ses liens avec les tiers.
 *   - rattachée uniquement à des tiers exclus            => exclue (adresse orpheline) ;
 *   - rattachée à au moins un tiers anonymisé            => anonymisée ;
 *   - sinon                                              => stratégie du type d'adresse (copie).
 * L'anonymisation se propage à toute la « famille » (parents et enfants) tant qu'on ne traverse pas
 * un niveau grossier (ville, département, région, pays…), qui reste toujours copié.
 *
 * Adresse anonymisée : même ville / code postal / pays (la carte reste crédible), mais numéro, voie et
 * coordonnées (décalées de quelques centaines de mètres) sont fictifs ; adresse forcée / exacte supprimées.
 */
class AdresseTransformer extends AbstractDemoTransformer {

    /** Types d'adresse « grossiers » : jamais anonymisés (une ville n'identifie personne). */
    private const COARSE_TYPES = ['ville', 'quartier', 'département', 'region', 'région', 'pays', 'zone', 'planète'];

    /** Amplitude max du décalage GPS, en degrés (0.006° ≈ 600 m). */
    private const GPS_JITTER = 0.006;

    /** @var array<int, DemoStrategy> */
    private array $computed = [];

    /** @var array<int, int> id adresse => id de la racine de sa « famille » (graine des fausses données) */
    private array $familyRoot = [];

    public function __construct(
        /** EntityManager de la base de PRODUCTION (source). */
        private EntityManagerInterface $em,
        #[Autowire(service: 'doctrine.orm.demo_entity_manager')] EntityManagerInterface $demoEm,
        DemoFaker $faker
    ) {
        parent::__construct($demoEm, $faker);
    }

    public function getOrder(): int { return 70; }

    public function getSourceClass(): string { return Adresse::class; }

    public function getLabel(): string { return 'Adresses'; }

    public function isHierarchical(): bool { return true; }

    public function getParentId(object $source): string|int|null {
        return $source->getAdresseParent()?->getId();
    }

    public function prepare(DemoContext $context): void {
        $this->computed = $this->familyRoot = [];

        // 1. stratégies des tiers liés, par adresse
        $links = [];
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(ta.adresse) AS aid, t.demoStrategy AS strategy
               FROM ' . TiersAdresse::class . ' ta JOIN ta.tiers t'
        )->getScalarResult();
        foreach ($rows as $row) {
            $links[(int) $row['aid']][] = $row['strategy'] instanceof DemoStrategy
                ? $row['strategy']
                : DemoStrategy::from($row['strategy']);
        }

        // 2. structure de l'arbre
        $rows = $this->em->createQuery(
            'SELECT a.id AS id, IDENTITY(a.adresseParent) AS pid, t.name AS type
               FROM ' . Adresse::class . ' a JOIN a.adresseType t'
        )->getScalarResult();

        $parent = $coarse = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parent[$id] = $row['pid'] !== null ? (int) $row['pid'] : null;
            $coarse[$id] = $this->isCoarse((string) $row['type']);
        }

        // 3. stratégie propre de chaque adresse
        foreach ($parent as $id => $_) {
            $own = $links[$id] ?? [];
            if ($coarse[$id]) {
                $this->computed[$id] = DemoStrategy::COPY;
            } elseif ($own === []) {
                $this->computed[$id] = DemoStrategy::COPY;
            } else {
                $kept = array_filter($own, static fn (DemoStrategy $s) => $s !== DemoStrategy::EXCLUDE);
                $this->computed[$id] = match (true) {
                    $kept === [] => DemoStrategy::EXCLUDE,
                    in_array(DemoStrategy::ANONYMIZE, $kept, true) => DemoStrategy::ANONYMIZE,
                    default => DemoStrategy::COPY,
                };
            }
        }

        // 4. « famille » = parent/enfants reliés sans traverser un niveau grossier
        foreach ($parent as $id => $_) {
            $root = $id;
            $guard = 0;
            while ($parent[$root] !== null && isset($coarse[$parent[$root]]) && !$coarse[$parent[$root]] && $guard++ < 50) {
                $root = $parent[$root];
            }
            $this->familyRoot[$id] = $root;
        }

        // 5. propagation de l'anonymisation à toute la famille
        $anonymizedFamilies = [];
        foreach ($this->computed as $id => $strategy) {
            if ($strategy === DemoStrategy::ANONYMIZE && !$coarse[$id]) {
                $anonymizedFamilies[$this->familyRoot[$id]] = true;
            }
        }
        foreach ($this->computed as $id => $strategy) {
            if (!$coarse[$id] && $strategy === DemoStrategy::COPY && isset($anonymizedFamilies[$this->familyRoot[$id]])) {
                $this->computed[$id] = DemoStrategy::ANONYMIZE;
            }
        }
    }

    public function strategyOf(object $source, DemoContext $context): DemoStrategy {
        /** @var Adresse $source */
        return $this->computed[$source->getId()] ?? $source->getDemoStrategy();
    }

    public function transform(object $source, DemoStrategy $strategy, DemoContext $context): ?object {
        /** @var Adresse $source */
        $type = $this->ref($context, AdresseType::class, $source->getAdresseType()->getId());
        if ($type === null) {
            return null;
        }

        $target = new Adresse();
        $target->setAdresseType($type)
            ->setDeletedAt($source->getDeletedAt())
            ->setVille($source->getVille())
            ->setCodePostal($source->getCodePostal())
            ->setPays($source->getPays());

        if ($source->getAdresseParent() !== null) {
            // plus proche ancêtre conservé (null si aucun)
            $target->setAdresseParent($this->ref($context, Adresse::class, $source->getAdresseParent()->getId()));
        }

        if ($strategy === DemoStrategy::ANONYMIZE) {
            $this->anonymize($source, $target);
        } else {
            $target->setName($source->getName())
                ->setAdresse($source->getAdresse())
                ->setPrefix($source->getPrefix())
                ->setNum($source->getNum())
                ->setBisTer($source->getBisTer())
                ->setTypeVoie($source->getTypeVoie())
                ->setNomVoie($source->getNomVoie())
                ->setCedex($source->getCedex())
                ->setAdresseForcee($source->getAdresseForcee())
                ->setLatitude($source->getLatitude())
                ->setLongitude($source->getLongitude());
        }

        return $target;
    }

    private function anonymize(Adresse $source, Adresse $target): void {
        $id = $source->getId();
        $family = $this->familyRoot[$id] ?? $id;

        $typeVoie = $source->getTypeVoie() !== null ? $source->getTypeVoie() : $this->faker->streetType($family);
        $nomVoie = $this->faker->streetName($family);
        $num = $source->getNum() !== null && $source->getNum() !== 0
            ? $this->faker->int('num', $id, 1, 120)
            : null;

        $street = trim(($num !== null ? $num . ' ' : '') . $typeVoie . ' ' . $nomVoie);
        $city = trim(($source->getCodePostal() ?? '') . ' ' . $source->getVille());

        $target->setName($street)
            ->setAdresse(implode(', ', array_filter([$street, $city, $source->getPays()])))
            ->setPrefix(null)
            ->setNum($num)
            ->setBisTer(null)
            ->setTypeVoie($typeVoie)
            ->setNomVoie($nomVoie)
            ->setCedex($source->getCedex())
            ->setAdresseForcee(null);

        // coordonnées : même décalage pour toute la famille (les distances relatives sont conservées)
        if ($source->getLatitude() !== null && $source->getLongitude() !== null) {
            $target->setLatitude(round($source->getLatitude() + $this->faker->ratio('lat', $family, -self::GPS_JITTER, self::GPS_JITTER), 6))
                ->setLongitude(round($source->getLongitude() + $this->faker->ratio('lon', $family, -self::GPS_JITTER, self::GPS_JITTER), 6));
        }
    }

    private function isCoarse(string $typeName): bool {
        return in_array(mb_strtolower(trim($typeName)), self::COARSE_TYPES, true);
    }
}
