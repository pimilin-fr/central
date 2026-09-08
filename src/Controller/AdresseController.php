<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Form\AdresseFormType;
use App\Repository\AdresseRepository;
use App\Service\Adresse\AdresseMapBuilder;
use App\Service\Geocoder\Geocoder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/adresse')]
final class AdresseController extends AbstractController {

    #[Route(name: 'app_adresse_index', methods: ['GET'])]
    public function index(AdresseRepository $adresseRepository): Response {
        $adresses = $adresseRepository->findAllOrdered();

        $adressesParType = [];

        foreach ($adresses as $adresse) {
            $type = $adresse->getAdresseType();
            $typeId = $type->getId();

            if (!isset($adressesParType[$typeId])) {
                $adressesParType[$typeId] = [
                    'type' => $type,
                    'adresses' => [],
                ];
            }

            $adressesParType[$typeId]['adresses'][] = $adresse;
        }

        return $this->render('adresse/index.html.twig', [
                    'adresses' => $adresses,
                    'adressesParType' => $adressesParType,
        ]);
    }

    #[Route('/new', name: 'app_adresse_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, Geocoder $geocoder, AdresseMapBuilder $adresseMapBuilder): Response {
        $adresse = new Adresse();

        return $this->handleForm(
                        $request,
                        $adresse,
                        $entityManager,
                        $geocoder,
                        $adresseMapBuilder,
                        'Adresse ajoutée avec succès'
                );
    }

    #[Route('/show/{id}', name: 'app_adresse_show', methods: ['GET', 'POST'])]
    public function show(Adresse $adresse, Request $request, EntityManagerInterface $entityManager, Geocoder $geocoder, AdresseMapBuilder $adresseMapBuilder): Response {
        return $this->handleForm(
                        $request,
                        $adresse,
                        $entityManager,
                        $geocoder,
                        $adresseMapBuilder,
                        'Adresse modifiée avec succès'
                );
    }

    private function handleForm(Request $request, Adresse $adresse, EntityManagerInterface $entityManager, Geocoder $geocoder, AdresseMapBuilder $adresseMapBuilder, string $successMessage): Response {
        $form = $this->createForm(
                AdresseFormType::class,
                $adresse
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $coords = $geocoder->geocode($adresse);

            if ($coords !== null) {
                $adresse->setLatitude($coords['lat']);
                $adresse->setLongitude($coords['lng']);
            }

            $entityManager->persist($adresse);
            $entityManager->flush();

            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute('app_adresse_show', [
                        'id' => $adresse->getId(),
                        'tab' => 'edit',
                            ], Response::HTTP_SEE_OTHER
                    );
        }

        $adresseMap = $adresse->getId() !== null ? $adresseMapBuilder->build($adresse) : null;

        return $this->render(
                        $adresse->getId() === null ? 'adresse/new.html.twig' : 'adresse/show.html.twig',
                        [
                            'adresse' => $adresse,
                            'form' => $form,
                            'adresseMap' => $adresseMap,
                        ]
                );
    }

    #[Route('/delete/{id}', name: 'app_adresse_delete', methods: ['GET'])]
    public function delete(Adresse $adresse, EntityManagerInterface $entityManager): Response {
        $adresse->setDeletedAt(new DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', 'Adresse supprimée avec succès');

        return $this->redirectToRoute('app_adresse_show', [
                    'id' => $adresse->getId(),
                    'tab' => 'summary',
                        ]
                );
    }

    #[Route('/restore/{id}', name: 'app_adresse_restore', methods: ['GET'])]
    public function restore(Adresse $adresse, EntityManagerInterface $entityManager): Response {
        $adresse->setDeletedAt(null);
        $entityManager->flush();

        $this->addFlash('success', 'Adresse restaurée avec succès');

        return $this->redirectToRoute('app_adresse_show', [
                    'id' => $adresse->getId(),
                    'tab' => 'summary',
                        ]
                );
    }

    #[Route('/search', name: 'api_adresse_search')]
    public function search(Request $request, AdresseRepository $adresseRepository): JsonResponse {
        $query = trim($request->query->get('q', ''));

        if (mb_strlen($query) < 2) {
            return $this->json([]);
        }

        $adresses = $adresseRepository->searchAdresse($query);

        return $this->json(array_map(static fn(Adresse $adresse) => [
                            'id' => $adresse->getId(),
                            'label' => $adresse->getAdresse() . ' (' . $adresse->getName() . ')',
                                ], $adresses));
    }
}
