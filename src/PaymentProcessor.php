<?php

namespace App;

use Swoole\Http\Request;
use Swoole\Http\Response;

class PaymentProcessor
{
    private const PAYMENT_QUEUE_KEY = 'payment_queue';

    public function __construct(private RedisPool $redisPool)
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

        $redis = null;
        $summary = [
            'default' => ['totalRequests' => 0, 'totalAmount' => 0],
            'fallback' => ['totalRequests' => 0, 'totalAmount' => 0]
        ];

        try {
            $redis = $this->redisPool->get();
            $allTransactions = $redis->lRange('transactions', 0, -1);
            foreach ($allTransactions as $item) {
                $transaction = json_decode($item, true);
                if (!$transaction) {
                    continue;
                }
                $createdAt = $transaction['created_at'] ?? null;
                $processor = $transaction['processor'] ?? null;
                $amount = $transaction['amount'] ?? 0;
                if (!$createdAt || !$processor) {
                    continue;
                }
                if ($createdAt >= $from && $createdAt <= $to) {
                    $summary[$processor]['totalRequests']++;
                    $summary[$processor]['totalAmount'] += $amount;
                }
            }

            $summary['default']['totalAmount'] = round($summary['default']['totalAmount'], 2);
            $summary['fallback']['totalAmount'] = round($summary['fallback']['totalAmount'], 2);

            $response->header('Content-Type', 'application/json');
            return json_encode($summary);
        } catch (\Throwable $e) {
            error_log("Failed to get summary: " . $e->getMessage());
            $response->status(500);
            return '{"error":"Failed to retrieve summary"}';
        } finally {
            if ($redis) {
                $this->redisPool->put($redis);
            }
        }
    }
}