<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class VolunteerInvitation extends Mailable
{
    public function __construct(public readonly string $invitationCode) {}

    public function build(): self
    {
        return $this->subject('Votre invitation — Salon de la Danse')
            ->html('<p>Bonjour,</p><p>Vous êtes invité(e) à rejoindre les bénévoles du Salon de la Danse.</p>'
                .'<p>Votre code d’inscription : <strong>'.e($this->invitationCode).'</strong></p>'
                .'<p>Saisissez ce code dans le formulaire d’inscription communiqué par l’organisation. '
                .'Gardez ce code confidentiel : il est utilisable une seule fois.</p>'
                .'<p>À bientôt,<br>L’équipe du Salon de la Danse</p>');
    }
}
