<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class VolunteerNotification extends Mailable
{
    public function __construct(public readonly array $contenu) {}

    public function build(): self
    {
        return $this->subject($this->contenu['titre'].' — Salon de la Danse')->view('emails.notification');
    }
}
