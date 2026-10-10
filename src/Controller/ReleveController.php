<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\Releve;
use App\Repository\DepensesRepository;
use App\Repository\ReleveRepository;
use App\Service\ReleveManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * « Faire le relevé » : on choisit les opérations DANS L'ORDRE du relevé de compte.
 * Peut se faire en plusieurs fois (relevé non finalisé) ; la finalisation fige l'ordre.
 */
#[Route('/releve')]
final class ReleveController extends AbstractController {

    private const TOKEN = 'releve_compose';

    /**
     * Écran de composition. Paramètres (tous facultatifs) :
     *   releve = identifiant d'un relevé à reprendre · date = Y-m-d (relevé de cette date, créé si besoin)
     *   ids[]  = opérations à pré-placer (ex. cases cochées dans la liste)
     */
    #[Route('/composer/{id}', name: 'app_releve_compose', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function compose(
            Portefeuille $portefeuille,
            Request $request,
            EntityManagerInterface $em,
            DepensesRepository $depRepo,
            ReleveRepository $releveRepo
    ): Response {
        $manager = new ReleveManager($em);

        $releve = null;
        if ($request->query->getInt('releve') > 0) {
            $releve = $releveRepo->find($request->query->getInt('releve'));
            if ($releve === null || $releve->getPortefeuille()->getId() !== $portefeuille->getId()) {
                throw $this->createNotFoundException('Relevé introuvable pour ce portefeuille');
            }
        } else {
            $date = $this->parseDate($request->query->get('date')) ?? new DateTime('today');
            $releve = $manager->findOrCreate($portefeuille, $date);
        }

        $lines = $manager->orderedLines($releve);

        $pool = [];
        foreach ($depRepo->findUnreleved($portefeuille) as $operation) {
            $pool[] = $operation;
        }

        // opérations pré-placées (ex. cases cochées) : une ligne chacune, à la suite, dans l'ordre chronologique
        if (!$releve->isClosed()) {
            $wanted = array_map('intval', (array) $request->query->all('ids'));
            if ($wanted !== []) {
                foreach ($pool as $i => $operation) {
                    if (in_array($operation->getId(), $wanted, true)) {
                        $lines[] = [$operation];
                        unset($pool[$i]);
                    }
                }
                $pool = array_values($pool);
            }
        }

        return $this->render('releve/composer.html.twig', [
                    'portefeuille' => $portefeuille,
                    'releve' => $releve,
                    'lines' => $lines,
                    'pool' => $pool,
                    'drafts' => $releveRepo->findOpen($portefeuille),
                    'token' => self::TOKEN,
        ]);
    }

    /** Enregistre (reste « en cours ») ou finalise. Corps : _token, releve?, date, label?, lines[i][] (opérations de la ligne i, dans l'ordre ; plusieurs = un détail), action. */
    #[Route('/composer/{id}', name: 'app_releve_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(
            Portefeuille $portefeuille,
            Request $request,
            EntityManagerInterface $em,
            DepensesRepository $depRepo,
            ReleveRepository $releveRepo
    ): Response {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez recommencer.');

            return $this->redirectToRoute('app_releve_compose', ['id' => $portefeuille->getId()]);
        }

        $manager = new ReleveManager($em);
        $date = $this->parseDate($request->request->get('date'));
        if ($date === null) {
            $this->addFlash('danger', 'Date de relevé invalide.');

            return $this->redirectToRoute('app_releve_compose', ['id' => $portefeuille->getId()]);
        }

        try {
            $releveId = $request->request->getInt('releve');
            if ($releveId > 0) {
                $releve = $releveRepo->find($releveId);
                if ($releve === null || $releve->getPortefeuille()->getId() !== $portefeuille->getId()) {
                    throw new \RuntimeException('Relevé introuvable pour ce portefeuille');
                }
                if ($releve->isClosed()) {
                    throw new \RuntimeException('Ce relevé est finalisé : rouvrez-le pour le modifier.');
                }
                if ($releve->getDate()->format('Y-m-d') !== $date->format('Y-m-d')) {
                    $clash = $releveRepo->findOneBy(['portefeuille' => $portefeuille, 'date' => $date]);
                    if ($clash !== null) {
                        throw new \RuntimeException('Il existe déjà un relevé à cette date pour ce portefeuille.');
                    }
                    $releve->setDate($date);
                }
            } else {
                $releve = $manager->findOrCreate($portefeuille, $date);
                if ($releve->isClosed()) {
                    throw new \RuntimeException('Le relevé de cette date est finalisé : rouvrez-le pour le modifier.');
                }
            }

            $label = trim((string) $request->request->get('label', ''));
            if ($label !== '') {
                $releve->setLabel(mb_substr($label, 0, 255));
            }

            // lignes dans l'ordre reçu : lines[0][]=12&lines[0][]=14&lines[1][]=7 …
            $received = (array) $request->request->all('lines');
            ksort($received, SORT_NUMERIC);
            $wantedIds = [];
            foreach ($received as $line) {
                foreach ((array) $line as $id) {
                    $wantedIds[] = (int) $id;
                }
            }
            $found = [];
            if ($wantedIds !== []) {
                foreach ($depRepo->findBy(['id' => array_values(array_unique($wantedIds))]) as $operation) {
                    $found[$operation->getId()] = $operation;
                }
            }
            $ordered = [];
            foreach ($received as $line) {
                $group = [];
                foreach ((array) $line as $id) {
                    if (isset($found[(int) $id])) {
                        $group[] = $found[(int) $id];
                    }
                }
                if ($group !== []) {
                    $ordered[] = $group;
                }
            }

            $finalize = $request->request->get('action') === 'finalize';
            if ($ordered === [] && $releve->getId() === null) {
                throw new \RuntimeException('Aucune opération choisie.');
            }

            $manager->compose($releve, $ordered);
            if ($finalize) {
                $manager->finalize($releve);
                $this->addFlash('success', sprintf('Relevé du %s finalisé (%d ligne(s)) : son ordre est figé.', $releve->getDate()->format('d/m/Y'), count($ordered)));

                return $this->redirectToRoute('app_portefeuille_show', ['id' => $portefeuille->getId(), 'groupBy' => 'releve']);
            }

            $this->addFlash('success', sprintf('Relevé du %s enregistré (%d ligne(s)) — en cours, à reprendre quand vous voulez.', $releve->getDate()->format('d/m/Y'), count($ordered)));

            return $this->redirectToRoute('app_releve_compose', ['id' => $portefeuille->getId(), 'releve' => $releve->getId()]);
        } catch (Throwable $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_releve_compose', array_filter([
                        'id' => $portefeuille->getId(),
                        'releve' => $request->request->getInt('releve') ?: null,
                        'date' => $date->format('Y-m-d'),
            ]));
        }
    }

    /** Rouvre un relevé finalisé (correction d'un ordre). */
    #[Route('/{id}/rouvrir', name: 'app_releve_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reopen(Releve $releve, Request $request, EntityManagerInterface $em): Response {
        if ($this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            (new ReleveManager($em))->reopen($releve);
            $this->addFlash('success', 'Relevé rouvert : vous pouvez corriger l\'ordre, puis le finaliser à nouveau.');
        }

        return $this->redirectToRoute('app_releve_compose', [
                    'id' => $releve->getPortefeuille()->getId(),
                    'releve' => $releve->getId(),
        ]);
    }

    private function parseDate(mixed $value): ?DateTime {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }
}
