<?php

namespace App\Http\Requests;

use App\Rules\BcryptPassword;

class ResetPasswordRequest extends ForgotPasswordRequest
{
    public function rules(): array
    {
        return parent::rules() + ['token' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed', new BcryptPassword]];
    }
}
