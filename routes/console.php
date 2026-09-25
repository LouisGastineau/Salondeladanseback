<?php

use Illuminate\Support\Facades\Schedule;

// Project commands are discovered in app/Console/Commands.
Schedule::command('notifications:envoyer')->everyFiveMinutes()->withoutOverlapping(30);
