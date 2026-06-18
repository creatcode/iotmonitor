<?php

namespace CreatCode\IotMonitor;

use think\facade\Db;

/**
 * 数据库连接管理器。
 *
 * 用于 Webman 常驻进程环境下的数据库连接探活、异常识别和断线重连。
 */
class DbManager
{
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
    protected static $pingInterval = 30;

    /**
     * 数据库探活。
     *
     * @throws \Throwable
     */
    public static function ping(): void
    {
        $now = time();
        $interval = (int)ManagerHelper::dbConfig('db_ping_interval', self::$pingInterval);
        if ($interval > 0 && $now - self::$lastPingAt < $interval) {
            return;
        }

        Db::query('SELECT 1');
        self::$lastPingAt = $now;
    }

    /**
     * 断开并重置数据库连接状态。
     *
     * @throws \Throwable
     */
    public static function reconnect(): void
    {
        Db::disconnect();
        self::$lastPingAt = 0;
    }

    /**
     * 安全执行数据库操作。
     *
     * 仅在连接异常时重连并重试一次，业务异常继续向外抛出。
     *
     * @param callable $callback
     * @return mixed
     * @throws \Throwable
     */
    public static function call(callable $callback)
    {
        try {
            self::ping();
            return $callback();
        } catch (\Throwable $e) {
            if (!self::isConnectionException($e)) {
                throw $e;
            }

            self::log('first_fail: ' . self::formatException($e));

            try {
                self::reconnect();
                return $callback();
            } catch (\Throwable $retryException) {
                self::log('retry_fail: ' . self::formatException($retryException) . ' | first_fail: ' . self::formatException($e));
                throw $retryException;
            }
        }
    }

    /**
     * 判断是否为数据库连接异常。
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
            'connection reset',
            'connection refused',
            'broken pipe',
            'server closed the connection unexpectedly',
            'is dead or not enabled',
            'error while sending',
            'communication link failure',
            'packet sequence number wrong',
            'sqlstate[hy000] [2002]',
            'sqlstate[hy000] [2006]',
            'sqlstate[hy000] [2013]',
            'mysql server has gone away',
            'php_network_getaddresses',
            'network is unreachable',
            'no route to host',
            'timed out',
        ];

        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
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
     * 写入数据库运行日志。
     *
     * 同一类错误 5 秒内只写一次，避免数据库故障时重复刷日志。
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
            ManagerHelper::log('db.log', '[' . date('Y-m-d H:i:s') . '] ' . $message);
        } catch (\Throwable $e) {
            // 日志失败不能影响数据库异常处理流程。
        }
    }
}
