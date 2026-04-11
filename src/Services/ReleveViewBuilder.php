<?php
namespace App\Services;

/* 
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/EmptyPHP.php to edit this template
 */

class ReleveViewBuilder
{
    public function build($releves)
    {
        $result = [];

        foreach ($releves as $releve) {

            $operations = [];
            $total = 0;

            foreach ($releve->getDepenses() as $depense) {

                $montant = $depense->getCategorie()->isDepense()
                    ? -$depense->getMontant()
                    : $depense->getMontant();

                $operations[] = [
                    'entity' => $depense,
                    'montant' => $montant
                ];

                $total += $montant;
            }

            $result[] = [
                'releve' => $releve,
                'operations' => $operations,
                'total' => $total
            ];
        }

        return $result;
    }
}