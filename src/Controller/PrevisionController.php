<?php

namespace App\Controller;

use App\Entity\PrevisionEcheance;
use App\Entity\PrevisionRegle;
use App\Entity\PrevisionTranche;
use App\Form\PrevisionRegleType;
use App\Prevision\StatutEcheance;
use App\Repository\PrevisionEcheanceRepository;
use App\Repository\PrevisionRegleRepository;
use App\Service\PrevisionManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/** Prévisions : règles récurrentes (abonnements, LOA, paiements en x fois, factures variables) et leurs échéances. */
#[Route('/prevision')]
final class PrevisionController extends AbstractController {

    private const TOKEN = 'prevision';

    #[Route(name: 'app_prevision_index', methods: ['GET'])]
    public function index(PrevisionRegleRepository $regleRepo, PrevisionEcheanceRepository $echeanceRepo, PrevisionManager $manager): Response {
        $today = new DateTime('today');
        $debutMois = (clone $today)->modify('first day of this month');

        $months = [];
        $retard = [];
        foreach ($echeanceRepo->findPrevues() as $echeance) {
            $date = $echeance->getDatePrevue();
            if ($date < $today && $date < $debutMois) {
                $retard[] = $echeance;
                continue;
            }
            $key = $date->format('Y-m');
            $months[$key]['label'] = $date;
            $months[$key]['echeances'][] = $echeance;
            $months[$key]['brut'] = ($months[$key]['brut'] ?? 0.0) + $echeance->getMontantSigne();
            $months[$key]['pondere'] = ($months[$key]['pondere'] ?? 0.0) + $echeance->getMontantSigne() * $echeance->getCertitude()->poids();
        }

        $regles = $regleRepo->findAllWithTranches();
        $estimations = [];
        foreach ($regles as $regle) {
            if ($regle->isEstime() && $regle->isActif()) {
                $estimations[$regle->getId()] = $manager->estimation($regle);
            }
        }

        return $this->render('prevision/index.html.twig', [
                    'months' => $months,
                    'retard' => $retard,
                    'regles' => $regles,
                    'estimations' => $estimations,
                    'today' => $today,
        ]);
    }

    #[Route('/regle/new', name: 'app_prevision_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, PrevisionManager $manager): Response {
        $regle = new PrevisionRegle();
        $regle->addTranche((new PrevisionTranche())->setAPartirDe(new DateTime('today')));

        return $this->handleForm($regle, $request, $em, $manager);
    }

    #[Route('/regle/{id}/edit', name: 'app_prevision_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(PrevisionRegle $regle, Request $request, EntityManagerInterface $em, PrevisionManager $manager): Response {
        return $this->handleForm($regle, $request, $em, $manager);
    }

    private function handleForm(PrevisionRegle $regle, Request $request, EntityManagerInterface $em, PrevisionManager $manager): Response {
        $form = $this->createForm(PrevisionRegleType::class, $regle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($regle);
            $em->flush(); // l'id de la règle est nécessaire aux échéances
            $stats = $manager->synchroniser($regle);
            $em->flush();
            $this->addFlash('success', sprintf('Règle enregistrée : %d échéance(s) créée(s), %d mise(s) à jour, %d retirée(s).', $stats['creees'], $stats['maj'], $stats['retirees']));

            return $this->redirectToRoute('app_prevision_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('prevision/form.html.twig', ['regle' => $regle, 'form' => $form]);
    }

    /** Recalcule les échéances de toutes les règles actives (à lancer à chaque début de mois, ou par la commande). */
    #[Route('/actualiser', name: 'app_prevision_refresh', methods: ['POST'])]
    public function refresh(Request $request, PrevisionRegleRepository $regleRepo, EntityManagerInterface $em, PrevisionManager $manager): Response {
        $this->checkToken($request);
        $n = 0;
        foreach ($regleRepo->findBy(['actif' => true]) as $regle) {
            $s = $manager->synchroniser($regle);
            $n += $s['creees'];
        }
        $em->flush();
        $this->addFlash('success', $n . ' échéance(s) ajoutée(s).');

        return $this->redirectToRoute('app_prevision_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/echeance/{id}/concretiser', name: 'app_prevision_concretiser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function concretiser(PrevisionEcheance $echeance, Request $request, EntityManagerInterface $em, PrevisionManager $manager): Response {
        $this->checkToken($request);
        try {
            $date = DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
            $montant = str_replace(',', '.', (string) $request->request->get('montant'));
            if ($date === false || !is_numeric($montant) || (float) $montant <= 0) {
                throw new \RuntimeException('Date ou montant invalide.');
            }
            $manager->concretiser($echeance, $date, $montant);
            $em->flush();
            $this->addFlash('success', 'Opération créée à partir de la prévision « ' . $echeance->getRegle()->getLibelle() . ' ».');
        } catch (Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_prevision_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Ajuste une échéance (date / montant) sans toucher aux autres ; elle n'est plus recalculée par la règle. */
    #[Route('/echeance/{id}/ajuster', name: 'app_prevision_ajuster', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajuster(PrevisionEcheance $echeance, Request $request, EntityManagerInterface $em): Response {
        $this->checkToken($request);
        $date = DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $montant = str_replace(',', '.', (string) $request->request->get('montant'));
        if ($echeance->isPrevue() && $date !== false && is_numeric($montant) && (float) $montant > 0) {
            $echeance->setDatePrevue($date)->setDateFin(null)->setMontant($montant)->setAjustee(true);
            $em->flush();
            $this->addFlash('success', 'Échéance ajustée.');
        } else {
            $this->addFlash('danger', 'Ajustement impossible (date ou montant invalide).');
        }

        return $this->redirectToRoute('app_prevision_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/echeance/{id}/ignorer', name: 'app_prevision_ignorer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ignorer(PrevisionEcheance $echeance, Request $request, EntityManagerInterface $em): Response {
        $this->checkToken($request);
        if ($echeance->isPrevue()) {
            $echeance->setStatut(StatutEcheance::IGNOREE);
            $em->flush();
            $this->addFlash('success', 'Échéance ignorée.');
        }

        return $this->redirectToRoute('app_prevision_index', [], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request): void {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
