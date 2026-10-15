<?php

/**
 * One real OS process of a concurrency test. It boots the app, opens its OWN database connection, waits for the start
 * barrier (a file created by the parent) and then sends a single request through the real HTTP kernel.
 *
 * Usage: php concurrency_worker.php <job.json>
 * Prints ONE json line: {status, body, headers, started, finished, pid}
 */

require __DIR__ . '/../../vendor/autoload.php';

$job = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\DB::connection()->getPdo();                       // connect BEFORE the barrier so only the work races
file_put_contents($job['ready'], '1');                                         // tell the parent this process is armed

$deadline = microtime(true) + 30;
while (! file_exists($job['go']) && microtime(true) < $deadline) {
    usleep(100);
}

$server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
foreach ($job['headers'] ?? [] as $name => $value) {
    $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
}

$started = microtime(true);
$request = Illuminate\Http\Request::create($job['uri'], $job['method'], [], [], [], $server, $job['raw_body'] ?? null);
$response = $kernel->handle($request);
$finished = microtime(true);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
    'replayed' => $response->headers->get('Idempotent-Replayed') === 'true',
    'started' => $started,
    'finished' => $finished,
    'pid' => getmypid(),
]), "\n";
