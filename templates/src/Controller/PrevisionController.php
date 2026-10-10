<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\PrevisionEcheance;
use App\Entity\PrevisionRegle;
use App\Entity\PrevisionTranche;
use App\Entity\Projet;
use App\Entity\Tiers;
use App\Entity\Portefeuille;
use App\Form\PrevisionRegleType;
use App\Prevision\StatutEcheance;
use App\Repository\PrevisionEcheanceRepository;
use App\Repository\PrevisionRegleRepository;
use App\Prevision\Certitude;
use App\Prevision\Frequence;
use App\Service\PrevisionDetector;
use App\Service\PrevisionManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        ['months' => $months, 'retard' => $retard] = $this->groupEcheances($echeanceRepo->findPrevues(), $today);

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
        $montant = null;
        if ($request->isMethod('GET')) {
            $montant = $this->prefill($regle, $request, $em);
        }
        $tranche = (new PrevisionTranche())->setAPartirDe($regle->getDebut());
        if ($montant !== null) {
            $tranche->setMontant($montant['montant'])->setMontantMin($montant['min'])->setMontantMax($montant['max']);
        }
        $regle->addTranche($tranche);

        return $this->handleForm($regle, $request, $em, $manager);
    }

    /** Pré-remplit une règle à partir des paramètres d'URL (suggestions détectées). @return array{montant: string, min: ?string, max: ?string}|null */
    private function prefill(PrevisionRegle $regle, Request $request, EntityManagerInterface $em): ?array {
        $q = $request->query;
        if ($q->get('tiers')) {
            $tiers = $em->getRepository(Tiers::class)->find((string) $q->get('tiers'));
            $regle->setTiers($tiers)->setLibelle($tiers?->getName() ?? '');
        }
        if ($this->intParam($q->get('categorie')) > 0) {
            $regle->setCategorie($em->getRepository(Categorie::class)->find($this->intParam($q->get('categorie'))));
        }
        if ($this->intParam($q->get('portefeuille')) > 0) {
            $regle->setPortefeuille($em->getRepository(Portefeuille::class)->find($this->intParam($q->get('portefeuille'))));
        }
        if ($this->intParam($q->get('projet')) > 0) {
            $regle->setProjet($em->getRepository(Projet::class)->find($this->intParam($q->get('projet'))));
        }
        if (($f = Frequence::tryFrom((string) $q->get('frequence'))) !== null) {
            $regle->setFrequence($f);
        }
        if (($c = Certitude::tryFrom((string) $q->get('certitude'))) !== null) {
            $regle->setCertitude($c);
        }
        if ($this->intParam($q->get('jour')) > 0) {
            $regle->setJour($this->intParam($q->get('jour')));
        }
        $regle->setEstime($q->getBoolean('estime'));
        if (($d = DateTime::createFromFormat('!Y-m-d', (string) $q->get('debut'))) !== false) {
            $regle->setDebut($d);
        }
        $montant = str_replace(',', '.', (string) $q->get('montant'));

        return is_numeric($montant) ? [
            'montant' => $montant,
            'min' => is_numeric($q->get('min')) ? (string) $q->get('min') : null,
            'max' => is_numeric($q->get('max')) ? (string) $q->get('max') : null,
        ] : null;
    }

    #[Route('/regle/{id}/edit', name: 'app_prevision_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(PrevisionRegle $regle, Request $request, EntityManagerInterface $em, PrevisionManager $manager): Response {
        return $this->handleForm($regle, $request, $em, $manager);
    }

    private function handleForm(PrevisionRegle $regle, Request $request, EntityManagerInterface $em, PrevisionManager $manager): Response {
        $form = $this->createForm(PrevisionRegleType::class, $regle);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->resolveRelations($form, $regle, $em);
        }

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

    /** Tiers / catégorie / projet viennent des champs à autocomplétion (identifiants cachés). */
    private function resolveRelations(\Symfony\Component\Form\FormInterface $form, PrevisionRegle $regle, EntityManagerInterface $em): void {
        $tiersId = (string) $form->get('tiers_id')->getData();
        $tiers = $tiersId !== '' ? $em->getRepository(Tiers::class)->find($tiersId) : null;
        if ($tiers === null) {
            $form->get('tiers')->addError(new FormError('Choisissez un tiers dans la liste.'));
        }
        $regle->setTiers($tiers);

        $categorieId = $this->intParam($form->get('categorie_id')->getData());
        $categorie = $categorieId > 0 ? $em->getRepository(Categorie::class)->find($categorieId) : null;
        if ($categorie === null) {
            $form->get('categorie')->addError(new FormError('Choisissez une catégorie dans la liste.'));
        }
        $regle->setCategorie($categorie);

        $projetId = $this->intParam($form->get('projet_id')->getData());
        $regle->setProjet($projetId > 0 ? $em->getRepository(Projet::class)->find($projetId) : null);
    }

    /** Saisie assistée : ce que l'historique sait déjà d'un tiers (catégorie, portefeuille, montant, rythme…). */
    #[Route('/assist/tiers/{id}', name: 'json_prevision_assist_tiers', methods: ['GET'])]
    public function assistTiers(Tiers $tiers, PrevisionDetector $detector): JsonResponse {
        return $this->json($detector->profilTiers($tiers) ?? ['vide' => true]);
    }

    /** Récurrences repérées dans l'historique, non encore couvertes par une règle. */
    #[Route('/suggestions', name: 'app_prevision_suggestions', methods: ['GET'])]
    public function suggestions(PrevisionDetector $detector): Response {
        $diag = $detector->diagnostic();

        return $this->render('prevision/suggestions.html.twig', [
                    'suggestions' => $diag['suggestions'],
                    'rejets' => $diag['rejets'],
                    'derniere' => $diag['derniere'],
                    'total' => $diag['total'],
                    'fenetre' => PrevisionDetector::FENETRE_MOIS,
        ]);
    }

    private function intParam(mixed $value): int {
        return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : 0;
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

        return $this->back($request);
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

        return $this->back($request);
    }

    #[Route('/echeance/{id}/ignorer', name: 'app_prevision_ignorer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ignorer(PrevisionEcheance $echeance, Request $request, EntityManagerInterface $em): Response {
        $this->checkToken($request);
        if ($echeance->isPrevue()) {
            $echeance->setStatut(StatutEcheance::IGNOREE);
            $em->flush();
            $this->addFlash('success', 'Échéance ignorée.');
        }

        return $this->back($request);
    }

    /**
     * Échéances prévues → « en retard » (mois précédents) et regroupement par mois avec totaux brut / pondéré.
     *
     * @param list<PrevisionEcheance> $echeances
     * @return array{months: array<string, array<string, mixed>>, retard: list<PrevisionEcheance>}
     */
    private function groupEcheances(array $echeances, DateTime $today): array {
        $debutMois = (clone $today)->modify('first day of this month');
        $months = [];
        $retard = [];
        foreach ($echeances as $echeance) {
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

        return ['months' => $months, 'retard' => $retard];
    }

    /** Onglet « Prévisions » de la fiche portefeuille (pas de route : appelé via render(controller(...))). */
    public function fragment(Portefeuille $portefeuille, PrevisionEcheanceRepository $echeanceRepo, \Symfony\Component\HttpFoundation\RequestStack $requests): Response {
        $today = new DateTime('today');
        $main = $requests->getMainRequest();

        return $this->render('prevision/_portefeuille.html.twig', $this->groupEcheances($echeanceRepo->findPrevues($portefeuille), $today) + [
                    'portefeuille' => $portefeuille,
                    'today' => $today,
                    'back' => ($main ? $main->getPathInfo() : '/portefeuille') . '?tab=prevision',
        ]);
    }

    /** Retour à la page d'où vient l'action (chemin interne uniquement), sinon la page Prévisions. */
    private function back(Request $request): Response {
        $back = (string) $request->request->get('_back');
        if ($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\')) {
            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_prevision_index', [], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request): void {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
