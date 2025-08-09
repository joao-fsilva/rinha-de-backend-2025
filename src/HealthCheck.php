<?php

namespace App;

use Redis;

class HealthCheck
{
    private Redis $redis;

    private const SERVICES = [
        'default' => ['host' => 'payment-processor-default', 'port' => 8080],
        'fallback' => ['host' => 'payment-processor-fallback', 'port' => 8080]
    ];

    private const HEALTH_CHECK_INTERVAL_MS = 5000; // 5 segundos
    private const HEALTH_KEY_TTL_S = 10; // Chave expira em 10s se não for atualizada
    private const LATENCY_KEY_PREFIX = 'service:latency:';
    private const UNHEALTHY_LATENCY = 99999; // Valor para representar um serviço offline/lento

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->pconnect('cache');
    }

    public function start(): void
    {
        $this->runChecks(); // Executa uma vez imediatamente ao iniciar
        
        \Swoole\Timer::tick(self::HEALTH_CHECK_INTERVAL_MS, function () {
            $this->runChecks();
        });
    }

    private function runChecks(): void
    {
        foreach (self::SERVICES as $name => $service) {
            go(function () use ($name, $service) {
                $this->checkService($name, $service['host'], $service['port']);
            });
        }
    }

    private function checkService(string $name, string $host, int $port): void
    {
        $latency = self::UNHEALTHY_LATENCY; // Assume o pior
        try {
            $client = new \Swoole\Coroutine\Http\Client($host, $port);
            $client->set(['timeout' => 4]);
            $client->get('/payments/service-health');
            
            if ($client->statusCode >= 200 && $client->statusCode < 300 && !empty($client->body)) {
                $body = json_decode($client->body, true);
                if (isset($body['failing']) && $body['failing'] === false) {
                    $latency = $body['minResponseTime'] ?? self::UNHEALTHY_LATENCY;
                }
            } else {
                error_log("NAME=$name QUEBRADO. status code: {$client->statusCode}, body: {$client->body}");
            }
            $client->close();
        } catch (\Throwable $e) {
            error_log("Health check failed for $name: " . $e->getMessage());
        } finally {
            error_log("Service $name latency: $latency ms UPDATED!");
            $this->redis->set(self::LATENCY_KEY_PREFIX . $name, $latency, ['ex' => self::HEALTH_KEY_TTL_S]);
        }
    }
}