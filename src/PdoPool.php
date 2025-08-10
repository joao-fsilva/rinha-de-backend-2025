<?php

namespace App;

use PDO;
use Swoole\Coroutine\Channel;

class PdoPool
{
    private Channel $pool;

    public function __construct(int $size = 10)
    {
        $this->pool = new Channel($size);
        for ($i = 0; $i < $size; $i++) {
            $user = 'rinhabackend';
            $password ='rinhabackend';
            $dsn = "pgsql:host=db;port=5432;dbname=rinhabackend";

            // High-performance, reusing pool.
            // ATTR_PERSISTENT MUST be disabled.
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];

            $pdo = new PDO($dsn, $user, $password, $options);

            $this->put($pdo);
        }
    }

    public function get(): PDO
    {
        return $this->pool->pop();
    }

    public function put(PDO $pdo): void
    {
        $this->pool->push($pdo);
    }

    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $pdo = $this->pool->pop(0.001);
            if ($pdo instanceof PDO) {
                // PDO objects are closed by destroying them.
                $pdo = null;
            }
        }
        $this->pool->close();
    }
}