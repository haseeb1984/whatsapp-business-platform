<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->select('SELECT 1');
            $databaseReady = true;
        } catch (Throwable) {
            $databaseReady = false;
        }

        $queueReady = false;
        if ($databaseReady) {
            try {
                $queueReady = config('queue.default') !== 'database'
                    || Schema::hasTable(config('queue.connections.database.table', 'jobs'));
            } catch (Throwable) {
                $queueReady = false;
            }
        }

        $ready = $databaseReady && $queueReady;

        return response()->json([
            'status' => $ready ? 'ready' : 'degraded',
            'checks' => [
                'database' => $databaseReady,
                'queue_storage' => $queueReady,
            ],
        ], $ready ? 200 : 503);
    }
}
