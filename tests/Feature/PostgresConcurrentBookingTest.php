<?php

namespace Tests\Feature;

use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresConcurrentBookingTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_customers_cannot_hold_the_same_staff_slot_concurrently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency test requires PostgreSQL row-level locks.');
        }

        $tenant = Tenant::query()->create([
            'name' => 'Concurrent Booking Workspace',
            'slug' => 'concurrent-booking-workspace',
            'timezone' => 'Asia/Karachi',
            'status' => 'active',
        ]);

        [$service, $staff, $customers] = \App\Support\Tenancy\TenantContext::runForTenant($tenant->id, function () {
            $service = Service::query()->create([
                'name' => 'Consultation',
                'price_amount' => '1000.00',
                'currency' => 'PKR',
                'duration_minutes' => 60,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'active' => true,
            ]);
            $staff = Staff::query()->create(['name' => 'Concurrent Staff', 'active' => true]);
            StaffService::query()->create(['staff_id' => $staff->id, 'service_id' => $service->id]);

            $localStart = CarbonImmutable::now('Asia/Karachi')->addDays(2)->setTime(11, 0);
            $weekday = $localStart->dayOfWeekIso - 1;
            BusinessHour::query()->create([
                'weekday' => $weekday,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);
            StaffWorkingHour::query()->create([
                'staff_id' => $staff->id,
                'weekday' => $weekday,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);

            $customers = [
                Customer::query()->create(['whatsapp_wa_id' => '923001112231']),
                Customer::query()->create(['whatsapp_wa_id' => '923001112232']),
            ];

            return [$service, $staff, $customers];
        });

        $localStart = CarbonImmutable::now('Asia/Karachi')->addDays(2)->setTime(11, 0);
        $utcStart = $localStart->utc()->toIso8601String();

        // Keep the first booking transaction open briefly after its insert.
        // The second independent process must wait on the tenant row lock,
        // then observe the first active hold during its in-transaction check.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION test_pause_concurrent_booking_insert()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                PERFORM pg_sleep(1.0);
                RETURN NEW;
            END;
            $$
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_pause_concurrent_booking_insert_trigger
            BEFORE INSERT ON appointments
            FOR EACH ROW
            EXECUTE FUNCTION test_pause_concurrent_booking_insert()
        SQL);

        $directory = sys_get_temp_dir().'/booking-workers-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $startPath = $directory.'/start';
        $readyPaths = [$directory.'/worker-1.ready', $directory.'/worker-2.ready'];
        $processes = [];

        try {
            foreach ($customers as $index => $customer) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/run-booking-worker.php'),
                    $tenant->id,
                    $customer->id,
                    $service->id,
                    $staff->id,
                    $utcStart,
                    $readyPaths[$index],
                    $startPath,
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path());

                $this->assertIsResource($process, 'Unable to start an independent booking worker.');
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
                    $this->fail('Booking worker '.($index + 1).' did not become ready: '.$detail);
                }
            }

            file_put_contents($startPath, 'go');
            $exitCodes = [];
            $workerErrors = [];

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
                    $this->fail('Booking worker exceeded the 15-second execution timeout.');
                }

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCodes[] = $status['exitcode'];
                proc_close($process);
                $processes[$index]['process'] = null;

                $this->assertContains($stdout, ["BOOKING_CREATED\n", "SLOT_REJECTED\n"]);
                if ($status['exitcode'] === 20) {
                    $workerErrors[] = trim($stderr);
                    $this->assertNotSame('', trim($stderr), 'Rejected worker must explain why the slot was rejected.');
                } else {
                    $this->assertSame('', $stderr, 'Successful booking worker emitted an unexpected error.');
                }
            }

            sort($exitCodes);
            $this->assertSame([0, 20], $exitCodes, 'Exactly one customer must acquire the slot; the other must be rejected. Worker diagnostics: '.implode(' | ', $workerErrors));
            $this->assertSame(1, DB::table('appointments')->where('tenant_id', $tenant->id)->count());
            $this->assertDatabaseHas('appointments', [
                'tenant_id' => $tenant->id,
                'staff_id' => $staff->id,
                'starts_at' => $localStart->utc()->format('Y-m-d H:i:s'),
                'status' => 'pending_hold',
            ]);
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

            DB::unprepared('DROP TRIGGER IF EXISTS test_pause_concurrent_booking_insert_trigger ON appointments');
            DB::unprepared('DROP FUNCTION IF EXISTS test_pause_concurrent_booking_insert()');
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
