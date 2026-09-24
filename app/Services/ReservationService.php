<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Mail\ReservationDecision;
use App\Models\Creneau;
use App\Models\Edition;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(private EditionService $editions) {}

    public function create(User $actor, int $creneauId, ?int $userId = null): Reservation
    {
        $admin = $userId !== null;
        if ($admin) {
            $this->assertAdmin($actor);
        }

        return DB::transaction(function () use ($actor, $creneauId, $userId, $admin) {
            // Every writer locks the owner before the slot. Current reads below
            // also avoid stale capacity counts under MariaDB REPEATABLE READ.
            $user = User::whereKey($userId ?? $actor->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($user, $admin);
            $edition = $this->editions->active();
            $slot = Creneau::whereKey($creneauId)->lockForUpdate()->firstOrFail();
            $slot->load('mission');
            if ($slot->mission->edition_id !== $edition->id) {
                throw new BusinessRuleException('Ce créneau ne fait pas partie de l’édition active.');
            }
            $this->assertSlot($slot, $edition->date_debut->toDateString(), $edition->date_fin->toDateString());
            $existing = $this->forEdition($user, $edition->id);
            if ($existing->contains('creneau_id', $slot->id)) {
                throw new BusinessRuleException('Vous avez déjà réservé ce créneau.');
            }
            $this->assertSchedule($existing->pluck('creneau')->push($slot));
            $occupied = Reservation::where('creneau_id', $slot->id)->lockForUpdate()->get(['id'])->count();
            if ($occupied >= $slot->capacite_max) {
                throw new BusinessRuleException('Ce créneau est complet.');
            }

            $reservation = new Reservation(['user_id' => $user->id, 'creneau_id' => $slot->id]);
            $reservation->statut = $user->statut_planning;
            $reservation->validation_admin = $slot->mission->isSensible ? ($admin ? Reservation::ACCEPTEE : Reservation::EN_ATTENTE) : null;
            $reservation->created_at = now();
            $reservation->save();

            return $reservation->load('creneau.mission');
        }, 3);
    }

    public function delete(User $actor, int $reservationId, bool $admin = false): void
    {
        if ($admin) {
            $this->assertAdmin($actor);
        }
        $reference = Reservation::query()->when(! $admin, fn ($q) => $q->where('user_id', $actor->id))
            ->findOrFail($reservationId);

        DB::transaction(function () use ($reference, $reservationId, $admin) {
            $user = User::whereKey($reference->user_id)->lockForUpdate()->firstOrFail();
            $slot = Creneau::whereKey($reference->creneau_id)->lockForUpdate()->firstOrFail();
            $slot->load('mission');
            $reservation = Reservation::whereKey($reservationId)->lockForUpdate()->firstOrFail();
            $pending = $reservation->validation_admin === Reservation::EN_ATTENTE;
            $this->assertEditable($user, $admin || $pending);
            $edition = $this->editions->active();
            if ($slot->mission->edition_id !== $edition->id) {
                throw new BusinessRuleException('Ce planning appartient à une édition inactive.');
            }
            if ($admin && ! $pending && $user->statut_planning === User::PLANNING_VALIDE
                && $this->forEdition($user, $edition->id)->count() <= 1) {
                throw new BusinessRuleException('Déverrouillez le planning avant de supprimer sa dernière réservation.');
            }
            $reservation->delete();
            if ($pending) {
                $this->reopenAfterRemoval($user, $edition->id);
            }
        }, 3);
    }

    public function validate(User $actor, ?int $userId = null): User
    {
        if ($userId !== null) {
            $this->assertAdmin($actor);
        }

        return DB::transaction(function () use ($actor, $userId) {
            $user = User::whereKey($userId ?? $actor->id)->lockForUpdate()->firstOrFail();
            $edition = $this->editions->active();
            $reservations = $this->forEdition($user, $edition->id);
            if ($reservations->isEmpty()) {
                throw new BusinessRuleException('Réservez au moins un créneau avant de valider le planning.');
            }
            $this->assertSchedule($reservations->pluck('creneau'));
            $user->statut_planning = User::PLANNING_VALIDE;
            $user->save();
            Reservation::whereIn('id', $reservations->modelKeys())->update(['statut' => Reservation::STATUT_VALIDE]);

            return $user;
        }, 3);
    }

    public function unlock(User $actor, int $userId): User
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($userId) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $edition = $this->editions->active();
            $reservations = $this->forEdition($user, $edition->id);
            $user->statut_planning = User::PLANNING_BROUILLON;
            $user->save();
            Reservation::whereIn('id', $reservations->modelKeys())->update(['statut' => Reservation::STATUT_BROUILLON]);

            return $user;
        }, 3);
    }

    public function validations(User $actor, array $filters)
    {
        $this->assertAdmin($actor);

        return Reservation::whereHas('creneau.mission', fn ($q) => $q->where('isSensible', true))
            ->where('validation_admin', $filters['statut'] ?? Reservation::EN_ATTENTE)
            ->with(['user', 'creneau' => fn ($q) => $q->with('mission')->withCount('reservations')])
            ->orderBy('created_at')->orderBy('id')->paginate($filters['per_page'] ?? 50)->withQueryString();
    }

    public function decide(User $actor, int $id, string $decision): ?Reservation
    {
        $this->assertAdmin($actor);
        if (! in_array($decision, [Reservation::ACCEPTEE, Reservation::REFUSEE], true)) {
            throw ValidationException::withMessages(['decision' => 'Décision invalide.']);
        }
        $reference = Reservation::findOrFail($id);
        $notification = null;
        $result = DB::transaction(function () use ($reference, $id, $decision, &$notification) {
            $user = User::whereKey($reference->user_id)->lockForUpdate()->firstOrFail();
            $slot = Creneau::whereKey($reference->creneau_id)->lockForUpdate()->firstOrFail();
            $slot->load('mission');
            $reservation = Reservation::whereKey($id)->lockForUpdate()->first();
            if (! $reservation || ! $slot->mission->isSensible || $reservation->validation_admin !== Reservation::EN_ATTENTE) {
                throw ValidationException::withMessages(['decision' => 'Cette réservation ne nécessite pas de validation ou a déjà été traitée.']);
            }
            $notification = [$user->email, new ReservationDecision($slot->mission->nom, $slot->jour->format('d/m/Y'), substr($slot->heure_debut, 0, 5), substr($slot->heure_fin, 0, 5), $decision)];
            if ($decision === Reservation::REFUSEE) {
                $reservation->delete();
                $this->reopenAfterRemoval($user, $slot->mission->edition_id);

                return null;
            }
            $reservation->validation_admin = Reservation::ACCEPTEE;
            $reservation->save();

            return $reservation->load(['creneau' => fn ($q) => $q->with('mission')->withCount('reservations')]);
        }, 3);
        // SMTP runs after commit: delivery failure must not roll back the decision.
        if ($notification && config('mail.mailers.'.config('mail.default').'.transport') === 'smtp') {
            try {
                Mail::to($notification[0])->send($notification[1]);
            } catch (\Throwable $exception) { /* The recorded decision remains authoritative. */
            }
        }

        return $result;
    }

    private function reopenAfterRemoval(User $user, int $editionId): void
    {
        // A removed pending request means the volunteer must be able to select a replacement.
        $reservations = $this->forEdition($user, $editionId);
        Reservation::whereIn('id', $reservations->modelKeys())->update(['statut' => Reservation::STATUT_BROUILLON]);
        if (Edition::whereKey($editionId)->where('isActive', true)->exists()) {
            $user->statut_planning = User::PLANNING_BROUILLON;
            $user->save();
        }
    }

    private function forEdition(User $user, int $editionId): Collection
    {
        return Reservation::where('user_id', $user->id)
            ->whereHas('creneau.mission', fn ($q) => $q->where('edition_id', $editionId))
            ->with('creneau.mission')->orderBy('id')->lockForUpdate()->get();
    }

    private function assertEditable(User $user, bool $admin): void
    {
        if ($user->role !== User::ROLE_BENEVOLE) {
            throw new BusinessRuleException('Seuls les comptes bénévoles ont un planning.');
        }
        if (! $admin && $user->statut_planning === User::PLANNING_VALIDE) {
            throw new BusinessRuleException('Planning validé : seul un administrateur peut le modifier.');
        }
    }

    private function assertSlot(Creneau $slot, string $start, string $end): void
    {
        if ($slot->jour->toDateString() < $start || $slot->jour->toDateString() > $end
            || $slot->heure_debut >= $slot->heure_fin || $slot->capacite_max < 1) {
            throw new BusinessRuleException('Ce créneau est mal configuré. Contactez un administrateur.');
        }
    }

    private function assertSchedule(Collection $slots): void
    {
        if ($slots->count() > 3) {
            throw new BusinessRuleException('Maximum trois créneaux sur le week-end.');
        }
        foreach ($slots->groupBy(fn ($slot) => $slot->jour->toDateString()) as $day) {
            $ordered = $day->sortBy('heure_debut')->values();
            $run = 1;
            for ($i = 1; $i < $ordered->count(); $i++) {
                $previous = $ordered[$i - 1];
                $current = $ordered[$i];
                if ($current->heure_debut < $previous->heure_fin) {
                    throw new BusinessRuleException('Deux réservations ne peuvent pas se chevaucher.');
                }
                $run = $current->heure_debut === $previous->heure_fin ? $run + 1 : 1;
                if ($run >= 3) {
                    throw new BusinessRuleException('Trois créneaux consécutifs sont interdits : prévoyez une pause.');
                }
            }
        }
    }

    private function assertAdmin(User $actor): void
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
    }
}
