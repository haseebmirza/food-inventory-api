<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Deep health check — verifies database and cache are reachable.
     *
     * Returns 200 if all services are healthy, 503 if any are degraded.
     * Designed for load balancers, uptime monitors, and ops dashboards.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{ok: bool, latency_ms: float|null, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return ['ok' => true, 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['ok' => false, 'latency_ms' => null, 'error' => 'Database unreachable'];
        }
    }

    /**
     * @return array{ok: bool, latency_ms: float|null, error?: string}
     */
    private function checkCache(): array
    {
        try {
            $start = microtime(true);
            $key = 'health:ping';
            Cache::put($key, true, 10);
            $hit = Cache::get($key);
            Cache::forget($key);
            $latency = round((microtime(true) - $start) * 1000, 2);

            return ['ok' => $hit === true, 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['ok' => false, 'latency_ms' => null, 'error' => 'Cache unreachable'];
        }
    }
}
