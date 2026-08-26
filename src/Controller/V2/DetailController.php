<?php

namespace App\Controller\V2;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\PortefeuilleView;
use App\Services\DepenseGrouper\DepenseGroupManager;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByReleve;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

class DetailController extends AbstractController {

    #[Route('/v2/portefeuille/{id}', name: 'app_v2_portefeuille_show')]
    public function portefeuille(Portefeuille $portefeuille, EntityManagerInterface $em, Request $request): Response {
        $depRepo = $em->getRepository(Depenses::class);
        $ptfRepo = $em->getRepository(PortefeuilleView::class);
        $ptfView = $ptfRepo->find($portefeuille->getId());

        $depenses = $depRepo->findBy(
                ["portefeuille" => $portefeuille],
                ['date' => 'DESC', 'id' => 'DESC']// IMPORTANT
        );

        $groupManager = new DepenseGroupManager();
        $reqGroup = $request->query->get('groupBy');
        
        switch ($reqGroup){
            default :
                $cleanGroup = new GroupByReleve();
                $reqGroup = "releve";
        }
        
        $groups = $groupManager->build(
                $depenses,
                $cleanGroup, // interchangeable
                0
        );

        return $this->render('detail/v2/show.html.twig', [
                    'entity' => $ptfView,
                    'entityType' => 'portefeuille',
                    'groups' => $groups,
                    'groupBy' => $reqGroup
        ]);
    }
}
