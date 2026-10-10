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
    public function index(PrevisionRegleRepository $regleRepo, PrevisionEcheanceRepository $echeanceRepo, PrevisionManager $manager, EntityManagerInterface $em): Response {
        $today = new DateTime('today');
        $periodes = $this->groupParPeriode($echeanceRepo->findPrevues(), $today, $this->ancrePeriodes($em, $today));

        $regles = $regleRepo->findAllWithTranches();
        $estimations = [];
        foreach ($regles as $regle) {
            if ($regle->isEstime() && $regle->isActif()) {
                $estimations[$regle->getId()] = $manager->estimation($regle);
            }
        }

        return $this->render('prevision/index.html.twig', [
                    'periodes' => $periodes,
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
        if (trim((string) $q->get('libelle')) !== '') {
            $regle->setLibelle(mb_substr(trim((string) $q->get('libelle')), 0, 255));
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

    /**
     * Depuis l'écran du relevé : crée l'opération à partir de l'échéance et renvoie la ligne HTML à placer
     * dans « À pointer » / dans le relevé (même réponse que le mini formulaire « Nouvelle opération »).
     */
    #[Route('/echeance/{id}/concretiser-releve', name: 'app_prevision_concretiser_releve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function concretiserReleve(PrevisionEcheance $echeance, Request $request, EntityManagerInterface $em, PrevisionManager $manager): JsonResponse {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Jeton de sécurité invalide, rechargez la page.'], 400);
        }
        $date = DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $montant = str_replace([' ', ','], ['', '.'], (string) $request->request->get('montant'));
        if ($date === false || !is_numeric($montant) || (float) $montant <= 0) {
            return $this->json(['ok' => false, 'error' => 'Date ou montant invalide.'], 422);
        }
        try {
            $depense = $manager->concretiser($echeance, $date, $montant);
            $em->flush();
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        return $this->json([
                    'ok' => true,
                    'id' => $depense->getId(),
                    'html' => $this->renderView('releve/_composer_row.html.twig', ['op' => $depense, 'inList' => false]),
        ]);
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
     * Repères des périodes « de relevé à relevé » (nos mois).
     *  - début = date du dernier relevé (≤ aujourd'hui), tous portefeuilles confondus ;
     *  - jour habituel du relevé = le plus fréquent parmi les 12 derniers (ex. le 5) ;
     *  - chaque fin = ce jour du mois suivant, décalée au lundi si elle tombe un samedi ou un dimanche
     *    (banque fermée ; d'après l'historique, un lundi reste le lundi).
     * Sans relevé : mois civils. Les repères avancent mois par mois tant que la période ne contient pas aujourd'hui.
     *
     * @return array{start: \DateTimeImmutable, mois: \DateTimeImmutable, jour: int}
     */
    private function ancrePeriodes(EntityManagerInterface $em, DateTime $today): array {
        $rows = $em->createQuery('SELECT DISTINCT r.date FROM App\\Entity\\Releve r WHERE r.date <= :t ORDER BY r.date DESC')
                ->setParameter('t', $today)->setMaxResults(12)->getScalarResult();
        $dates = array_map(static fn (array $r): \DateTimeImmutable => new \DateTimeImmutable((string) $r['date']), $rows);
        $todayI = \DateTimeImmutable::createFromMutable($today)->setTime(0, 0);

        if ($dates === []) {
            $ctx = ['start' => $todayI->modify('last day of last month'), 'mois' => $todayI->modify('first day of last month'), 'jour' => 1];
        } else {
            $jours = array_count_values(array_map(static fn (\DateTimeImmutable $d): int => (int) $d->format('j'), $dates));
            arsort($jours);
            $ctx = ['start' => $dates[0]->setTime(0, 0), 'mois' => $dates[0]->modify('first day of this month')->setTime(0, 0), 'jour' => (int) array_key_first($jours)];
        }
        while ($this->borne($ctx, 1) < $todayI) {
            $ctx = ['start' => $this->borne($ctx, 1), 'mois' => $ctx['mois']->modify('+1 month'), 'jour' => $ctx['jour']];
        }

        return $ctx;
    }

    /** Fin de la k-ième période (k = 0 : début de la période en cours, c'est-à-dire le dernier relevé). */
    private function borne(array $ctx, int $k): \DateTimeImmutable {
        if ($k === 0) {
            return $ctx['start'];
        }
        $mois = $ctx['mois']->modify('+' . $k . ' month');
        $date = $mois->setDate((int) $mois->format('Y'), (int) $mois->format('n'), min($ctx['jour'], (int) $mois->format('t')));
        $weekday = (int) $date->format('N'); // 6 samedi, 7 dimanche

        return $weekday >= 6 ? $date->modify('+' . (8 - $weekday) . ' day') : $date;
    }

    /**
     * Échéances prévues regroupées par période « de relevé à relevé ». La première période reprend aussi tout ce qui est
     * plus ancien et pas encore concrétisé (le retard n'est pas mis en avant).
     *
     * @param list<PrevisionEcheance> $echeances
     * @param array{start: \DateTimeImmutable, mois: \DateTimeImmutable, jour: int} $ctx
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, echeances: list<PrevisionEcheance>, brut: float, pondere: float}>
     */
    private function groupParPeriode(array $echeances, DateTime $today, array $ctx): array {
        $periodes = [];
        $nouvelle = fn (int $k): array => ['debut' => $this->borne($ctx, $k)->modify('+1 day'), 'fin' => $this->borne($ctx, $k + 1), 'echeances' => [], 'brut' => 0.0, 'pondere' => 0.0];
        foreach ($echeances as $echeance) {
            $date = \DateTimeImmutable::createFromInterface($echeance->getDatePrevue());
            $k = 0;
            while ($date > $this->borne($ctx, $k + 1)) {
                $k++;
            }
            $periodes[$k] ??= $nouvelle($k);
            $periodes[$k]['echeances'][] = $echeance;
            $periodes[$k]['brut'] += $echeance->getMontantSigne();
            $periodes[$k]['pondere'] += $echeance->getMontantSigne() * $echeance->getCertitude()->poids();
        }
        $periodes[0] ??= $nouvelle(0);
        ksort($periodes);

        return array_values($periodes);
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

    /**
     * Panneau latéral « Prochaines échéances » de la fiche portefeuille (pas de route : render(controller(...))).
     * Échéances en retard + celles des 45 prochains jours ; rien d'affiché s'il n'y en a pas.
     * Le nombre et le montant net restent visibles panneau replié.
     */
    public function prochaines(Portefeuille $portefeuille, PrevisionEcheanceRepository $echeanceRepo, \Symfony\Component\HttpFoundation\RequestStack $requests, string $mode = 'page'): Response {
        $today = new DateTime('today');
        $limite = (clone $today)->modify('+45 days');
        $debutMois = (clone $today)->modify('first day of this month');
        $retard = [];
        $proches = [];
        $total = 0;
        $brut = 0.0;
        $pondere = 0.0;
        foreach ($echeanceRepo->findPrevues($portefeuille) as $echeance) {
            $total++;
            $date = $echeance->getDatePrevue();
            if ($date < $today && $date < $debutMois) {
                $retard[] = $echeance;
            } elseif ($date <= $limite) {
                $proches[] = $echeance;
            } else {
                continue;
            }
            $brut += $echeance->getMontantSigne();
            $pondere += $echeance->getMontantSigne() * $echeance->getCertitude()->poids();
        }
        if ($retard === [] && $proches === []) {
            return new Response('');
        }
        $main = $requests->getMainRequest();

        return $this->render('prevision/_prochaines.html.twig', [
                    'portefeuille' => $portefeuille,
                    'retard' => $retard,
                    'proches' => $proches,
                    'total' => $total,
                    'brut' => $brut,
                    'pondere' => $pondere,
                    'today' => $today,
                    'back' => $main ? $main->getRequestUri() : '/portefeuille',
                    'mode' => $mode === 'releve' ? 'releve' : 'page',
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
