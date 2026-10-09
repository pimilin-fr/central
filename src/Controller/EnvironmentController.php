<?php

namespace App\Controller;

use App\Demo\DbMode;
use App\Twig\DbModeExtension;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Bascule Central (prod) / Démo et reconstruction de la démo, depuis le menu en haut à droite.
 *
 * - La bascule ne fait qu'écrire var/db_mode (la prod n'est jamais modifiée).
 * - La reconstruction est une action À PART : elle lance « ./app build » en tâche de fond
 *   (sauvegarde de la prod d'abord, prod en lecture seule), uniquement depuis l'environnement Central.
 */
#[Route('/environnement')]
final class EnvironmentController extends AbstractController {

    public function __construct(
        private ManagerRegistry $registry,
        #[Autowire('%kernel.project_dir%')] private string $projectDir
    ) {
    }

    #[Route('/basculer/{mode}', name: 'app_env_switch', requirements: ['mode' => 'prod|demo'], methods: ['POST'])]
    public function switch(Request $request, string $mode): RedirectResponse {
        if (!$this->validToken($request)) {
            return $this->back($request, 'error', 'Action refusée (jeton invalide). Rechargez la page.');
        }
        if (DbMode::isBuilding($this->projectDir)) {
            return $this->back($request, 'warning', 'Une reconstruction de la démo est en cours : patientez avant de basculer.');
        }

        if ($mode === DbMode::DEMO) {
            $problem = $this->demoProblem();
            if ($problem !== null) {
                return $this->back($request, 'warning', $problem);
            }
        }

        try {
            DbMode::write($this->projectDir, $mode);
        } catch (Throwable $e) {
            return $this->back($request, 'error', $e->getMessage());
        }

        $this->addFlash('success', $mode === DbMode::DEMO
            ? 'Vous êtes maintenant sur la DÉMO (données fictives).'
            : 'Retour sur Central (production).');

        // Les identifiants d'une base n'existent pas forcément dans l'autre : on retourne à une page sûre.
        return $this->redirectToRoute('app_tiers_index');
    }

    #[Route('/reconstruire-demo', name: 'app_env_rebuild', methods: ['POST'])]
    public function rebuild(Request $request): RedirectResponse {
        if (!$this->validToken($request)) {
            return $this->back($request, 'error', 'Action refusée (jeton invalide). Rechargez la page.');
        }
        if (DbMode::current() === DbMode::DEMO) {
            return $this->back($request, 'warning', 'Revenez d\'abord sur Central : la démo est reconstruite depuis la production.');
        }
        if (DbMode::isBuilding($this->projectDir)) {
            return $this->back($request, 'info', 'Une reconstruction est déjà en cours.');
        }
        $script = $this->projectDir . '/app';
        if (!is_file($script) || !function_exists('exec')) {
            return $this->back($request, 'error', 'Impossible de lancer la reconstruction depuis le web : utilisez « ./app build » en ligne de commande.');
        }

        $php = PHP_BINDIR . '/php';
        $log = $this->projectDir . '/var/demo-build.log';
        $command = sprintf(
            'cd %s && PATH="$PATH:/usr/local/bin:/usr/bin:/bin:/usr/local/mysql/bin" PHP_BIN=%s nohup bash %s build >> %s 2>&1 < /dev/null &',
            escapeshellarg($this->projectDir),
            escapeshellarg(is_executable($php) ? $php : 'php'),
            escapeshellarg($script),
            escapeshellarg($log)
        );
        @mkdir(dirname($log), 0775, true);
        DbMode::writeStatus($this->projectDir, 'running'); // le menu passe tout de suite en « en cours »
        exec($command);

        return $this->back($request, 'info', 'Reconstruction de la démo lancée en tâche de fond (sauvegarde de la prod, puis copie). Le menu indique quand elle est terminée.');
    }

    /** État de la reconstruction (interrogé par le menu pendant qu'elle tourne). */
    #[Route('/etat', name: 'app_env_state', methods: ['GET'])]
    public function state(): JsonResponse {
        $last = DbMode::lastBuild($this->projectDir);
        $building = DbMode::isBuilding($this->projectDir);

        return $this->json([
            'building' => $building,
            'state' => $building ? 'running' : ($last['state'] ?? null),
            'date' => $last['date'] ?? null,
            'mode' => DbMode::current(),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** Raison pour laquelle on ne peut pas basculer sur la démo, ou null si tout est prêt. */
    private function demoProblem(): ?string {
        $prod = $_ENV['DATABASE_PROD_URL'] ?? $_SERVER['DATABASE_PROD_URL'] ?? null;
        $demo = $_ENV['DATABASE_DEMO_URL'] ?? $_SERVER['DATABASE_DEMO_URL'] ?? null;
        if (!DbMode::isUsableDemoUrl(is_string($prod) ? $prod : null, is_string($demo) ? $demo : null)) {
            return 'Configuration démo invalide : DATABASE_DEMO_URL doit viser une base distincte de la prod, dont le nom contient « demo ».';
        }

        try {
            $em = $this->registry->getManager('demo');
            $connection = $em->getConnection();
            $names = [];
            foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
                if (!$metadata->isMappedSuperclass && !$metadata->isEmbeddedClass) {
                    $names[] = $metadata->getTableName();
                }
            }
            // information_schema directement : le schema_filter de Doctrine (ex. ~^(?!v_)~) masquerait certaines tables/vues.
            $existing = array_map('strtolower', $connection->fetchFirstColumn(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
            ));
            $missing = array_values(array_filter($names, static fn (string $name): bool => !in_array(strtolower($name), $existing, true)));
            if ($names === [] || $missing !== []) {
                return 'La démo n\'est pas (complètement) construite' . ($missing !== [] ? ' — tables absentes : ' . implode(', ', array_slice($missing, 0, 5)) : '')
                    . ' ; lancez « Reconstruire la démo » (depuis Central).';
            }
        } catch (Throwable $e) {
            return 'La base démo est inaccessible (' . $e->getMessage() . ').';
        }

        $status = DbMode::lastBuild($this->projectDir);
        if ($status !== null && $status['state'] !== 'ok') {
            return 'La dernière reconstruction de la démo a échoué : relancez « Reconstruire la démo ».';
        }

        return null;
    }

    private function validToken(Request $request): bool {
        $expected = $request->getSession()->get(DbModeExtension::TOKEN_KEY);
        $given = (string) $request->request->get('_token', '');
        $site = $request->headers->get('Sec-Fetch-Site');

        return is_string($expected) && $expected !== '' && hash_equals($expected, $given)
            && ($site === null || in_array($site, ['same-origin', 'none'], true));
    }

    private function back(Request $request, string $type, string $message): RedirectResponse {
        $this->addFlash($type, $message);

        $referer = $this->refererPath($request);

        return $referer !== null ? $this->redirect($referer) : $this->redirectToRoute('app_tiers_index');
    }

    private function refererPath(Request $request): ?string {
        $referer = $request->headers->get('referer');
        if (!$referer) {
            return null;
        }
        $parts = parse_url($referer);
        if (($parts['host'] ?? null) !== $request->getHost()) {
            return null;
        }

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
