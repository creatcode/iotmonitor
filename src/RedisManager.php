<?php

namespace CreatCode\IotMonitor;

use think\facade\Cache;

/**
 * Redis 连接管理器。
 *
 * 适用于 Webman 常驻进程环境下的 Redis 连接复用、探活、断线重连和批量执行。
 */
class RedisManager
{
    /**
     * Redis 底层连接实例。
     *
     * @var mixed|null
     */
    protected static $redis = null;

    /**
     * 最近一次探活时间。
     *
     * @var int
     */
    protected static $lastPingAt = 0;

    /**
     * 默认探活间隔，单位秒。
     *
     * @var int
     */
    protected static $pingInterval = 15;

    /**
     * 获取 Redis 连接。
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
     * 获取底层 Redis 连接。
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
     * Redis 探活。
     *
     * @throws \Throwable
     */
    public static function ping(): void
    {
        $now = time();
        $interval = (int)ManagerHelper::dbConfig('redis_ping_interval', self::$pingInterval);
        if ($interval > 0 && $now - self::$lastPingAt < $interval) {
            return;
        }

        $redis = self::get();

        try {
            $pong = $redis->ping();
            if ($pong !== true && stripos((string)$pong, 'PONG') === false) {
                throw new \RuntimeException('Redis ping failed');
            }

            self::$lastPingAt = $now;
        } catch (\Throwable $e) {
            // 探活失败后丢弃旧连接，避免常驻进程继续复用坏连接。
            self::discardConnection($redis, 'ping_fail: ' . self::formatException($e));
            throw $e;
        }
    }

    /**
     * 重连 Redis。
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
                // 连接已经断开时关闭失败可忽略。
            }
        }

        self::$redis = null;
        self::$lastPingAt = 0;

        return self::get(true);
    }

    /**
     * 安全执行 Redis 操作。
     *
     * @param callable $callback 要执行的 Redis 命令
     * @param mixed $default 异常被静默处理时返回的默认值
     * @param bool $swallowException 是否静默处理异常
     * @param bool $retryOnConnectionException 连接异常时是否重试一次
     * @return mixed
     * @throws \Throwable
     */
    public static function call(callable $callback, $default = null, bool $swallowException = false, bool $retryOnConnectionException = true)
    {
        try {
            self::ping();
            return $callback(self::get());
        } catch (\Throwable $e) {
            if (!self::isConnectionException($e) || !$retryOnConnectionException) {
                if ($swallowException) {
                    self::log('non_connection_fail: ' . self::formatException($e));
                    return $default;
                }

                throw $e;
            }

            self::log('first_fail: ' . self::formatException($e));

            try {
                return $callback(self::reconnect());
            } catch (\Throwable $retryException) {
                self::log('retry_fail: ' . self::formatException($retryException) . ' | first_fail: ' . self::formatException($e));

                if ($swallowException) {
                    return $default;
                }

                throw $retryException;
            }
        }
    }

    /**
     * 显式安全写入入口。
     *
     * 只在调用方确认命令可接受兜底或可重试时使用，避免非幂等写命令被全局重复执行。
     *
     * @param string $method Redis 方法名
     * @param array $arguments Redis 方法参数
     * @param mixed $default 失败时返回的默认值
     * @param bool $retryOnConnectionException 连接异常时是否重试一次
     * @return mixed
     */
    public static function safeWrite(string $method, array $arguments = [], $default = null, bool $retryOnConnectionException = true)
    {
        return self::call(function ($redis) use ($method, $arguments) {
            return call_user_func_array([$redis, $method], $arguments);
        }, $default, true, $retryOnConnectionException);
    }

