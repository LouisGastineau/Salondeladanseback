<?php

// php tests/api-smoke.php — isolated MariaDB tables; no live account is created.
use App\Mail\PasswordRecovery;
use App\Mail\ReservationDecision;
use App\Mail\VolunteerInvitation;
use App\Models\Edition;
use App\Models\InvitationCode;
use App\Models\Reservation;
use App\Models\User;
use App\Services\InvitationCsvService;
use App\Services\InvitationService;
use App\Services\ReservationService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
// Configure the limiter before providers resolve it, so repeated runs never share counters.
$app->beforeBootstrapping(BootProviders::class,
    fn () => config(['cache.default' => 'array', 'cache.limiter' => 'array']));
$app->make(ConsoleKernel::class)->bootstrap();
$config = config('database.connections.'.config('database.default'));
$prefix = 't'.bin2hex(random_bytes(3)).'_';
$config['prefix'] = $prefix;
config(['database.connections.api_test' => $config, 'cache.default' => 'array']);
DB::setDefaultConnection('api_test');
$checks = 0;
$check = function (bool $ok, string $label) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks++;
};
$requestNumber = 0;
$api = function (string $method, string $uri, array $data = [], ?string $token = null, array $headers = [], array $files = []) use ($app, &$requestNumber): array {
    $app->make('auth')->forgetGuards();
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.'.(++$requestNumber % 250 + 1)];
    if ($token) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }
    $request = Request::create($uri, $method, [], [], $files, array_merge($server, $headers), json_encode($data));
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    if ($response instanceof StreamedResponse) {
        ob_start();
        $response->sendContent();
        $body = ob_get_clean();
    } else {
        $body = $response->getContent();
    }
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), json_decode($body, true), $body, $response->headers];
};
$expect = function (int $status, array $response, string $label) use ($check): array {
    $check($response[0] === $status, $label.': expected '.$status.', received '.$response[0].' '.substr($response[2], 0, 400));

    return $response;
};
$race = function (array $jobs) use ($prefix, $check): array {
    $workers = [];
    foreach ($jobs as $job) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/race-worker.php', $prefix, base64_encode(json_encode($job))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_timeout($pipes[1], 20);
        $check(trim(fgets($pipes[1]) ?: '') === 'READY', 'Worker ready');
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        fwrite($pipes[0], "GO\n");
        fclose($pipes[0]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = (int) trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $check(proc_close($process) === 0, 'Worker failure: '.$error);
    }
    sort($results);

    return $results;
};

