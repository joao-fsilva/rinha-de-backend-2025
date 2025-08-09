<?php

error_log("server.php: Starting script...");

require __DIR__ . '/vendor/autoload.php';

error_log("server.php: Autoload loaded.");

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use App\HealthCheck;
use App\PaymentProcessor;

error_log("server.php: Classes imported.");

$server = new Server("0.0.0.0", 80, SWOOLE_BASE);
error_log("server.php: Server instance created.");

$server->set([
    'worker_num' => 1, // Optimal for 0.5 CPU limit
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
    'log_file' => '/dev/stderr' // Redirect logs to stderr for Docker
]);
error_log("server.php: Server settings applied.");

$processor = null;

$server->on('workerStart', function (Server $server, int $workerId) use (&$processor) {
    error_log("server.php: workerStart event triggered. $workerId");
    $processor = new PaymentProcessor();

    if ($workerId === 0 && getenv('IS_HEALTH_CHECKER') === 'true') {
        error_log("Starting HealthCheck on this worker.");
        go(function() {
            $healthCheck = new HealthCheck();
            $healthCheck->start();
        });
    }
});

$server->on('request', function (Request $request, Response $response) use (&$processor) {
    error_log("server.php: Request event triggered.");
    try {
        if ($request->server['request_uri'] === '/payments' && $request->server['request_method'] === 'POST') {
            $processor->handlePayment($request, $response);
        } elseif (str_starts_with($request->server['request_uri'], '/payments-summary') && $request->server['request_method'] === 'GET') {
            $processor->handleSummary($request, $response);
        } else {
            $response->status(404);
            $response->end("Not Found");
        }
    } catch (Throwable $e) {
        error_log("Internal Server Error: " . $e->getMessage());
        $response->status(500);
        $response->end("Internal Server Error");
    }
});

error_log("server.php: Calling server->start().");
$server->start();