<?php

namespace App\Services;

use App\Exceptions\InvitationDeliveryException;
use App\Mail\VolunteerInvitation;
use App\Models\InvitationCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvitationService
{
    public function send(User $actor, string $email): InvitationCode
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
        $email = mb_strtolower(trim($email));
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Cette adresse possède déjà un compte.']);
        }

        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        // A log/array transport or a fallback to logs must never count as an email sent.
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
            throw new InvitationDeliveryException('L’envoi des invitations par email n’est pas configuré.');
        }

        // Persist before sending, but do not allow registration until the transport accepts it.
        $invitation = InvitationCode::create([
            'code' => strtoupper(bin2hex(random_bytes(12))),
            'isActive' => false,
        ]);

        try {
            Mail::to($email)->send(new VolunteerInvitation($invitation->code));
            $invitation->update(['isActive' => true]);
        } catch (Throwable $exception) {
            // The committed code remains inactive if sending or activation fails.
            throw new InvitationDeliveryException('L’invitation n’a pas pu être envoyée. Veuillez réessayer.');
        }

        return $invitation;
    }
}
