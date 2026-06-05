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
            self::$redis = Cache::store('redis', $forceReconnect)->handler();
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
        if ($interval > 0 && $now - self::$lastPingAt < $interval) {
            return;
        }

        try {
            $pong = $redis->ping();
            if ($pong !== true && stripos((string)$pong, 'PONG') === false) {
                throw new \RuntimeException('Redis ping failed');
            }

            self::$lastPingAt = $now;
        } catch (\Throwable $e) {
            // 探活失败后丢弃连接，避免常驻进程继续复用坏连接
            self::discardConnection($redis, 'ping_fail: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 重连 Redis
     *
     * @return mixed
     * @throws \Throwable
     */
    public static function reconnect()
    {
        if (self::$redis) {
            try {
                self::$redis->close();
            } catch (\Throwable $e) {
                // 连接已断开时关闭失败可忽略
            }
        }

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
            'connection timed out',
            'operation timed out',
            'timed out',
            'no route to host',
            'network is unreachable',
            'php_network_getaddresses',
            'resource temporarily unavailable',
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
     * @param callable $callback 要执行的 Redis 命令
     * @param mixed $default 默认值，当异常被静默处理时返回
     * @param bool $swallowException 是否静默处理异常，不抛出异常
     * @param bool $retryOnConnectionException 连接异常时是否重试一次，仅适用于可重复执行的命令
     * @return mixed
     * @throws \Throwable
     */
    public static function call(callable $callback, $default = null, bool $swallowException = false, bool $retryOnConnectionException = true)
    {
        try {
            return $callback(self::get());
        } catch (\Throwable $e) {
            if (!self::isConnectionException($e) || !$retryOnConnectionException) {
                if ($swallowException) {
                    self::log('non_connection_fail: ' . $e->getMessage());
                    return $default;
                }

                throw $e;
            }

            self::log('first_fail: ' . $e->getMessage());

            try {
                return $callback(self::reconnect());
            } catch (\Throwable $retryException) {
                self::log('retry_fail: ' . $retryException->getMessage() . ' | first_fail: ' . $e->getMessage());

                if ($swallowException) {
                    return $default;
                }

                throw $retryException;
            }
        }
    }

    /**
     * 使用 Redis pipeline 批量执行命令
     * 默认不自动重试，避免非幂等命令被重复执行
     *
     * @param callable $callback
     * @param bool $retryOnConnectionException 连接异常时是否重试一次，仅适用于可重复执行的批量命令
     * @return array
     * @throws \Throwable
     */
    public static function pipeline(callable $callback, bool $retryOnConnectionException = false): array
    {
        $redis = null;

        try {
            $redis = self::get();
            return self::runPipeline($redis, $callback);
        } catch (\Throwable $e) {
            if ($redis !== null) {
                self::discardConnection($redis, 'pipeline_fail: ' . $e->getMessage());
            }

            if (!$retryOnConnectionException || !self::isConnectionException($e)) {
                throw $e;
            }

            self::log('pipeline_retry: ' . $e->getMessage());

            $retryRedis = null;

            try {
                $retryRedis = self::reconnect();
                return self::runPipeline($retryRedis, $callback);
            } catch (\Throwable $retryException) {
                if ($retryRedis !== null) {
                    self::discardConnection($retryRedis, 'pipeline_retry_fail: ' . $retryException->getMessage());
                }

                throw $retryException;
            }
        }
    }

    /**
     * 执行一次 Redis pipeline
     *
     * @param mixed $redis
     * @param callable $callback
     * @return array
     * @throws \Throwable
     */
    private static function runPipeline($redis, callable $callback): array
    {
        $redis->multi(\Redis::PIPELINE);
        $callback($redis);

        $result = $redis->exec();
        if ($result === false) {
            throw new \RuntimeException('Redis pipeline exec failed');
        }

        return $result;
    }

    /**
     * 丢弃当前 Redis 连接
     *
     * @param mixed $redis
     * @param string $message
     * @return void
     */
    private static function discardConnection($redis, string $message): void
    {
        try {
            $redis->close();
        } catch (\Throwable $e) {
            // 连接可能已经断开，关闭失败可忽略
        }

        if (self::$redis === $redis) {
            self::$redis = null;
            self::$lastPingAt = 0;
        }

        self::log($message);
    }

    /**
     * 判断 Redis 命令是否为只读命令
     *
     * @param string $method
     * @return bool
     */
    protected static function isReadCommand(string $method): bool
    {
        return in_array(strtolower($method), [
            'get',
            'mget',
            'hget',
            'hgetall',
            'hexists',
            'llen',
            'scard',
            'zcard',
            'zcount',
            'ttl',
            'exists',
            'type',
            'lrange',
            'zrange',
        ], true);
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
        }, $default, $swallowException, self::isReadCommand((string)$method));
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

    /**
     * 写入运行日志
     *
     * @param string $message
     * @return void
     */
    private static function log(string $message): void
    {
        try {
            ManagerHelper::log('redis.log', '[' . date('Y-m-d H:i:s') . '] ' . $message);
        } catch (\Throwable $e) {
            // 日志失败不能影响 Redis 异常处理流程
        }
    }
}
