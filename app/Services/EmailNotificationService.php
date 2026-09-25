<?php

namespace App\Services;

use App\Mail\VolunteerNotification;
use App\Models\EmailNotification;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    public function record(User $user, string $type, string $key, array $content, ?int $reservationId = null): EmailNotification
    {
        $notification = EmailNotification::firstOrCreate(['cle' => $key], ['user_id' => $user->id, 'reservation_id' => $reservationId, 'type' => $type, 'contenu' => $content]);
        // Synchronous after commit: a failed SMTP connection never cancels the business action.
        if ($notification->wasRecentlyCreated) {
            DB::afterCommit(function () use ($notification) {
                try {
                    $this->send($notification->id);
                } catch (\Throwable $exception) {
                    Log::warning('Notification non finalisée.', ['notification_id' => $notification->id]);
                }
            });
        }

        return $notification;
    }

    public function slot(Reservation $reservation): array
    {
        $slot = $reservation->creneau;

        return ['mission' => $slot->mission->nom, 'jour' => $slot->jour->format('d/m/Y'), 'debut' => substr($slot->heure_debut, 0, 5), 'fin' => substr($slot->heure_fin, 0, 5), 'validation' => $reservation->validation_admin === Reservation::EN_ATTENTE ? 'En attente de validation par un administrateur' : 'Créneau confirmé'];
    }

    public function registration(User $user): void
    {
        $this->record($user, 'inscription', 'inscription:'.$user->id, ['titre' => 'Inscription confirmée', 'message' => 'Votre compte bénévole a bien été créé. Vous pouvez choisir vos créneaux puis valider votre planning.']);
    }

    public function reservation(Reservation $reservation): void
    {
        $pending = $reservation->validation_admin === Reservation::EN_ATTENTE;
        $this->record($reservation->user, 'reservation', 'reservation:'.$reservation->id, ['titre' => $pending ? 'Demande de créneau reçue' : 'Réservation confirmée', 'message' => $pending ? 'Votre place est réservée en attendant la décision d’un administrateur.' : 'Votre réservation a bien été enregistrée. Pensez à valider votre planning.', 'creneaux' => [$this->slot($reservation)]], $reservation->id);
    }

    public function planning(User $user, int $editionId): void
    {
        $reservations = $user->reservations()->whereHas('creneau.mission', fn ($q) => $q->where('edition_id', $editionId))->with('creneau.mission')->orderBy('id')->get();
        $content = ['titre' => 'Planning validé', 'message' => 'Votre planning a été validé. Les demandes indiquées en attente nécessitent encore l’accord d’un administrateur.', 'creneaux' => $reservations->map(fn ($r) => $this->slot($r))->all()];
        $key = 'planning:'.$user->id.':'.$editionId.':'.hash('sha256', json_encode([$reservations->modelKeys(), $content]));
        $this->record($user, 'planning', $key, $content);
    }

    public function send(int $id): bool
    {
        if (config('mail.mailers.'.config('mail.default').'.transport') !== 'smtp') {
            return false;
        }
        $claimed = EmailNotification::whereKey($id)->whereIn('statut', ['a_envoyer', 'echec'])->where('tentatives', '<', 3)
            ->update(['statut' => 'en_cours', 'tentatives' => DB::raw('tentatives + 1'), 'erreur' => null]);
        if (! $claimed) {
            return false;
        }
        $notification = EmailNotification::findOrFail($id);
        $user = User::find($notification->user_id);
        if (! $user || ($notification->reservation_id && ! Reservation::whereKey($notification->reservation_id)->exists())
            || ($notification->type === 'rappel' && ! $this->reminderStillValid($notification))) {
            $notification->update(['statut' => 'annulee']);

            return false;
        }
        try {
            Mail::to($user->email)->send(new VolunteerNotification($notification->contenu));
        } catch (\Throwable $e) {
            $notification->update(['statut' => 'echec', 'erreur' => 'Échec de transmission au serveur SMTP.']);

            return false;
        }
        $notification->update(['statut' => 'envoyee', 'envoye_at' => now()]);

        return true;
    }

    private function reminderStillValid(EmailNotification $notification): bool
    {
        $r = $this->eligibleReminders()->whereKey($notification->reservation_id)->first();
        if (! $r) {
            return false;
        }

        return $notification->cle === $this->reminderKey($r);
    }

    private function reminderKey(Reservation $r): string
    {
        return 'rappel:'.$r->id.':'.$r->creneau->jour->toDateString().':'.$r->creneau->heure_debut;
    }

    private function eligibleReminders()
    {
        $now = CarbonImmutable::now(config('notifications.timezone'));
        $q = Reservation::where('statut', Reservation::STATUT_VALIDE)
            ->whereHas('user', fn ($q) => $q->where('role', User::ROLE_BENEVOLE)->where('statut_planning', User::PLANNING_VALIDE))
            ->where(fn ($q) => $q->whereNull('validation_admin')->orWhere('validation_admin', Reservation::ACCEPTEE))
            ->whereHas('creneau', fn ($q) => $q->whereDate('jour', $now->addDay()->toDateString()))
            ->whereHas('creneau.mission.edition', fn ($q) => $q->where('isActive', true)->where('isArchived', false))
            ->with('creneau.mission', 'user');
        if ($now->hour < config('notifications.reminder_hour')) {
            $q->whereRaw('1=0');
        }

        return $q;
    }

    public function process(): void
    {
        // Bounded retry pass, then create due reminders. Unique keys prevent duplicate schedules.
        EmailNotification::whereIn('statut', ['a_envoyer', 'echec'])->where('tentatives', '<', 3)->orderBy('id')->limit(500)->get(['id'])
            ->each(fn ($n) => $this->send($n->id));
        $this->eligibleReminders()->chunkById(100, function ($reservations) {
            foreach ($reservations as $r) {
                $this->record($r->user, 'rappel', $this->reminderKey($r), ['titre' => 'Rappel : votre créneau de demain', 'message' => 'Nous vous attendons demain pour votre créneau au Salon de la Danse. Les horaires sont indiqués en heure de Paris.', 'creneaux' => [$this->slot($r)]], $r->id);
            }
        });
    }
}
