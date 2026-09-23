<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Edition;

class EditionService
{
    public function active(): Edition
    {
        $editions = Edition::where('isActive', true)->limit(2)->get();
        if ($editions->count() !== 1) {
            throw new BusinessRuleException('Une seule édition doit être active pour ouvrir les réservations.');
        }

        return $editions->first();
    }
}
