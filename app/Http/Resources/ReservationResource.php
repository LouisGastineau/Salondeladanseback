<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'statut' => $this->statut,
            'validation_admin' => $this->validation_admin,
            'created_at' => $this->created_at?->toISOString(),
            'user_id' => $this->when($request->is('api/admin/*') && $request->user()?->role === 'admin', $this->user_id),
            'creneau' => new CreneauResource($this->whenLoaded('creneau')),
        ];
    }
}
