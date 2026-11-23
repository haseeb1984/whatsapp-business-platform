<?php

namespace Tests\\Feature;

use App\\Models\\Tenant;
use App\\Models\\WebhookEvent;
use Illuminate\\Foundation\\Testing\\DatabaseMigrations;
use Illuminate\\Support\\Facades\\DB;
use Symfony\\Component\\Process\\Process;
use Tests\\TestCase;

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
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/run-webhook-worker.php'),
                    $event->id,
                    $readyPath,
                    $startPath,
                ], base_path(), null, null, 15);
                $process->start();
                $processes[] = $process;
            }

            $readyDeadline = microtime(true) + 10;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) && microtime(true) < $readyDeadline) {
                usleep(10_000);
            }

            $this->assertFileExists($readyPaths[0], 'First worker did not become ready.');
            $this->assertFileExists($readyPaths[1], 'Second worker did not become ready.');
            file_put_contents($startPath, 'go');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    'Webhook worker failed: '.$process->getErrorOutput().' '.$process->getOutput(),
                );
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
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
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
}
