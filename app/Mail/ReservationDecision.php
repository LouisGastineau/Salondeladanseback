<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class ReservationDecision extends Mailable
{
    public function __construct(public readonly string $mission, public readonly string $jour, public readonly string $debut, public readonly string $fin, public readonly string $decision) {}

    public function build(): self
    {
        return $this->subject('Votre demande de créneau — Salon de la Danse')->view('emails.reservation-decision');
    }
}
