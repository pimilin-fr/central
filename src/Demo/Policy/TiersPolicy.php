<?php
namespace App\Demo\Policy;
/**
 * Description of TiersPolicy
 *
 * @author Pierre
 */
class TiersPolicy {
    public function decide(Tiers $tiers): DemoAction
    {
        // Exemples : règles à définir ensemble

        if ($this->mustBeExcluded($tiers)) {
            return DemoAction::EXCLUDE;
        }

        if ($this->mustBeAnonymized($tiers)) {
            return DemoAction::ANONYMIZE;
        }

        return DemoAction::COPY;
    }

    private function mustBeExcluded(Tiers $tiers): bool
    {
        return false;
    }

    private function mustBeAnonymized(Tiers $tiers): bool
    {
        return false;
    }
}
