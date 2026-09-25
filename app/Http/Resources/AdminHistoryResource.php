<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'admin_id' => $this->admin_id, 'admin_nom' => $this->admin_nom, 'action' => $this->action,
            'entite' => $this->entite, 'entite_id' => $this->entite_id, 'avant' => $this->avant, 'apres' => $this->apres,
            'route' => $this->route, 'created_at' => $this->created_at->toISOString()];
    }
}
