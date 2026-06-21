<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiCallbackRequest;
use App\Models\AiTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AiCallbackController extends Controller
{
    public function store(AiCallbackRequest $request, string $taskUuid): JsonResponse
    {
        $expected = $this->expectedSignature($taskUuid, $request->header('X-AI-Timestamp'));
        $provided = (string) $request->header('X-AI-Signature', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            Log::warning('ai-callback.bad-signature', [
                'task_uuid' => $taskUuid,
                'remote' => $request->ip(),
            ]);
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        $task = AiTask::where('task_uuid', $taskUuid)->first();
        if (! $task) {
            return response()->json(['ok' => false, 'error' => 'task_not_found'], 404);
        }

        $payload = $request->validated();

        $task->status = $payload['status'];
        $task->result = $payload['result'] ?? null;
        $task->metadata = array_merge($task->metadata ?? [], $payload['metadata'] ?? []);
        $task->error = $payload['error'] ?? null;
        $task->completed_at = now();
        $task->save();

        Log::info('ai-callback.applied', [
            'task_uuid' => $taskUuid,
            'status' => $task->status,
        ]);

        return response()->json(['ok' => true, 'task_uuid' => $task->task_uuid, 'status' => $task->status]);
    }

    private function expectedSignature(string $taskUuid, ?string $timestamp): string
    {
        // Read directly from env() rather than config() so that rotating
        // CALLBACK_HMAC_SECRET in .env takes effect on the next request,
        // even when php artisan config:cache has captured a previous value.
        $secret = (string) env('CALLBACK_HMAC_SECRET', '');
        if ($secret === '') {
            return '';
        }

        $ts = $timestamp ?: (string) time();
        $body = $taskUuid.'|'.$ts;

        return hash_hmac('sha256', $body, $secret);
    }
}
