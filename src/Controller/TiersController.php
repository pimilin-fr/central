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
    public function show(Tiers $tiers, Request $request, EntityManagerInterface $em, TiersAdresseRepository $tiersAdresseRepo): Response {
        $depRepo = $em->getRepository(Depenses::class);
//        $tiersAdresseRepo = $em->getRepository(TiersAdresse::class);

        $depenses = $depRepo->createQueryBuilder('d')
                ->join('d.portefeuille', 'p')
                ->andWhere('d.tiers = :tiers')
                ->andWhere('p.isReal = :isReal')
                ->setParameter('tiers', $tiers)
                ->setParameter('isReal', true)
                ->orderBy('d.date', 'DESC')
                ->addOrderBy('d.id', 'DESC')
                ->getQuery()
                ->getResult();
        $groupManager = new DepenseGroupManager($request);
        $groups = $groupManager->build(
                $depenses,
                0
        );

        $adresses = $tiersAdresseRepo->findByTiersOrdered($tiers);

        return $this->render('tiers/show.html.twig', [
                    'entity' => $tiers,
                    'entityType' => 'tiers',
                    'groups' => $groups,
                    'groupBy' => $groupManager->getGroupBy(),
                    'adresses' => $adresses
        ]);
    }

    #[Route('/js/adresses/{id}', name: 'app_tiers_adresse_list', methods: ['GET'])]
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

    #[Route('/edit/{id}', name: 'app_tiers_edit', methods: ['GET', 'POST'])]
    public function edit(Tiers $tiers, Request $request, EntityManagerInterface $em): Response {
        $form = $this->createForm(TiersType::class, $tiers);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $em->persist($tiers);
            $em->flush();

            $this->addFlash('success', 'Tiers modifié avec succès');

            return $this->redirectToRoute('app_tiers_show', [
                        'id' => $tiers->getId(),
                        'tab' => 'edit',
            ]);
        }

        return $this->render('tiers/_form.html.twig', [
                    'form' => $form->createView(),
                    'tiers' => $tiers,
        ]);
    }

    #[Route('/add-adresse/{id}', name: 'app_tiers_add_adresse', methods: ['GET', 'POST'])]
    public function addAdresse(Tiers $tiers, Request $request, EntityManagerInterface $em): Response {
        $adresseRepo = $em->getRepository(\App\Entity\Adresse::class);
        $tiersAdresse = new TiersAdresse();
        $tiersAdresse->setTiers($tiers);

        $form = $this->createForm(AddAdresseType::class, $tiersAdresse);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 🔹 Récupération de la valeur non mappée
            $adresseValue = $form->get('adresse_id')->getData();
            // 🔹 Recherche de l'adresse (ex: par ID ou libellé)
            $adresse = $adresseRepo->findOneBy(['id' => intval($adresseValue)]);

            $tiersAdresse->setAdresse($adresse);
            $em->persist($tiersAdresse);
            $em->flush();

            $this->addFlash('success', 'Adresse liée avec succès');

            return $this->redirectToRoute('app_tiers_show', [
                        'id' => $tiers->getId(),
                        'tab' => 'adresses',
            ]);
        }

        return $this->render('tiers/_add_adresse_form.html.twig', [
                    'form' => $form->createView(),
                    'tiers' => $tiers,
        ]);
    }
    
    #[Route('/adresse/{tiers}/toggle-principale/{id}',name: 'app_tiers_adresse_toggle_principale',methods: ['GET'])]
    public function togglePrincipale(Tiers $tiers, TiersAdresse $tiersAdresse,EntityManagerInterface $em): Response {
        $tiersAdresse->setIsPrincipale(!$tiersAdresse->isPrincipale());

        $em->flush();

        return $this->redirectToRoute(
            'app_tiers_show',
            [
                'id' => $tiers->getId(),
                'tab' => 'adresses',
            ]
        );
    }

    #[Route('/unlink-adresse/{id}', name: 'app_tiers_adresse_unlink', methods: ['GET'])]
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
