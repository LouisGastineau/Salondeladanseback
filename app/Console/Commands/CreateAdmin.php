<?php

namespace App\Console\Commands;

use App\Rules\BcryptPassword;
use App\Services\AuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'salon:admin {email}';

    protected $description = 'Créer un administrateur (mot de passe saisi sans affichage).';

    public function handle(AuthService $service): int
    {
        $data = [
            'email' => mb_strtolower(trim($this->argument('email'))),
            'nom' => $this->ask('Nom'),
            'prenom' => $this->ask('Prénom'),
            'telephone' => $this->ask('Téléphone'),
            'password' => $this->secret('Mot de passe (8 caractères minimum)'),
            'password_confirmation' => $this->secret('Confirmer le mot de passe'),
        ];
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:users,email', 'max:255'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'telephone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed', new BcryptPassword],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $service->createAdminForConsole($validator->validated());
        $this->info('Administrateur créé.');

        return self::SUCCESS;
    }
}
