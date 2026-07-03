<?php

namespace App\Controller;

use App\Entity\ProjetType;
use App\Form\ProjetTypeFormType;
use App\Repository\ProjetTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/typeprojet')]
final class ProjetTypeController extends AbstractController {

    #[Route(name: 'app_projet_type_index', methods: ['GET'])]
    public function index(ProjetTypeRepository $repository): Response {
        return $this->render('projet_type/index.html.twig', [
                    'types' => $repository->findTree(),
        ]);
    }

    #[Route('/new', name: 'app_projet_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response {
        $projet = new ProjetType();
        $form = $this->createForm(ProjetTypeFormType::class, $projet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($projet);
            $entityManager->flush();

            $this->addFlash('success', 'Tiers ajouté avec succès');

            return $this->redirectToRoute('app_projet_type_show', ['id' => $projet->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('projet_type/new.html.twig', [
                    'projet' => $projet,
                    'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_projet_type_show', methods: ['GET', 'POST'])]
    public function show(ProjetType $type, Request $request, \App\Repository\ProjetRepository $pRepo, EntityManagerInterface $entityManager): Response {
        $form = $this->createForm(ProjetTypeFormType::class, $type);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($type);
            $entityManager->flush();

            $this->addFlash('success', 'Tiers modifié avec succès');

            return $this->redirectToRoute('app_projet_type_show', [
                        'id' => $type->getId(),
                        'tab' => "edit"
                            ], Response::HTTP_SEE_OTHER);
        }

        return $this->render('projet_type/show.html.twig', [
                    'type' => $type,
                    'form' => $form,
                    'projets' => $pRepo->findByProjetType($type)
        ]);
    }
}
