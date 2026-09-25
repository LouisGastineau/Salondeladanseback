<?php

use App\Exceptions\BusinessRuleException;
use App\Mail\VolunteerInvitation;
use App\Mail\VolunteerNotification;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EmailNotificationService;
use App\Services\InvitationService;
use App\Services\ReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$prefix = $argv[1] ?? '';
if (! preg_match('/^t[0-9a-f]{6}_$/', $prefix)) {
    exit(2);
}
$config = config('database.connections.'.config('database.default'));
$config['prefix'] = $prefix;
config(['database.connections.api_test' => $config]);
DB::setDefaultConnection('api_test');
$job = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);
try {
    if ($job['mode'] === 'register') {
        app(AuthService::class)->register($job['data']);
    } elseif ($job['mode'] === 'notification') {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        app(EmailNotificationService::class)->registration(User::findOrFail($job['user']));
        echo Mail::sent(VolunteerNotification::class)->count() === 1 ? "201\n" : "200\n";
        exit(0);
    } elseif ($job['mode'] === 'validation') {
        Mail::fake();
        app(ReservationService::class)->decide(User::findOrFail($job['user']), $job['reservation'], 'acceptee');
    } elseif ($job['mode'] === 'invitation') {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        app(InvitationService::class)->send(User::findOrFail($job['user']), $job['email']);
        echo Mail::sent(VolunteerInvitation::class)->count() === 1 ? "201\n" : "200\n";
        exit(0);
    } else {
        app(ReservationService::class)->create(User::findOrFail($job['user']), $job['slot']);
    }
    echo "201\n";
} catch (ValidationException $e) {
    echo "422\n";
} catch (BusinessRuleException $e) {
    echo $e->getStatusCode()."\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
