<?php
namespace App\Demo\Transformer;

use App\Demo\DemoContext;
use App\Entity\Tiers;
use App\Entity\TypeTiers;
use Doctrine\ORM\EntityManagerInterface;

class TiersTransformer{
    private DemoContext $context;
    private EntityManagerInterface $demoEm;
    
    public function __construct(DemoContext $context,EntityManagerInterface $demoEm) {
        $this->context = $context;
        $this->demoEm = $demoEm;
    }

    public function transform(Tiers $source): Tiers{
        $target = new Tiers();

        $target->setName($this->fakeName($source) );
        $target->setSearchText($target->getName());

        $target->setTypeTiers(
            $this->context->get(
                TypeTiers::class,
                $source->getTypeTiers()->getId()
            )
        );

        $target->setCode($this->generateCode($target));

        $this->demoEm->persist($target);

        $this->context->set(
            Tiers::class,
            $source->getId(),
            $target
        );

        return $target;
    }
}