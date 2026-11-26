<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

[$script, $tenantId, $readyPath, $startPath] = $argv + [null, null, null, null];

if (! is_string($tenantId) || ! is_string($readyPath) || ! is_string($startPath)) {
    fwrite(STDERR, "Usage: php run-outbox-dispatcher.php <tenant-id> <ready-file> <start-file>\n");
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
        fwrite(STDERR, "Timed out waiting for the dispatcher barrier.\n");
        exit(3);
    }

    usleep(10_000);
}

try {
    $exitCode = Artisan::call('messages:dispatch-pending', ['--tenant' => $tenantId]);
    fwrite(STDOUT, Artisan::output());
    $app['db']->disconnect();

    exit($exitCode);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    $app['db']->disconnect();
    exit(1);
}
