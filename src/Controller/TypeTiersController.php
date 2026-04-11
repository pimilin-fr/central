<?php

namespace App\Controller;

use App\Entity\TypeTiers;
use App\Form\TypeTiersType;
use App\Repository\TiersRepository;
use App\Repository\TypeTiersRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/typetiers')]
final class TypeTiersController extends AbstractController {

    #[Route('', name: 'app_type_tiers_index', methods: ['GET'])]
    public function index(TypeTiersRepository $repo): Response {
        return $this->render('type_tiers/index.html.twig', [
                    'types' => $repo->findAllOrdered(),
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
    public function show(
            Request $request,
            TypeTiers $typeTiers,
            TiersRepository $tiersRepo,
            EntityManagerInterface $em
    ): Response {
        // Form d’édition (intégré)
        $form = $this->createForm(TypeTiersType::class, $typeTiers);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($request->request->get('recompute_code')) {
                $typeTiers->computeFields();
            }
            $em->flush();

            // retour sur l’onglet Modifier
            return $this->redirectToRoute('app_type_tiers_show', [
                        'id' => $typeTiers->getId(),
            ]);
        }

        return $this->render('type_tiers/show.html.twig', [
                    'typeTiers' => $typeTiers,
                    'tiers' => $tiersRepo->findByTiersType($typeTiers),
                    'form' => $form->createView(),
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
}
