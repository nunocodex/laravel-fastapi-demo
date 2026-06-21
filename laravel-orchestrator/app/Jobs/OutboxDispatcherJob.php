<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Services\OutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OutboxDispatcherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly string $eventId,
    ) {}

    public function handle(OutboxService $outbox): void
    {
        $event = OutboxEvent::where('event_id', $this->eventId)->first();

        if (! $event || $event->status !== OutboxEvent::STATUS_PROCESSING) {
            return;
        }

        try {
            $this->dispatchEvent($event);
            $outbox->markDispatched($event);
            Log::info('outbox.dispatched', [
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'aggregate_id' => $event->aggregate_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('outbox.dispatch_failed', [
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
            ]);
            $outbox->markFailed($event, $e->getMessage());
        }
    }

    private function dispatchEvent(OutboxEvent $event): void
    {
        $engineUrl = rtrim((string) config('app.fastapi_internal_url', env('FASTAPI_INTERNAL_URL', 'http://ai_fastapi_engine:8000')), '/');
        $correlationId = $event->metadata['correlation_id'] ?? app('correlation_id');

        match ($event->event_type) {
            'ai_task.created' => $this->forwardToEngine($engineUrl, $correlationId, $event),
            default => Log::warning('outbox.unknown_event_type', ['event_type' => $event->event_type]),
        };
    }

    private function forwardToEngine(string $engineUrl, string $correlationId, OutboxEvent $event): void
    {
        $payload = $event->payload;

        $response = Http::timeout(15)
            ->acceptJson()
            ->asJson()
            ->withHeader('X-Correlation-ID', $correlationId)
            ->withHeader('Idempotency-Key', $event->event_id)
            ->post("{$engineUrl}/api/v1/analyze/{$payload['task_uuid']}", [
                'document_id' => $payload['document_id'],
                'prompt_template' => $payload['prompt_template'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Engine returned {$response->status()}: " . substr($response->body(), 0, 500)
            );
        }
    }
}
