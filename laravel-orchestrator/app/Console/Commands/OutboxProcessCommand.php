<?php

namespace App\Console\Commands;

use App\Jobs\OutboxDispatcherJob;
use App\Models\OutboxEvent;
use App\Services\OutboxService;
use Illuminate\Console\Command;

class OutboxProcessCommand extends Command
{
    protected $signature = 'outbox:process {--batch=25 : Events per batch} {--lock=30 : Lock timeout in seconds}';
    protected $description = 'Process pending outbox events and dispatch them to the queue';

    public function handle(OutboxService $outbox): int
    {
        $batch = (int) $this->option('batch');
        $lockSeconds = (int) $this->option('lock');

        $events = $outbox->fetchPendingBatch($batch, $lockSeconds);

        if (empty($events)) {
            $this->line('No pending outbox events.');
            return self::SUCCESS;
        }

        $this->info("Dispatching {$this->wordwrap(count($events))} events to queue…");

        foreach ($events as $event) {
            OutboxDispatcherJob::dispatch($event->event_id);
        }

        return self::SUCCESS;
    }
}
