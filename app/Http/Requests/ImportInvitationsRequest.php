<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv', 'max:256'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
