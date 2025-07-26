<?php

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\HealthCheck;
use App\PaymentProcessor;

$server = new Server("0.0.0.0", 80, SWOOLE_BASE);
$server->set([
    'worker_num' => (int) shell_exec('nproc') ?: 4, // Use all available cores
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
    'log_file' => '/dev/stderr' // Redirect logs to stderr for Docker
]);

// Initialize Health Checker in the first worker
$server->on('workerStart', function (Server $server, int $workerId) {
    if ($workerId === 0) {
        go(function() {
            $healthCheck = new HealthCheck();
            $healthCheck->start();
        });
    }
});

// Handle incoming requests
$server->on('request', function (Request $request, Response $response) {
    try {
        $processor = new PaymentProcessor();
        if ($request->server['request_uri'] === '/payments' && $request->server['request_method'] === 'POST') {
            $processor->handlePayment($request, $response);
        } elseif (str_starts_with($request->server['request_uri'], '/payments-summary') && $request->server['request_method'] === 'GET') {
            $processor->handleSummary($request, $response);
        } else {
            $response->status(404)->end();
        }
    } catch (Throwable $e) {
        error_log("Internal Server Error: " . $e->getMessage());
        $response->status(500)->end();
    }
});

$server->start();
