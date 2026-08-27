<?php

namespace App\Controller\V2;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\PortefeuilleView;
use App\Form\PortefeuilleType;
use App\Services\DepenseGrouper\DepenseGroupManager;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByCategorie;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByMonth;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByProjet;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByQuarter;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByReleve;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByTiers;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByWeek;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DetailController extends AbstractController {

    #[Route('/v2/portefeuille/{id}', name: 'app_v2_portefeuille_show')]
    public function showPortefeuille(Portefeuille $portefeuille, EntityManagerInterface $em, Request $request): Response {
        $depRepo = $em->getRepository(Depenses::class);
        $ptfRepo = $em->getRepository(PortefeuilleView::class);
        $ptfView = $ptfRepo->find($portefeuille->getId());

        $depenses = $depRepo->findBy(
                ["portefeuille" => $portefeuille],
                ['date' => 'DESC', 'id' => 'DESC']// IMPORTANT
        );

        $groupManager = new DepenseGroupManager();
        $reqGroup = $request->query->get('groupBy');

        switch ($reqGroup) {
            case 'categorie':
                $cleanGroup = new GroupByCategorie();
                break;
            case 'projet':
                $cleanGroup = new GroupByProjet();
                break;
            case 'tiers':
                $cleanGroup = new GroupByTiers();
                break;
            case 'annee':
                $cleanGroup = new GroupByYear();
                break;
            case 'trimestre':
                $cleanGroup = new GroupByQuarter();
                break;
            case 'mois':
                $cleanGroup = new GroupByMonth();
                break;
            case 'semaine':
                $cleanGroup = new GroupByWeek();
                break;
            default :
                $cleanGroup = new GroupByReleve();
                $reqGroup = "releve";
        }

        $groups = $groupManager->build(
                $depenses,
                $cleanGroup,
                0
        );

        return $this->render('detail/v2/show.html.twig', [
                    'entity' => $ptfView,
                    'entityType' => 'portefeuille',
                    'groups' => $groups,
                    'groupBy' => $reqGroup
        ]);
    }

    #[Route('/v2/portefeuille/{id}/edit', name: 'app_v2_portefeuille_edit', methods: ['GET', 'POST'])]
    public function editPortefeuille(Portefeuille $portefeuille, Request $request, EntityManagerInterface $em): Response {
        
        $form = $this->createForm(
                PortefeuilleType::class,
                $portefeuille
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            return $this->redirectToRoute(
                            'app_v2_portefeuille_show',
                            [
                                'id' => $portefeuille->getId(),
                                'tab' => 'edit',
                            ]
                    );
        }

        return $this->render('portefeuille/_form.html.twig', [
                    'form' => $form->createView(),
                    'portefeuille' => $portefeuille,
        ]);
    }
}
