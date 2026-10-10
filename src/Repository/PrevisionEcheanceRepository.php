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
     * Échéances encore « prévues » (en retard comprises), des plus proches aux plus lointaines.
     *
     * @return list<PrevisionEcheance>
     */
    public function findPrevues(?\App\Entity\Portefeuille $portefeuille = null): array {
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
