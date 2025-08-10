<?php

require __DIR__ . '/vendor/autoload.php';

\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

use App\RedisPool;
use App\PdoPool;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\HealthCheck;
use App\PaymentProcessor;

$server = new Server("0.0.0.0", 80, SWOOLE_BASE);

$server->set([
    'worker_num' => 1, // Optimal for 0.5 CPU limit
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
    'log_file' => '/dev/stderr' // Redirect logs to stderr for Docker
]);

$processor = null;
$redisPool = null; // Renamed for clarity
$pdoPool = null;   // Add pdoPool

// This callback will be executed when the worker process starts
$server->on('workerStart', function (Server $server, int $workerId) use (&$processor, &$redisPool, &$pdoPool) {
    // Pool sizes can be configured via env vars in a real app
    $redisPool = new RedisPool(22);
    $pdoPool = new PdoPool(15); // A pool for PDO connections

    $processor = new PaymentProcessor($redisPool, $pdoPool);

    if (getenv('IS_HEALTH_CHECKER') === 'true') {
        go(function() use($redisPool) {
            $healthCheck = new HealthCheck($redisPool);
            $healthCheck->start();
        });
    }

    $paymentWorker = new \App\PaymentWorker($redisPool, $pdoPool);
    $paymentWorker->start(20);
});

// This callback will be executed when the worker process stops
$server->on('workerStop', function(Server $server, int $workerId) use ($redisPool, $pdoPool) {
    if ($redisPool) {
        error_log("Closing Redis pool.");
        $redisPool->close();
    }
    if ($pdoPool) {
        error_log("Closing PDO pool.");
        $pdoPool->close();
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
        error_log("Error processing request: " . $e->getMessage());
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