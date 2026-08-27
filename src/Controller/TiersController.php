<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Entity\Tiers;
use App\Entity\TiersAdresse;
use App\Form\AddAdresseType;
use App\Form\TiersType;
use App\Repository\AdresseRepository;
use App\Repository\TiersAdresseRepository;
use App\Repository\TiersRepository;
use App\Services\DepenseGrouper\DepenseGroupManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tiers')]
final class TiersController extends AbstractController {

    #[Route(name: 'app_tiers_index', methods: ['GET'])]
    public function index(TiersRepository $tiersRepository): Response {
        return $this->render('tiers/index.html.twig', [
                    'tiers' => $tiersRepository->findAllWithAdresses(),
        ]);
    }

    #[Route('/new', name: 'app_tiers_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response {
        $tier = new Tiers();
        $form = $this->createForm(TiersType::class, $tier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($tier);
            $entityManager->flush();

            $this->addFlash('success', 'Tiers ajouté avec succès');

            return $this->redirectToRoute('app_tiers_show', ['id' => $tier->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tiers/new.html.twig', [
                    'tier' => $tier,
                    'form' => $form,
        ]);
    }

    #[Route('/show/{id}', name: 'app_tiers_show', methods: ['GET', 'POST'])]
    public function show(Tiers $tiers, Request $request, EntityManagerInterface $em): Response {
        $depRepo = $em->getRepository(Depenses::class);

        $depenses = $depRepo->findBy(
                ['tiers' => $tiers],
                ['date' => 'DESC', 'id' => 'DESC']// IMPORTANT
        );
        $groupManager = new DepenseGroupManager($request);
        $groups = $groupManager->build(
                $depenses,
                0
        );
        return $this->render('tiers/show.html.twig', [
                    'entity' => $tiers,
                    'entityType' => 'tiers',
                    'groups' => $groups,
                    'groupBy' => $groupManager->getGroupBy()
        ]);
//public function show(Tiers $tiers, Request $request, TiersAdresseRepository $tiersAdresseRepo, DepensesRepository $depRepo, EntityManagerInterface $entityManager): Response {
//        $form = $this->createForm(TiersType::class, $tiers);
//        $form->handleRequest($request);
//        if ($form->isSubmitted() && $form->isValid()) {
//            $entityManager->persist($tiers);
//            $entityManager->flush();
//
//            $this->addFlash('success', 'Tiers modifié avec succès');
//
//            return $this->redirectToRoute('app_tiers_show', [
//                        'id' => $tiers->getId(),
//                        'tab' => "edit"
//                            ], Response::HTTP_SEE_OTHER);
//        }
//
//        $adresses = $tiersAdresseRepo->findByTiersOrdered($tiers);
//
//        $addAdresseForm = $this->createForm(
//                AddAdresseType::class,
//                new TiersAdresse(),
//                [
//                    'action' => $this->generateUrl('app_tiers_adresse_add', [
//                        'id' => $tiers->getId(),
//                    ]),
//                ]
//        );
//        return $this->render('tiers/show.html.twig', [
//                    'tiers' => $tiers,
//                    'form' => $form,
//                    'depenses' => $depRepo->findByTiers($tiers),
//                    'addAdresseForm' => $addAdresseForm,
//                    'adresses' => $adresses
//        ]);
    }

    #[Route('/adresses/{id}', name: 'app_tiers_adresse_list', methods: ['GET'])]
    public function adresses(Tiers $tiers, TiersAdresseRepository $repo): JsonResponse {
        $results = [];

        foreach ($repo->findByTiersOrdered($tiers) as $link) {

            $results[] = [
                'id' => $link->getAdresse()->getId(),
                'label' => (string) $link->getAdresse(),
                'principale' => $link->isPrincipale(),
            ];
        }

        return $this->json($results);
    }

    #[Route('/add_adresse/{id}/', name: 'app_tiers_adresse_add', methods: ['POST'])]
    public function addAdresse(Request $request, Tiers $tiers, EntityManagerInterface $em, TiersAdresseRepository $repo, AdresseRepository $adresseRepo) {
        $tiersAdresse = new TiersAdresse();
        $tiersAdresse->setTiers($tiers);

        $form = $this->createForm(AddAdresseType::class, $tiersAdresse);
        $form->handleRequest($request);
        // 🔹 Récupération de la valeur non mappée
        $adresseValue = $form->get('adresse_id')->getData();

        // 🔹 Recherche de l'adresse (ex: par ID ou libellé)
        $adresse = $adresseRepo->findOneBy([
            'id' => intval($adresseValue)
        ]);
        $tiersAdresse->setAdresse($adresse);

        if ($form->isSubmitted() && $form->isValid()) {

            // ⚠️ Si la nouvelle adresse est principale
            if ($tiersAdresse->isPrincipale()) {

                // 1️⃣ Retirer l'ancienne principale
//                $repo->unsetPrincipaleForTiers($tiers);
                // 2️⃣ Marquer celle-ci comme principale
                $tiersAdresse->setIsPrincipale(true);
            }

            $em->persist($tiersAdresse);
            $em->flush();

            $this->addFlash('success', 'Adresse liée avec succès');

            return $this->redirectToRoute('app_tiers_show', [
                        'id' => $tiers->getId(),
                        'tab' => 'adresses'
            ]);
        }

        return $this->render('tiers/add_adresse_form.html.twig', [
                    'addAdresseForm' => $form->createView(),
                    'tiers' => $tiers,
        ]);
    }

    #[Route('/unlink_adresse/{id}', name: 'app_tiers_adresse_unlink', methods: ['GET'])]
    public function unlinkAdresse(TiersAdresse $tiersAdresse, EntityManagerInterface $em) {
        $id = $tiersAdresse->getTiers()->getId();
        $em->remove($tiersAdresse);
        $em->flush();

        $this->addFlash('success', 'Adresse liée avec succès');

        return $this->redirectToRoute('app_tiers_show', [
                    'id' => $id,
                    'tab' => 'adresses'
        ]);
    }

    #[Route('/delete/{id}', name: 'app_tiers_delete', methods: ['GET'])]
    public function delete(Tiers $tier, EntityManagerInterface $entityManager): Response {
        $tier->setDeletedAt(new DateTimeImmutable());
        $entityManager->flush();
        return $this->redirectToRoute('app_tiers_show', [
                    'id' => $tier->getId()
        ]);
    }

    #[Route('/restore/{id}', name: 'app_tiers_restore', methods: ['GET'])]
    public function restore(Tiers $tier, EntityManagerInterface $entityManager): Response {
        $tier->setDeletedAt(null);
        $entityManager->flush();
        return $this->redirectToRoute('app_tiers_show', [
                    'id' => $tier->getId()
        ]);
    }

    #[Route('/search', name: 'json_tiers_search')]
    public function search(Request $request, TiersRepository $repo): JsonResponse {
        $q = $request->query->get('q', '');
        $tiers = $repo->search($q);
        $results = [];
        foreach ($tiers as $c) {
            $results[] = ['id' => $c->getId(), 'label' => $c->getName()];
        }
//        var_dump($results);die;
        return $this->json($results);
    }
}
