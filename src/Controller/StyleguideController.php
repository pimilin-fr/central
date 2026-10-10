<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Styleguide : catalogue vivant du design system (accordéon, exemples avec code copiable),
 * rendus avec les variables du thème courant.
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
