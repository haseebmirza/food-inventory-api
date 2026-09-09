<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackRequest
{
    /**
     * Assign a unique request ID and measure response time.
     *
     * Headers added to every response:
     *   X-Request-Id    — UUID for tracing through logs and downstream services
     *   X-Response-Time — Duration in milliseconds (e.g. "42ms")
     *
     * The request ID is also pushed into the log context so every log
     * entry within this request can be correlated.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $start = microtime(true);

        $request->headers->set('X-Request-Id', $requestId);

        app('log')->shareContext(['request_id' => $requestId]);

        $response = $next($request);

        $durationMs = round((microtime(true) - $start) * 1000, 2);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Response-Time', $durationMs.'ms');

        $status = $response->getStatusCode();
        $message = "{$request->method()} {$request->path()} {$status} {$durationMs}ms";
        $context = [
            'status' => $status,
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'user_agent' => $request->userAgent(),
        ];

        match (true) {
            $status >= 500 => Log::error($message, $context),
            $status >= 400 => Log::warning($message, $context),
            default => Log::info($message, $context),
        };

        return $response;
    }
}
