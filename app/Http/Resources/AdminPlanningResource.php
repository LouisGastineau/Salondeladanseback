<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminPlanningResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'reservations' => ReservationResource::collection($this->whenLoaded('reservations')),
        ]);
    }
}
