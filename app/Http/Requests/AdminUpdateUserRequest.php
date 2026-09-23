<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'prenom' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore((int) $this->route('id'))],
            'telephone' => ['sometimes', 'required', 'string', 'max:30'],
            'isMineur' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'role' => ['prohibited'],
            'password' => ['prohibited'],
            'statut_planning' => ['prohibited'],
            'invitation_code_id' => ['prohibited'],
            'photo_path' => ['prohibited'],
        ];
    }
}
