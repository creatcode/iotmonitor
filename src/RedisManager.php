<?php

namespace CreatCode\IotMonitor;

use think\facade\Cache;

/**
 * Redis 连接管理器
 * 适用于 Webman 常驻内存环境下的 Redis 连接复用、断线重连和批量执行
 */
class RedisManager
{
    /**
     * Redis 底层连接实例
     *
     * @var mixed|null
     */
    protected static $redis = null;

    /**
     * 最近一次探活时间
     *
     * @var int
     */
    protected static $lastPingAt = 0;

    /**
     * 默认探活间隔，单位秒
     *
     * @var int
     */
    protected static $pingInterval = 15;

    /**
     * 获取 Redis 连接
     *
     * @param bool $forceReconnect
     * @return mixed
     * @throws \Throwable
     */
    public static function get(bool $forceReconnect = false)
    {
        if ($forceReconnect || self::$redis === null) {
            self::$redis = Cache::store('redis')->handler();
            self::$lastPingAt = 0;
        }

        return self::$redis;
    }

    /**
     * 获取底层 Redis 连接
     *
     * @param bool $forceReconnect
     * @return mixed
     * @throws \Throwable
     */
    public static function connection(bool $forceReconnect = false)
    {
        return self::get($forceReconnect);
    }

    /**
     * Redis 探活
     *
     * @throws \Throwable
     */
    public static function ping(): void
    {
        $redis = self::get();

        $now = time();
        $interval = (int)ManagerHelper::dbConfig('redis_ping_interval', self::$pingInterval);
        if ($now - self::$lastPingAt < $interval) {
            return;
        }

        $pong = $redis->ping();
        if ($pong !== true && stripos((string)$pong, 'PONG') === false) {
            throw new \RuntimeException('Redis ping failed');
        }

        self::$lastPingAt = $now;
    }

    /**
     * 重连 Redis
     *
     * @return mixed
     * @throws \Throwable
     */
    public static function reconnect()
    {
        self::$redis = null;
        self::$lastPingAt = 0;

        return self::get(true);
    }

    /**
     * 判断是否为 Redis 连接异常
     *
     * @param \Throwable $e
     * @return bool
     */
    protected static function isConnectionException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $keywords = [
            'server has gone away',
            'lost connection',
            'connection lost',
            'connection reset',
            'broken pipe',
            'send of',
            'read error on connection',
            'socket',
            'went away',
            'connection closed',
            'connection refused',
            'connection is empty',
            'redis ping failed',
        ];

        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 安全执行 Redis 操作
     *
     * @param callable $callback
     * @param mixed $default
     * @param bool $swallowException
     * @return mixed
     * @throws \Throwable
     */
    public static function call(callable $callback, $default = null, bool $swallowException = false)
    {
        try {
            return $callback(self::get());
        } catch (\Throwable $e) {
            if (!self::isConnectionException($e)) {
                if ($swallowException) {
                    ManagerHelper::log('redis.log', '[' . date('Y-m-d H:i:s') . '] non_connection_fail: ' . $e->getMessage());
                    return $default;
                }

                throw $e;
            }

            ManagerHelper::log('redis.log', '[' . date('Y-m-d H:i:s') . '] first_fail: ' . $e->getMessage());

            try {
                return $callback(self::reconnect());
            } catch (\Throwable $retryException) {
                ManagerHelper::log('redis.log', '[' . date('Y-m-d H:i:s') . '] retry_fail: ' . $retryException->getMessage());

                if ($swallowException) {
                    return $default;
                }

                throw $retryException;
            }
        }
    }

    /**
     * 使用 Redis pipeline 批量执行命令
     * 回调中应尽量只组织 Redis 命令，避免夹杂耗时业务逻辑
     *
     * @param callable $callback
     * @return array
     * @throws \Throwable
     */
    public static function pipeline(callable $callback): array
    {
        return self::call(function ($redis) use ($callback) {
            $redis->multi(\Redis::PIPELINE);

            try {
                $callback($redis);
                return $redis->exec();
            } catch (\Throwable $e) {
                // pipeline 组包阶段异常时重置连接，避免连接残留在批量命令状态
                self::$redis = null;
                self::$lastPingAt = 0;
                throw $e;
            }
        });
    }

    /**
     * 根据方法名执行 Redis 调用
     *
     * @param string $method
     * @param array $arguments
     * @param mixed $default
     * @param bool $swallowException
     * @return mixed
     * @throws \Throwable
     */
    public static function execute($method, array $arguments = [], $default = null, bool $swallowException = false)
    {
        return self::call(function ($redis) use ($method, $arguments) {
            return call_user_func_array([$redis, $method], $arguments);
        }, $default, $swallowException);
    }

    /**
     * 支持 RedisManager::hGetAll() 这类静态调用
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic($method, $arguments)
    {
        return self::execute($method, $arguments);
    }
}
