<?php

// php tests/api-smoke.php — isolated MariaDB tables; no live account is created.
use App\Models\Edition;
use App\Models\InvitationCode;
use App\Models\Reservation;
use App\Models\User;
use App\Mail\VolunteerInvitation;
use App\Services\ReservationService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
// Configure the limiter before providers resolve it, so repeated runs never share counters.
$app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class,
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
    $expect(409, $api('POST', '/api/planning/valider', token: $token), 'Minimum one');
    $publicSlots = $expect(200, $api('GET', '/api/creneaux', token: $token), 'Public slots');
    $check(! str_contains($publicSlots[2], 'Billetterie'), 'Sensitive mission excluded');
    $check(! str_contains($publicSlots[2], $admin->nom) && ! str_contains($publicSlots[2], 'email'), 'No other identity on slots');
    $check($publicSlots[1]['data'][0]['places_restantes'] === 10, 'Available places');
    $expect(404, $api('POST', '/api/reservations', ['creneau_id' => $hidden->id], $token), 'Sensitive assignment hidden');
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
    $check(count($planning[1]['data']) === 2 && ! str_contains($planning[2], 'Billetterie'), 'Sensitive own booking hidden');
    $adminPlanning = $expect(200, $api('GET', '/api/admin/users/'.$user->id.'/planning', token: $adminToken), 'Admin planning');
    $check(count($adminPlanning[1]['data']) === 3 && str_contains($adminPlanning[2], 'Billetterie'), 'Admin sees sensitive');
    $expect(404, $api('DELETE', '/api/reservations/'.$hiddenReservation, token: $token), 'Sensitive deletion concealed');
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
    } finally {
        Mail::swap($originalMailManager);
        config(['mail.default' => $originalMailer]);
    }
} finally {
    // Delete only data in this run's uniquely prefixed tables before rollback.
    foreach (['personal_access_tokens', 'reservations', 'users', 'invitation_codes', 'creneaux', 'missions', 'editions'] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }
    $check(Artisan::call('migrate:rollback', ['--database' => 'api_test', '--force' => true]) === 0, 'Rollback isolated schema');
    Schema::dropIfExists('migrations');
}
echo $checks." checks passed, including real concurrent requests; live data untouched.\n";
