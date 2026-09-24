<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPlanningIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'edition_id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', Rule::in(['benevole', 'admin'])],
            'statut_planning' => ['sometimes', Rule::in(['brouillon', 'valide'])],
            'isMineur' => ['sometimes', 'boolean'],
        ];
    }
}
