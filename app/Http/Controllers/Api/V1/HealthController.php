<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends ApiController
{
    /** Liveness: no dependencies, always cheap. */
    public function health(): JsonResponse
    {
        return $this->ok(['status' => 'ok', 'time' => now()->toIso8601String()]);
    }

    /** Readiness: verifies the database and cache are reachable. Reports 503 if not. Reveals no error details. */
    public function ready(): JsonResponse
    {
        $checks = ['database' => $this->probe(fn () => DB::select('select 1')), 'cache' => $this->probe(function () {
            Cache::put('health:probe', 1, 10);
            return Cache::get('health:probe') === 1;
        })];

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Ready.' : 'Not ready.',
            'data' => ['checks' => $checks],
        ], $ok ? 200 : 503);
    }

    private function probe(callable $fn): bool
    {
        try {
            return $fn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
