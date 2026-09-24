<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Mail\PasswordRecovery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordService
{
    public function forgot(string $email): void
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            return;
        }
        DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $broker = Password::broker();
            if ($broker->getRepository()->recentlyCreatedToken($user)) {
                return;
            }
            $transport = config('mail.mailers.'.config('mail.default').'.transport');
            if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
                throw new BusinessRuleException('Envoi des emails indisponible.', 503);
            }
            $token = $broker->createToken($user);
            try {
                Mail::to($user->email)->send(new PasswordRecovery($token));
            } catch (\Throwable $e) {
                throw new BusinessRuleException('Envoi des emails indisponible.', 503);
            }
        });
    }

    public function reset(array $data): void
    {
        DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            $broker = Password::broker();
            if (! $user || ! $broker->tokenExists($user, $data['token'])) {
                throw ValidationException::withMessages(['token' => 'Code invalide ou expiré.']);
            }
            $user->password = $data['password'];
            $user->save();
            $user->tokens()->delete();
            $broker->deleteToken($user);
        }, 3);
    }
}
