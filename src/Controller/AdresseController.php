<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Form\AdresseFormType;
use App\Repository\AdresseRepository;
use App\Service\Geocoder\Geocoder;
use DateTimeImmutable;
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
    public function new(Request $request, EntityManagerInterface $entityManager, Geocoder $geocoder): Response {
        $adresse = new Adresse();

        return $this->handleForm(
                        $request,
                        $adresse,
                        $entityManager,
                        $geocoder,
                        'Adresse ajoutée avec succès'
                );
    }

    #[Route('/show/{id}', name: 'app_adresse_show', methods: ['GET', 'POST'])]
    public function show(Adresse $adresse, Request $request, EntityManagerInterface $entityManager, Geocoder $geocoder): Response {
        return $this->handleForm(
                        $request,
                        $adresse,
                        $entityManager,
                        $geocoder,
                        'Adresse modifiée avec succès'
                );
    }

    private function handleForm(Request $request, Adresse $adresse, EntityManagerInterface $entityManager, Geocoder $geocoder, string $successMessage): Response {
        $form = $this->createForm(AdresseFormType::class, $adresse);

        $adresseMap = null;

        if ($adresse->getId() !== null) {
            $adresseMap = $this->buildHierarchicalMap($adresse);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Géocodage uniquement lors de la sauvegarde
            $coords = $geocoder->geocode($adresse);

//            var_dump($coords);die('...');
            if ($coords !== null) {
                $adresse->setLatitude($coords['lat']);
                $adresse->setLongitude($coords['lng']);
            }

            $entityManager->persist($adresse);
            $entityManager->flush();

            $this->addFlash('success', $successMessage);

            return $this->redirectToRoute(
                            'app_adresse_show',
                            [
                                'id' => $adresse->getId(),
                                'tab' => 'edit',
                            ],
                            Response::HTTP_SEE_OTHER
                    );
        }

        return $this->render(
                        $adresse->getId() === null ? 'adresse/new.html.twig' : 'adresse/show.html.twig',
                        [
                            'adresse' => $adresse,
                            'form' => $form,
                            'adresseMap' => $adresseMap,
                        ]
                );
    }

    private function buildHierarchicalMap(Adresse $adresse): array {
        $map = [
            'current' => [
                'id' => $adresse->getId(),
                'name' => $adresse->getName(),
                'latitude' => $adresse->getLatitude(),
                'longitude' => $adresse->getLongitude(),
                'url' => $this->generateUrl(
                        'app_adresse_show',
                        ['id' => $adresse->getId()]
                ),
            ],
            'zones' => [],
        ];

        /*
         * Une Rue n'a pas d'enfants :
         * la carte affichera uniquement son propre point.
         */
        if ($adresse->getChildren()->isEmpty()) {
            return $map;
        }

        $children = $adresse->getChildren()->toArray();

        /*
         * Cas Quartier :
         * les enfants directs sont les Rues.
         *
         * On crée donc UNE SEULE zone contenant
         * toutes les Rues du quartier.
         */
        $childrenAreLeaves = true;

        foreach ($children as $child) {
            if (!$child->getChildren()->isEmpty()) {
                $childrenAreLeaves = false;
                break;
            }
        }

        if ($childrenAreLeaves) {
            $points = [];

            foreach ($children as $child) {
                if (!$child->isGeolocalisee()) {
                    continue;
                }

                $points[] = [
                    'id' => $child->getId(),
                    'name' => $child->getName(),
                    'latitude' => $child->getLatitude(),
                    'longitude' => $child->getLongitude(),
                    'url' => $this->generateUrl(
                            'app_adresse_show',
                            ['id' => $child->getId()]
                    ),
                ];
            }

            $map['zones'][] = [
                'id' => $adresse->getId(),
                'name' => $adresse->getName(),
                'points' => $points,
            ];

            return $map;
        }

        /*
         * Cas Ville et niveaux supérieurs :
         *
         * chaque enfant direct devient une zone,
         * puis on descend jusqu'aux Rues.
         */
        foreach ($children as $zone) {
            $points = [];

            $this->collectRuePoints(
                    $zone,
                    $points
            );

            $map['zones'][] = [
                'id' => $zone->getId(),
                'name' => $zone->getName(),
                'points' => $points,
            ];
        }

        return $map;
    }

    private function collectRuePoints(Adresse $adresse, array &$points): void {
        /*
         * Nous sommes arrivés à une Rue.
         */
        if ($adresse->getChildren()->isEmpty()) {
            if ($adresse->isGeolocalisee()) {
                $points[] = [
                    'id' => $adresse->getId(),
                    'name' => $adresse->getName(),
                    'latitude' => $adresse->getLatitude(),
                    'longitude' => $adresse->getLongitude(),
                    'url' => $this->generateUrl(
                            'app_adresse_show',
                            ['id' => $adresse->getId()]
                    ),
                ];
            }

            return;
        }

        foreach ($adresse->getChildren() as $child) {
            $this->collectRuePoints(
                    $child,
                    $points
            );
        }
    }

    #[Route('/delete/{id}', name: 'app_adresse_delete', methods: ['GET'])]
    public function delete(Adresse $adresse, EntityManagerInterface $em): Response {
        $adresse->setDeletedAt(new DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', 'Adresse supprimée avec succès');

        return $this->redirectToRoute('app_adresse_show', [
                    'id' => $adresse->getId(),
                    'tab' => 'summary'
        ]);
    }

    #[Route('/restore/{id}', name: 'app_adresse_restore', methods: ['GET'])]
    public function restore(Adresse $adresse, EntityManagerInterface $em): Response {
        $adresse->setDeletedAt(null);
        $em->flush();
        $this->addFlash('success', 'Portefeuille supprimé avec succès');

        return $this->redirectToRoute('app_adresse_show', [
                    'id' => $adresse->getId(),
                    'tab' => 'summary'
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
