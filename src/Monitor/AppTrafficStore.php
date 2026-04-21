<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Monitor;

use CreatCode\IotMonitor\RedisManager;
use CreatCode\IotMonitor\StoreInterface;

class AppTrafficStore implements StoreInterface
{
    /**
     * 累加分钟流量统计
     */
    public function incrementMinute(string $minute, array $fields, int $ttl): void
    {
        $key = "MonitorTraffic:minute:{$minute}";

        RedisManager::pipeline(function ($redis) use ($key, $fields, $ttl) {
            foreach ($fields as $field => $value) {
                if ($value > 0) {
                    $redis->hIncrBy($key, $field, $value);
                }
            }

            $redis->expire($key, $ttl);
        });
    }

    /**
     * 读取分钟流量统计
     */
    public function getMinute(string $minute): array
    {
        return RedisManager::hGetAll("MonitorTraffic:minute:{$minute}") ?: [];
    }
}
