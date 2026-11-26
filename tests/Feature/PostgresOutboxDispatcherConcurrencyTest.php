<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresOutboxDispatcherConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_dispatchers_only_enqueue_one_job_for_the_same_pending_message(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency test requires PostgreSQL row-level locks.');
        }

        if (config('queue.default') !== 'database') {
            $this->markTestSkipped('This concurrency test requires the database queue driver.');
        }

        $tenant = Tenant::query()->create([
            'name' => 'Concurrent Outbox Workspace',
            'slug' => 'concurrent-outbox-workspace',
            'status' => 'active',
        ]);

        $message = TenantContext::runForTenant($tenant->id, function (): Message {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'AUTO_ACTIVE',
            ]);

            return Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => 'Queued reply',
                'delivery_status' => 'pending',
                'metadata' => [],
            ]);
        });

        DB::table('messages')->where('id', $message->id)->update([
            'updated_at' => now()->subMinutes(8),
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION test_pause_outbox_dispatch_lease()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.delivery_status = 'pending'
                    AND NEW.delivery_status = 'pending'
                    AND NEW.updated_at IS DISTINCT FROM OLD.updated_at THEN
                    PERFORM pg_sleep(1.0);
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_pause_outbox_dispatch_lease_trigger
            BEFORE UPDATE ON messages
            FOR EACH ROW
            EXECUTE FUNCTION test_pause_outbox_dispatch_lease()
        SQL);

        $directory = sys_get_temp_dir().'/outbox-dispatchers-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $startPath = $directory.'/start';
        $readyPaths = [$directory.'/worker-1.ready', $directory.'/worker-2.ready'];
        $processes = [];

        try {
            foreach ($readyPaths as $readyPath) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/run-outbox-dispatcher.php'),
                    $tenant->id,
                    $readyPath,
                    $startPath,
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path());

                $this->assertIsResource($process, 'Unable to start an independent outbox dispatcher.');
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
                    $this->fail('Outbox dispatcher '.($index + 1).' did not become ready: '.$detail);
                }
            }

            file_put_contents($startPath, 'go');

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
                    $this->fail('Outbox dispatcher exceeded the 15-second execution timeout.');
                }

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = $status['exitcode'];
                proc_close($process);
                $processes[$index]['process'] = null;

                $this->assertSame(0, $exitCode, 'Outbox dispatcher failed: '.$stderr.' '.$stdout);
            }

            $this->assertSame(
                1,
                DB::table('jobs')->where('queue', 'default')->count(),
                'Concurrent schedulers must enqueue exactly one delivery job for the pending message.'
            );
            $this->assertDatabaseHas('messages', [
                'id' => $message->id,
                'tenant_id' => $tenant->id,
                'delivery_status' => 'pending',
            ]);
            $this->assertGreaterThan(
                now()->subMinute()->timestamp,
                strtotime((string) DB::table('messages')->where('id', $message->id)->value('updated_at')),
                'The successful dispatcher must refresh the pending-message lease.'
            );
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

            DB::unprepared('DROP TRIGGER IF EXISTS test_pause_outbox_dispatch_lease_trigger ON messages');
            DB::unprepared('DROP FUNCTION IF EXISTS test_pause_outbox_dispatch_lease()');

            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
