<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// AI commands in app/Console/Commands/ are auto-discovered.
// - ai:dlq-replay        Replay failed callbacks from the FastAPI Dead Letter Queue
// - outbox:process       Process pending outbox events and dispatch to queue
