<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Creneau;
use App\Models\Edition;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AdminCatalogService
{
    public function editions(User $actor)
    {
        $this->authorize($actor);

        return Edition::with('missions')->orderByDesc('date_debut')->orderByDesc('id')->get();
    }

    public function edition(User $actor, int $id): Edition
    {
        $this->authorize($actor);

        return Edition::with('missions')->findOrFail($id);
    }

    public function saveEdition(User $actor, array $data, ?int $id = null): Edition
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $id) {
            $edition = $id === null ? new Edition : Edition::whereKey($id)->lockForUpdate()->firstOrFail();
            $edition->fill($data);
            if ($edition->isActive) {
                Edition::where('id', '!=', $edition->id)->update(['isActive' => false]);
            }
            $edition->save();

            return $edition->load('missions');
        }, 3);
    }

    public function deleteEdition(User $actor, int $id): void
    {
        $this->authorize($actor);
        try {
            Edition::whereKey($id)->firstOrFail()->delete();
        } catch (QueryException $exception) {
            throw new BusinessRuleException('Cette édition contient encore des missions ou des créneaux et ne peut pas être supprimée.');
        }
    }

    public function missions(User $actor, ?int $editionId = null)
    {
        $this->authorize($actor);

        return Mission::with('edition')->when($editionId, fn ($q) => $q->where('edition_id', $editionId))
            ->orderBy('edition_id')->orderBy('nom')->get();
    }

    public function saveMission(User $actor, array $data, ?int $id = null): Mission
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $id) {
            $mission = $id === null ? new Mission : Mission::whereKey($id)->lockForUpdate()->firstOrFail();
            $mission->fill($data)->save();

            return $mission->load('edition');
        }, 3);
    }

    public function deleteMission(User $actor, int $id): void
    {
        $this->authorize($actor);
        try {
            Mission::whereKey($id)->firstOrFail()->delete();
        } catch (QueryException $exception) {
            throw new BusinessRuleException('Cette mission contient encore des créneaux et ne peut pas être supprimée.');
        }
    }

    public function creneaux(User $actor, ?int $missionId = null, ?int $editionId = null)
    {
        $this->authorize($actor);

        return Creneau::with('mission.edition')->withCount('reservations')
            ->when($missionId, fn ($q) => $q->where('mission_id', $missionId))
            ->when($editionId, fn ($q) => $q->whereHas('mission', fn ($m) => $m->where('edition_id', $editionId)))
            ->orderBy('jour')->orderBy('heure_debut')->orderBy('id')->get();
    }

    public function saveCreneau(User $actor, array $data, ?int $id = null): Creneau
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $id) {
            $creneau = $id === null ? new Creneau : Creneau::whereKey($id)->lockForUpdate()->firstOrFail();
            $mission = Mission::with('edition')->findOrFail($data['mission_id'] ?? $creneau->mission_id);
            $jour = $data['jour'] ?? $creneau->jour->toDateString();
            if ($jour < $mission->edition->date_debut->toDateString() || $jour > $mission->edition->date_fin->toDateString()) {
                throw new BusinessRuleException('Le jour du créneau doit être compris dans les dates de l’édition.');
            }
            if (isset($data['capacite_max']) && $data['capacite_max'] < $creneau->reservations()->count()) {
                throw new BusinessRuleException('La capacité ne peut pas être inférieure aux réservations existantes.');
            }
            $creneau->fill($data)->save();

            return $creneau->load('mission.edition')->loadCount('reservations');
        }, 3);
    }

    public function deleteCreneau(User $actor, int $id): void
    {
        $this->authorize($actor);
        try {
            Creneau::whereKey($id)->firstOrFail()->delete();
        } catch (QueryException $exception) {
            throw new BusinessRuleException('Ce créneau possède des réservations et ne peut pas être supprimé.');
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
    }
}
