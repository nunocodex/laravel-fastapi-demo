<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Illuminate\Foundation\Configuration\Middleware $middleware) {
        $middleware->preventRequestForgery(except: [
            'api/ai-callback/*',
            'livewire*',
        ]);
        $middleware->append(\App\Http\Middleware\CorrelationIdMiddleware::class);
    })
    ->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        // Process outbox events every 5 seconds to keep latency low.
        $schedule->command('outbox:process --batch=50 --lock=30')
            ->everyFiveSeconds()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/outbox-scheduler.log'));
    })
    ->create();
