<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAiTaskRequest;
use App\Models\AiTask;
use App\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiTaskController extends Controller
{
    public function show(string $taskUuid): JsonResponse
    {
        $task = AiTask::where('task_uuid', $taskUuid)->first();

        if (! $task) {
            return response()->json(['ok' => false, 'error' => 'task_not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'task' => $task->toArray(),
        ]);
    }

    public function store(CreateAiTaskRequest $request): JsonResponse
    {
        // Idempotency check: if client sent Idempotency-Key, check for duplicate.
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey) {
            $existing = AiTask::where('metadata->idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'task_uuid' => $existing->task_uuid,
                    'status' => $existing->status,
                    'idempotent' => true,
                ], 200);
            }
        }

        // Transactional outbox: create task + publish event atomically.
        $outbox = app(OutboxService::class);
        $task = null;

        DB::transaction(function () use ($request, $idempotencyKey, $outbox, &$task) {
            $task = AiTask::create([
                'task_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'status' => AiTask::STATUS_PENDING,
                'document_id' => $request->integer('document_id'),
                'prompt_template' => $request->string('prompt_template'),
                'metadata' => $idempotencyKey ? ['idempotency_key' => $idempotencyKey] : null,
            ]);

            $outbox->publish(
                'ai_task.created',
                $task->task_uuid,
                $task->only(['task_uuid', 'document_id', 'prompt_template', 'status']),
            );
        });

        Log::info('ai-task.created', [
            'task_uuid' => $task->task_uuid,
            'correlation_id' => app('correlation_id'),
        ]);

        return response()->json([
            'ok' => true,
            'task_uuid' => $task->task_uuid,
            'status' => $task->status,
        ], 202);
    }
}
