<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Form\AdresseFormType;
use App\Form\AdresseType;
use App\Repository\AdresseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use function mb_strlen;

#[Route('/adresse')]
final class AdresseController extends AbstractController {

    #[Route(name: 'app_adresse_index', methods: ['GET'])]
    public function index(AdresseRepository $adresseRepository): Response {
        return $this->render('adresse/index.html.twig', [
                    'adresses' => $adresseRepository->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_adresse_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response {
        $adresse = new Adresse();
        $form = $this->createForm(AdresseFormType::class, $adresse);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($adresse);
            $entityManager->flush();
            $this->addFlash('success', 'Adresse ajoutée avec succès');
            return $this->redirectToRoute('app_adresse_show', ['id' => $adresse->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('adresse/new.html.twig', [
                    'adresse' => $adresse,
                    'form' => $form,
        ]);
    }

    #[Route('/show/{id}', name: 'app_adresse_show', methods: ['GET', 'POST'])]
    public function show(Adresse $adresse, Request $request, EntityManagerInterface $entityManager): Response {
        $form = $this->createForm(AdresseFormType::class, $adresse);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($adresse);
            $entityManager->flush();

            $this->addFlash('success', 'Adresse modifiée avec succès');

            return $this->redirectToRoute('app_adresse_show', [
                        'id' => $adresse->getId(),
                        'tab' => "edit"
                            ], Response::HTTP_SEE_OTHER);
        }

        return $this->render('adresse/show.html.twig', [
                    'adresse' => $adresse,
                    'form' => $form
        ]);
    }

    #[Route('/search', name: 'api_adresse_search')]
    public function search(Request $request, AdresseRepository $repo): JsonResponse {
        $q = trim($request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->json([]);
        }

        $adresses = $repo->searchAdresse($q);

        return $this->json(array_map(fn(Adresse $a) => [
                            'id' => $a->getId(),
                            'label' => $a->getAdresse() . " (" . $a->getName() . ")",
                                ], $adresses));
    }
}
