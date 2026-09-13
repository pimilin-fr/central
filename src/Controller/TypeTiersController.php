<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Entity\Tiers;
use App\Entity\TiersAdresse;
use App\Entity\TypeTiers;
use App\Form\TiersType;
use App\Form\TypeTiersType;
use App\Repository\TiersRepository;
use App\Repository\TypeTiersRepository;
use App\Service\DepenseGrouper\DepenseGroupManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/typetiers')]
final class TypeTiersController extends AbstractController {

    #[Route('', name: 'app_type_tiers_index', methods: ['GET'])]
    public function index(TypeTiersRepository $repo): Response {
        $types = $repo->findAllOrdered();

        $grouped = [];

        foreach ($types as $type) {
            $n1 = $type->getTypeN1();
            $n2 = $type->getTypeN2();

            $grouped[$n1][$n2][] = $type;
        }

        return $this->render('type_tiers/index.html.twig', [
                    'grouped' => $grouped
        ]);
    }

    #[Route('/new', name: 'app_type_tiers_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response {
        $typeTiers = new TypeTiers();
        $form = $this->createForm(TypeTiersType::class, $typeTiers);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $typeTiers->computeFields();
            $em->persist($typeTiers);
            $em->flush();

            return $this->redirectToRoute('app_type_tiers_index');
        }

        return $this->render('type_tiers/new.html.twig', [
                    'form' => $form,
        ]);
    }

    #[Route('/show/{id}', name: 'app_type_tiers_show', methods: ['GET', 'POST'])]
    public function show(Request $request, TypeTiers $typeTiers, TiersRepository $tiersRepo, EntityManagerInterface $em): Response {
        $depRepo = $em->getRepository(Depenses::class);
        $tiersAdresseRepo = $em->getRepository(TiersAdresse::class);

        $depenses = $depRepo->findByTypeTiers($typeTiers);
        
        $groupManager = new DepenseGroupManager($request);
        $groups = $groupManager->build($depenses, 0);

        $adresses = $tiersAdresseRepo->findByTypeTiersOrdered($typeTiers);

        return $this->render('type_tiers/show.html.twig', [
                    'entity' => $typeTiers,
                    'entityType' => 'tiers',
                    'tiers' => $tiersRepo->findByTiersType($typeTiers),
                    'groups' => $groups,
                    'groupBy' => $groupManager->getGroupBy(),
                    'adresses' => $adresses
        ]);
    }

    #[Route('/delete/{id}', name: 'app_type_tiers_delete', methods: ['POST'])]
    public function delete(
            Request $request,
            TypeTiers $typeTiers,
            EntityManagerInterface $em
    ): Response {
        if ($this->isCsrfTokenValid('delete' . $typeTiers->getId(), $request->request->get('_token'))) {
            $em->remove($typeTiers);
            $em->flush();
        }

        return $this->redirectToRoute('app_type_tiers_index');
    }
    
    #[Route('/edit/{id}', name: 'app_type_tiers_edit', methods: ['GET', 'POST'])]
    public function edit(TypeTiers $tiersType, Request $request, EntityManagerInterface $em): Response {
        $form = $this->createForm(TypeTiersType::class, $tiersType);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $em->persist($tiersType);
            $em->flush();

            $this->addFlash('success', 'Type Tiers modifié avec succès');

            return $this->redirectToRoute('app_type_tiers_show', [
                        'id' => $tiersType->getId(),
                        'tab' => 'edit',
            ]);
        }

        return $this->render('type_tiers/_form.html.twig', [
                    'form' => $form->createView(),
                    'typeTiers' => $tiersType,
        ]);
    }

}
