<?php

use App\\Jobs\\ProcessMetaWebhookEvent;
use Illuminate\\Contracts\\Console\\Kernel;

[$script, $eventId, $readyPath, $startPath] = $argv + [null, null, null, null];

if (! is_string($eventId) || ! is_string($readyPath) || ! is_string($startPath)) {
    fwrite(STDERR, "Usage: php run-webhook-worker.php <event-id> <ready-file> <start-file>\n");
    exit(2);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
    (new ProcessMetaWebhookEvent($eventId))->handle();
    $app['db']->disconnect();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    $app['db']->disconnect();
    exit(1);
}
