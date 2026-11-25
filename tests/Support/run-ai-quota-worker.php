<?php

use App\Services\AI\AiUsageQuota;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

[$script, $tenantId, $limit, $readyPath, $startPath] = $argv + [null, null, null, null, null];

if (! is_string($tenantId) || ! is_string($limit) || ! is_string($readyPath) || ! is_string($startPath)) {
    fwrite(STDERR, "Usage: php run-ai-quota-worker.php <tenant-id> <limit> <ready-file> <start-file>\n");
    exit(2);
}

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['services.groq.daily_request_limit' => max(0, (int) $limit)]);
} catch (Throwable $exception) {
    file_put_contents($readyPath.'.error', $exception::class.': '.$exception->getMessage());
    exit(10);
}

file_put_contents($readyPath, (string) getmypid());

$deadline = microtime(true) + 10;
while (! is_file($startPath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the worker barrier.\n");
        exit(3);
    }

    usleep(10_000);
}

try {
    $reserved = TenantContext::runForTenant(
        $tenantId,
        fn (): bool => app(AiUsageQuota::class)->reserveRequest(),
    );
    fwrite(STDOUT, $reserved ? "reserved\n" : "blocked\n");
    $app['db']->disconnect();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    $app['db']->disconnect();
    exit(1);
}
