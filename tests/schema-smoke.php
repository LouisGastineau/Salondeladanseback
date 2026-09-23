<?php

// Run with: php tests/schema-smoke.php
// Uses unique table names in the configured MariaDB database, never the live tables.
use App\Models\Creneau;
use App\Models\Edition;
use App\Models\InvitationCode;
use App\Models\Mission;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.connections.'.config('database.default'));
$connection['prefix'] = 't'.bin2hex(random_bytes(3)).'_';
config(['database.connections.schema_smoke' => $connection]);
DB::setDefaultConnection('schema_smoke');
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$mustReject = function (callable $operation) use ($check): void {
    try {
        $operation();
    } catch (QueryException $exception) {
        $check($exception->getCode() === '23000', 'Expected an integrity constraint violation.');
        return;
    }
    throw new RuntimeException('Expected database rejection.');
};

try {
    $check(Artisan::call('migrate', ['--database' => 'schema_smoke', '--force' => true]) === 0, 'Migrations failed.');
    foreach (['editions', 'missions', 'creneaux', 'invitation_codes', 'users', 'reservations', 'personal_access_tokens'] as $table) {
        $check(Schema::hasTable($table), 'Missing table '.$table);
    }
    $columns = Schema::getColumns('users');
    $id = array_values(array_filter($columns, fn ($column) => $column['name'] === 'id'))[0];
    $check($id['type_name'] === 'int', 'User primary key must be INT.');
    $check(! Schema::hasColumn('users', 'created_at'), 'Unexpected timestamps.');

    DB::beginTransaction();
    $edition = Edition::create(['nom' => 'Schema test', 'date_debut' => '2026-10-09', 'date_fin' => '2026-10-11', 'isActive' => true]);
    $mission = $edition->missions()->create(['nom' => 'Accueil', 'isSensible' => false]);
    $creneau = $mission->creneaux()->create(['jour' => '2026-10-09', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00', 'capacite_max' => 3]);
    $code = InvitationCode::create(['code' => 'SCHEMA-TEST']);
    $user = User::factory()->make();
    $user->invitationCode()->associate($code);
    $user->save();
    $user->refresh();
    $reservation = $user->reservations()->create(['creneau_id' => $creneau->id]);
    $reservation->refresh();

    $check($edition->creneaux->first()->is($creneau), 'Edition creneaux relation.');
    $check($mission->edition->is($edition), 'Mission edition relation.');
    $check($creneau->mission->is($mission), 'Creneau mission relation.');
    $check($creneau->reservations->first()->is($reservation), 'Creneau reservations relation.');
    $check($reservation->user->is($user) && $reservation->creneau->is($creneau), 'Reservation relations.');
    $check($code->user->is($user) && $user->invitationCode->is($code), 'Invitation relations.');
    $check($edition->isActive === true && $mission->isSensible === false && $user->isMineur === false, 'Boolean casts.');
    $check($code->refresh()->isActive === true && $code->created_at !== null, 'Invitation defaults.');
    $check($user->role === 'benevole' && $user->statut_planning === 'brouillon' && $reservation->statut === 'brouillon', 'Status defaults.');
    $check(Hash::check('test-password', $user->password), 'Password hashing.');
    $user->load('invitationCode');
    $serialized = $user->toArray();
    $check(! isset($serialized['password'], $serialized['invitation_code_id']) && ! array_key_exists('invitation_code', $serialized), 'User secret serialization.');
    $check(! array_key_exists('code', $code->toArray()), 'Invitation secret serialization.');
    $mustReject(fn () => User::factory()->create(['email' => $user->email]));
    $mustReject(fn () => User::factory()->create(['invitation_code_id' => $code->id]));
    $mustReject(fn () => InvitationCode::create(['code' => 'SCHEMA-TEST']));
    $mustReject(fn () => Reservation::create(['user_id' => $user->id, 'creneau_id' => $creneau->id]));
    $mustReject(fn () => $mission->delete());
    $token = $user->createToken('schema-test');
    $check($user->tokens()->count() === 1 && str_contains($token->plainTextToken, '|'), 'Sanctum token creation.');
    DB::rollBack();
    $check(User::count() === 0, 'Transaction rollback.');
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Only this run's uniquely prefixed migration history is rolled back.
    $result = Artisan::call('migrate:rollback', ['--database' => 'schema_smoke', '--force' => true]);
    if ($result !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    $check(! Schema::hasTable('users') && ! Schema::hasTable('reservations'), 'Migration rollback.');
    Schema::dropIfExists('migrations');
}

echo $checks." checks passed on isolated MariaDB tables; migration rollback completed.\n";
