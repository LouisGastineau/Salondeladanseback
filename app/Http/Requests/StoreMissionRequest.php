<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMissionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'edition_id' => ['required', 'integer', Rule::exists('editions', 'id')],
            'nom' => ['required', 'string', 'max:255'],
            'isSensible' => ['sometimes', 'boolean'],
        ];
    }
}
