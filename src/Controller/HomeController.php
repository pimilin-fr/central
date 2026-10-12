<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page d'accueil : liste des applications hébergées sur le Raspberry Pi, pour passer de l'une à l'autre.
 * La liste est dans le template (templates/home/index.html.twig) ; le sélecteur d'applications du bandeau est tenu à jour à la main.
 */
final class HomeController extends AbstractController {

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response {
        return $this->render('home/index.html.twig');
    }
}
