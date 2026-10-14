<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Form\AdresseFormType;
use App\Repository\AdresseRepository;
use App\Service\Adresse\AdresseMapBuilder;
use App\Service\Adresse\AdresseAssistant;
use App\Service\Adresse\AdresseLevel;
use App\Service\Geocoder\Geocoder;
use App\Service\Geocoder\GeocoderException;
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
        // onglets par type dans l'ordre de la hiérarchie (Rue, Quartier, Ville… Planète) ; tri stable : l'ordre par nom est conservé
        usort($adresses, static fn(Adresse $x, Adresse $y) => [
            AdresseLevel::rankOfTypeName($x->getAdresseType()->getName()), $x->getAdresseType()->getName() ?? '',
        ] <=> [
            AdresseLevel::rankOfTypeName($y->getAdresseType()->getName()), $y->getAdresseType()->getName() ?? '',
        ]);

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
    public function new(Request $request, EntityManagerInterface $entityManager, Geocoder $geocoder, AdresseMapBuilder $adresseMapBuilder, AdresseAssistant $assistant): Response {
        $adresse = new Adresse();

        return $this->handleForm(
                        $request,
                        $adresse,
                        $entityManager,
                        $geocoder,
                        $adresseMapBuilder,
                        $assistant,
                        'Adresse ajoutée avec succès'
                );
    }

    #[Route('/edit/{id}', name: 'app_adresse_edit', methods: ['GET', 'POST'])]
    public function edit(Adresse $adresse, Request $request, EntityManagerInterface $em, Geocoder $geocoder, AdresseMapBuilder $adresseMapBuilder, AdresseAssistant $assistant): Response {
        return $this->handleForm($request, $adresse, $em, $geocoder, $adresseMapBuilder, $assistant, 'Adresse modifiée avec succès');
    }

    #[Route('/show/{id}', name: 'app_adresse_show', methods: ['GET', 'POST'])]
    public function show(Adresse $adresse, Request $request, EntityManagerInterface $entityManager, Geocoder $geocoder, AdresseMapBuilder $adresseMapBuilder, AdresseAssistant $assistant): Response {
        return $this->handleForm(
                        $request,
                        $adresse,
                        $entityManager,
                        $geocoder,
                        $adresseMapBuilder,
                        $assistant,
                        'Adresse modifiée avec succès'
                );
    }

    private function handleForm(Request $request, Adresse $adresse, EntityManagerInterface $entityManager, Geocoder $geocoder, AdresseMapBuilder $adresseMapBuilder, AdresseAssistant $assistant, string $successMessage): Response {
        $form = $this->createForm(
                AdresseFormType::class,
                $adresse
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Position : coordonnées saisies > position issue de l'assistant > géocodage automatique
            $forced = self::parseCoords((string) $adresse->getAdresseForcee());
            if ($forced !== null) {
                $adresse->setLatitude($forced['lat']);
                $adresse->setLongitude($forced['lng']);
            } elseif (!($request->request->get('geo_from_assist') && $adresse->isGeolocalisee())) {
                try {
                    $coords = $geocoder->geocode($adresse);
                    $adresse->setLatitude($coords['lat']);
                    $adresse->setLongitude($coords['lng']);
                } catch (GeocoderException $e) {
                    $this->addFlash('warning', 'Adresse enregistrée sans géolocalisation (' . $e->getMessage() . ').');
                }
            }

            // Rattachement assisté : crée les niveaux manquants (quartier, ville…) puis relie la rue
            $chain = json_decode((string) $request->request->get('chain_json', ''), true);
            if (is_array($chain) && $chain !== []) {
                $created = $assistant->applyChain($adresse, $chain, $adresse->getLatitude(), $adresse->getLongitude());
                if ($created > 0) {
                    $this->addFlash('success', $created . ' adresse(s) parente(s) créée(s).');
                }
                if ($assistant->reused !== []) {
                    $this->addFlash('info', 'Déjà existant(s), réutilisé(s) sans doublon : ' . implode(', ', $assistant->reused) . '.');
                }
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

    /** Étape 1 de la saisie assistée : adresse complète (ou coordonnées) → candidats décomposés. */
    #[Route('/assist/locate', name: 'json_adresse_assist_locate', methods: ['GET'])]
    public function assistLocate(Request $request, AdresseAssistant $assistant): JsonResponse {
        try {
            $candidates = $assistant->locate(
                    trim((string) $request->query->get('q', '')),
                    self::parseCoords((string) $request->query->get('coords', '')),
                    AdresseLevel::tryFrom((string) $request->query->get('level', 'rue')) ?? AdresseLevel::Rue
            );
        } catch (GeocoderException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        return $this->json(['ok' => true, 'candidates' => $candidates]);
    }

    /** Étape suivante : les N adresses existantes d'un niveau les plus proches d'une position (carte + liste). */
    #[Route('/assist/nearby', name: 'json_adresse_assist_nearby', methods: ['GET'])]
    public function assistNearby(Request $request, AdresseAssistant $assistant): JsonResponse {
        $q = $request->query;
        $level = AdresseLevel::tryFrom((string) $q->get('level', ''));
        if ($level === null || $level === AdresseLevel::Rue) {
            return $this->json(['ok' => false, 'error' => 'Niveau inconnu.']);
        }
        $lat = is_numeric($q->get('lat')) ? (float) $q->get('lat') : null;
        $lng = is_numeric($q->get('lng')) ? (float) $q->get('lng') : null;

        return $this->json(['ok' => true] + $assistant->nearby($level, $lat, $lng, (string) $q->get('name', ''), null, $q->getBoolean('all'), $q->getInt('exclude') ?: null));
    }

    /** « 48.8566, 2.3522 » → ['lat' => 48.8566, 'lng' => 2.3522 ], sinon null. */
    private static function parseCoords(string $raw): ?array {
        if (!preg_match('/^\s*(-?\d{1,2}(?:[.,]\d+)?)\s*[;,\s]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/', $raw, $m)) {
            return null;
        }
        $lat = (float) str_replace(',', '.', $m[1]);
        $lng = (float) str_replace(',', '.', $m[2]);

        return (abs($lat) <= 90 && abs($lng) <= 180) ? ['lat' => $lat, 'lng' => $lng] : null;
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
