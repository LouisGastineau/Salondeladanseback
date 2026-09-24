<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EditionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'date_debut' => $this->date_debut->toDateString(),
            'date_fin' => $this->date_fin->toDateString(),
            'isActive' => (bool) $this->isActive,
            'isArchived' => (bool) $this->isArchived,
            'missions' => MissionResource::collection($this->whenLoaded('missions')),
        ];
    }
}
