<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Creneau;
use App\Models\Edition;
use App\Models\Mission;
use App\Models\Reservation;
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
            // Same owner-first locking order as reservation writers. Serializes edition switches.
            User::orderBy('id')->lockForUpdate()->get();
            $editions = Edition::orderBy('id')->lockForUpdate()->get();
            $edition = $id === null ? new Edition : $editions->firstWhere('id', $id);
            abort_unless($edition, 404);
            $wasActive = (bool) $edition->isActive;
            $edition->fill($data);
            if ($edition->date_fin < $edition->date_debut) {
                throw new BusinessRuleException('La date de fin doit suivre la date de début.');
            }
            if ($edition->exists && $edition->creneaux()->where(fn ($q) => $q
                ->where('jour', '<', $edition->date_debut->toDateString())
                ->orWhere('jour', '>', $edition->date_fin->toDateString()))->exists()) {
                throw new BusinessRuleException('Les dates doivent contenir tous les créneaux existants.');
            }
            if ($edition->isArchived) {
                if (! empty($data['isActive'])) {
                    throw new BusinessRuleException('Une édition archivée ne peut pas être active.');
                }
                $edition->isActive = false;
            }
            if ($edition->isActive) {
                Edition::where('isActive', true)->update(['isActive' => false]);
                $edition->isActive = false;
                $edition->syncOriginalAttribute('isActive');
                $edition->isActive = true;
            }
            $edition->save();
            if ($edition->isActive || $wasActive) {
                $validated = $edition->isActive ? Reservation::whereHas('creneau.mission', fn ($q) => $q->where('edition_id', $edition->id))
                    ->select('user_id')->groupBy('user_id')->havingRaw("COUNT(*) = SUM(statut = 'valide')")->pluck('user_id')->all() : [];
                // Reservation statuses retain the history; users mirrors the active edition only.
                User::query()->update(['statut_planning' => User::PLANNING_BROUILLON]);
                User::whereIn('id', $validated)->update(['statut_planning' => User::PLANNING_VALIDE]);
            }

            return $edition->load('missions');
        }, 3);
    }

    public function deleteEdition(User $actor, int $id): void
    {
        $this->authorize($actor);
        try {
            Edition::whereKey($id)->firstOrFail()->delete();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1451) {
                throw $exception;
            }
            throw new BusinessRuleException('Cette édition contient encore des missions ou des créneaux et ne peut pas être supprimée.');
        }
    }

    public function missions(User $actor, ?int $editionId = null)
    {
        $this->authorize($actor);

        return Mission::with('edition')->when($editionId, fn ($q) => $q->where('edition_id', $editionId))
            ->orderBy('edition_id')->orderBy('nom')->get();
    }

    public function mission(User $actor, int $id): Mission
    {
        $this->authorize($actor);

        return Mission::findOrFail($id);
    }

    public function creneau(User $actor, int $id): Creneau
    {
        $this->authorize($actor);

        return Creneau::with('mission')->withCount('reservations')->findOrFail($id);
    }

    public function saveMission(User $actor, array $data, ?int $id = null): Mission
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $id) {
            $reference = $id === null ? null : Mission::findOrFail($id);
            $parents = Edition::whereIn('id', array_filter([$data['edition_id'] ?? null, $reference?->edition_id]))->orderBy('id')->lockForUpdate()->get();
            if ($parents->contains('isArchived', true)) {
                throw new BusinessRuleException('Restaurez cette édition avant de modifier ses missions.');
            }
            $mission = $id === null ? new Mission : Mission::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($mission->exists && isset($data['edition_id']) && (int) $data['edition_id'] !== $mission->edition_id && $mission->creneaux()->exists()) {
                throw new BusinessRuleException('Une mission contenant des créneaux ne peut pas changer d’édition.');
            }
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
            if (($exception->errorInfo[1] ?? null) !== 1451) {
                throw $exception;
            }
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
            $reference = $id === null ? null : Creneau::with('mission')->findOrFail($id);
            $destination = Mission::findOrFail($data['mission_id'] ?? $reference->mission_id);
            $parents = Edition::whereIn('id', array_filter([$destination->edition_id, $reference?->mission->edition_id]))->orderBy('id')->lockForUpdate()->get();
            if ($parents->contains('isArchived', true)) {
                throw new BusinessRuleException('Restaurez cette édition avant de modifier ses créneaux.');
            }
            $creneau = $id === null ? new Creneau : Creneau::whereKey($id)->lockForUpdate()->firstOrFail();
            $mission = Mission::with('edition')->findOrFail($data['mission_id'] ?? $creneau->mission_id);
            $jour = $data['jour'] ?? $creneau->jour->toDateString();
            if ($jour < $mission->edition->date_debut->toDateString() || $jour > $mission->edition->date_fin->toDateString()) {
                throw new BusinessRuleException('Le jour du créneau doit être compris dans les dates de l’édition.');
            }
            $occupied = $creneau->exists ? $creneau->reservations()->lockForUpdate()->get(['id'])->count() : 0;
            $creneau->fill($data);
            foreach (['heure_debut', 'heure_fin'] as $field) {
                if (strlen($creneau->$field) === 5) {
                    $creneau->$field .= ':00';
                }
            }
            if ($creneau->heure_fin <= $creneau->heure_debut) {
                throw new BusinessRuleException('L’heure de fin doit suivre l’heure de début.');
            }
            if ($occupied && $creneau->isDirty(['mission_id', 'jour', 'heure_debut', 'heure_fin'])) {
                throw new BusinessRuleException('Retirez les réservations avant de déplacer ce créneau ou de modifier ses horaires.');
            }
            if (isset($data['capacite_max']) && $data['capacite_max'] < $occupied) {
                throw new BusinessRuleException('La capacité ne peut pas être inférieure aux réservations existantes.');
            }
            $creneau->save();

            return $creneau->load('mission.edition')->loadCount('reservations');
        }, 3);
    }

    public function deleteCreneau(User $actor, int $id): void
    {
        $this->authorize($actor);
        try {
            Creneau::whereKey($id)->firstOrFail()->delete();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1451) {
                throw $exception;
            }
            throw new BusinessRuleException('Ce créneau possède des réservations et ne peut pas être supprimé.');
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
    }
}
