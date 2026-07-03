<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Entity\Projet;
use App\Entity\Tiers;
use App\Entity\TiersAdresse;
use App\Form\AddAdresseType;
use App\Form\AddDepensesType;
use App\Form\TiersType;
use App\Repository\CategorieRepository;
use App\Repository\DepensesRepository;
use App\Repository\PortefeuilleRepository;
use App\Repository\ProjetRepository;
use App\Repository\TiersAdresseRepository;
use App\Repository\TiersRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/depenses')]
final class DepensesController extends AbstractController {

    #[Route(name: 'app_depenses_index', methods: ['GET'])]
    public function index(Request $request, DepensesRepository $repository, PortefeuilleRepository $ptfRepo): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 200;

        $depense = new Depenses();
        // 👇 On récupère le premier portefeuille par défaut
        $defaultPortefeuille = $ptfRepo->createQueryBuilder('p')
                ->where('p.isDefault = :default')
                ->setParameter('default', true)
                ->orderBy('p.id', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

        if ($defaultPortefeuille) {
            $depense->setPortefeuille($defaultPortefeuille);
        }
        $addDepenseForm = $this->createForm(AddDepensesType::class, $depense, [
            'action' => $this->generateUrl('app_depenses_new'),
            'method' => 'POST',
        ]);

        $total = $repository->countAll();

        $pages = ceil($total / $limit);
        return $this->render('depenses/index.html.twig', [
                    'depenses' => $repository->findAllOrdered($page, $limit),
                    'page' => $page,
                    'pages' => $pages,
                    'total' => $total,
                    'depensesForm' => $addDepenseForm
        ]);
    }

    #[Route('/new', name: 'app_depenses_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response {

        $depense = new Depenses();

        /*
         * =========================================================
         * CONTEXTE (GET UNIQUEMENT)
         * =========================================================
         */
        $query = $request->query;
        $ptfRepo = $entityManager->getRepository(\App\Entity\Portefeuille::class);
        $catRepo = $entityManager->getRepository(\App\Entity\Categorie::class);
        $tiersRepo = $entityManager->getRepository(Tiers::class);
        $projRepo = $entityManager->getRepository(Projet::class);
        $adrRepo = $entityManager->getRepository(\App\Entity\Adresse::class);

        $portefeuille = null;
        $categorie = new \App\Entity\Categorie();
        $tiers = new Tiers();
        $projet = new Projet();
        if ($query->get('portefeuille')) {
            $portefeuille = $ptfRepo->find($query->get('portefeuille'));
        }
        if ($query->get('categorie')) {
            $categorie = $catRepo->find($query->get('categorie'));
        }
        if ($query->get('tiers')) {
            $tiers = $tiersRepo->find($query->get('tiers'));
        }
        if ($query->get('projet')) {
            $projet = $projRepo->find($query->get('projet'));
        }

        /*
         * =========================================================
         * FORM
         * =========================================================
         */
        $form = $this->createForm(AddDepensesType::class, $depense, [
            'portefeuille_entity' => $portefeuille,
            'categorie_id' => $categorie->getId(),
            'categorie_label' => $categorie->getLibelle(),
            'projet_id' => $projet->getId(),
            'projet_label' => $projet->getName(),
            'tiers_id' => $tiers->getId(),
            'tiers_label' => $tiers->getName(),
        ]);

        /*
         * =========================================================
         * HANDLE REQUEST
         * =========================================================
         */
        $form->handleRequest($request);

        if ($form->isSubmitted()) {

            if ($form->isValid()) {

                /*
                 * =========================================================
                 * AUTOCOMPLETE → toujours via le form (POST)
                 * =========================================================
                 */
                $categorieId = $form->get('categorie_id')->getData();
                $tiersId = $form->get('tiers_id')->getData();
                $projetId = $form->get('projet_id')->getData();
                $adresseId = $form->get('adresse_id')->getData();

                if ($categorieId) {
                    $categorie = $catRepo->find($categorieId);
                    $depense->setCategorie($categorie);
                }

                if ($tiersId) {
                    $tiers = $tiersRepo->find($tiersId);
                    $depense->setTiers($tiers);
                }

                if ($projetId) {
                    $projet = $projRepo->find($projetId);
                    $depense->setProjet($projet);
                }
                if ($adresseId) {
                    $adresse = $adrRepo->find($adresseId);
                    $depense->setAdresse($adresse);
                }

//                var_dump($form->get('portefeuille')->getData());die;

                /*
                 * =========================================================
                 * SÉCURITÉ : fallback si portefeuille absent
                 * =========================================================
                 */
                if (!$depense->getPortefeuille() && $portefeuille) {
                    $depense->setPortefeuille($portefeuille);
                }

                /*
                 * =========================================================
                 * SAVE
                 * =========================================================
                 */
                $entityManager->persist($depense);
                $entityManager->flush();

                /*
                 * =========================================================
                 * RESPONSE
                 * =========================================================
                 */
                if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                    return $this->json(['success' => true]);
                }

                return $this->redirectToRoute('app_portefeuille_index');
            }

