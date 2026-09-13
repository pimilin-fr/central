<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Entity\Projet;
use App\Form\ProjetFormType;
use App\Repository\ProjetRepository;
use App\Service\DepenseGrouper\DepenseGroupManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projet')]
final class ProjetController extends AbstractController {

    #[Route(name: 'app_projet_index', methods: ['GET'])]
    public function index(ProjetRepository $repository): Response {
        return $this->render('projet/index.html.twig', [
                    'projets' => $repository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_projet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response {
        $projet = new Projet();
        $form = $this->createForm(ProjetFormType::class, $projet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($projet);
            $entityManager->flush();

            $this->addFlash('success', 'Tiers ajouté avec succès');

            return $this->redirectToRoute('app_projet_show', ['id' => $projet->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('projet/new.html.twig', [
                    'projet' => $projet,
                    'form' => $form,
        ]);
    }

    #[Route('/show/{id}', name: 'app_projet_show', methods: ['GET', 'POST'])]
    public function show(Projet $projet, Request $request, EntityManagerInterface $em): Response {
        $depRepo = $em->getRepository(Depenses::class);
//        $tiersAdresseRepo = $em->getRepository(TiersAdresse::class);

        $depenses = $depRepo->findByProjet($projet);
        $groupManager = new DepenseGroupManager($request);
        $groups = $groupManager->build(
                $depenses,
                0
        );

        return $this->render('projet/show.html.twig', [
                    'entity' => $projet,
                    'entityType' => 'projet',
                    'groups' => $groups,
                    'groupBy' => $groupManager->getGroupBy(),
        ]);
    }

    #[Route('/search', name: 'json_projet_search')]
    public function search(Request $request, ProjetRepository $repo): JsonResponse {
        $q = $request->query->get('q', '');
        $projets = $repo->search($q);
        $results = [];
        foreach ($projets as $c) {
            $results[] = ['id' => $c->getId(), 'label' => $c->__toString()];
        }
//        var_dump($results);die;
        return $this->json($results);
    }
    
    #[Route('/edit/{id}', name: 'app_projet_edit', methods: ['GET', 'POST'])]
    public function edit(Projet $projet, Request $request, EntityManagerInterface $em): Response {
        $form = $this->createForm(ProjetFormType::class, $projet);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $em->persist($projet);
            $em->flush();

            $this->addFlash('success', 'Projet modifié avec succès');

            return $this->redirectToRoute('app_projet_show', [
                        'id' => $projet->getId(),
                        'tab' => 'edit',
            ]);
        }

        return $this->render('projet/_form.html.twig', [
                    'form' => $form->createView(),
                    'projet' => $projet,
        ]);
    }
}
