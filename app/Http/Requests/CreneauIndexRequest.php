<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreneauIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jour' => ['sometimes', 'date_format:Y-m-d'],
            'mission_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
