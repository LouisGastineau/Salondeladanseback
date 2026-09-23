<?php

namespace App\Services;

use App\Models\Creneau;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PlanningService
{
    public function __construct(private EditionService $editions) {}

    public function slots(array $filters, bool $admin = false): LengthAwarePaginator
    {
        return Creneau::query()
            ->whereHas('mission', fn ($q) => $q->where('edition_id', $this->editions->active()->id)
                ->when(! $admin, fn ($q) => $q->where('isSensible', false)))
            ->when(isset($filters['jour']), fn ($q) => $q->whereDate('jour', $filters['jour']))
            ->when(isset($filters['mission_id']), fn ($q) => $q->where('mission_id', $filters['mission_id']))
            ->with('mission')->withCount('reservations')
            ->orderBy('jour')->orderBy('heure_debut')->orderBy('id')
            ->paginate($filters['per_page'] ?? 50)->withQueryString();
    }

    public function reservations(User $user, bool $admin = false): Collection
    {
        return Reservation::where('user_id', $user->id)
            ->whereHas('creneau.mission', fn ($q) => $q->where('edition_id', $this->editions->active()->id)
                ->when(! $admin, fn ($q) => $q->where('isSensible', false)))
            ->with('creneau.mission')->get()->sortBy([
                fn ($a, $b) => $a->creneau->jour <=> $b->creneau->jour,
                fn ($a, $b) => $a->creneau->heure_debut <=> $b->creneau->heure_debut,
            ])->values();
    }
}
