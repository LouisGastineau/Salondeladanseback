<?php

namespace App\Console\Commands;

use App\Services\EmailNotificationService;
use Illuminate\Console\Command;

class SendVolunteerNotifications extends Command
{
    protected $signature = 'notifications:envoyer';

    protected $description = 'Envoyer les rappels de la veille à 18 h (Europe/Paris) et réessayer les notifications en échec.';

    public function handle(EmailNotificationService $service): int
    {
        $service->process();
        $this->info('Traitement des notifications terminé.');

        return self::SUCCESS;
    }
}
