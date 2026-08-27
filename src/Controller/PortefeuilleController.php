<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Entity\Depenses as Operation;
use App\Entity\Portefeuille;
use App\Entity\PortefeuilleView;
use App\Form\AddDepensesType;
use App\Form\PortefeuilleType;
use App\Services\DepenseGrouper\DepenseGrouper;
use App\Services\DepenseGrouper\DepenseGroupManager;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByCategorie;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByMonth;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByProjet;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByQuarter;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByReleve;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByTiers;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByWeek;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByYear;
use App\Services\DepenseGrouper\GrouperStrategy\SortByDateDesc;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/portefeuille')]
final class PortefeuilleController extends AbstractController {

    #[Route(name: 'app_portefeuille_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response {
        $ptfRepo = $entityManager->getRepository(PortefeuilleView::class);
        $depRepo = $entityManager->getRepository(Operation::class);
        $ptfList = $ptfRepo->findAll();
        $groupingService = new DepenseGrouper();
        $items = [];
        foreach ($ptfList as $ptf) {
            $groups = $groupingService->group(
                    $depRepo->findBy([
                        "portefeuille" => $ptf
                            ], [
                        "date" => "DESC",
                        "id" => "DESC"
                    ]),
                    new GroupByReleve()
            );

            $items[$ptf->getId()] = [
                "portefeuille" => $ptf,
                "releves" => (new SortByDateDesc())->sort($groups)
            ];
        }



        return $this->render('portefeuille/index.html.twig', [
                    'items' => $items,
        ]);
    }

    #[Route('/new', name: 'app_portefeuille_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response {
        $ptf = new Portefeuille();
        $form = $this->createForm(PortefeuilleType::class, $ptf);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ptf->regenerateCode();
            $entityManager->persist($ptf);
            $entityManager->flush();
            $this->addFlash('success', 'Portefeuille ajouté avec succès');
            return $this->redirectToRoute('app_portefeuille_show', ['id' => $ptf->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('portefeuille/new.html.twig', [
                    'portefeuille' => $ptf,
                    'form' => $form
        ]);
    }

    #[Route('/show/{id}', name: 'app_portefeuille_show', methods: ['GET', 'POST'])]
    public function show(Request $request, Portefeuille $portefeuille, EntityManagerInterface $em): Response {
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

    #[Route('/edit/{id}', name: 'app_portefeuille_edit', methods: ['GET', 'POST'])]
    public function edit(Portefeuille $portefeuille, Request $request, EntityManagerInterface $em): Response {

        $form = $this->createForm(
                PortefeuilleType::class,
                $portefeuille
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Portefeuille modifié avec succès');

            return $this->redirectToRoute(
                            'app_portefeuille_show',
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

    #[Route('/add-operation/{id}', name: 'app_portefeuille_add_operation', methods: ['GET', 'POST'])]
    public function addOperation(Portefeuille $portefeuille, Request $request, EntityManagerInterface $em): Response {
        $operation = new Operation();

        $form = $this->createForm(
                AddDepensesType::class,
                $operation,
                [
                    'portefeuille_entity' => $portefeuille,
                ]
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Si ton AddDepensesType ne fait pas déjà l'association,
            // on force le portefeuille ici.
            $operation->setPortefeuille($portefeuille);

            $em->persist($operation);
            $em->flush();

            $this->addFlash('success', 'Opération ajoutée avec succès');

            return $this->redirectToRoute(
                            'app_portefeuille_show',
                            [
                                'id' => $portefeuille->getId(),
                                'tab' => 'addoperation',
                            ]
                    );
        }

        return $this->render('depenses/_add_form.html.twig', [
                    'form' => $form->createView(),
                    'portefeuille' => $portefeuille,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_portefeuille_delete', methods: ['GET'])]
    public function delete(Portefeuille $portefeuille, EntityManagerInterface $em): Response {
        $portefeuille->setDeleted(new DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', 'Portefeuille supprimé avec succès');

        return $this->redirectToRoute('app_portefeuille_show', [
                    'id' => $portefeuille->getId(),
                    'tab' => 'edit'
        ]);
    }

    #[Route('/restore/{id}', name: 'app_portefeuille_restore', methods: ['GET'])]
    public function restore(Portefeuille $portefeuille, EntityManagerInterface $em): Response {
        $portefeuille->setDeleted(null);
        $em->flush();
        $this->addFlash('success', 'Portefeuille restauré avec succès');

        return $this->redirectToRoute('app_portefeuille_show', [
                    'id' => $portefeuille->getId(),
                    'tab' => 'edit'
        ]);
    }
}
