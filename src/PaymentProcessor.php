<?php

namespace App;

use Redis;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine\Http\Client;
use PDO;

class PaymentProcessor
{
    private Redis $redis;
    private PDO $pdo;
    private const REQUEST_TIMEOUT = 5;
    private const ACCEPTABLE_LATENCY_MS = 800;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->pconnect(getenv('REDIS_HOST') ?: 'cache');
        $this->pdo = (new Database())->getPdo();
    }

    public function handlePayment(Request $request, Response $response): void
    {
        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['correlationId']) || !isset($data['amount'])) {
            $response->status(400);
            return;
        }

        $correlationId = (string) $data['correlationId'];
        $amount = (int)($data['amount'] * 100);

        $default_latency = $this->getLatency('default');
        $fallback_latency = $this->getLatency('fallback');

        $use_default = $default_latency < self::ACCEPTABLE_LATENCY_MS;
        $use_fallback = $fallback_latency < self::ACCEPTABLE_LATENCY_MS;
        error_log("Default latency: $default_latency, Fallback latency: $fallback_latency");
        error_log("Use fallback: $use_fallback");
        error_log("Use default: $use_default");

        if ($use_default && $default_latency <= $fallback_latency) {
            error_log("if 1: Using default processor.");
            if ($this->tryProcessor('default', $data)) {
                error_log("Processing with default.");
                $this->saveTransaction($correlationId, $amount, 'default', true);
                error_log("Processed with default processor successfully.");
                $response->status(200);
                return;
            }
        }

        if ($use_fallback) {
            error_log("if 2: Using fallback processor.");
            if ($this->tryProcessor('fallback', $data)) {
                error_log("Processing with fallback.");
                $this->saveTransaction($correlationId, $amount, 'fallback', true);
                error_log("Processed with fallback processor successfully.");
                $response->status(200);
                return;
            }
        }
        
        if (!$use_default && $this->tryProcessor('default', $data)) {
            error_log("Processed with default processor after fallback failure.");
            $this->saveTransaction($correlationId, $amount, 'default', true);
            $response->status(200);
            return;
        }

        error_log("If default fallback is enabled, it will be used.");
        $this->saveTransaction($correlationId, $amount, 'fallback', false);
        error_log("All processors failed for correlation ID: $correlationId");
        $response->status(500);
    }
    
    private function getLatency(string $serviceName): int
    {
        return (int)($this->redis->get('service:latency:' . $serviceName) ?? 99999);
    }

    public function handleSummary(Request $request, Response $response): string
    {
        $from = $request->get['from'] ?? '1970-01-01T00:00:00.000Z';
        $to = $request->get['to'] ?? '2100-01-01T00:00:00.000Z';

        $stmt = $this->pdo->prepare(
            "SELECT processor, COUNT(1) as totalRequests, SUM(amount) as totalAmount
             FROM transactions
             WHERE success = true AND created_at BETWEEN :from AND :to
             GROUP BY processor"
        );
        $stmt->execute([':from' => $from, ':to' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'default' => ['totalRequests' => 0, 'totalAmount' => 0],
            'fallback' => ['totalRequests' => 0, 'totalAmount' => 0],
        ];

        foreach ($rows as $row) {
            $processor = $row['processor'];
            if (isset($summary[$processor])) {
                $summary[$processor]['totalRequests'] = (int) $row['totalrequests'];
                $summary[$processor]['totalAmount'] = (float)($row['totalamount'] / 100);
            }
        }

        $response->header('Content-Type', 'application/json');
        return json_encode($summary);
    }

    private function tryProcessor(string $serviceName, array $data): bool
    {
        $host = $serviceName === 'default' ? 'payment-processor-default' : 'payment-processor-fallback';
        $client = new Client($host, 8080);
        $client->setHeaders(['Content-Type' => 'application/json', 'Connection' => 'keep-alive']);
        $client->set(['timeout' => self::REQUEST_TIMEOUT]);
        $client->post('/payments', json_encode($data));

        $success = $client->statusCode >= 200 && $client->statusCode < 300;

        error_log("Processor $serviceName response status: " . $client->statusCode);
        error_log("Processor $serviceName response body: " . $client->body);
        
        if (!$success) {
            $this->redis->set('service:latency:' . $serviceName, 99999, ['ex' => 5]);
        }
        
        $client->close();
        return $success;
    }

    private function saveTransaction(string $correlationId, int $amount, string $processor, bool $success): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO transactions (correlation_id, amount, processor, success, created_at) VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$correlationId, $amount, $processor, ($success ? 1 : 0)]);
    }
}