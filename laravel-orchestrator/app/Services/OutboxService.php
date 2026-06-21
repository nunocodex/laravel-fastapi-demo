<?php

namespace App\Services;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboxService
{
    /**
     * Write an event to the outbox within an existing or new DB transaction.
     *
     * Usage:
     *   DB::transaction(function () use ($outbox, $task) {
     *       $task->save();
     *       $outbox->publish('ai_task.created', $task->task_uuid, $task->toArray());
     *   });
     */
    public function publish(
        string $eventType,
        string $aggregateId,
        array $payload,
        string $aggregateType = 'ai_task',
        array $metadata = [],
    ): OutboxEvent {
        return OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'metadata' => array_merge([
                'published_at' => now()->toIso8601String(),
                'correlation_id' => app('correlation_id'),
            ], $metadata),
            'status' => OutboxEvent::STATUS_PENDING,
        ]);
    }

    /**
     * Fetch the next batch of pending events with optimistic locking.
     * Skips events whose locked_until is in the future (picked up by another worker).
     */
    public function fetchPendingBatch(int $limit = 25, int $lockSeconds = 30): array
    {
        $now = now();

        $events = OutboxEvent::where('status', OutboxEvent::STATUS_PENDING)
            ->where(function ($query) use ($now) {
                $query->whereNull('locked_until')
                    ->orWhere('locked_until', '<=', $now);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        // Optimistic lock: set locked_until to prevent other workers from picking these up.
        $locked = [];
        foreach ($events as $event) {
            $affected = OutboxEvent::where('id', $event->id)
                ->where('status', OutboxEvent::STATUS_PENDING)
                ->where(function ($query) use ($now) {
                    $query->whereNull('locked_until')
                        ->orWhere('locked_until', '<=', $now);
                })
                ->update([
                    'status' => OutboxEvent::STATUS_PROCESSING,
                    'locked_until' => now()->addSeconds($lockSeconds),
                    'attempts' => $event->attempts + 1,
                ]);

            if ($affected > 0) {
                $event->status = OutboxEvent::STATUS_PROCESSING;
                $locked[] = $event;
            }
        }

        return $locked;
    }

    public function markDispatched(OutboxEvent $event): void
    {
        $event->update([
            'status' => OutboxEvent::STATUS_DISPATCHED,
            'dispatched_at' => now(),
            'locked_until' => null,
        ]);
    }

    public function markFailed(OutboxEvent $event, string $error): void
    {
        $isTerminal = $event->attempts >= $event->max_attempts;

        $event->update([
            'status' => $isTerminal ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_PENDING,
            'last_error' => $error,
            'locked_until' => $isTerminal ? null : now()->addSeconds(min(60 * $event->attempts, 600)),
            'metadata' => array_merge($event->metadata ?? [], [
                'last_failed_at' => now()->toIso8601String(),
                'attempt' => $event->attempts,
            ]),
        ]);
    }
}
