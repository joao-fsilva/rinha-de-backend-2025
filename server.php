<?php

require __DIR__ . '/vendor/autoload.php';

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\HealthCheck;
use App\PaymentProcessor;

$server = new Server("0.0.0.0", 80, SWOOLE_BASE);

$server->set([
    'worker_num' => 20, // Optimal for 0.5 CPU limit
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
    'log_file' => '/dev/stderr' // Redirect logs to stderr for Docker
]);

$processor = null;

$server->on('workerStart', function (Server $server, int $workerId) use (&$processor) {
    $processor = new PaymentProcessor();

    if ($workerId === 0 && getenv('IS_HEALTH_CHECKER') === 'true') {
        go(function() {
            $healthCheck = new HealthCheck();
            $healthCheck->start();
        });
    }
});

$server->on('request', function (Request $request, Response $response) use (&$processor) {
    $body = null;
    try {
        if ($request->server['request_uri'] === '/payments' && $request->server['request_method'] === 'POST') {
            $processor->handlePayment($request, $response);
        } elseif (str_starts_with($request->server['request_uri'], '/payments-summary') && $request->server['request_method'] === 'GET') {
            $body = $processor->handleSummary($request, $response);
        } else {
            $response->status(404);
            $body = "Not Found";
        }
    } catch (Throwable $e) {
        if ($response->isWritable()) {
            $response->status(500);
            $body = "Internal Server Error";
        }
    } finally {
        if ($response->isWritable()) {
            $response->end($body);
        }
    }
});

$server->start();