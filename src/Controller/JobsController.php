<?php

namespace App\Controller;

use App\Service\Job\JobStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Section « Sauvegardes » : état des tâches planifiées du Raspberry Pi (dernières exécutions, erreurs, évolution des tailles)
 * et relance manuelle. Le web ne lance rien lui-même : il pose une demande (var/jobs/requests) que « bin/jobs dispatch »,
 * planifié chaque minute sous l'utilisateur pi, exécute. Seules les tâches de config/jobs.json peuvent être relancées.
 */
#[Route('/sauvegardes')]
final class JobsController extends AbstractController {

    private const TOKEN = 'jobs_run';

    public function __construct(private JobStore $store) {
    }

    #[Route('', name: 'app_jobs_index', methods: ['GET'])]
    public function index(): Response {
        return $this->render('sauvegarde/index.html.twig', [
            'items' => $this->store->evaluateAll(),
            'disk' => $this->store->disk(),
            'summary' => $this->store->summary(),
        ]);
    }

    /** Pour le rafraîchissement automatique pendant qu'une tâche tourne. */
    #[Route('/etat', name: 'app_jobs_state', methods: ['GET'])]
    public function state(): JsonResponse {
        $out = [];
        foreach ($this->store->evaluateAll() as $e) {
            $out[$e['id']] = [$e['status'], (int) ($e['state']['endedAt'] ?? 0), $e['pending']];
        }

        return new JsonResponse(['jobs' => $out, 'summary' => $this->store->summary()]);
    }

    #[Route('/{id}', name: 'app_jobs_show', requirements: ['id' => '[a-z0-9_-]+'], methods: ['GET'])]
    public function show(string $id): Response {
        $item = $this->store->evaluate($id);
        if ($item === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('sauvegarde/show.html.twig', [
            'item' => $item,
            'history' => $this->store->history($id, 60),
            'log' => $this->store->logTail($id, 80),
        ]);
    }

    #[Route('/{id}/relancer', name: 'app_jobs_run', requirements: ['id' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function run(Request $request, string $id): RedirectResponse {
        $back = (string) $request->request->get('back', 'index');
        $target = $back === 'show' ? $this->redirectToRoute('app_jobs_show', ['id' => $id]) : $this->redirectToRoute('app_jobs_index');

        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée (jeton invalide). Rechargez la page.');

            return $target;
        }
        $item = $this->store->evaluate($id);
        if ($item === null) {
            throw $this->createNotFoundException();
        }
        if ($item['status'] === JobStore::RUNNING) {
            $this->addFlash('warning', 'Cette tâche est déjà en cours.');
        } elseif (!$this->store->requestRun($id)) {
            $this->addFlash('error', 'Impossible de poser la demande (droits sur var/jobs/requests ?).');
        } else {
            $this->addFlash('success', 'Relance demandée : elle démarre dans la minute qui suit (planificateur du Pi).');
        }

        return $target;
    }
}
