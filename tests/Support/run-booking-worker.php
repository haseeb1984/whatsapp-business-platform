<?php

use App\Services\Booking\AppointmentBookingService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

[$script, $tenantId, $customerId, $serviceId, $staffId, $startsAt, $readyPath, $startPath] = $argv + array_fill(0, 8, null);

if (! is_string($tenantId) || ! is_string($customerId) || ! is_string($serviceId)
    || ! is_string($staffId) || ! is_string($startsAt) || ! is_string($readyPath) || ! is_string($startPath)) {
    fwrite(STDERR, "Invalid booking worker arguments.\n");
    exit(2);
}

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $exception) {
    file_put_contents($readyPath.'.error', $exception::class.': '.$exception->getMessage());
    exit(10);
}

file_put_contents($readyPath, (string) getmypid());
$deadline = microtime(true) + 10;
while (! is_file($startPath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for booking barrier.\n");
        exit(3);
    }
    usleep(10_000);
}

try {
    TenantContext::runForTenant($tenantId, fn () => app(AppointmentBookingService::class)->createHold(
        $customerId,
        $serviceId,
        $staffId,
        CarbonImmutable::parse($startsAt)->setTimezone('UTC'),
        'concurrent-booking-'.$customerId,
        'api',
    ));
    fwrite(STDOUT, "BOOKING_CREATED\n");
    $app['db']->disconnect();
    exit(0);
} catch (ValidationException $exception) {
    fwrite(STDOUT, "SLOT_REJECTED\n");
    fwrite(STDERR, json_encode($exception->errors(), JSON_THROW_ON_ERROR)."\n");
    $app['db']->disconnect();
    exit(20);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString());
    $app['db']->disconnect();
    exit(1);
}
