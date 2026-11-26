<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresWebhookWorkerConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_workers_process_the_same_webhook_event_only_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency test requires PostgreSQL row-level locks.');
        }

        $tenant = Tenant::query()->create([
            'name' => 'Concurrent Worker Workspace',
            'slug' => 'concurrent-worker-workspace',
        ]);

        $event = WebhookEvent::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', 'concurrent-worker-event'),
            'phone_number_id' => '123456789012345',
            'event_type' => 'message.received',
            'payload' => [
                'from' => '923001112233',
                'id' => 'wamid.concurrent-worker-message',
                'timestamp' => '1795280000',
                'type' => 'image',
            ],
            'status' => 'pending',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        // Hold the first worker's row-lock transaction long enough for the
        // second independent connection to contend on SELECT ... FOR UPDATE.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION test_pause_webhook_processing()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.status = 'pending' AND NEW.status = 'processing' THEN
                    PERFORM pg_sleep(1.0);
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_pause_webhook_processing_trigger
            BEFORE UPDATE ON webhook_events
            FOR EACH ROW
            EXECUTE FUNCTION test_pause_webhook_processing()
        SQL);

        $directory = sys_get_temp_dir().'/webhook-workers-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $startPath = $directory.'/start';
        $readyPaths = [$directory.'/worker-1.ready', $directory.'/worker-2.ready'];
        $processes = [];

        try {
            foreach ($readyPaths as $readyPath) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/run-webhook-worker.php'),
                    $event->id,
                    $readyPath,
                    $startPath,
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path());

                $this->assertIsResource($process, 'Unable to start an independent webhook worker.');
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
                    $this->fail('Worker '.($index + 1).' did not become ready: '.$detail);
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
                    $this->fail('Webhook worker exceeded the 15-second execution timeout.');
                }

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = $status['exitcode'];
                proc_close($process);
                $processes[$index]['process'] = null;

                $this->assertSame(0, $exitCode, 'Webhook worker failed: '.$stderr.' '.$stdout);
            }

            $this->assertDatabaseHas('webhook_events', [
                'id' => $event->id,
                'status' => 'processed',
                'attempts' => 1,
            ]);
            $this->assertSame(1, DB::table('customers')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(1, DB::table('conversations')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(1, DB::table('messages')->where('tenant_id', $tenant->id)->count());
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

            DB::unprepared('DROP TRIGGER IF EXISTS test_pause_webhook_processing_trigger ON webhook_events');
            DB::unprepared('DROP FUNCTION IF EXISTS test_pause_webhook_processing()');

            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function test_distinct_first_messages_from_same_new_sender_share_one_customer_and_conversation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency test requires PostgreSQL row-level locks.');
        }

        $tenant = Tenant::query()->create([
            'name' => 'Concurrent First Contact Workspace',
            'slug' => 'concurrent-first-contact-workspace',
        ]);

        $events = collect(['first', 'second'])->map(function (string $suffix) use ($tenant): WebhookEvent {
            return WebhookEvent::query()->create([
                'tenant_id' => $tenant->id,
                'provider' => 'meta_whatsapp',
                'provider_event_key' => hash('sha256', 'concurrent-first-contact-'.$suffix),
                'phone_number_id' => '123456789012345',
                'event_type' => 'message.received',
                'payload' => [
                    'from' => '923009998877',
                    'id' => 'wamid.concurrent-first-contact-'.$suffix,
                    'timestamp' => '1795280000',
                    'type' => 'image',
                ],
                'status' => 'pending',
                'attempts' => 0,
                'received_at' => now(),
            ]);
        });

        // Force both transactions to race while creating the previously absent
        // customer. The unique (tenant_id, whatsapp_wa_id) index must select one.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION test_pause_customer_creation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $
            BEGIN
                PERFORM pg_sleep(1.0);
                RETURN NEW;
            END;
            $$
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_pause_customer_creation_trigger
            BEFORE INSERT ON customers
            FOR EACH ROW
            EXECUTE FUNCTION test_pause_customer_creation()
        SQL);

        $directory = sys_get_temp_dir().'/webhook-first-contact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $startPath = $directory.'/start';
        $readyPaths = [$directory.'/worker-1.ready', $directory.'/worker-2.ready'];
        $processes = [];

        try {
            foreach ($events->values() as $index => $event) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/run-webhook-worker.php'),
                    $event->id,
                    $readyPaths[$index],
                    $startPath,
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path());

                $this->assertIsResource($process, 'Unable to start an independent webhook worker.');
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
                    $this->fail('Worker '.($index + 1).' did not become ready: '.$detail);
                }
            }
            file_put_contents($startPath, 'go');

            foreach ($processes as $index => $worker) {
                $process = $worker['process'];
                $pipes = $worker['pipes'];
                $deadline = microtime(true) + 20;
                do {
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);

                if ($status['running']) {
                    proc_terminate($process, 9);
                    $this->fail('Webhook worker exceeded the 20-second execution timeout.');
                }

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = $status['exitcode'];
                proc_close($process);
                $processes[$index]['process'] = null;

                $this->assertSame(0, $exitCode, 'Webhook worker failed: '.$stderr.' '.$stdout);
            }

            foreach ($events as $event) {
                $this->assertDatabaseHas('webhook_events', [
                    'id' => $event->id,
                    'status' => 'processed',
                    'attempts' => 1,
                ]);
            }
            $this->assertSame(1, DB::table('customers')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(1, DB::table('conversations')->where('tenant_id', $tenant->id)->where('status', 'open')->count());
            $this->assertSame(2, DB::table('messages')->where('tenant_id', $tenant->id)->count());
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

            DB::unprepared('DROP TRIGGER IF EXISTS test_pause_customer_creation_trigger ON customers');
            DB::unprepared('DROP FUNCTION IF EXISTS test_pause_customer_creation()');

            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

}