            /*
             * =========================================================
             * ERREUR FORM → renvoyer HTML (modale)
             * =========================================================
             */
            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return $this->render('depenses/_add_form.html.twig', [
                            'form' => $form->createView(),
                ]);
            }
        }

        /*
         * =========================================================
         * AFFICHAGE INITIAL
         * =========================================================
         */
        return $this->render('depenses/_add_form.html.twig', [
                    'form' => $form->createView(),
        ]);
    }

    #[Route('/multiupdate', name: 'app_depenses_bulk_update', methods: ['POST'])]
    public function multiupdate(Request $request, EntityManagerInterface $entityManager) {
        echo "<pre>";

        $depenses = $entityManager->getRepository(Depenses::class)
                ->createQueryBuilder('d')
                ->where('d.id IN (:ids)')
                ->setParameter('ids', $request->request->all('ids'))
                ->getQuery()
                ->getResult();

        switch ($request->request->get("action")) {
            case 'delete':
                foreach ($depenses as $depense) {
                    $entityManager->remove($depense);
                }
                $entityManager->flush();
                $i = sizeof($depenses);
                $this->addFlash('success', $i . ' ligne(s) supprimée(s) avec succès');
                break;
            case "set_projet":
                $projet = $entityManager->getRepository(Projet::class)
                        ->find($request->request->get('projet_id'));

                foreach ($depenses as $depense) {
                    $depense->setProjet($projet);
                    $entityManager->persist($depense);
                }
                $entityManager->flush();
                $i = sizeof($depenses);
                $this->addFlash('success', $i . ' ligne(s) modifiée(s) avec succès');
//                var_dump($projet, $depenses, $request->request->all());
                break;
            case 'set_date_paiement':
                $date = \DateTime::createFromFormat('Y-m-d', $request->request->get('date_value'));

                foreach ($depenses as $depense) {
                    $depense->setDatePaiement($date);
                    $entityManager->persist($depense);
                }
                $i = sizeof($depenses);
                $this->addFlash('success', $i . ' ligne(s) marquée(s) payée(s) avec succès');
//                var_dump($projet, $depenses, $request->request->all());
                $entityManager->flush();
                break;
            case 'set_date_releve':
                if (sizeof($depenses) < 1) {
                    break;
                }
                $manager = new \App\Services\ReleveManager($entityManager);

                $date = \DateTime::createFromFormat('Y-m-d', $request->request->get('date_value'));

                // relevé à cette date
                $releve = $manager->addOperations($date, $depenses);

                $entityManager->persist($releve);
                $i = sizeof($depenses);
                $this->addFlash('success', $i . ' ligne(s) ajoutés au relevé du ' . $date->format('d/M/y') . ' avec succès');
//                var_dump($projet, $depenses, $request->request->all());
                $entityManager->flush();
                break;
            default :
                var_dump($request->request->all());
                die;
                break;
        }

        $referer = $request->headers->get('referer');
        return $this->redirect($referer ?? $this->generateUrl('app_depenses_index'));
    }

    #[Route('/{id}', name: 'app_depenses_show', methods: ['GET', 'POST'])]
    public function show(Tiers $tiers, Request $request, TiersAdresseRepository $tiersAdresseRepo, EntityManagerInterface $entityManager): Response {
        $form = $this->createForm(TiersType::class, $tiers);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($tiers);
            $entityManager->flush();

            $this->addFlash('success', 'Tiers modifié avec succès');

            return $this->redirectToRoute('app_tiers_show', [
                        'id' => $tiers->getId(),
                        'tab' => "edit"
                            ], Response::HTTP_SEE_OTHER);
        }

        $adresses = $tiersAdresseRepo->findByTiersOrdered($tiers);

        $addAdresseForm = $this->createForm(
                AddAdresseType::class,
                new TiersAdresse(),
                [
                    'action' => $this->generateUrl('app_tiers_adresse_add', [
                        'id' => $tiers->getId(),
                    ]),
                ]
        );
        return $this->render('tiers/show.html.twig', [
                    'tiers' => $tiers,
                    'form' => $form,
                    'addAdresseForm' => $addAdresseForm,
                    'adresses' => $adresses
        ]);
    }
}
