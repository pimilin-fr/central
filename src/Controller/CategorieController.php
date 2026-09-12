<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Form\CategorieType;
use App\Repository\CategorieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/categories')]
class CategorieController extends AbstractController {

    #[Route('', name: 'app_categorie_index')]
    public function index(CategorieRepository $repo, \App\Service\CategorieTreeBuilder $treeBuilder) {
        return $this->render('categorie/index.html.twig', [
                    'categories' => $treeBuilder->build($repo->findAllOrdered())
        ]);
    }

    #[Route('/new', name: 'app_categorie_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response {
        die('NOT IMPLEMENTED YET !');//--- TODO ?
    }

    #[Route('/search', name: 'json_categories_search')]
    public function search(Request $request, CategorieRepository $repo): JsonResponse {
        $q = $request->query->get('q', '');
        $categories = $repo->search($q);
        $results = [];
        foreach ($categories as $c) {
            if ($c->isLeaf()) {
                $results[] = ['id' => $c->getId(), 'label' => $c->__toString()];
            }
        }
//        var_dump($results);die;
        return $this->json($results);
    }

    #[Route('/show/{id}', name: 'app_categorie_show', methods: ['GET', 'POST'])]
    public function show(Request $request, Categorie $categorie, EntityManagerInterface $em): Response {
        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($request->request->get('recompute_code')) {
                $categorie->computeFields();
            }
            $em->flush();

            // retour sur l’onglet Modifier
            return $this->redirectToRoute('app_categorie_show', [
                        'id' => $categorie->getId(),
                        'tab' => "edit"
            ]);
        }
        return $this->render('categorie/show.html.twig', [
                    'categorie' => $categorie,
                    'form' => $form->createView(),
        ]);
    }
    
    #[Route('/edit/{id}', name: 'app_categorie_edit', methods: ['GET', 'POST'])]
    public function edit(Categorie $category, Request $request, EntityManagerInterface $em): Response {
        $form = $this->createForm(CategorieType::class, $category);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $em->persist($category);
            $em->flush();

            $this->addFlash('success', 'Categorie modifié avec succès');

            return $this->redirectToRoute('app_categorie_show', [
                        'id' => $category->getId(),
                        'tab' => 'edit',
            ]);
        }

        return $this->render('categorie/_form.html.twig', [
                    'form' => $form->createView(),
                    'categorie' => $category,
        ]);
    }

}
