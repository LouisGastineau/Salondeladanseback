<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreneauResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jour' => $this->jour->toDateString(),
            'heure_debut' => $this->heure_debut,
            'heure_fin' => $this->heure_fin,
            'capacite_max' => $this->capacite_max,
            'places_restantes' => $this->whenCounted('reservations', fn () => max(0, $this->capacite_max - $this->reservations_count)),
            'mission' => new MissionResource($this->whenLoaded('mission')),
        ];
    }
}
