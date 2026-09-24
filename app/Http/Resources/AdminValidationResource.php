<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminValidationResource extends ReservationResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['user' => new UserResource($this->whenLoaded('user'))];
    }
}
