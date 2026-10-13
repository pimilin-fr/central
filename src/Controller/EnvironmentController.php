<?php

namespace App\Controller;

use App\Demo\DbMode;
use App\Demo\DemoProgress;
use App\Twig\DbModeExtension;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
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
        DemoProgress::reconcile($this->projectDir);
        if (DbMode::isBuilding($this->projectDir)) {
            return $this->back($request, 'warning', 'Une reconstruction de la démo est en cours : patientez avant de basculer (suivi : menu Environnement → Suivi de la démo).');
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
        DemoProgress::reconcile($this->projectDir);
        if (DbMode::isBuilding($this->projectDir)) {
            return $this->back($request, 'info', 'Une reconstruction est déjà en cours (voir « Suivi de la démo » dans le menu Environnement ; « Débloquer » si elle est bloquée).');
        }
        $script = $this->projectDir . '/app';
        if (!is_file($script) || !function_exists('exec')) {
            return $this->back($request, 'error', 'Impossible de lancer la reconstruction depuis le web : utilisez « ./app build » en ligne de commande.');
        }

        // Script enregistré avec des fins de ligne Windows (CRLF) : bash échoue (« set: pipefail\r : invalid option name »).
        $healed = false;
        if ($this->hasCrlf($script)) {
            $content = (string) file_get_contents($script);
            if (!is_writable($script) || @file_put_contents($script, str_replace("\r\n", "\n", $content)) === false) {
                return $this->back($request, 'error', 'Le script ./app a des fins de ligne Windows (CRLF) et ne peut pas être corrigé automatiquement. Corrigez-le : sed -i \'s/\\r$//\' app');
            }
            $healed = true;
        }

        $php = PHP_BINDIR . '/php';
        $log = DemoProgress::logFile($this->projectDir);
        @mkdir(dirname($log), 0775, true);
        if ($healed) {
            @file_put_contents($log, "[corrigé] fins de ligne Windows (CRLF) retirées du script ./app\n", FILE_APPEND);
        }
        @file_put_contents($log, sprintf("\n=== %s — reconstruction demandée depuis le web (utilisateur système : %s) ===\n", date('Y-m-d H:i:s'), get_current_user()), FILE_APPEND);

        // « & echo $! » : on récupère le pid du lanceur pour savoir plus tard s'il vit encore
        $command = sprintf(
            'cd %s && PATH="$PATH:/usr/local/bin:/usr/bin:/bin:/usr/local/mysql/bin" PHP_BIN=%s nohup bash %s build >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($this->projectDir),
            escapeshellarg(is_executable($php) ? $php : 'php'),
            escapeshellarg($script),
            escapeshellarg($log)
        );
        DbMode::writeStatus($this->projectDir, 'running'); // le menu passe tout de suite en « en cours »
        $out = [];
        exec($command, $out);
        $pid = isset($out[0]) && ctype_digit(trim($out[0])) ? (int) trim($out[0]) : null;
        DemoProgress::starting($this->projectDir, $pid);
        @file_put_contents($log, sprintf("[%s] lanceur démarré (pid %s)\n", date('H:i:s'), $pid ?? '?'), FILE_APPEND);

        $this->addFlash('info', 'Reconstruction de la démo lancée en tâche de fond. Suivez son avancement ici.');

        return $this->redirectToRoute('app_env_demo');
    }

    /** Page de suivi détaillé : avancement, étapes, erreur, diagnostic, journal. */
    #[Route('/demo', name: 'app_env_demo', methods: ['GET'])]
    public function demo(): Response {
        $progress = DemoProgress::reconcile($this->projectDir);

        return $this->render('environment/demo.html.twig', [
            'building' => DbMode::isBuilding($this->projectDir),
            'mode' => DbMode::current(),
            'checks' => $this->diagnostics(),
            'progress' => $progress,
        ]);
    }

    /** Fin du journal (ou suite depuis `offset`), interrogé par la page de suivi. */
    #[Route('/journal', name: 'app_env_log', methods: ['GET'])]
    public function log(Request $request): JsonResponse {
        $offset = $request->query->has('offset') ? (int) $request->query->get('offset') : null;

        return $this->json(DemoProgress::tail($this->projectDir, $offset), 200, ['Cache-Control' => 'no-store']);
    }

    /** Vide le journal (et, hors reconstruction, le suivi détaillé) pour repartir d'une page propre avant un test. */
    #[Route('/journal/vider', name: 'app_env_log_clear', methods: ['POST'])]
    public function clearLog(Request $request): RedirectResponse {
        if (!$this->validToken($request)) {
            return $this->redirectToRoute('app_env_demo');
        }
        $log = DemoProgress::logFile($this->projectDir);
        if (is_file($log) && @file_put_contents($log, '') === false) {
            $this->addFlash('error', 'Impossible de vider le journal (droits sur var/demo-build.log ?).');

            return $this->redirectToRoute('app_env_demo');
        }
        if (!DbMode::isBuilding($this->projectDir)) {
            @unlink(DemoProgress::file($this->projectDir));
        }
        $this->addFlash('success', 'Journal vidé' . (DbMode::isBuilding($this->projectDir) ? ' (la reconstruction en cours continue d\'y écrire).' : ' et suivi réinitialisé.'));

        return $this->redirectToRoute('app_env_demo');
    }

    /** Débloque une reconstruction « fantôme » (statut « en cours » alors qu'aucun processus ne tourne). */
    #[Route('/debloquer', name: 'app_env_unlock', methods: ['POST'])]
    public function unlock(Request $request): RedirectResponse {
        if (!$this->validToken($request)) {
            return $this->back($request, 'error', 'Action refusée (jeton invalide). Rechargez la page.');
        }
        if (!DemoProgress::unlock($this->projectDir)) {
            return $this->back($request, 'warning', 'Un processus de reconstruction est encore actif : rien n\'a été débloqué.');
        }

        return $this->back($request, 'success', 'Reconstruction débloquée (marquée en échec). Vous pouvez la relancer.');
    }

    /** État de la reconstruction (interrogé par le menu pendant qu'elle tourne). */
    #[Route('/etat', name: 'app_env_state', methods: ['GET'])]
    public function state(): JsonResponse {
        $progress = DemoProgress::reconcile($this->projectDir);
        $last = DbMode::lastBuild($this->projectDir);
        $building = DbMode::isBuilding($this->projectDir);
        $state = $building ? 'running' : ($last['state'] ?? ($progress['state'] ?? null));
        if (!$building && $progress !== null && in_array($progress['state'] ?? '', ['ok', 'failed'], true)) {
            $state = $progress['state']; // le suivi détaillé fait foi
        }

        return $this->json([
            'building' => $building,
            'state' => $state,
            'date' => $last['date'] ?? null,
            'mode' => DbMode::current(),
            'now' => time(),
            'progress' => $progress,
            'processAlive' => $building ? DemoProgress::anyBuildProcess() : null,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** @return list<array{label:string, ok:?bool, detail:string}> ok = null : information / avertissement */
    private function diagnostics(): array {
        $checks = [];
        $add = static function (string $label, ?bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $execOk = function_exists('exec') && !in_array('exec', $disabled, true);
        $add('Lancement de commandes depuis le web (exec)', $execOk, $execOk ? 'disponible' : 'désactivé dans php.ini (disable_functions) : lancez « ./app build » en ligne de commande');

        $script = $this->projectDir . '/app';
        $add('Script ./app', is_file($script), is_file($script) ? $script . (is_readable($script) ? '' : ' (illisible par ce compte !)') : 'introuvable : ' . $script);
        if (is_file($script)) {
            $crlf = $this->hasCrlf($script);
            $add('Fins de ligne du script ./app', !$crlf, $crlf ? 'Windows (CRLF) : bash échoue. Corrigé automatiquement au prochain lancement, ou : sed -i \'s/\\r$//\' app' : 'Unix (LF)');
        }

        $var = $this->projectDir . '/var';
        $add('Dossier var/ inscriptible', is_writable($var), $var . ' — compte web : ' . get_current_user());

        $php = PHP_BINDIR . '/php';
        $add('PHP en ligne de commande', is_executable($php), is_executable($php) ? $php : $php . ' introuvable (le lanceur utilisera « php » du PATH)');

        if ($execOk) {
            foreach (['bash' => true, 'mysqldump' => null, 'pgrep' => null] as $bin => $required) {
                $out = [];
                $code = 1;
                @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
                $add('Outil « ' . $bin . ' »', $code === 0 ? true : ($required ? false : null), $code === 0 ? ($out[0] ?? 'présent') : ($bin === 'mysqldump' ? 'absent du PATH web : la sauvegarde de la prod peut échouer' : 'absent'));
            }
            $any = DemoProgress::anyBuildProcess();
            $add('Processus de reconstruction actif', null, $any === null ? 'indéterminé' : ($any ? 'oui' : 'aucun'));
        }

        $prod = $_ENV['DATABASE_PROD_URL'] ?? $_SERVER['DATABASE_PROD_URL'] ?? null;
        $demo = $_ENV['DATABASE_DEMO_URL'] ?? $_SERVER['DATABASE_DEMO_URL'] ?? null;
        $usable = DbMode::isUsableDemoUrl(is_string($prod) ? $prod : null, is_string($demo) ? $demo : null);
        $add('Configuration DATABASE_DEMO_URL', $usable, $usable ? 'base démo : ' . DbMode::databaseName((string) $demo) : 'doit viser une base distincte de la prod dont le nom contient « demo »');

        if ($usable) {
            try {
                $this->registry->getManager('demo')->getConnection()->executeQuery('SELECT 1')->fetchOne();
                $add('Connexion à la base démo', true, 'ok');
            } catch (Throwable $e) {
                $add('Connexion à la base démo', false, $e->getMessage());
            }
        }

        $lock = DbMode::lockDir($this->projectDir);
        $add('Verrou var/demo.lock', is_dir($lock) ? null : true, is_dir($lock) ? 'présent depuis ' . max(0, (int) ((time() - (int) @filemtime($lock)) / 60)) . ' min' : 'absent');
        $status = DbMode::lastBuild($this->projectDir);
        $add('Dernier statut (var/demo-build.status)', $status === null ? null : $status['state'] !== 'failed', $status ? $status['state'] . ' · ' . $status['date'] : 'aucun');

        return $checks;
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

    private function hasCrlf(string $file): bool {
        return is_file($file) && str_contains((string) file_get_contents($file, false, null, 0, 8192), "\r\n");
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
