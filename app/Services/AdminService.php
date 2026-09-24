<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\InvitationCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminService
{
    public function user(User $actor, int $id): User
    {
        $this->authorize($actor);

        return User::findOrFail($id);
    }

    public function users(User $actor, array $filters): Builder
    {
        $this->authorize($actor);

        return User::query()->withCount(['reservations as demandes_en_attente' => fn ($q) => $q->where('validation_admin', 'en_attente')])
            ->when(isset($filters['q']), function ($query) use ($filters) {
                $term = '%'.addcslashes($filters['q'], '%_\\').'%';
                $query->where(fn ($q) => $q->where('nom', 'like', $term)->orWhere('prenom', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when(isset($filters['role']), fn ($q) => $q->where('role', $filters['role']))
            ->when(isset($filters['statut_planning']), fn ($q) => $q->where('statut_planning', $filters['statut_planning']))
            ->when(isset($filters['isMineur']), fn ($q) => $q->where('isMineur', $filters['isMineur']))
            ->orderBy('nom')->orderBy('prenom')->orderBy('id');
    }

    public function update(User $actor, int $userId, array $data, ?UploadedFile $photo): User
    {
        $this->authorize($actor);
        $path = $photo?->store('photos', 'local');
        $oldPath = null;
        try {
            $user = DB::transaction(function () use ($userId, $data, $path, &$oldPath) {
                $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $user->fill(collect($data)->only(['nom', 'prenom', 'email', 'telephone', 'isMineur'])->all());
                if ($path) {
                    $oldPath = $user->photo_path;
                    $user->photo_path = $path;
                }
                $user->save();

                return $user;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => 'Cette adresse email est déjà utilisée.']);
            }
            throw $exception;
        }
        if ($oldPath && str_starts_with($oldPath, 'photos/')) {
            Storage::disk('local')->delete($oldPath);
        }

        return $user;
    }

    public function changeRole(User $actor, int $userId, string $role): User
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $userId, $role) {
            // Lock both rows before checking the last-admin invariant.
            $actorLocked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($user->id === $actorLocked->id && $role !== User::ROLE_ADMIN) {
                throw new BusinessRuleException('Un administrateur ne peut pas se rétrograder lui-même.', 409);
            }

            if ($user->role === User::ROLE_ADMIN && $role === User::ROLE_BENEVOLE
                && User::where('role', User::ROLE_ADMIN)->lockForUpdate()->count() <= 1) {
                throw new BusinessRuleException('Le dernier administrateur ne peut pas être rétrogradé.', 409);
            }

            $user->role = $role;
            $user->save();

            // Remove elevated sessions immediately when an admin is demoted.
            if ($role === User::ROLE_BENEVOLE) {
                $user->tokens()->delete();
            }

            return $user->refresh();
        }, 3);
    }

    public function invitations(User $actor, int $count): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($count) {
            $codes = [];
            for ($i = 0; $i < $count; $i++) {
                $codes[] = InvitationCode::create(['code' => strtoupper(bin2hex(random_bytes(12))), 'isActive' => true]);
            }

            return $codes;
        }, 3);
    }

    public function photo(User $actor, ?int $userId = null): string
    {
        if ($userId !== null) {
            $this->authorize($actor);
        }
        $user = $userId === null ? $actor : User::findOrFail($userId);
        abort_unless(str_starts_with($user->photo_path, 'photos/')
            && Storage::disk('local')->exists($user->photo_path), 404);

        return $user->photo_path;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
    }
}