try {
    $check(Artisan::call('migrate', ['--database' => 'api_test', '--force' => true]) === 0, 'Migrate');
    $edition = Edition::create(['nom' => 'Salon de la Danse - Démonstration', 'date_debut' => '2026-10-09', 'date_fin' => '2026-10-11', 'isActive' => true]);
    $mission = $edition->missions()->create(['nom' => 'Accueil et orientation', 'isSensible' => false]);
    $other = $edition->missions()->create(['nom' => 'Vestiaire', 'isSensible' => false]);
    $secret = $edition->missions()->create(['nom' => 'Billetterie confidentielle', 'isSensible' => true]);
    $slot = fn ($start, $end, $day = '2026-10-09', $capacity = 10, $m = null) => ($m ?? $mission)->creneaux()->create(['jour' => $day, 'heure_debut' => $start, 'heure_fin' => $end, 'capacite_max' => $capacity]);
    $a = $slot('09:00:00', '10:00:00');
    $b = $slot('10:00:00', '11:00:00');
    $c = $slot('11:00:00', '12:00:00');
    $pause = $slot('13:00:00', '14:00:00');
    $fourth = $slot('15:00:00', '16:00:00', '2026-10-10');
    $overlap = $slot('09:30:00', '10:30:00', m: $other);
    $same = $slot('09:00:00', '10:00:00', m: $other);
    $hidden = $slot('17:00:00', '18:00:00', m: $secret);
    $admin = User::factory()->create(['role' => 'admin']);
    // Exercise the migration against pre-existing records, not just empty tables.
    $legacyMigration = require __DIR__.'/../database/migrations/2026_09_24_000003_add_reservation_admin_validation.php';
    $legacyMigration->down();
    $legacySensitive = Reservation::create(['user_id' => $admin->id, 'creneau_id' => $hidden->id]);
    $legacyNormal = Reservation::create(['user_id' => $admin->id, 'creneau_id' => $a->id]);
    $legacyMigration->up();
    $check($legacySensitive->fresh()->validation_admin === 'acceptee', 'Migration preserves old admin approvals');
    $check($legacyNormal->fresh()->validation_admin === null, 'Migration leaves normal reservations untouched');
    $legacySensitive->delete();
    $legacyNormal->delete();
    $adminToken = $admin->createToken('test')->plainTextToken;
    $payload = ['nom' => 'Bénévole', 'prenom' => 'Élodie', 'email' => 'elodie@example.test', 'telephone' => '0600000000', 'password' => 'test-password', 'password_confirmation' => 'test-password', 'isMineur' => false];

    $expect(401, $api('GET', '/api/me', headers: ['HTTP_ACCEPT' => '*/*']), 'Unauthenticated JSON');
    $expect(422, $api('POST', '/api/register', $payload), 'Invitation required');
    $expect(422, $api('POST', '/api/register', $payload + ['code_invitation' => 'INVALID']), 'Invalid invitation');
    $codes = $expect(201, $api('POST', '/api/admin/invitation-codes', ['nombre' => 2], $adminToken), 'Generate invitations')[1]['data'];
    $payload['code_invitation'] = $codes[0]['code'];
    $longPassword = str_repeat('é', 40);
    $expect(422, $api('POST', '/api/register', array_replace($payload, ['password' => $longPassword, 'password_confirmation' => $longPassword])), 'Bcrypt byte limit');
    $expect(422, $api('POST', '/api/login', ['email' => $payload['email'], 'password' => "invalid\0password"]), 'Bcrypt null byte rejected');
    $expect(422, $api('POST', '/api/register', $payload + ['role' => 'admin']), 'Privilege escalation rejected');
    $registered = $expect(201, $api('POST', '/api/register', $payload), 'Register')[1]['data'];
    $token = $registered['token'];
    $user = User::findOrFail($registered['user']['id']);
    $check(! InvitationCode::find($codes[0]['id'])->isActive && $user->invitation_code_id === $codes[0]['id'], 'Consume invitation');
    $check(Hash::check('test-password', $user->password), 'Hash password');
    $reuse = array_replace($payload, ['email' => 'reuse@example.test']);
    $expect(422, $api('POST', '/api/register', $reuse), 'Cannot reuse invitation');
    $duplicate = array_replace($payload, ['email' => 'ELODIE@example.test', 'code_invitation' => $codes[1]['code']]);
    $expect(422, $api('POST', '/api/register', $duplicate), 'Unique normalized email');
    $check(InvitationCode::find($codes[1]['id'])->isActive, 'Rejected registration preserves invitation');
    $expect(422, $api('POST', '/api/login', ['email' => $payload['email'], 'password' => 'incorrect']), 'Invalid login');
    $login = $expect(200, $api('POST', '/api/login', ['email' => $payload['email'], 'password' => 'test-password']), 'Login')[1]['data']['token'];
    $me = $expect(200, $api('GET', '/api/me', token: $token), 'Me');
    foreach (['password', 'invitation_code_id', 'code_invitation', 'photo_path'] as $key) {
        $check(! array_key_exists($key, $me[1]['data']), 'Hidden '.$key);
    }
    $expect(405, $api('PATCH', '/api/me', ['nom' => 'Changed'], $token), 'No volunteer profile mutation');
    $expect(403, $api('PATCH', '/api/admin/users/'.$user->id, ['nom' => 'Changed'], $token), 'Admin update protected');
    foreach (['/api/admin/users', '/api/admin/export', '/api/admin/creneaux', '/api/admin/users/'.$user->id.'/planning'] as $uri) {
        $expect(403, $api('GET', $uri, token: $token), 'Admin protected '.$uri);
    }
    $expect(403, $api('POST', '/api/admin/reservations', ['user_id' => $user->id, 'creneau_id' => $hidden->id], $token), 'Manual assignment protected');
    $expect(403, $api('POST', '/api/admin/invitation-codes', ['nombre' => 1], $token), 'Codes protected');
    $expect(403, $api('POST', '/api/admin/users/'.$user->id.'/planning/deverrouiller', token: $token), 'Unlock protected');
    $roleTarget = User::factory()->create(['role' => User::ROLE_BENEVOLE]);
    $expect(403, $api('PATCH', '/api/admin/users/'.$roleTarget->id.'/role', ['role' => 'admin'], $token), 'Role change protected');
    $expect(422, $api('PATCH', '/api/admin/users/'.$roleTarget->id.'/role', ['role' => 'superadmin'], $adminToken), 'Invalid role rejected');
    $promoted = $expect(200, $api('PATCH', '/api/admin/users/'.$roleTarget->id.'/role', ['role' => 'admin'], $adminToken), 'Promote user')[1]['data'];
    $check($promoted['role'] === User::ROLE_ADMIN && $roleTarget->fresh()->role === User::ROLE_ADMIN, 'Role promotion persisted');
    $roleTargetToken = $roleTarget->fresh()->createToken('role-test')->plainTextToken;
    $expect(200, $api('GET', '/api/admin/users', token: $roleTargetToken), 'Promoted admin access');
    $expect(200, $api('PATCH', '/api/admin/users/'.$roleTarget->id.'/role', ['role' => 'benevole'], $adminToken), 'Demote user');
    $check($roleTarget->fresh()->role === User::ROLE_BENEVOLE && $roleTarget->tokens()->count() === 0, 'Demotion revokes tokens');
    $expect(401, $api('GET', '/api/admin/users', token: $roleTargetToken), 'Demoted token revoked');
    $expect(409, $api('PATCH', '/api/admin/users/'.$admin->id.'/role', ['role' => 'benevole'], $adminToken), 'Cannot demote self');
    $expect(409, $api('POST', '/api/planning/valider', token: $token), 'Minimum one');
    $publicSlots = $expect(200, $api('GET', '/api/creneaux', token: $token), 'Public slots');
    $check(str_contains($publicSlots[2], 'Billetterie'), 'Sensitive mission visible');
    $check(! str_contains($publicSlots[2], $admin->nom) && ! str_contains($publicSlots[2], 'email'), 'No other identity on slots');
    $check($publicSlots[1]['data'][0]['places_restantes'] === 10, 'Available places');
    $temporary = $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $hidden->id], $token), 'Sensitive request permitted')[1]['data'];
    $check($temporary['validation_admin'] === 'en_attente', 'Sensitive request pending');
    $expect(204, $api('DELETE', '/api/reservations/'.$temporary['id'], token: $token), 'Cancel draft pending request');
    $expect(422, $api('POST', '/api/reservations', ['creneau_id' => $a->id, 'user_id' => $admin->id], $token), 'Cannot assign other user');
    $r1 = $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $a->id], $token), 'First booking')[1]['data']['id'];
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $a->id], $token), 'Duplicate slot');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $same->id], $token), 'Identical times across missions');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $overlap->id], $token), 'Partial overlap');
    $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $b->id], $token), 'Two consecutive allowed');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $c->id], $token), 'Three consecutive rejected');
    $r3 = $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $pause->id], $token), 'Pause allowed')[1]['data']['id'];
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $fourth->id], $token), 'Three across weekend maximum');
    $expect(200, $api('POST', '/api/planning/valider', token: $token), 'Validate');
    $check($user->fresh()->statut_planning === 'valide' && $user->reservations()->where('statut', 'brouillon')->count() === 0, 'Atomic validation statuses');
    $expect(409, $api('DELETE', '/api/reservations/'.$r1, token: $token), 'Locked deletion');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $fourth->id], $token), 'Locked addition');
    $expect(204, $api('DELETE', '/api/admin/reservations/'.$r3, token: $adminToken), 'Admin override deletion');
    $hiddenReservation = $expect(201, $api('POST', '/api/admin/reservations', ['user_id' => $user->id, 'creneau_id' => $hidden->id], $adminToken), 'Manual sensitive assignment')[1]['data']['id'];
    $check(Reservation::find($hiddenReservation)->statut === 'valide', 'Admin assignment status coherent');
    $planning = $expect(200, $api('GET', '/api/planning', token: $token), 'Own planning');
    $check(count($planning[1]['data']) === 3 && str_contains($planning[2], 'Billetterie'), 'Sensitive own booking visible');
    $adminPlanning = $expect(200, $api('GET', '/api/admin/users/'.$user->id.'/planning', token: $adminToken), 'Admin planning');
    $check(count($adminPlanning[1]['data']) === 3 && str_contains($adminPlanning[2], 'Billetterie'), 'Admin sees sensitive');
    $expect(409, $api('DELETE', '/api/reservations/'.$hiddenReservation, token: $token), 'Accepted reservation locked');
    $check(Reservation::find($hiddenReservation)->validation_admin === 'acceptee', 'Admin assignment accepted');
    $pdf = $expect(200, $api('GET', '/api/planning/pdf', token: $token), 'PDF');
    $check(str_starts_with($pdf[2], '%PDF-') && $pdf[3]->get('Content-Type') === 'application/pdf', 'Valid PDF response');
    file_put_contents(__DIR__.'/planning-test.pdf', $pdf[2]);
    $expect(200, $api('PATCH', '/api/admin/users/'.$user->id, ['nom' => '=1+1', 'telephone' => '+33600000000'], $adminToken), 'Admin personal edit');
    $expect(422, $api('PATCH', '/api/admin/users/'.$user->id, ['email' => $admin->email], $adminToken), 'Admin duplicate email');
    $expect(422, $api('PATCH', '/api/admin/users/'.$user->id, ['statut_planning' => 'brouillon'], $adminToken), 'Status requires service endpoint');
    $filtered = $expect(200, $api('GET', '/api/admin/users?q=elodie&role=benevole&statut_planning=valide&isMineur=0', token: $adminToken), 'User filters');
    $check($filtered[1]['meta']['total'] === 1, 'Filter result');
    $csv = $expect(200, $api('GET', '/api/admin/export', token: $adminToken), 'CSV');
    $check(str_contains($csv[2], "'=1+1") && str_contains($csv[2], "'+33600000000") && str_contains($csv[2], 'Billetterie'), 'CSV formula protection and admin scope');
    $expect(200, $api('POST', '/api/admin/users/'.$user->id.'/planning/deverrouiller', token: $adminToken), 'Unlock');
    $check($user->fresh()->statut_planning === 'brouillon' && $user->reservations()->where('statut', 'valide')->count() === 0, 'Atomic unlock');
    $expect(204, $api('DELETE', '/api/reservations/'.$r1, token: $token), 'Delete unlocked');
    $stranger = User::factory()->create();
    $strangerToken = $stranger->createToken('test')->plainTextToken;
    $expect(404, $api('DELETE', '/api/reservations/'.$hiddenReservation, token: $strangerToken), 'Ownership privacy');
    $capacity = $slot('08:00:00', '09:00:00', '2026-10-10', 1);
    $competitor = User::factory()->create();
    $check($race([['mode' => 'reserve', 'user' => $stranger->id, 'slot' => $capacity->id], ['mode' => 'reserve', 'user' => $competitor->id, 'slot' => $capacity->id]]) === [201, 409], 'Concurrent last place');
    $check(Reservation::where('creneau_id', $capacity->id)->count() === 1, 'No capacity overflow');
    $quotaUser = User::factory()->create();
    app(ReservationService::class)->create($quotaUser, $a->id);
    app(ReservationService::class)->create($quotaUser, $b->id);
    $check($race([['mode' => 'reserve', 'user' => $quotaUser->id, 'slot' => $pause->id], ['mode' => 'reserve', 'user' => $quotaUser->id, 'slot' => $fourth->id]]) === [201, 409], 'Concurrent user quota');
    $overlapUser = User::factory()->create();
    $check($race([['mode' => 'reserve', 'user' => $overlapUser->id, 'slot' => $a->id], ['mode' => 'reserve', 'user' => $overlapUser->id, 'slot' => $same->id]]) === [201, 409], 'Concurrent overlapping missions');
    $raceCode = InvitationCode::create(['code' => 'RACE-CODE']);
    $check($race([['mode' => 'register', 'data' => array_replace($payload, ['email' => 'race1@example.test', 'code_invitation' => $raceCode->code])], ['mode' => 'register', 'data' => array_replace($payload, ['email' => 'race2@example.test', 'code_invitation' => $raceCode->code])]]) === [201, 422], 'Concurrent invitation consumption');
    $check(User::where('invitation_code_id', $raceCode->id)->count() === 1, 'One code one user');
    $cors = $expect(204, $api('OPTIONS', '/api/reservations', headers: ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type']), 'CORS preflight');
    $check($cors[3]->get('Access-Control-Allow-Origin') === 'http://localhost:5173', 'Vite origin allowed');
    $badCors = $api('OPTIONS', '/api/reservations', headers: ['HTTP_ORIGIN' => 'https://untrusted.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
    $check($badCors[3]->get('Access-Control-Allow-Origin') !== 'https://untrusted.example', 'Other origin excluded');
    $expect(204, $api('POST', '/api/logout', token: $login), 'Logout');
    $expect(401, $api('GET', '/api/me', token: $login), 'Token revoked');
    $expect(200, $api('GET', '/api/me', token: $token), 'Other token preserved');
    $expect(422, $api('GET', '/api/creneaux?jour=invalid&per_page=999', token: $token), 'Input validation');
    $photo = UploadedFile::fake()->image('portrait.png', 40, 40);
    $expect(200, $api('PATCH', '/api/admin/users/'.$user->id, token: $adminToken, files: ['photo' => $photo]), 'Admin photo upload');
    $photoPath = $user->fresh()->photo_path;
    $expect(200, $api('GET', '/api/me/photo', token: $token), 'Own private photo');
    $expect(403, $api('GET', '/api/admin/users/'.$user->id.'/photo', token: $strangerToken), 'Private photo forbidden');
    $expect(200, $api('GET', '/api/admin/users/'.$user->id.'/photo', token: $adminToken), 'Admin private photo');
    Storage::disk('local')->delete($photoPath);
    $winner = Reservation::where('creneau_id', $capacity->id)->firstOrFail();
    $loser = $winner->user_id === $stranger->id ? $competitor : $stranger;
    $expect(409, $api('POST', '/api/admin/reservations', ['user_id' => $loser->id, 'creneau_id' => $capacity->id], $adminToken), 'Admin cannot overfill');
    $expect(204, $api('DELETE', '/api/admin/reservations/'.$winner->id, token: $adminToken), 'Release capacity');
    $newReservation = $expect(201, $api('POST', '/api/admin/reservations', ['user_id' => $loser->id, 'creneau_id' => $capacity->id], $adminToken), 'Released place reusable')[1]['data']['id'];
    $expect(200, $api('POST', '/api/admin/users/'.$loser->id.'/planning/valider', token: $adminToken), 'Admin validates');
    $expect(409, $api('DELETE', '/api/admin/reservations/'.$newReservation, token: $adminToken), 'Cannot empty validated planning');
    $emailCodes = [InvitationCode::create(['code' => 'RACE-EMAIL-A']), InvitationCode::create(['code' => 'RACE-EMAIL-B'])];
    $check($race(array_map(fn ($code) => ['mode' => 'register', 'data' => array_replace($payload, ['email' => 'unique-race@example.test', 'code_invitation' => $code->code])], $emailCodes)) === [201, 422], 'Concurrent unique email');
    $check(InvitationCode::whereIn('id', array_map(fn ($code) => $code->id, $emailCodes))->where('isActive', true)->count() === 1, 'Losing email race keeps invitation');
    $inactive = Edition::create(['nom' => 'Inactive', 'date_debut' => '2026-10-09', 'date_fin' => '2026-10-11', 'isActive' => false]);
    $inactiveMission = $inactive->missions()->create(['nom' => 'Inactive mission', 'isSensible' => false]);
    $inactiveSlot = $slot('19:00:00', '20:00:00', m: $inactiveMission);
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $inactiveSlot->id], $strangerToken), 'Inactive edition rejected');
    $edition->update(['isActive' => false]);
    $expect(409, $api('GET', '/api/creneaux', token: $token), 'No active edition');
    $edition->update(['isActive' => true]);
    foreach (['/api/admin/users/'.$user->id, '/api/admin/plannings', '/api/admin/creneaux/'.$hidden->id.'/inscrits'] as $uri) {
        $expect(401, $api('GET', $uri), 'Anonymous denied '.$uri);
        $expect(403, $api('GET', $uri, token: $token), 'Volunteer denied '.$uri);
    }
    $detail = $expect(200, $api('GET', '/api/admin/users/'.$user->id, token: $adminToken), 'Admin user detail');
    $check($detail[1]['data']['id'] === $user->id && $detail[1]['data']['email'] === $user->fresh()->email, 'Correct user detail');
    $check(! str_contains($detail[2], 'password') && ! str_contains($detail[2], 'invitation_code_id'), 'User detail excludes secrets');
    $expect(404, $api('GET', '/api/admin/users/999999999', token: $adminToken), 'Missing user');
    $expect(404, $api('GET', '/api/admin/creneaux/999999999/inscrits', token: $adminToken), 'Missing slot');
    $expect(404, $api('GET', '/api/admin/plannings?edition_id=999999999', token: $adminToken), 'Missing edition');
    $expect(422, $api('GET', '/api/admin/plannings?edition_id=oops', token: $adminToken), 'Invalid edition filter');

    $inactiveBooking = Reservation::create(['user_id' => $user->id, 'creneau_id' => $inactiveSlot->id]);
    $emptyUser = User::factory()->create();
    // More than a page of users, with several populated plans: catch truncation and N+1 queries.
    $bulkUsers = User::factory()->count(105)->create(['password' => Hash::make('test-password')]);
    $bulkSlot = $slot('20:00:00', '21:00:00', capacity: 20);
    foreach ($bulkUsers->take(10) as $bulkUser) {
        Reservation::create(['user_id' => $bulkUser->id, 'creneau_id' => $bulkSlot->id]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $bulk = $expect(200, $api('GET', '/api/admin/plannings', token: $adminToken), 'Bulk admin plannings');
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    $check($queryCount <= 12, 'Bulk plannings use a bounded number of queries, got '.$queryCount);
    $check(count($bulk[1]['data']) === User::count(), 'All users returned without pagination');
    $check($bulk[1]['meta']['edition_id'] === $edition->id, 'Active edition identified');
    $check(collect($bulk[1]['data'])->firstWhere('id', $emptyUser->id)['reservations'] === [], 'Empty plans retained');
    $mine = collect($bulk[1]['data'])->firstWhere('id', $user->id);
    $check(in_array($hiddenReservation, array_column($mine['reservations'], 'id')), 'Bulk includes sensitive admin assignment');
    $check(! in_array($inactiveBooking->id, array_column($mine['reservations'], 'id')), 'Bulk excludes other editions');
    $check(! str_contains($bulk[2], 'password') && ! str_contains($bulk[2], 'invitation_code_id'), 'Bulk excludes secrets');
    $filteredBulk = $expect(200, $api('GET', '/api/admin/plannings?q='.rawurlencode($user->fresh()->email).'&role=benevole', token: $adminToken), 'Bulk filters');
    $check(count($filteredBulk[1]['data']) === 1, 'Bulk search result');

    $participants = $expect(200, $api('GET', '/api/admin/creneaux/'.$hidden->id.'/inscrits', token: $adminToken), 'Sensitive slot participants');
    $check(count($participants[1]['data']) === 1 && $participants[1]['data'][0]['user']['id'] === $user->id, 'Correct participant identity');
    $check($participants[1]['meta']['creneau']['places_restantes'] === $hidden->capacite_max - 1, 'Participant count consistent with capacity');
    $check(! str_contains($participants[2], 'password') && ! str_contains($participants[2], 'invitation_code_id'), 'Participants exclude secrets');
    $emptyParticipants = $expect(200, $api('GET', '/api/admin/creneaux/'.$inactiveSlot->id.'/inscrits', token: $adminToken), 'Inactive edition slot accessible to admin');
    $check(count($emptyParticipants[1]['data']) === 1, 'Inactive slot participant returned');
    $edition->update(['isActive' => false]);
    $expect(409, $api('GET', '/api/admin/plannings', token: $adminToken), 'Bulk requires active edition by default');
    $history = $expect(200, $api('GET', '/api/admin/plannings?edition_id='.$inactive->id, token: $adminToken), 'Explicit inactive edition planning');
    $historicalUser = collect($history[1]['data'])->firstWhere('id', $user->id);
    $check(array_column($historicalUser['reservations'], 'id') === [$inactiveBooking->id], 'Historical edition does not mix reservations');
    $edition->update(['isActive' => true]);

    $tooLarge = UploadedFile::fake()->image('oversize.jpg')->size(2049);
    $photoFailure = $expect(422, $api('PATCH', '/api/admin/users/'.$user->id, token: $adminToken, files: ['photo' => $tooLarge]), 'Photos above 2 MiB rejected');
    $check(isset($photoFailure[1]['errors']['photo']), 'Photo size validation identifies field');
    for ($i = 0; $i < 5; $i++) {
        $expect(422, $api('POST', '/api/login', ['email' => 'throttle@example.test', 'password' => 'wrong'], headers: ['REMOTE_ADDR' => '127.0.9.1']), 'Failed login throttling');
    }
    $expect(429, $api('POST', '/api/login', ['email' => 'throttle@example.test', 'password' => 'wrong'], headers: ['REMOTE_ADDR' => '127.0.9.1']), 'Login rate limited');

    $expect(401, $api('POST', '/api/admin/invitations', ['email' => 'invite@example.test']), 'Anonymous cannot send invitations');
    $expect(403, $api('POST', '/api/admin/invitations', ['email' => 'invite@example.test'], $token), 'Volunteer cannot send invitations');
    $expect(422, $api('POST', '/api/admin/invitations', ['email' => 'invalid'], $adminToken), 'Invitation email validation');
    $expect(422, $api('POST', '/api/admin/invitations', ['email' => $user->fresh()->email], $adminToken), 'Existing account cannot be invited');
    $originalMailer = config('mail.default');
    $originalMailManager = Mail::getFacadeRoot();
    try {
        config(['mail.default' => 'log']);
        $before = InvitationCode::count();
        $expect(503, $api('POST', '/api/admin/invitations', ['email' => 'invite@example.test'], $adminToken), 'Logging is not delivery');
        $check(InvitationCode::count() === $before, 'No code generated without a delivery transport');

        config(['mail.default' => 'smtp']);
        Mail::fake();
        $sent = $expect(201, $api('POST', '/api/admin/invitations', ['email' => ' INVITE@example.test '], $adminToken), 'Send invitation')[1]['data'];
        Mail::assertSent(VolunteerInvitation::class, fn ($mail) => $mail->hasTo('invite@example.test') && $mail->invitationCode === $sent['code']);
        $check(InvitationCode::findOrFail($sent['id'])->isActive, 'Sent code activated');
        $check(str_contains((new VolunteerInvitation($sent['code']))->render(), $sent['code']), 'Email contains generated code');
        $expect(201, $api('POST', '/api/register', array_replace($payload, ['email' => 'invite@example.test', 'code_invitation' => $sent['code']])), 'Emailed code permits registration');
        $check(! InvitationCode::findOrFail($sent['id'])->isActive, 'Emailed code is consumed once');

        Mail::swap($originalMailManager);
        Mail::shouldReceive('to')->once()->with('failure@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('secret transport details'));
        $failure = $expect(503, $api('POST', '/api/admin/invitations', ['email' => 'failure@example.test'], $adminToken), 'Mail transport failure');
        $check(! str_contains($failure[2], 'secret transport details'), 'No transport details exposed');
        $check(! InvitationCode::orderByDesc('id')->firstOrFail()->isActive, 'Failed delivery code is unusable');
        $expect(422, $api('POST', '/api/register', array_replace($payload, ['email' => 'failure@example.test', 'code_invitation' => InvitationCode::orderByDesc('id')->firstOrFail()->code])), 'Failed delivery cannot unlock registration');
        Mail::swap($originalMailManager);
        Mail::fake();
        $csvFile = fn ($text) => UploadedFile::fake()->createWithContent('invitations.csv', $text);
        $csvText = "\xEF\xBB\xBFnom;email\nAlice; CSV-A@example.test \nDup;csv-a@example.test\nBad;not-an-email\nMember;".$user->fresh()->email."\nBob;csv-b@example.test\n";
        $expect(401, $api('POST', '/api/admin/invitations/import', files: ['file' => $csvFile($csvText)]), 'CSV requires authentication');
        $expect(403, $api('POST', '/api/admin/invitations/import', token: $token, files: ['file' => $csvFile($csvText)]), 'CSV admin only');
        $expect(422, $api('POST', '/api/admin/invitations/import', token: $adminToken), 'CSV required');
        $before = InvitationCode::count();
        $csv = $expect(200, $api('POST', '/api/admin/invitations/import', ['limit' => 4], $adminToken, files: ['file' => $csvFile($csvText)]), 'CSV first chunk')[1];
        $check(array_column($csv['data'], 'statut') === ['envoye', 'doublon', 'invalide', 'deja_inscrit'], 'CSV reports individual outcomes');
        $check($csv['meta']['total'] === 5 && $csv['meta']['next_offset'] === 4, 'CSV continuation offset');
        $check(InvitationCode::count() === $before + 1, 'Only eligible addresses generate codes');
        Mail::assertSent(VolunteerInvitation::class, 1);
        $again = $expect(200, $api('POST', '/api/admin/invitations/import', ['limit' => 4], $adminToken, files: ['file' => $csvFile($csvText)]), 'Repeated CSV')[1];
        $check($again['data'][0]['statut'] === 'deja_invite', 'Repeated CSV does not resend');
        Mail::assertSent(VolunteerInvitation::class, 1);
        $last = $expect(200, $api('POST', '/api/admin/invitations/import', ['offset' => 4], $adminToken, files: ['file' => $csvFile($csvText)]), 'CSV next chunk')[1];
        $check($last['meta']['next_offset'] === null && $last['data'][0]['statut'] === 'envoye', 'CSV completed');
        Mail::assertSent(VolunteerInvitation::class, 2);
        $record = InvitationCode::where('email', 'csv-a@example.test')->firstOrFail();
        $reused = app(InvitationService::class)->send($admin, 'CSV-A@example.test');
        $check($reused->id === $record->id && $reused->envoye_at !== null, 'Individual send reuses delivered invitation');
        Mail::assertSent(VolunteerInvitation::class, 2);
        $listing = $expect(200, $api('GET', '/api/admin/invitations?email=csv-a%40example.test&statut_envoi=envoye', token: $adminToken), 'Invitation listing')[1];
        $check($listing['meta']['total'] === 1 && $listing['data'][0]['email'] === 'csv-a@example.test', 'Invitation listing filters');
        $expect(403, $api('GET', '/api/admin/invitations', token: $token), 'Invitation identities private');

        $csvService = app(InvitationCsvService::class);
        foreach (["email\n", "name,address\nX,y@example.test\n", "email\n\xFF@example.test", "email\n".str_repeat("x@example.test\n", 201)] as $invalidCsv) {
            try {
                $csvService->import($admin, $csvFile($invalidCsv), 0, 20);
                throw new RuntimeException('Invalid CSV was accepted');
            } catch (ValidationException $expected) {
                $check(isset($expected->errors()['file']), 'Invalid CSV rejected before sending');
            }
        }
        Mail::assertSent(VolunteerInvitation::class, 2);
        $plain = $csvService->import($admin, $csvFile("csv-c@example.test\n\ncsv-c@example.test\n"), 0, 20);
        $check(array_column($plain['rows'], 'statut') === ['envoye', 'doublon'], 'Single column CSV without header');
        $quoted = $csvService->import($admin, $csvFile("nom,email\n\"Name, with comma\",csv-d@example.test\n"), 0, 20);
        $check($quoted['rows'][0]['statut'] === 'envoye', 'Quoted CSV fields');
        InvitationCode::create(['code' => 'CSV-PENDING', 'email' => 'pending@example.test', 'statut_envoi' => 'en_cours', 'isActive' => false]);
        $pending = $csvService->import($admin, $csvFile("email\npending@example.test\n"), 0, 20);
        $check($pending['rows'][0]['statut'] === 'en_cours', 'Pending invitation is not sent twice');

        Mail::swap($originalMailManager);
        Mail::shouldReceive('to')->once()->with('csv-failure@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP secret'));
        $partial = $csvService->import($admin, $csvFile("email\ncsv-failure@example.test\ninvalid\n"), 0, 20);
        $check(array_column($partial['rows'], 'statut') === ['echec', 'invalide'], 'Partial failure returns report');
        $failed = InvitationCode::where('email', 'csv-failure@example.test')->firstOrFail();
        $check(! $failed->isActive && $failed->statut_envoi === 'echec', 'Failed CSV invitation remains inactive');
        Mail::swap($originalMailManager);
        Mail::fake();
        $retry = $csvService->import($admin, $csvFile("email\ncsv-failure@example.test\n"), 0, 20);
        $check($retry['rows'][0]['statut'] === 'envoye' && $failed->fresh()->isActive, 'Failed invitation can be retried');
        $check(InvitationCode::where('email', 'csv-failure@example.test')->count() === 1, 'Retry reuses a single code');
        $invitationRace = $race([
            ['mode' => 'invitation', 'user' => $admin->id, 'email' => 'csv-race@example.test'],
            ['mode' => 'invitation', 'user' => $admin->id, 'email' => 'csv-race@example.test'],
        ]);
        $check(in_array($invitationRace, [[200, 201], [201, 409]], true), 'Concurrent invitations send once');
        $check(InvitationCode::where('email', 'csv-race@example.test')->count() === 1, 'Concurrent invitations share a single code');
    } finally {
        Mail::swap($originalMailManager);
        config(['mail.default' => $originalMailer]);
    }
    $validationUser = User::factory()->create();
    $validationToken = $validationUser->createToken('validation-test')->plainTextToken;
    $reviewSlot = $slot('06:00:00', '07:00:00', capacity: 1, m: $secret);
    $pending = $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $reviewSlot->id], $validationToken), 'Request sensitive slot')[1]['data'];
    $check($pending['validation_admin'] === 'en_attente' && $pending['creneau']['mission']['isSensible'] === true, 'Pending approval and sensitive marker');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $reviewSlot->id], $strangerToken), 'Pending occupies last place');
    $sensitiveOverlap = $slot('06:30:00', '07:30:00');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $sensitiveOverlap->id], $validationToken), 'Pending prevents overlap');
    $normal = $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $a->id], $validationToken), 'Normal booking')[1]['data'];
    $check($normal['validation_admin'] === null, 'Normal booking requires no approval');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $same->id], $validationToken), 'Overlap still rejected');
    $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $pause->id], $validationToken), 'Third with pending');
    $expect(409, $api('POST', '/api/reservations', ['creneau_id' => $fourth->id], $validationToken), 'Pending counts for quota');
    $expect(200, $api('POST', '/api/planning/valider', token: $validationToken), 'Validate pending planning');
    $check(Reservation::find($pending['id'])->validation_admin === 'en_attente', 'Planning does not accept request');
    $expect(403, $api('GET', '/api/admin/validations', token: $validationToken), 'Validation list admin only');
    $expect(403, $api('PATCH', '/api/admin/reservations/'.$pending['id'].'/validation', ['decision' => 'acceptee'], $validationToken), 'Decision admin only');
    $expect(422, $api('GET', '/api/admin/validations?statut=invalid', token: $adminToken), 'Invalid validation filter');
    $list = $expect(200, $api('GET', '/api/admin/validations', token: $adminToken), 'Pending queue')[1]['data'];
    $entry = collect($list)->firstWhere('id', $pending['id']);
    $check($entry['user']['id'] === $validationUser->id && $entry['creneau']['places_restantes'] === 0 && $entry['created_at'] !== null, 'Queue identity capacity timestamp');
    $bulkReview = $expect(200, $api('GET', '/api/admin/plannings?q='.rawurlencode($validationUser->email), token: $adminToken), 'Pending counter')[1]['data'][0];
    $check($bulkReview['demandes_en_attente'] === 1, 'Pending count per user');
    $expect(422, $api('PATCH', '/api/admin/reservations/'.$normal['id'].'/validation', ['decision' => 'acceptee'], $adminToken), 'Normal reservation cannot be reviewed');
    $oldDecisionMailer = config('mail.default');
    $decisionMailManager = Mail::getFacadeRoot();
    config(['mail.default' => 'smtp']);
    Mail::fake();
    try {
        $accepted = $expect(200, $api('PATCH', '/api/admin/reservations/'.$pending['id'].'/validation', ['decision' => 'acceptee'], $adminToken), 'Accept pending')[1]['data'];
        $check($accepted['validation_admin'] === 'acceptee' && $accepted['statut'] === 'valide', 'Independent approval');
        Mail::assertSent(ReservationDecision::class, 1);
        $expect(422, $api('PATCH', '/api/admin/reservations/'.$pending['id'].'/validation', ['decision' => 'refusee'], $adminToken), 'Processed request rejected');
        $expect(409, $api('DELETE', '/api/reservations/'.$pending['id'], token: $validationToken), 'Accepted validated request locked');
        $expect(200, $api('POST', '/api/admin/users/'.$validationUser->id.'/planning/deverrouiller', token: $adminToken), 'Unlock for next scenario');
        $expect(204, $api('DELETE', '/api/reservations/'.$pending['id'], token: $validationToken), 'Delete unlocked accepted');
        $pending = $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $reviewSlot->id], $validationToken), 'Request again')[1]['data'];
        $expect(200, $api('POST', '/api/planning/valider', token: $validationToken), 'Revalidate pending');
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Simulated SMTP failure'));
        $expect(200, $api('PATCH', '/api/admin/reservations/'.$pending['id'].'/validation', ['decision' => 'refusee'], $adminToken), 'Refuse despite email failure');
        $check(! Reservation::find($pending['id']) && $reviewSlot->reservations()->count() === 0, 'Refusal frees place');
        $check($validationUser->fresh()->statut_planning === 'brouillon', 'Refusal reopens planning');
        $pending = $expect(201, $api('POST', '/api/reservations', ['creneau_id' => $reviewSlot->id], $validationToken), 'Replacement allowed after refusal')[1]['data'];
        $expect(200, $api('POST', '/api/planning/valider', token: $validationToken), 'Validate before cancellation');
        $expect(204, $api('DELETE', '/api/reservations/'.$pending['id'], token: $validationToken), 'Cancel pending despite validated planning');
        $check($validationUser->fresh()->statut_planning === 'brouillon', 'Cancellation reopens planning');
    } finally {
        Mail::swap($decisionMailManager);
        config(['mail.default' => $oldDecisionMailer]);
    }
    $validationUser->reservations()->delete();
    $raceVolunteer = User::factory()->create();
    $raceOther = User::factory()->create();
    $check($race([['mode' => 'reserve', 'user' => $raceVolunteer->id, 'slot' => $reviewSlot->id], ['mode' => 'reserve', 'user' => $raceOther->id, 'slot' => $reviewSlot->id]]) === [201, 409], 'Concurrent sensitive last place');
    $racePending = $reviewSlot->reservations()->firstOrFail();
    $check($racePending->validation_admin === 'en_attente', 'Concurrent winner pending');
    $check($race([['mode' => 'validation', 'user' => $admin->id, 'reservation' => $racePending->id], ['mode' => 'validation', 'user' => $admin->id, 'reservation' => $racePending->id]]) === [201, 422], 'Concurrent decision only once');
    $racePending->delete();
    $resetUser = User::factory()->create();
    $resetSession = $resetUser->createToken('reset-test')->plainTextToken;
    $mailManager = Mail::getFacadeRoot();
    $oldMailer = config('mail.default');
    config(['mail.default' => 'smtp']);
    Mail::fake();
    try {
        $unknown = $expect(200, $api('POST', '/api/forgot-password', ['email' => 'absent@example.test']), 'Unknown email generic response');
        $known = $expect(200, $api('POST', '/api/forgot-password', ['email' => $resetUser->email]), 'Password recovery');
        $check($unknown[1] === $known[1], 'No account existence disclosure');
        $mail = Mail::sent(PasswordRecovery::class)->first();
        $check($mail !== null && str_contains($mail->render(), $mail->token), 'Recovery email rendered');
        $resetData = ['email' => $resetUser->email, 'token' => $mail->token, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];
        $expect(422, $api('POST', '/api/reset-password', array_replace($resetData, ['token' => 'wrong'])), 'Invalid recovery token');
        $expect(200, $api('POST', '/api/reset-password', $resetData), 'Reset password');
        $check(Hash::check('new-password-123', $resetUser->fresh()->password), 'New password hashed');
        $expect(401, $api('GET', '/api/me', token: $resetSession), 'Reset revokes sessions');
        $expect(422, $api('POST', '/api/reset-password', $resetData), 'Recovery token single use');
        $expired = Password::createToken($resetUser);
        DB::table('password_reset_tokens')->where('email', $resetUser->email)->update(['created_at' => now()->subMinutes(61)]);
        $expect(422, $api('POST', '/api/reset-password', array_replace($resetData, ['token' => $expired])), 'Recovery token expires');
    } finally {
        Mail::swap($mailManager);
        config(['mail.default' => $oldMailer]);
    }
    $catalogEdition = $expect(201, $api('POST', '/api/admin/editions', [
        'nom' => 'Édition CRUD', 'date_debut' => '2027-10-01', 'date_fin' => '2027-10-03', 'isActive' => false,
    ], $adminToken), 'Create edition')[1]['data'];
    $expect(200, $api('PATCH', '/api/admin/editions/'.$catalogEdition['id'], ['nom' => 'Édition CRUD modifiée'], $adminToken), 'Update edition');
    $catalogMission = $expect(201, $api('POST', '/api/admin/missions', [
        'edition_id' => $catalogEdition['id'], 'nom' => 'Mission CRUD', 'isSensible' => true,
    ], $adminToken), 'Create mission')[1]['data'];
    $catalogSlot = $expect(201, $api('POST', '/api/admin/creneaux', [
        'mission_id' => $catalogMission['id'], 'jour' => '2027-10-01', 'heure_debut' => '09:00', 'heure_fin' => '11:00', 'capacite_max' => 4,
    ], $adminToken), 'Create slot')[1]['data'];
    $check($catalogSlot['mission']['id'] === $catalogMission['id'], 'Created slot relation');
    $expect(200, $api('PATCH', '/api/admin/creneaux/'.$catalogSlot['id'], ['capacite_max' => 8], $adminToken), 'Update slot');
    $expect(200, $api('GET', '/api/admin/missions/'.$catalogMission['id'], token: $adminToken), 'Mission detail');
    $expect(200, $api('GET', '/api/admin/creneaux/'.$catalogSlot['id'], token: $adminToken), 'Slot detail');
    $expect(409, $api('PATCH', '/api/admin/creneaux/'.$catalogSlot['id'], ['heure_fin' => '08:00'], $adminToken), 'Partial invalid time');
    $expect(409, $api('PATCH', '/api/admin/editions/'.$catalogEdition['id'], ['date_debut' => '2027-10-04'], $adminToken), 'Partial invalid dates');
    $expect(200, $api('PATCH', '/api/admin/editions/'.$catalogEdition['id'], ['isActive' => true], $adminToken), 'Switch edition');
    $check(Edition::where('isActive', true)->count() === 1 && $user->fresh()->statut_planning === 'brouillon', 'New edition starts draft: '.Edition::where('isActive', true)->count().' / '.$user->fresh()->statut_planning);
    $booking = $expect(201, $api('POST', '/api/admin/reservations', ['user_id' => $user->id, 'creneau_id' => $catalogSlot['id']], $adminToken), 'Book new edition')[1]['data']['id'];
    $second = Reservation::create(['user_id' => $resetUser->id, 'creneau_id' => $catalogSlot['id']]);
    $expect(409, $api('PATCH', '/api/admin/creneaux/'.$catalogSlot['id'], ['capacite_max' => 1], $adminToken), 'Capacity below reservations');
    $expect(409, $api('PATCH', '/api/admin/creneaux/'.$catalogSlot['id'], ['heure_fin' => '12:00'], $adminToken), 'Booked time change rejected');
    $expect(200, $api('POST', '/api/admin/users/'.$user->id.'/planning/valider', token: $adminToken), 'Validate new edition');
    $expect(200, $api('PATCH', '/api/admin/editions/'.$catalogEdition['id'], ['isArchived' => true], $adminToken), 'Archive active edition');
    $check(! Edition::find($catalogEdition['id'])->isActive && Reservation::find($booking)->statut === 'valide', 'Archive retains history');
    $expect(409, $api('PATCH', '/api/admin/editions/'.$catalogEdition['id'], ['isActive' => true], $adminToken), 'Archived activation rejected');
    $expect(200, $api('PATCH', '/api/admin/editions/'.$catalogEdition['id'], ['isArchived' => false, 'isActive' => true], $adminToken), 'Restore edition');
    $check($user->fresh()->statut_planning === 'valide', 'Restore planning status');
    $expect(200, $api('PATCH', '/api/admin/editions/'.$catalogEdition['id'], ['nom' => 'Renamed active'], $adminToken), 'Rename active edition');
    $check(Edition::find($catalogEdition['id'])->isActive, 'Active flag preserved');
    $expect(200, $api('POST', '/api/admin/users/'.$user->id.'/planning/deverrouiller', token: $adminToken), 'Unlock test planning');
    $expect(204, $api('DELETE', '/api/admin/reservations/'.$booking, token: $adminToken), 'Remove test reservation');
    $second->delete();
    $expect(204, $api('DELETE', '/api/admin/creneaux/'.$catalogSlot['id'], token: $adminToken), 'Delete slot');
    $expect(204, $api('DELETE', '/api/admin/missions/'.$catalogMission['id'], token: $adminToken), 'Delete mission');
    $expect(204, $api('DELETE', '/api/admin/editions/'.$catalogEdition['id'], token: $adminToken), 'Delete edition');
} finally {
    // Delete only data in this run's uniquely prefixed tables before rollback.
    foreach (['password_reset_tokens', 'personal_access_tokens', 'reservations', 'users', 'invitation_codes', 'creneaux', 'missions', 'editions'] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }
    $check(Artisan::call('migrate:rollback', ['--database' => 'api_test', '--force' => true]) === 0, 'Rollback isolated schema');
    Schema::dropIfExists('migrations');
}
echo $checks." checks passed, including real concurrent requests; live data untouched.\n";
