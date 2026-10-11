<?php

namespace App\Controller;

use App\Entity\Depenses;
use App\Repository\AdresseRepository;
use App\Repository\CategorieRepository;
use App\Repository\PortefeuilleRepository;
use App\Repository\ProjetRepository;
use App\Repository\TiersAdresseRepository;
use App\Repository\TiersRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tiroir « Nouvelle opération » : UN SEUL formulaire (fragment) et UN SEUL point d'envoi (JSON),
 * partagés par le relevé, la fiche portefeuille et la fiche tiers.
 * Le contexte (portefeuille ou tiers) est verrouillé par la page ; l'autre est à choisir dans le tiroir.
 */
#[Route('/operation')]
final class OperationController extends AbstractController {

    private const TOKEN = 'operation_new';

    /**
     * Fragment à inclure dans une page : render(controller('App\\Controller\\OperationController::drawer', { … })).
     * Paramètres : portefeuille (id, verrouillé) · tiers (uuid, verrouillé) · mode 'page'|'releve' · date (Y-m-d par défaut).
     */
    public function drawer(
            PortefeuilleRepository $ptfRepo,
            TiersRepository $tiersRepo,
            TiersAdresseRepository $tiersAdresseRepo,
            ?int $portefeuille = null,
            ?string $tiers = null,
            string $mode = 'page',
            ?string $date = null
    ): Response {
        $portefeuilles = $ptfRepo->findAllOrdered()->getQuery()->getResult();
        $locked = $portefeuille !== null ? $ptfRepo->find($portefeuille) : null;
        $tiersEntity = $tiers !== null && $tiers !== '' ? $tiersRepo->find($tiers) : null;

        $adresses = [];
        if ($tiersEntity !== null) {
            foreach ($tiersAdresseRepo->findByTiersOrdered($tiersEntity) as $link) {
                $adresses[] = ['id' => $link->getAdresse()->getId(), 'label' => (string) $link->getAdresse(), 'principale' => $link->isPrincipale()];
            }
        }

        return $this->render('operation/_drawer.html.twig', [
                    'portefeuilles' => $portefeuilles,
                    'portefeuille' => $locked,
                    'tiers' => $tiersEntity,
                    'adresses' => $adresses,
                    'mode' => $mode === 'releve' ? 'releve' : 'page',
                    'date' => $date ?: (new DateTime())->format('Y-m-d'),
        ]);
    }

    /**
     * Création. Corps : _token, context ('releve' | 'page'), op[date|montant|categorie_id|tiers_id|portefeuille_id|adresse_id|projet_id|numCommande|note].
     * Réponse JSON : { ok, id, html? } (html = ligne du relevé, uniquement si context = releve).
     */
    #[Route('/new', name: 'app_operation_new', methods: ['POST'])]
    public function new(
            Request $request,
            EntityManagerInterface $em,
            PortefeuilleRepository $ptfRepo,
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
        $portefeuilleId = (string) ($data['portefeuille_id'] ?? '');
        $portefeuille = ctype_digit($portefeuilleId) ? $ptfRepo->find((int) $portefeuilleId) : null;
        $categorieId = (string) ($data['categorie_id'] ?? '');
        $categorie = $categorieId !== '' ? $catRepo->find((int) $categorieId) : null;
        $tiersId = trim((string) ($data['tiers_id'] ?? '')); // identifiant TEXTE (uuid), pas un entier
        $tiers = $tiersId !== '' ? $tiersRepo->find($tiersId) : null;

        $errors = [];
        if ($date === null) {
            $errors[] = 'la date';
        }
        if ($montant === '' || !is_numeric($montant)) {
            $errors[] = 'le montant';
        }
        if ($portefeuille === null) {
            $errors[] = 'le portefeuille';
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

        $payload = ['ok' => true, 'id' => $operation->getId()];
        if ((string) $request->request->get('context') === 'releve') {
            $payload['html'] = $this->renderView('releve/_composer_row.html.twig', ['op' => $operation, 'inList' => false]);
        } else {
            $this->addFlash('success', 'Opération ajoutée avec succès');
        }

        return $this->json($payload);
    }

    private function parseDate(mixed $value): ?DateTime {
        $date = DateTime::createFromFormat('!Y-m-d', trim((string) $value));

        return $date !== false ? $date : null;
    }
}
