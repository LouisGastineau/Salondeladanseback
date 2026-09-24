<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\PasswordService;

class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request, PasswordService $service)
    {
        $service->forgot($request->validated('email'));

        return response()->json(['message' => 'Si cette adresse possède un compte, un email de réinitialisation a été envoyé.']);
    }

    public function reset(ResetPasswordRequest $request, PasswordService $service)
    {
        $service->reset($request->validated());

        return response()->json(['message' => 'Mot de passe modifié. Reconnectez-vous.']);
    }
}
