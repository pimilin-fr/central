<?php

namespace App\Doctrine;

use App\Entity\Tiers;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

class TiersIdGenerator extends AbstractIdGenerator {

    private const SEPARATOR = "-";

    #[\Override]
    public function generateId(EntityManagerInterface $em, $entity): mixed {
//        $conn = $em->getConnection();
//        $stmt = $conn->executeQuery("SELECT MAX(CAST(SUBSTRING(id,6) AS UNSIGNED)) as max_id FROM type_tiers");
//        $max = $stmt->fetchAssociative()['max_id'] ?? 0;
//        $next = $max + 1;

        return $this->genereId($entity);
//        return sprintf('TT-%03d', $next);
    }

    private function genereId(Tiers $entity) {
        $id = "T" . self::SEPARATOR . $entity->getTiersType()->getId();
        $id .= self::SEPARATOR . $entity->getCreatedAt()->format("ymd");
        $id .= self::SEPARATOR . $this->makeCode($entity->getName(), 15);
        return $id;
    }

    private function makeCode(?string $value, int $length = 5): ?string {
        if (!$value) {
            return null;
        } else {
            // 1️⃣ Normaliser les accents
            $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

            // 2️⃣ Supprimer tout sauf lettres et espaces
            $lettersAndSpaces = preg_replace('/[^A-Za-z ]/', '', $normalized);

            // 3️⃣ Remplacer les espaces par _
            $withUnderscore = preg_replace('/\s+/', '_', trim($lettersAndSpaces));

            return strtoupper(substr($withUnderscore, 0, $length));
        }
    }
}
