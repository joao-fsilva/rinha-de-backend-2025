<?php

namespace App;

use Swoole\Http\Request;
use Swoole\Http\Response;
use PDO;
use Redis;
use App\RedisPool;
use App\PdoPool;

class PaymentProcessor
{
    private const PAYMENT_QUEUE_KEY = 'payment_queue';

    public function __construct(private RedisPool $redisPool, private PdoPool $pdoPool)
    {
    }

    public function handlePayment(Request $request, Response $response): void
    {
        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['correlationId']) || !isset($data['amount'])) {
            $response->status(400);
            return;
        }

        $redis = null;
        try {
            $redis = $this->redisPool->get();
            $redis->rPush(self::PAYMENT_QUEUE_KEY, $request->getContent());
            $response->status(200);
        } catch (\Throwable $e) {
            error_log("Failed to queue payment: " . $e->getMessage());
            $response->status(500);
        } finally {
            if ($redis) {
                $this->redisPool->put($redis);
            }
        }
    }

    public function handleSummary(Request $request, Response $response): string
    {
        $from = $request->get['from'] ?? '1970-01-01T00:00:00.000Z';
        $to = $request->get['to'] ?? '2100-01-01T00:00:00.000Z';

        $pdo = null;
        $rows = [];
        try {
            $pdo = $this->pdoPool->get();
            $stmt = $pdo->prepare(
                "SELECT processor, COUNT(1) as totalRequests, SUM(amount) as totalAmount
                 FROM transactions
                 WHERE created_at BETWEEN :from AND :to
                 GROUP BY processor"
            );
            $stmt->execute([':from' => $from, ':to' => $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("Failed to get summary: " . $e->getMessage());
            $response->status(500);
            return '{"error":"Failed to retrieve summary"}';
        } finally {
            if ($pdo) {
                $this->pdoPool->put($pdo);
            }
        }

        $summary = [
            'default' => ['totalRequests' => 0, 'totalAmount' => 0],
            'fallback' => ['totalRequests' => 0, 'totalAmount' => 0],
        ];

        foreach ($rows as $row) {
            $processor = $row['processor'];
            if (isset($summary[$processor])) {
                $summary[$processor]['totalRequests'] = (int) $row['totalrequests'];
                $summary[$processor]['totalAmount'] = (float)$row['totalamount'];
            }
        }

        $response->header('Content-Type', 'application/json');
        return json_encode($summary);
    }
}