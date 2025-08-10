<?php

namespace App;

use Swoole\Coroutine\Channel;
use Redis;

class RedisPool
{
    private Channel $pool;

    public function __construct(int $size = 10)
    {
        $this->pool = new Channel($size);
        for ($i = 0; $i < $size; $i++) {
            $redis = new Redis();
            // read_timeout para manter a conexão viva durante blPop
            $redis->pconnect('cache', 6379, 0.0, null, 0, 300); 
            $this->put($redis);
        }
    }

    public function get(): Redis
    {
        return $this->pool->pop();
    }

    public function put(Redis $redis): void
    {
        $this->pool->push($redis);
    }

    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $redis = $this->pool->pop(0.001);
            if ($redis instanceof Redis) {
                $redis->close();
            }
        }
        $this->pool->close();
    }
}