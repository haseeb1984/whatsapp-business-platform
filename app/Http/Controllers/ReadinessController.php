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
                $queueTable = config('queue.connections.database.table', 'jobs');
                $queueReady = config('queue.default') !== 'database'
                    || (is_string($queueTable)
                        && $queueTable !== ''
                        && Schema::hasColumns($queueTable, [
                            'id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at',
                        ]));
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
