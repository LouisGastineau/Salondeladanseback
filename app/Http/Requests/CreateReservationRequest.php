<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'creneau_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['prohibited'],
            'statut' => ['prohibited'],
        ];
    }
}
