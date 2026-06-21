<?php

use App\Models\AiTask;
use App\Models\OutboxEvent;
use function Pest\Laravel\postJson;
use function Pest\Laravel\getJson;

beforeEach(function () {
    // Ensure outbox is clean before each test.
    OutboxEvent::query()->delete();
    AiTask::query()->delete();
});

test('POST /api/ai-tasks creates task and outbox event with 202', function () {
    $response = postJson('/api/ai-tasks', [
        'document_id' => 42,
        'prompt_template' => 'summarize',
    ]);

    $response->assertStatus(202)
        ->assertJsonStructure(['ok', 'task_uuid', 'status'])
        ->assertJson(['ok' => true, 'status' => 'pending']);

    $taskUuid = $response->json('task_uuid');

    // Verify task was created in the database.
    $task = AiTask::where('task_uuid', $taskUuid)->first();
    expect($task)->not->toBeNull()
        ->and($task->document_id)->toBe(42)
        ->and($task->prompt_template)->toBe('summarize');

    // Verify an outbox event was published (transactional).
    $outboxEvent = OutboxEvent::where('aggregate_id', $taskUuid)
        ->where('event_type', 'ai_task.created')
        ->first();
    expect($outboxEvent)->not->toBeNull()
        ->and($outboxEvent->status)->toBe('pending');
});

test('POST /api/ai-tasks validates required fields', function () {
    $response = postJson('/api/ai-tasks', []);
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['document_id', 'prompt_template']);
});

test('POST /api/ai-tasks validates document_id is positive integer', function () {
    $response = postJson('/api/ai-tasks', [
        'document_id' => -1,
        'prompt_template' => 'test',
    ]);
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['document_id']);
});

test('POST /api/ai-tasks is idempotent with Idempotency-Key header', function () {
    $key = 'test-idempotency-' . uniqid();

    $first = postJson('/api/ai-tasks', [
        'document_id' => 1,
        'prompt_template' => 'test',
    ], ['Idempotency-Key' => $key]);

    $first->assertStatus(202);
    $firstUuid = $first->json('task_uuid');

    $second = postJson('/api/ai-tasks', [
        'document_id' => 1,
        'prompt_template' => 'test',
    ], ['Idempotency-Key' => $key]);

    $second->assertStatus(200)
        ->assertJson(['idempotent' => true, 'task_uuid' => $firstUuid]);

    // Only one task should exist.
    expect(AiTask::where('metadata->idempotency_key', $key)->count())->toBe(1);
});

test('GET /api/ai-tasks/{uuid} returns task when it exists', function () {
    $task = AiTask::create([
        'task_uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'status' => AiTask::STATUS_COMPLETED,
        'document_id' => 42,
        'prompt_template' => 'test',
    ]);

    $response = getJson('/api/ai-tasks/550e8400-e29b-41d4-a716-446655440000');

    $response->assertStatus(200)
        ->assertJson(['ok' => true]);
});

test('GET /api/ai-tasks/{uuid} returns 404 for unknown task', function () {
    $response = getJson('/api/ai-tasks/00000000-0000-0000-0000-000000000000');
    $response->assertStatus(404)
        ->assertJson(['ok' => false, 'error' => 'task_not_found']);
});
