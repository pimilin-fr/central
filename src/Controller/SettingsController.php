<?php

namespace App\Controller;

use App\Service\Settings\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/parametres')]
final class SettingsController extends AbstractController {

    #[Route(name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, AppSettings $settings): Response|RedirectResponse {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app_settings', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Action refusée (jeton invalide). Rechargez la page.');
                return $this->redirectToRoute('app_settings');
            }
            if ($request->request->has('reset')) {
                $settings->reset();
                $this->addFlash('success', 'Valeurs par défaut rétablies.');
                return $this->redirectToRoute('app_settings');
            }
            $errors = $settings->save($request->request->all());
            if ($errors === []) {
                $this->addFlash('success', 'Paramètres enregistrés.');
                return $this->redirectToRoute('app_settings');
            }
            foreach ($errors as $e) {
                $this->addFlash('error', $e);
            }
        }

        $groups = [];
        $values = $settings->all();
        foreach (AppSettings::DEFINITIONS as $key => $def) {
            $groups[$def['group']][] = $def + ['key' => $key, 'field' => str_replace('.', '_', $key), 'value' => $values[$key]];
        }

        return $this->render('settings/index.html.twig', ['groups' => $groups]);
    }
}
