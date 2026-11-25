<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresAiUsageQuotaConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_workers_cannot_exceed_the_same_tenant_daily_quota(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency test requires PostgreSQL row-level locks.');
        }

        $tenant = Tenant::query()->create([
            'name' => 'Concurrent AI Quota Workspace',
            'slug' => 'concurrent-ai-quota-workspace',
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION test_pause_ai_quota_reservation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.requests = 0 AND NEW.requests = 1 THEN
                    PERFORM pg_sleep(1.0);
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_pause_ai_quota_reservation_trigger
            BEFORE UPDATE ON ai_usage_daily
            FOR EACH ROW
            EXECUTE FUNCTION test_pause_ai_quota_reservation()
        SQL);

        $directory = sys_get_temp_dir().'/ai-quota-workers-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $startPath = $directory.'/start';
        $readyPaths = [$directory.'/worker-1.ready', $directory.'/worker-2.ready'];
        $processes = [];

        try {
            foreach ($readyPaths as $readyPath) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/run-ai-quota-worker.php'),
                    $tenant->id,
                    '1',
                    $readyPath,
                    $startPath,
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path());

                $this->assertIsResource($process, 'Unable to start an independent AI quota worker.');
                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'pipes' => $pipes];
            }

            $readyDeadline = microtime(true) + 10;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) && microtime(true) < $readyDeadline) {
                usleep(10_000);
            }

            foreach ($readyPaths as $index => $readyPath) {
                if (! is_file($readyPath)) {
                    $errorPath = $readyPath.'.error';
                    $detail = is_file($errorPath) ? file_get_contents($errorPath) : 'No worker bootstrap diagnostic was written.';
                    $this->fail('AI quota worker '.($index + 1).' did not become ready: '.$detail);
                }
            }
            file_put_contents($startPath, 'go');

            $results = [];
            foreach ($processes as $index => $worker) {
                $process = $worker['process'];
                $pipes = $worker['pipes'];
                $deadline = microtime(true) + 15;
                do {
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);

                if ($status['running']) {
                    proc_terminate($process, 9);
                    $this->fail('AI quota worker exceeded the 15-second execution timeout.');
                }

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = $status['exitcode'];
                proc_close($process);
                $processes[$index]['process'] = null;

                $this->assertSame(0, $exitCode, 'AI quota worker failed: '.$stderr.' '.$stdout);
                $results[] = trim($stdout);
            }

            sort($results);
            $this->assertSame(['blocked', 'reserved'], $results);
            $this->assertDatabaseHas('ai_usage_daily', [
                'tenant_id' => $tenant->id,
                'usage_date' => now()->toDateString(),
                'requests' => 1,
            ]);
            $this->assertSame(1, DB::table('ai_usage_daily')->where('tenant_id', $tenant->id)->count());
        } finally {
            foreach ($processes as $worker) {
                $process = $worker['process'];
                if (is_resource($process)) {
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, 9);
                    }
                }
                foreach ([1, 2] as $index) {
                    if (isset($worker['pipes'][$index]) && is_resource($worker['pipes'][$index])) {
                        fclose($worker['pipes'][$index]);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }

            DB::unprepared('DROP TRIGGER IF EXISTS test_pause_ai_quota_reservation_trigger ON ai_usage_daily');
            DB::unprepared('DROP FUNCTION IF EXISTS test_pause_ai_quota_reservation()');

            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
