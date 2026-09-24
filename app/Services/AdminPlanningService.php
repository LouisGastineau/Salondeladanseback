<?php

namespace App\Services;

use App\Models\Creneau;
use App\Models\Edition;
use App\Models\User;

class AdminPlanningService
{
    public function __construct(private EditionService $editions, private AdminService $admins) {}

    public function index(User $actor, array $filters): array
    {
        // The admin service checks the actor before any planning query.
        $query = $this->admins->users($actor, $filters);
        $edition = isset($filters['edition_id'])
            ? Edition::findOrFail($filters['edition_id'])
            : $this->editions->active();

        $users = $query->with(['reservations' => fn ($q) => $q
            ->whereHas('creneau.mission', fn ($mission) => $mission->where('edition_id', $edition->id))
            ->with(['creneau' => fn ($slot) => $slot->with('mission')->withCount('reservations')]),
        ])->get();

        foreach ($users as $user) {
            $user->setRelation('reservations', $user->reservations->sortBy([
                fn ($a, $b) => $a->creneau->jour <=> $b->creneau->jour,
                fn ($a, $b) => $a->creneau->heure_debut <=> $b->creneau->heure_debut,
                fn ($a, $b) => $a->id <=> $b->id,
            ])->values());
        }

        return ['edition_id' => $edition->id, 'users' => $users];
    }

    public function participants(User $actor, int $slotId): Creneau
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);

        // Explicit slot lookup also works for an inactive edition.
        return Creneau::with('mission')->withCount('reservations')
            ->with(['reservations' => fn ($q) => $q->with('user')->orderBy('id')])
            ->findOrFail($slotId);
    }
}