    /**
     * 使用 Redis pipeline 批量执行命令。
     *
     * 默认不自动重试，避免非幂等命令被重复执行。确认批量命令可重放时再传入 true。
     *
     * @param callable $callback
     * @param bool $retryOnConnectionException 连接异常时是否重试一次
     * @return array
     * @throws \Throwable
     */
    public static function pipeline(callable $callback, bool $retryOnConnectionException = false): array
    {
        $redis = null;

        try {
            self::ping();
            $redis = self::get();
            return self::runPipeline($redis, $callback);
        } catch (\Throwable $e) {
            if ($redis !== null) {
                self::discardConnection($redis, 'pipeline_fail: ' . self::formatException($e));
            }

            if (!$retryOnConnectionException || !self::isConnectionException($e)) {
                throw $e;
            }

            self::log('pipeline_retry: ' . self::formatException($e));

            $retryRedis = null;

            try {
                $retryRedis = self::reconnect();
                return self::runPipeline($retryRedis, $callback);
            } catch (\Throwable $retryException) {
                if ($retryRedis !== null) {
                    self::discardConnection($retryRedis, 'pipeline_retry_fail: ' . self::formatException($retryException));
                }

                throw $retryException;
            }
        }
    }

    /**
     * 显式安全 pipeline。
     *
     * Redis 短暂不可用时返回默认值，适合监控统计、非关键缓存等允许降级的写入。
     *
     * @param callable $callback
     * @param array $default
     * @param bool $retryOnConnectionException 连接异常时是否重试一次
     * @return array
     */
    public static function safePipeline(callable $callback, array $default = [], bool $retryOnConnectionException = true): array
    {
        try {
            return self::pipeline($callback, $retryOnConnectionException);
        } catch (\Throwable $e) {
            self::log('safe_pipeline_fail: ' . self::formatException($e));
            return $default;
        }
    }

    /**
     * 根据方法名执行 Redis 调用。
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
     * 支持 RedisManager::hGetAll() 这类静态调用。
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws \Throwable
     */
    public static function __callStatic($method, $arguments)
    {
        return self::execute($method, $arguments);
    }

    /**
     * 执行一次 Redis pipeline。
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
     * 判断是否为 Redis 连接异常。
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
            'connection reset by peer',
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
            'timeout',
            'no route to host',
            'network is unreachable',
            'php_network_getaddresses',
            'resource temporarily unavailable',
            'temporary failure in name resolution',
            'name or service not known',
            'cannot assign requested address',
        ];

        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 Redis 命令是否为只读命令。
     *
     * 只读命令默认允许连接异常后重连重试一次。
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
            'hmget',
            'hgetall',
            'hexists',
            'hkeys',
            'hvals',
            'hlen',
            'llen',
            'scard',
            'sismember',
            'smembers',
            'srandmember',
            'zcard',
            'zcount',
            'zrange',
            'zrevrange',
            'zrangebyscore',
            'zrevrangebyscore',
            'zscore',
            'ttl',
            'pttl',
            'exists',
            'type',
            'lindex',
            'lrange',
            'keys',
            'scan',
        ], true);
    }

    /**
     * 丢弃当前 Redis 连接。
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
            // 连接可能已经断开，关闭失败可忽略。
        }

        if (self::$redis === $redis) {
            self::$redis = null;
            self::$lastPingAt = 0;
        }

        self::log($message);
    }

    /**
     * 格式化异常，补充文件和行号便于定位。
     *
     * @param \Throwable $e
     * @return string
     */
    private static function formatException(\Throwable $e): string
    {
        return 'File:' . $e->getFile() . ' Line:' . $e->getLine() . ' Msg:' . $e->getMessage();
    }

    /**
     * 写入 Redis 运行日志。
     *
     * 同一类错误 5 秒内只写一次，避免 Redis 故障时高频业务刷爆日志。
     *
     * @param string $message
     * @return void
     */
    private static function log(string $message): void
    {
        static $lastLogAt = [];

        $now = time();
        $logKey = md5($message);

        if (isset($lastLogAt[$logKey]) && $now - $lastLogAt[$logKey] < 5) {
            return;
        }

        $lastLogAt[$logKey] = $now;

        try {
            ManagerHelper::log('redis.log', '[' . date('Y-m-d H:i:s') . '] ' . $message);
        } catch (\Throwable $e) {
            // 日志失败不能影响 Redis 异常处理流程。
        }
    }
}
