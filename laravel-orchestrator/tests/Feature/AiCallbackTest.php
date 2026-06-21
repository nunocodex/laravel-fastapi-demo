<?php

use App\Models\AiTask;
use function Pest\Laravel\postJson;

beforeEach(function () {
    config()->set('app.fastapi_internal_url', 'http://ai_fastapi_engine:8000');
    AiTask::query()->delete();
});

test('POST /api/ai-callback with valid HMAC updates task to completed', function () {
    $secret = 'test-secret';
    config()->set('app.callback_hmac_secret', $secret);
    putenv("CALLBACK_HMAC_SECRET={$secret}");

    $task = AiTask::create([
        'task_uuid' => '550e8400-e29b-41d4-a716-446655440001',
        'status' => AiTask::STATUS_RUNNING,
        'document_id' => 42,
        'prompt_template' => 'test',
    ]);

    $timestamp = (string) time();
    $signature = hash_hmac('sha256', "{$task->task_uuid}|{$timestamp}", $secret);

    $response = postJson(
        "/api/ai-callback/{$task->task_uuid}",
        [
            'status' => 'completed',
            'result' => ['inference' => 'test result'],
            'metadata' => ['engine_version' => '1.0.0'],
        ],
        [
            'X-AI-Signature' => $signature,
            'X-AI-Timestamp' => $timestamp,
            'X-AI-Engine' => 'test/1.0',
        ]
    );

    $response->assertStatus(200)
        ->assertJson(['ok' => true, 'status' => 'completed']);

    $task->refresh();
    expect($task->status)->toBe('completed')
        ->and($task->result)->toBe(['inference' => 'test result']);
});

test('POST /api/ai-callback with invalid HMAC returns 401', function () {
    putenv('CALLBACK_HMAC_SECRET=test-secret');

    $task = AiTask::create([
        'task_uuid' => '550e8400-e29b-41d4-a716-446655440002',
        'status' => AiTask::STATUS_RUNNING,
        'document_id' => 42,
        'prompt_template' => 'test',
    ]);

    $response = postJson(
        "/api/ai-callback/{$task->task_uuid}",
        [
            'status' => 'completed',
            'result' => ['inference' => 'bad'],
        ],
        [
            'X-AI-Signature' => 'invalid-signature',
            'X-AI-Timestamp' => (string) time(),
            'X-AI-Engine' => 'test/1.0',
        ]
    );

    $response->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'invalid_signature']);
});

test('POST /api/ai-callback for unknown task returns 404', function () {
    $secret = 'test-secret';
    putenv("CALLBACK_HMAC_SECRET={$secret}");

    $uuid = '550e8400-e29b-41d4-a716-446655440099';
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', "{$uuid}|{$timestamp}", $secret);

    $response = postJson(
        "/api/ai-callback/{$uuid}",
        [
            'status' => 'completed',
            'result' => [],
        ],
        [
            'X-AI-Signature' => $signature,
            'X-AI-Timestamp' => $timestamp,
        ]
    );

    $response->assertStatus(404)
        ->assertJson(['ok' => false, 'error' => 'task_not_found']);
});

test('POST /api/ai-callback validates required fields', function () {
    $secret = 'test-secret';
    putenv("CALLBACK_HMAC_SECRET={$secret}");

    $task = AiTask::create([
        'task_uuid' => '550e8400-e29b-41d4-a716-446655440003',
        'status' => AiTask::STATUS_RUNNING,
        'document_id' => 42,
        'prompt_template' => 'test',
    ]);

    $timestamp = (string) time();
    $signature = hash_hmac('sha256', "{$task->task_uuid}|{$timestamp}", $secret);

    $response = postJson(
        "/api/ai-callback/{$task->task_uuid}",
        ['status' => 'completed'],  // missing 'result'
        [
            'X-AI-Signature' => $signature,
            'X-AI-Timestamp' => $timestamp,
        ]
    );

    $response->assertStatus(422);
});
