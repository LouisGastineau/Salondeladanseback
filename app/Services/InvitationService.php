<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvitationDeliveryException;
use App\Mail\VolunteerInvitation;
use App\Models\InvitationCode;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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

        // Create outside the transaction so a concurrent unique-key winner is visible
        // to firstOrCreate's recovery query under MariaDB REPEATABLE READ.
        $record = InvitationCode::firstOrCreate(['email' => $email], [
            'code' => strtoupper(bin2hex(random_bytes(12))), 'isActive' => false,
        ]);
        $invitation = DB::transaction(function () use ($record) {
            $record = InvitationCode::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->statut_envoi === 'envoye') {
                return $record;
            }
            if ($record->statut_envoi === 'en_cours') {
                throw new BusinessRuleException('Un envoi est déjà en cours pour cette adresse.');
            }
            $record->update(['statut_envoi' => 'en_cours', 'isActive' => false]);

            return $record;
        }, 3);
        if ($invitation->statut_envoi === 'envoye') {
            return $invitation;
        }

        try {
            Mail::to($email)->send(new VolunteerInvitation($invitation->code));
        } catch (Throwable $exception) {
            $invitation->update(['statut_envoi' => 'echec', 'isActive' => false]);
            throw new InvitationDeliveryException('L’invitation n’a pas pu être envoyée. Veuillez réessayer.');
        }
        // If persistence fails after SMTP acceptance, keep en_cours to prevent blind retries.
        $invitation->update(['isActive' => true, 'statut_envoi' => 'envoye', 'envoye_at' => now()]);

        return $invitation;
    }

    public function index(User $actor, array $filters): LengthAwarePaginator
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);

        return InvitationCode::query()
            ->when(isset($filters['email']), fn ($q) => $q->where('email', mb_strtolower(trim($filters['email']))))
            ->when(isset($filters['statut_envoi']), fn ($q) => $q->where('statut_envoi', $filters['statut_envoi']))
            ->when(isset($filters['isActive']), fn ($q) => $q->where('isActive', $filters['isActive']))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 50)->withQueryString();
    }
}
