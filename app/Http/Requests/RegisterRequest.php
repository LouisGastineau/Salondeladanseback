<?php

namespace App\Http\Requests;

use App\Rules\BcryptPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'telephone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed', new BcryptPassword],
            'code_invitation' => ['required', 'string', 'max:255'],
            'isMineur' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'role' => ['prohibited'],
            'statut_planning' => ['prohibited'],
            'invitation_code_id' => ['prohibited'],
            'photo_path' => ['prohibited'],
        ];
    }
}
