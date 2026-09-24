<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreneauRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'mission_id' => ['required', 'integer', Rule::exists('missions', 'id')],
            'jour' => ['required', 'date'],
            'heure_debut' => ['required', 'date_format:H:i,H:i:s'],
            'heure_fin' => ['required', 'date_format:H:i,H:i:s', 'after:heure_debut'],
            'capacite_max' => ['required', 'integer', 'min:1'],
        ];
    }
}
