<?php


namespace App\Entity;

abstract class ColorableEntity{
    abstract public function getCouleur():?string;
    
    public function getColor(){
        return $this->getCouleur();
    }


    public function getTextColor(): string {
        $couleur = $this->getCouleur();
        if (!$couleur || !preg_match('/^#[0-9A-Fa-f]{6}$/', $couleur)) {
            return '#FFFFFF';
        }

        $r = hexdec(substr($couleur, 1, 2));
        $g = hexdec(substr($couleur, 3, 2));
        $b = hexdec(substr($couleur, 5, 2));

        $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;

        return $luminance > 165 ? '#1F2937' : '#FFFFFF';
    }
}