<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HistoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_id' => ['sometimes', 'integer', 'min:1'], 'entite_id' => ['sometimes', 'integer', 'min:1'],
            'entite' => ['sometimes', Rule::in(['users', 'editions', 'missions', 'creneaux', 'reservations', 'invitation_codes'])],
            'action' => ['sometimes', Rule::in(['creation', 'modification', 'suppression'])],
            'du' => ['sometimes', 'date_format:Y-m-d'], 'au' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
