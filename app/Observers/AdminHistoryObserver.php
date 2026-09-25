<?php

namespace App\Observers;

use App\Models\AdminHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdminHistoryObserver
{
    private const FIELDS = [
        'users' => ['nom', 'prenom', 'email', 'telephone', 'photo_path', 'role', 'isMineur', 'statut_planning'],
        'editions' => ['nom', 'date_debut', 'date_fin', 'isActive', 'isArchived'],
        'missions' => ['edition_id', 'nom', 'isSensible'],
        'creneaux' => ['mission_id', 'jour', 'heure_debut', 'heure_fin', 'capacite_max'],
        'reservations' => ['user_id', 'creneau_id', 'statut', 'validation_admin'],
        'invitation_codes' => ['email', 'isActive', 'statut_envoi', 'envoye_at'],
    ];

    public function created(Model $model): void
    {
        $this->record($model, 'creation', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'modification', $model->getRawOriginal(), $model->getAttributes());
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'suppression', $model->getRawOriginal(), null);
    }

    private function record(Model $model, string $action, ?array $before, ?array $after): void
    {
        $actor = request()->user();
        if (! $actor || $actor->role !== User::ROLE_ADMIN || ! request()->is('api/admin/*')) {
            return;
        }
        $keys = array_flip(self::FIELDS[$model->getTable()]);
        $before = $before === null ? null : array_intersect_key($before, $keys);
        $after = $after === null ? null : array_intersect_key($after, $keys);
        // Store only changed fields for updates. Never capture request bodies or secrets.
        if ($before !== null && $after !== null) {
            $changed = array_filter(array_keys($after), fn ($key) => ($before[$key] ?? null) != $after[$key]);
            $before = array_intersect_key($before, array_flip($changed));
            $after = array_intersect_key($after, array_flip($changed));
            if (! $after) {
                return;
            }
        }
        AdminHistory::create(['admin_id' => $actor->id, 'admin_nom' => $actor->prenom.' '.$actor->nom, 'action' => $action, 'entite' => $model->getTable(), 'entite_id' => $model->getKey(), 'avant' => $before, 'apres' => $after, 'route' => request()->method().' /'.request()->path()]);
    }
}
