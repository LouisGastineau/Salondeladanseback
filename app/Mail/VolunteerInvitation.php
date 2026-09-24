<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class VolunteerInvitation extends Mailable
{
    public function __construct(public readonly string $invitationCode) {}

    public function build(): self
    {
        return $this->subject('Votre invitation — Salon de la Danse')
            ->view('emails.invitation', [
                'invitationCode' => $this->invitationCode,
            ]);
    }
}
