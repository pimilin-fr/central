<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Entity\Depenses;
use App\Entity\Depenses as Operation;
use App\Entity\Portefeuille;
use App\Entity\PortefeuilleView;
use App\Form\AdresseType;
use App\Form\PortefeuilleType;
use App\Repository\ReleveRepository;
use App\Services\DepenseGrouper\DepenseGrouper;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByReleve;
use App\Services\DepenseGrouper\GrouperStrategy\SortByDateDesc;
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
    public function show(
            Request $request,
            Portefeuille $portefeuille,
            EntityManagerInterface $em
    ): Response {
        $depRepo = $em->getRepository(Depenses::class);
        $ptfRepo = $em->getRepository(PortefeuilleView::class);
        $ptfView = $ptfRepo->find($portefeuille->getId());

        $depenses = $depRepo->findBy(
                ["portefeuille" => $portefeuille],
                ["date" => "ASC", "id" => "ASC"] // IMPORTANT
        );
        
        $groupManager = new \App\Services\DepenseGrouper\DepenseGroupManager();

        $groups = $groupManager->build(
                $depenses,
                new GroupByReleve(), // interchangeable
                0
        );


        // 👉 FORMULAIRE (WRITE)
        $form = $this->createForm(PortefeuilleType::class, $portefeuille);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $portefeuille->regenerateCode();
            $em->flush();

            return $this->redirectToRoute('app_portefeuille_show', [
                        'id' => $portefeuille->getId(),
                        'tab' => 'edit'
            ]);
        }
//        
//        $groupingService = new DepenseGrouper();
//        $groups = $groupingService->group(
//                    $depRepo->findBy([
//                        "portefeuille" => $portefeuille
//                    ], [
//                        "date" => "DESC",
//                        "id" => "DESC"
//                    ]),
//                    new GroupByReleve()
//            );
//            
        return $this->render('portefeuille/show.html.twig', [
                    'portefeuille' => $ptfView,
                    'form' => $form->createView(),
//                    'operations' => $operations,
                    'releves' => $groups
        ]);
//        // 👉 NON RELEVÉ (ATTENTION: ID, pas objet)
//        $manager = new ReleveManager($em);
//        $operations = $em->getRepository(Operation::class)
//                ->findNonReleveByPortefeuille($portefeuille);
//        if (empty($operations)) {
//            $fake = new Releve();
//            $fake->setPortefeuille($portefeuille);
//        } else {
//            $fake = $manager->addOperations(new DateTime(), $operations, false);
//        }
//        $fake->setLabel("En attente");
//
//        // 👉 PAGINATION "12 MOIS"
//        $offset = $request->query->getInt('offset', 0); // 0, 12, 24...
//        $limit = 12;
//
//        $releves = $em->getRepository(Releve::class)
//                ->findLastWithOffset($portefeuille, $limit, $offset);
//
//        array_unshift($releves, $fake);

//        return $this->render('portefeuille/show.html.twig', [
//                    'portefeuille' => $ptfView,
//                    'form' => $form->createView(),
////                    'operations' => $operations,
//                    'releves' => $releves,
//                    'offset' => $offset,
//                    'limit' => $limit
//        ]);
    }

    #[Route('/{id}/releves', name: 'app_portefeuille_releves_ajax')]
    public function relevesAjax(
            Portefeuille $portefeuille,
            Request $request,
            ReleveRepository $repo
    ): Response {

        $offset = $request->query->getInt('offset', 0);
        $limit = 12;

        $releves = $repo->findLastWithOffset($portefeuille, $limit, $offset);

        return $this->render('portefeuille/_releve.html.twig', [
                    'releves' => $releves
        ]);
    }

    #[Route('/edit/{id}', name: 'app_adresse_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Adresse $adresse, EntityManagerInterface $entityManager): Response {
        $form = $this->createForm(AdresseType::class, $adresse);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_adresse_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('adresse/edit.html.twig', [
                    'adresse' => $adresse,
                    'form' => $form,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_portefeuille_delete', methods: ['GET'])]
    public function delete(
            Portefeuille $portefeuille,
            EntityManagerInterface $em
    ): Response {

        $em->remove($portefeuille);
        $em->flush();
        $this->addFlash('success', 'Portefeuille supprimé avec succès');

        return $this->redirectToRoute('app_portefeuille_index');
    }
}
