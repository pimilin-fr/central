<?php

namespace App\Doctrine;

use App\Entity\TypeTiers;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

class TypeTiersIdGenerator extends AbstractIdGenerator
{
    #[\Override]
    public function generateId(EntityManagerInterface $em, $entity):mixed
    {
//        $conn = $em->getConnection();
//        $stmt = $conn->executeQuery("SELECT MAX(CAST(SUBSTRING(id,6) AS UNSIGNED)) as max_id FROM type_tiers");
//        $max = $stmt->fetchAssociative()['max_id'] ?? 0;
//        $next = $max + 1;
        
        return $this->genereId($entity);
//        return sprintf('TT-%03d', $next);
    }
    
    private function genereId(TypeTiers $entity) {
         
        return "TT-".$entity->getCodeN1()."-".$entity->getCodeN2()."-".$entity->getCodeN3();

    }

}