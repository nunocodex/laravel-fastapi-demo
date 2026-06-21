<?php

use App\Models\OutboxEvent;
use App\Services\OutboxService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    OutboxEvent::query()->delete();
});

test('OutboxService publishes event within transaction', function () {
    $outbox = app(OutboxService::class);

    DB::transaction(function () use ($outbox) {
        $outbox->publish('ai_task.created', 'test-agg-1', [
            'task_uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'document_id' => 42,
        ]);
    });

    $event = OutboxEvent::where('aggregate_id', 'test-agg-1')->first();
    expect($event)->not->toBeNull()
        ->and($event->status)->toBe('pending')
        ->and($event->event_type)->toBe('ai_task.created')
        ->and($event->payload)->toHaveKey('task_uuid');
});

test('OutboxService fetchPendingBatch locks events', function () {
    $outbox = app(OutboxService::class);

    DB::transaction(function () use ($outbox) {
        for ($i = 0; $i < 5; $i++) {
            $outbox->publish('ai_task.created', "test-agg-{$i}", ['task_uuid' => "uuid-{$i}"]);
        }
    });

    expect(OutboxEvent::where('status', 'pending')->count())->toBe(5);

    $batch = $outbox->fetchPendingBatch(3, 30);
    expect(count($batch))->toBe(3);

    // These 3 should now be in 'processing' state.
    foreach ($batch as $event) {
        expect($event->status)->toBe('processing');
    }

    // Remaining 2 should still be pending.
    expect(OutboxEvent::where('status', 'pending')->count())->toBe(2);
});

test('OutboxService marks event as dispatched', function () {
    $outbox = app(OutboxService::class);

    DB::transaction(function () use ($outbox) {
        $outbox->publish('ai_task.created', 'test-disp', ['task_uuid' => 'uuid-disp']);
    });

    $event = OutboxEvent::where('aggregate_id', 'test-disp')->first();
    $event->status = 'processing';
    $event->save();

    $outbox->markDispatched($event);
    $event->refresh();

    expect($event->status)->toBe('dispatched')
        ->and($event->dispatched_at)->not->toBeNull();
});

test('OutboxService marks event as failed with backoff', function () {
    $outbox = app(OutboxService::class);

    DB::transaction(function () use ($outbox) {
        $outbox->publish('ai_task.created', 'test-fail', ['task_uuid' => 'uuid-fail']);
    });

    $event = OutboxEvent::where('aggregate_id', 'test-fail')->first();
    $event->status = 'processing';
    $event->attempts = 2;
    $event->save();

    $outbox->markFailed($event, 'Connection refused');

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->last_error)->toBe('Connection refused')
        ->and($event->attempts)->toBe(2)
        ->and($event->locked_until)->not->toBeNull();
});
