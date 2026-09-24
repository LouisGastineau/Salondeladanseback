<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ligne' => $this->resource['ligne'],
            'email' => $this->resource['email'],
            'statut' => $this->resource['statut'],
            'message' => $this->resource['message'],
        ];
    }
}
