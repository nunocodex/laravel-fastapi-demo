<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        if (strlen($correlationId) > 64) {
            $correlationId = substr($correlationId, 0, 64);
        }

        // Make it available to the rest of the request lifecycle.
        $request->headers->set('X-Correlation-ID', $correlationId);
        app()->instance('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
