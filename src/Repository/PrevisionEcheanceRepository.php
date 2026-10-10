<?php

namespace App\Repository;

use App\Entity\PrevisionEcheance;
use App\Prevision\StatutEcheance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PrevisionEcheance> */
class PrevisionEcheanceRepository extends ServiceEntityRepository {

    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, PrevisionEcheance::class);
    }

    /**
     * Une échéance « réalisée » dont l'opération a été supprimée (depense_id remis à NULL) redevient « prévue »,
     * pour pouvoir être concrétisée à nouveau.
     */
    public function restaurerOrphelines(): int {
        return (int) $this->createQueryBuilder('e')
                        ->update()
                        ->set('e.statut', ':prevue')
                        ->where('e.statut = :realisee')
                        ->andWhere('e.depense IS NULL')
                        ->setParameter('prevue', StatutEcheance::PREVUE)
                        ->setParameter('realisee', StatutEcheance::REALISEE)
                        ->getQuery()->execute();
    }

    /**
     * Échéances encore « prévues » (en retard comprises), des plus proches aux plus lointaines.
     *
     * @return list<PrevisionEcheance>
     */
    public function findPrevues(?\App\Entity\Portefeuille $portefeuille = null): array {
        $this->restaurerOrphelines();
        $qb = $this->createQueryBuilder('e')
                        ->innerJoin('e.regle', 'r')
                        ->innerJoin('r.categorie', 'c')
                        ->innerJoin('r.tiers', 't')
                        ->innerJoin('r.portefeuille', 'p')
                        ->addSelect('r', 'c', 't', 'p')
                        ->andWhere('e.statut = :s')
                        ->andWhere('r.actif = true')
                        ->setParameter('s', StatutEcheance::PREVUE)
                        ->orderBy('e.datePrevue', 'ASC')
                        ->addOrderBy('e.id', 'ASC');
        if ($portefeuille !== null) {
            $qb->andWhere('r.portefeuille = :ptf')->setParameter('ptf', $portefeuille);
        }

        return $qb->getQuery()->getResult();
    }
}
