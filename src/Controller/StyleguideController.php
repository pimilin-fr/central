<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page temporaire : catalogue de tous les composants du design system,
 * rendus avec les variables du thème courant. À retirer une fois le paramétrage terminé.
 */
#[Route('/styleguide')]
class StyleguideController extends AbstractController
{
    #[Route('', name: 'app_styleguide', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('styleguide/index.html.twig');
    }
}
