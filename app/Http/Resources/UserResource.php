<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'role' => $this->role,
            'isMineur' => $this->isMineur,
            'statut_planning' => $this->statut_planning,
            'photo_url' => $this->photo_path && str_starts_with($this->photo_path, 'photos/')
                ? ($request->is('api/admin/*') ? '/api/admin/users/'.$this->id.'/photo' : '/api/me/photo') : null,
        ];
    }
}
