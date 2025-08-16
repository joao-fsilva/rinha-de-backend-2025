<?php

namespace App;

use Redis;
use Throwable;
use RedisException;
use Swoole\Coroutine\Http\Client;

class PaymentWorker
{
    private const PAYMENT_QUEUE_KEY = 'payment_queue';
    private const REQUEST_TIMEOUT = 5;
    private const ACCEPTABLE_LATENCY_MS = 10;

    public function __construct(private RedisPool $redisPool)
    {
    }

    public function start(int $concurrency): void
    {
        for ($i = 0; $i < $concurrency; $i++) {
            go(function () use ($i) {
                $redis = $this->redisPool->get();

                while (true) {
                    try {
                        $item = $redis->blPop(self::PAYMENT_QUEUE_KEY, 0);
                        if ($item && isset($item[1])) {
                            $data = json_decode($item[1], true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $this->processPayment($redis, $data);
                            } else {
                                error_log("Worker #{$i}: Failed to decode JSON: " . $item[1]);
                            }
                        }
                    } catch (RedisException $e) {
                        error_log("Worker #{$i}: Redis error: " . $e->getMessage() . ". Re-acquiring connection.");
                        if ($redis) { $redis->close(); }
                        $redis = $this->redisPool->get();
                    } catch (Throwable $e) {
                        error_log("Worker #{$i}: Unexpected error: " . $e->getMessage());
                        \Swoole\Coroutine::sleep(1);
                    }
                }
            });
        }
    }

    private function processPayment(Redis $redis, array $data): void
    {
        $correlationId = (string) $data['correlationId'];
        $amount = (float) $data['amount'];

        $preciseTimestamp = microtime(true);
        $date = \DateTime::createFromFormat('U.u', sprintf('%.6f', $preciseTimestamp));
        $requestedAt = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        $data['requestedAt'] = $requestedAt;

        $default_latency = $this->getLatency($redis, 'default');
        $fallback_latency = $this->getLatency($redis, 'fallback');

        $use_default = $default_latency < self::ACCEPTABLE_LATENCY_MS;
        $use_fallback = $fallback_latency < self::ACCEPTABLE_LATENCY_MS;

        if ($use_default && $default_latency <= $fallback_latency) {
            if ($this->tryProcessor($redis, 'default', $data)) {
                $this->saveTransaction($correlationId, $amount, 'default', $requestedAt);
                return;
            }
        }

        if ($use_fallback) {
            if ($this->tryProcessor($redis, 'fallback', $data)) {
                $this->saveTransaction($correlationId, $amount, 'fallback', $requestedAt);
                return;
            }
        }
        
        if (!$use_default && $this->tryProcessor($redis, 'default', $data)) {
            $this->saveTransaction($correlationId, $amount, 'default', $requestedAt);
            return;
        }

        $redis->rPush(self::PAYMENT_QUEUE_KEY, json_encode($data));
    }
    
    private function getLatency(Redis $redis, string $serviceName): int
    {
        return (int)($redis->get('service:latency:' . $serviceName) ?? 99999);
    }

    private function tryProcessor(Redis $redis, string $serviceName, array $data): bool
    {
        $host = $serviceName === 'default' ? 'payment-processor-default' : 'payment-processor-fallback';
        $client = new Client($host, 8080);
        $client->setHeaders(['Content-Type' => 'application/json', 'Connection' => 'keep-alive']);
        $client->set(['timeout' => self::REQUEST_TIMEOUT]);
        $client->post('/payments', json_encode($data));

//        error_log("statusCode: " . $client->statusCode . " for service: " . $serviceName);

        $success = $client->statusCode >= 200 && $client->statusCode < 300;

        if (!$success) {
            $redis->set('service:latency:' . $serviceName, 99999, ['ex' => 5]);
        }
        
        $client->close();
        return $success;
    }

    private function saveTransaction(string $correlationId, float $amount, string $processor, string $requestedAt): void
    {
        $redis = null;
        try {
            $redis = $this->redisPool->get();
            $transaction = [
                'correlation_id' => $correlationId,
                'amount' => $amount,
                'processor' => $processor,
                'created_at' => $requestedAt,
            ];
            $redis->rPush('transactions', json_encode($transaction));
        } catch (\Throwable $e) {
            error_log("Failed to save transaction in Redis: " . $e->getMessage());
        } finally {
            if ($redis) {
                $this->redisPool->put($redis);
            }
        }
    }
}
