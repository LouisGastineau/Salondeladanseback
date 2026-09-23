<?php

namespace App\Services;

use App\Models\InvitationCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(array $data, ?UploadedFile $photo = null): array
    {
        $path = $photo?->store('photos', 'local');
        try {
            return DB::transaction(function () use ($data, $path) {
                $code = InvitationCode::where('code', $data['code_invitation'])->lockForUpdate()->first();
                if (! $code || ! $code->isActive) {
                    throw ValidationException::withMessages(['code_invitation' => 'Code invalide ou déjà utilisé.']);
                }

                $user = new User;
                $user->fill(collect($data)->only(['nom', 'prenom', 'email', 'telephone', 'password', 'isMineur'])->all());
                $user->email = mb_strtolower(trim($data['email']));
                $user->photo_path = $path ?: '';
                $user->role = User::ROLE_BENEVOLE;
                $user->statut_planning = User::PLANNING_BROUILLON;
                $user->invitationCode()->associate($code);
                $user->save();
                $code->isActive = false;
                $code->save();

                return ['user' => $user->refresh(), 'token' => $user->createToken('front')->plainTextToken];
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
    }

    public function login(array $data): array
    {
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Identifiants incorrects.']);
        }

        return ['user' => $user, 'token' => $user->createToken('front')->plainTextToken];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function createAdminForConsole(array $data): User
    {
        $user = new User(collect($data)->only(['nom', 'prenom', 'email', 'telephone', 'password'])->all());
        $user->role = User::ROLE_ADMIN;
        $user->statut_planning = User::PLANNING_BROUILLON;
        $user->isMineur = false;
        $user->photo_path = '';
        $user->save();

        return $user;
    }
}
