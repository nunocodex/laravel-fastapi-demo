<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DlqReplayCommand extends Command
{
    protected $signature = 'ai:dlq-replay {--dry-run : Show entries without replaying}';
    protected $description = 'Replay failed callbacks from the FastAPI Dead Letter Queue';

    public function handle(): int
    {
        $engineUrl = rtrim((string) config('app.fastapi_internal_url', env('FASTAPI_INTERNAL_URL', 'http://ai_fastapi_engine:8000')), '/');
        $correlationId = (string) \Illuminate\Support\Str::uuid();

        if ($this->option('dry-run')) {
            $this->info('Dry-run mode — not replaying, just checking DLQ size.');

            try {
                $response = Http::timeout(10)
                    ->withHeader('X-Correlation-ID', $correlationId)
                    ->get("{$engineUrl}/api/v1/admin/dlq/size");

                if ($response->successful()) {
                    $data = $response->json();
                    $this->info("DLQ size: " . ($data['size'] ?? 'unknown'));
                } else {
                    $this->warn("DLQ endpoint returned {$response->status()}");
                }
            } catch (\Throwable $e) {
                $this->error("Cannot reach engine: {$e->getMessage()}");
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->info('Replaying DLQ entries…');

        try {
            $response = Http::timeout(120)
                ->withHeader('X-Correlation-ID', $correlationId)
                ->post("{$engineUrl}/api/v1/admin/dlq/replay");

            if ($response->successful()) {
                $data = $response->json();
                $count = $data['replayed'] ?? 0;
                $this->info("Replayed {$count} entries from DLQ.");
                Log::info('ai.dlq_replay', ['replayed' => $count, 'correlation_id' => $correlationId]);
            } else {
                $this->error("DLQ replay failed: {$response->status()} — {$response->body()}");
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error("Cannot reach engine: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
