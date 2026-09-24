<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'isActive' => $this->isActive,
            'email' => $this->email,
            'statut_envoi' => $this->statut_envoi,
            'envoye_at' => $this->envoye_at?->toIso8601String(),
        ];
    }
}
