<?php

namespace App\Controller\V2;

use App\Entity\Depenses;
use App\Entity\Portefeuille;
use App\Entity\PortefeuilleView;
use App\Form\PortefeuilleType;
use App\Services\DepenseGrouper\DepenseGroupManager;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByCategorie;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByMonth;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByProjet;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByQuarter;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByReleve;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByTiers;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByWeek;
use App\Services\DepenseGrouper\GrouperStrategy\GroupByYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DetailController extends AbstractController {
    
}
