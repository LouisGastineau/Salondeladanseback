<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'edition_id' => $this->edition_id,
            'nom' => $this->nom,
            'isSensible' => $this->when($request->is('api/admin/*') && $request->user()?->role === 'admin', $this->isSensible),
        ];
    }
}
