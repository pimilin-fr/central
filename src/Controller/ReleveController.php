<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\Releve;
use App\Repository\DepensesRepository;
use App\Repository\CategorieRepository;
use App\Repository\ProjetRepository;
use App\Repository\ReleveRepository;
use App\Repository\TiersRepository;
use App\Repository\AdresseRepository;
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
        if ($this->intParam($request->query->get('releve')) > 0) {
            $releve = $releveRepo->find($this->intParam($request->query->get('releve')));
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
                    'history' => $manager->history($releve),
                    'foreign' => count($manager->foreignOperations($releve)),
        ]);
    }

    /** Page « Relevés » d'un portefeuille : tous ses relevés, avec leur état et leurs soldes. */
    #[Route('/portefeuille/{id}', name: 'app_releve_index', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(Portefeuille $portefeuille, EntityManagerInterface $em, ReleveRepository $releveRepo): Response {
        return $this->render('releve/index.html.twig', $this->listData($portefeuille, $em, $releveRepo));
    }

    /**
     * Tous les relevés, tous portefeuilles confondus (récents d'abord).
     * Filtres GET : portefeuille = id · etat = clos | ouvert. Le solde cumulé reste celui de CHAQUE portefeuille
     * (calculé avant filtrage).
     */
    #[Route('/tous', name: 'app_releve_all', methods: ['GET'])]
    public function all(Request $request, EntityManagerInterface $em, ReleveRepository $releveRepo, \App\Repository\PortefeuilleRepository $ptfRepo): Response {
        $manager = new ReleveManager($em);
        $byPtf = [];
        foreach ($releveRepo->findEveryAsc() as $releve) {
            $byPtf[$releve->getPortefeuille()->getId()][] = $releve;
        }

        $rows = [];
        foreach ($byPtf as $releves) {
            foreach ($manager->summarize($releves) as $row) {
                $rows[] = $row;
            }
        }

        $ptfId = $this->intParam($request->query->get('portefeuille'));
        $etat = (string) $request->query->get('etat', '');
        $rows = array_values(array_filter($rows, static function (array $row) use ($ptfId, $etat): bool {
            if ($ptfId > 0 && $row['releve']->getPortefeuille()->getId() !== $ptfId) {
                return false;
            }

            return match ($etat) {
                'clos' => $row['releve']->isClosed(),
                'ouvert' => !$row['releve']->isClosed(),
                default => true,
            };
        }));
        usort($rows, static fn (array $a, array $b): int => [$b['releve']->getDate(), $b['releve']->getId()] <=> [$a['releve']->getDate(), $a['releve']->getId()]);

        return $this->render('releve/all.html.twig', [
                    'rows' => $rows,
                    'portefeuilles' => $ptfRepo->findAll(),
                    'ptfId' => $ptfId,
                    'etat' => $etat,
        ]);
    }

    /** Même liste, sans page autour (onglet « Relevés » de la fiche portefeuille). */
    public function fragment(Portefeuille $portefeuille, EntityManagerInterface $em, ReleveRepository $releveRepo): Response {
        return $this->render('releve/_list.html.twig', $this->listData($portefeuille, $em, $releveRepo));
    }

    /** @return array<string, mixed> */
    private function listData(Portefeuille $portefeuille, EntityManagerInterface $em, ReleveRepository $releveRepo): array {
        $rows = (new ReleveManager($em))->summarize($releveRepo->findAllAsc($portefeuille));

        return ['portefeuille' => $portefeuille, 'rows' => array_reverse($rows)]; // récent d'abord
    }

    /**
     * Mini formulaire d'ajout d'une opération spécifique depuis l'écran du relevé (ex. frais bancaires).
     * Le portefeuille est celui de l'écran. Répond en JSON avec la ligne HTML à insérer dans « À pointer ».
     */
    #[Route('/composer/{id}/operation', name: 'app_releve_add_operation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addOperation(
            Portefeuille $portefeuille,
            Request $request,
            EntityManagerInterface $em,
            CategorieRepository $catRepo,
            TiersRepository $tiersRepo,
            ProjetRepository $projRepo,
            AdresseRepository $adrRepo
    ): Response {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Jeton de sécurité invalide, rechargez la page.'], 400);
        }

        $data = (array) $request->request->all('op');
        $date = $this->parseDate($data['date'] ?? null);
        $montant = str_replace([' ', ','], ['', '.'], trim((string) ($data['montant'] ?? '')));
        $categorie = ($data['categorie_id'] ?? '') !== '' ? $catRepo->find((int) $data['categorie_id']) : null;
        $tiersId = trim((string) ($data['tiers_id'] ?? '')); // identifiant TEXTE (uuid), pas un entier
        $tiers = $tiersId !== '' ? $tiersRepo->find($tiersId) : null;

        $errors = [];
        if ($date === null) {
            $errors[] = 'la date';
        }
        if ($montant === '' || !is_numeric($montant)) {
            $errors[] = 'le montant';
        }
        if ($categorie === null) {
            $errors[] = 'la catégorie (à choisir dans la liste)';
        }
        if ($tiers === null) {
            $errors[] = 'le tiers (à choisir dans la liste)';
        }
        if ($errors !== []) {
            return $this->json(['ok' => false, 'error' => 'Vérifiez ' . implode(', ', $errors) . '.'], 422);
        }

        $operation = (new Depenses())
                ->setDate($date)
                ->setMontant(number_format((float) $montant, 2, '.', ''))
                ->setCategorie($categorie)
                ->setTiers($tiers)
                ->setPortefeuille($portefeuille);

        $numCommande = trim((string) ($data['numCommande'] ?? ''));
        if ($numCommande !== '') {
            $operation->setNumCommande(mb_substr($numCommande, 0, 255));
        }
        $note = trim((string) ($data['note'] ?? ''));
        if ($note !== '') {
            $operation->setNote($note);
        }
        if (($data['projet_id'] ?? '') !== '' && ($projet = $projRepo->find((int) $data['projet_id'])) !== null) {
            $operation->setProjet($projet);
        }
        $adresseId = ($data['adresse_id'] ?? '') !== '' ? $data['adresse_id'] : ($data['adresse'] ?? '');
        if (is_numeric($adresseId) && ($adresse = $adrRepo->find((int) $adresseId)) !== null) {
            $operation->setAdresse($adresse);
        }

        $em->persist($operation);
        $em->flush();

        return $this->json([
                    'ok' => true,
                    'id' => $operation->getId(),
                    'html' => $this->renderView('releve/_composer_row.html.twig', ['op' => $operation, 'inList' => false]),
        ]);
    }

    /** Enregistre (reste « en cours ») ou finalise. Corps : _token, releve?, date, lines[i][] (opérations de la ligne i, dans l'ordre ; plusieurs = un détail), action. */
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
            $releveId = $this->intParam($request->request->get('releve'));
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

            // pas de libellé libre : « Relevé du jj/mm/aaaa », toujours cohérent avec la date
            $releve->setLabel('Relevé du ' . $date->format('d/m/Y'));

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

                return $this->redirectToRoute('app_releve_compose', ['id' => $portefeuille->getId(), 'releve' => $releve->getId()]);
            }

            $this->addFlash('success', sprintf('Relevé du %s enregistré (%d ligne(s)) — en cours, à reprendre quand vous voulez.', $releve->getDate()->format('d/m/Y'), count($ordered)));

            return $this->redirectToRoute('app_releve_compose', ['id' => $portefeuille->getId(), 'releve' => $releve->getId()]);
        } catch (Throwable $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_releve_compose', array_filter([
                        'id' => $portefeuille->getId(),
                        'releve' => $this->intParam($request->request->get('releve')) ?: null,
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

    /** Entier tolérant : '' / null / non numérique => 0 (getInt() lève une exception sur une chaîne vide). */
    private function intParam(mixed $value): int {
        return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : 0;
    }

    private function parseDate(mixed $value): ?DateTime {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }
}
