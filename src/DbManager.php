<?php

namespace CreatCode\IotMonitor;

use think\facade\Db;

/**
 * 数据库连接管理器
 * 用于 Webman 常驻进程环境下的数据库连接探活、异常识别和断线重连
 */
class DbManager
{
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
    protected static $pingInterval = 30;

    /**
     * 数据库探活
     *
     * @throws \Throwable
     */
    public static function ping(): void
    {
        $now = time();
        $interval = (int)ManagerHelper::dbConfig('db_ping_interval', self::$pingInterval);
        if ($now - self::$lastPingAt < $interval) {
            return;
        }

        Db::query('SELECT 1');
        self::$lastPingAt = $now;
    }

    /**
     * 断开并重置数据库连接状态
     *
     * @throws \Throwable
     */
    public static function reconnect(): void
    {
        Db::disconnect();
        self::$lastPingAt = 0;
    }

    /**
     * 判断是否为数据库连接异常
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
        ];

        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 安全执行数据库操作
     * 仅在连接异常时重试一次
     *
     * @param callable $callback
     * @return mixed
     * @throws \Throwable
     */
    public static function call(callable $callback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            if (!self::isConnectionException($e)) {
                throw $e;
            }

            ManagerHelper::log('db.log', '[' . date('Y-m-d H:i:s') . '] first_fail: ' . $e->getMessage());

            self::reconnect();

            return $callback();
        }
    }
}
