<?php

namespace App\Http\Controllers;

use App\Models\AiTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DemoController extends Controller
{
    private function engineUrl(): string
    {
        $url = rtrim((string) config('app.fastapi_internal_url', ''), '/');

        return $url ?: 'http://ai_fastapi_engine:8000';
    }

    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeader('X-Correlation-ID', app('correlation_id'));
    }

    public function index(Request $request)
    {
        return view('demo', [
            'fastapiUrl' => $this->engineUrl(),
        ]);
    }

    public function health(): JsonResponse
    {
        $checks = [
            'laravel' => ['status' => 'up', 'latency_ms' => 0],
            'fastapi' => ['status' => 'unknown', 'latency_ms' => null, 'detail' => null],
        ];

        $start = microtime(true);
        try {
            $response = $this->httpClient()->timeout(5)->get("{$this->engineUrl()}/");
            $checks['fastapi']['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
            if ($response->successful()) {
                $checks['fastapi']['status'] = 'up';
                $checks['fastapi']['detail'] = $response->json();
            } else {
                $checks['fastapi']['status'] = 'down';
                $checks['fastapi']['detail'] = ['http_status' => $response->status()];
            }
        } catch (\Throwable $e) {
            $checks['fastapi']['status'] = 'down';
            $checks['fastapi']['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
            $checks['fastapi']['detail'] = ['error' => $e->getMessage()];
            Log::warning('demo.health.fastapi_unreachable', ['err' => $e->getMessage()]);
        }

        $overall = $checks['fastapi']['status'] === 'up' ? 'ok' : 'degraded';

        return response()->json([
            'status' => $overall,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = [
            'db' => [
                'status' => 'unknown',
                'tasks_total' => 0,
                'tasks_by_status' => [],
                'host' => config('database.connections.pgsql.host'),
                'database' => config('database.connections.pgsql.database'),
            ],
            'qdrant' => ['status' => 'unknown', 'points_count' => 0, 'documents_count' => 0],
            'redis' => [
                'status' => 'unknown',
                'host' => config('database.redis.default.host'),
                'port' => config('database.redis.default.port'),
            ],
            'fastapi' => ['status' => 'unknown', 'version' => null],
        ];

        try {
            $stats['db']['tasks_total'] = AiTask::count();
            $stats['db']['tasks_by_status'] = AiTask::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');
            $stats['db']['status'] = 'up';
        } catch (\Throwable $e) {
            $stats['db']['status'] = 'down';
            $stats['db']['error'] = $e->getMessage();
        }

        try {
            $response = $this->httpClient()->timeout(10)->get("{$this->engineUrl()}/api/v1/stats");
            if ($response->successful()) {
                $data = $response->json();
                $stats['qdrant'] = array_merge($stats['qdrant'], $data['qdrant'] ?? []);
                $stats['qdrant']['status'] = 'up';
                $stats['redis'] = array_merge($stats['redis'], $data['redis'] ?? []);
                $stats['redis']['status'] = 'up';
                $stats['fastapi'] = array_merge($stats['fastapi'], $data['fastapi'] ?? []);
                $stats['fastapi']['status'] = 'up';
            } else {
                $stats['qdrant']['status'] = 'down';
                $stats['redis']['status'] = 'down';
                $stats['fastapi']['status'] = 'down';
            }
        } catch (\Throwable $e) {
            $stats['qdrant']['status'] = 'down';
            $stats['redis']['status'] = 'down';
            $stats['fastapi']['status'] = 'down';
            $stats['fastapi']['error'] = $e->getMessage();
        }

        try {
            Redis::ping();
            $stats['redis']['status'] = 'up';
        } catch (\Throwable $e) {
            $stats['redis']['status'] = 'down';
            $stats['redis']['error'] = $e->getMessage();
        }

        return response()->json($stats);
    }

    public function documents(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            return $this->storeDocument($request);
        }

        try {
            $response = $this->httpClient()->timeout(15)->get("{$this->engineUrl()}/api/v1/documents");

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('demo.documents.list_failed', ['err' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,txt,md', 'max:10240'],
            'document_id' => ['nullable', 'string', 'max:128'],
        ]);

        $file = $request->file('file');

        $payload = [];
        if (! empty($validated['document_id'])) {
            $payload['document_id'] = $validated['document_id'];
        }

        try {
            $response = $this->httpClient()->timeout(120)
                ->attach(
                    'file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )
                ->post("{$this->engineUrl()}/api/v1/documents", $payload);

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('demo.documents.upload_failed', ['err' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function destroyDocument(string $documentId): JsonResponse
    {
        try {
            $response = $this->httpClient()->timeout(15)->delete("{$this->engineUrl()}/api/v1/documents/{$documentId}");

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('demo.documents.delete_failed', ['document_id' => $documentId, 'err' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4096'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'document_id' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $response = $this->httpClient()->timeout(120)
                ->acceptJson()
                ->asJson()
                ->post("{$this->engineUrl()}/api/v1/chat", $validated);

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('demo.chat.failed', ['err' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }
}
