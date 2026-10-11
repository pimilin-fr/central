<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Entity\Categorie;
use App\Entity\Portefeuille;
use App\Entity\Projet;
use App\Entity\Releve;
use App\Entity\Tiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TEMPORAIRE — vérification du chantier « kit UI ».
 * FIN DE CHANTIER : supprimer ce fichier + le dossier templates/verification/ + le dossier public/js/verification/.
 */
#[Route('/styleguide/verification')]
class VerificationController extends AbstractController
{
    /**
     * TEMPORAIRE — page de vérification du chantier « kit UI » (à supprimer en fin de chantier :
     * cette route, templates/styleguide/verification.html.twig et public/js/verification.js).
     * Les liens sont de vrais liens : ils pointent sur des enregistrements existants (lecture seule).
     */
    #[Route('', name: 'app_styleguide_verification', methods: ['GET'])]
    public function verification(EntityManagerInterface $em): Response
    {
        $first = static fn (string $class) => $em->getRepository($class)->findOneBy([], ['id' => 'ASC']);

        $ptf = $first(Portefeuille::class);
        $releve = $ptf ? $em->getRepository(Releve::class)->findOneBy(['portefeuille' => $ptf], ['date' => 'DESC']) : null;

        return $this->render('verification/index.html.twig', [
            'ptf' => $ptf,
            'releve' => $releve,
            'tiers' => $first(Tiers::class),
            'projet' => $first(Projet::class),
            'categorie' => $first(Categorie::class),
            'adresse' => $first(Adresse::class),
        ]);
    }

    /**
     * TEMPORAIRE — cherche sur le DISQUE (pas dans le HTML rendu) les classes données dans les gabarits
     * et scripts : distingue « fichier pas remplacé » de « cache Twig périmé ».
     * ?classes=px-4,py-2,…  →  { fichiers: [{fichier, ligne, classe}], parasites: [dossiers] }
     */
    #[Route('/sources', name: 'app_styleguide_verification_sources', methods: ['GET'])]
    public function verificationSources(Request $request, #[Autowire('%kernel.project_dir%')] string $projectDir): JsonResponse
    {
        $classes = array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('classes', '')))));
        $hits = [];
        foreach (['templates', 'public/js'] as $dir) {
            if (!is_dir($projectDir.'/'.$dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectDir.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($projectDir) + 1));
                if (!preg_match('/\.(twig|js)$/', $rel) || str_starts_with($rel, 'templates/public/') || str_starts_with($rel, 'templates/src/') || str_starts_with($rel, 'templates/templates/') || str_contains($rel, 'verification')) {
                    continue;
                }
                foreach (preg_split('/\R/', (string) file_get_contents($file->getPathname())) as $n => $line) {
                    if (!preg_match_all('/class\s*=\s*(["\'])(.*?)\1/', $line, $m)) {
                        continue;
                    }
                    foreach ($m[2] as $attr) {
                        foreach (preg_split('/\s+/', $attr) as $tok) {
                            if (in_array($tok, $classes, true)) {
                                $hits[] = ['fichier' => $rel, 'ligne' => $n + 1, 'classe' => $tok];
                            }
                        }
                    }
                }
            }
        }

        // dossiers parasites (archives dézippées au mauvais endroit) : à supprimer à la main
        $parasites = array_values(array_filter(
            ['templates/public', 'templates/src', 'templates/templates', 'templates/config', 'templates/assets'],
            static fn (string $d) => is_dir($projectDir.'/'.$d)
        ));

        return $this->json(['fichiers' => $hits, 'parasites' => $parasites]);
    }
}
