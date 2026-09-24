<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PasswordRecovery extends Mailable
{
    public function __construct(public readonly string $token) {}

    public function build(): self
    {
        return $this->subject('Réinitialisation du mot de passe — Salon de la Danse')->view('emails.password-recovery');
    }
}
