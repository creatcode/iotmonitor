<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor;

use Workerman\Timer;

class TrafficMonitor
{
    /**
     * 刷入存储间隔，监控允许几秒延迟，用低频写入换取更小压力
     */
    protected static $flushInterval = 5;

    /**
     * 分钟统计保留时间
     */
    protected static $retentionSeconds = 172800;

    /**
     * 监控开关
     */
    protected static $enabled = true;

    /**
     * 存储实现
     *
     * @var StoreInterface|null
     */
    protected static $store = null;

    /**
     * 进程内统计缓冲区
     */
    protected static $buffer = [];

    /**
     * 定时器是否已启动
     */
    protected static $timerStarted = false;

    /**
     * 是否正在刷入存储
     */
    protected static $flushing = false;

    /**
     * 当前分钟缓存，避免高频包处理时重复格式化时间
     */
    protected static $cachedMinuteSlot = 0;

    /**
     * 当前分钟字符串
     */
    protected static $cachedMinute = '';

    /**
     * 初始化流量监控
     */
    public static function init(StoreInterface $store, array $config = []): void
    {
        self::$store = $store;
        self::$enabled = (bool)($config['enable'] ?? true);
        self::$flushInterval = max(1, (int)($config['flush_interval'] ?? 5));
        self::$retentionSeconds = max(60, (int)($config['retention_seconds'] ?? 172800));
    }

    /**
     * 判断流量监控是否开启
     */
    public static function isEnabled(): bool
    {
        return self::$enabled && self::$store !== null;
    }

    /**
     * 记录接收流量，并按需记录上报包数量
     */
    public static function recordIncoming(string $protocol, int $bytes, bool $isReport): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::record($protocol, 'rx', $bytes);

        if ($isReport) {
            self::recordReport($protocol);
        }
    }

    /**
     * 记录协议层收发流量
     */
    public static function record(string $protocol, string $direction, int $bytes): void
    {
        if (!self::isEnabled() || $bytes <= 0 || ($direction !== 'rx' && $direction !== 'tx')) {
            return;
        }

        $protocol = $protocol !== '' ? $protocol : 'unknown';
        $minute = self::currentMinute();

        self::incr($minute, "{$direction}_bytes", $bytes);
        self::incr($minute, "{$direction}_packets", 1);
        self::incr($minute, "{$protocol}:{$direction}_bytes", $bytes);
        self::incr($minute, "{$protocol}:{$direction}_packets", 1);
    }

    /**
     * 记录上报包数量
     */
    public static function recordReport(string $protocol): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $protocol = $protocol !== '' ? $protocol : 'unknown';
        $minute = self::currentMinute();

        self::incr($minute, 'report_packets', 1);
        self::incr($minute, "{$protocol}:report_packets", 1);
    }

    /**
     * 累加到内存缓冲区
     */
    protected static function incr(string $minute, string $field, int $value): void
    {
        self::startTimer();

        if (!isset(self::$buffer[$minute])) {
            self::$buffer[$minute] = [];
        }

        self::$buffer[$minute][$field] = (self::$buffer[$minute][$field] ?? 0) + $value;
    }

    /**
     * 获取当前分钟
     */
    protected static function currentMinute(): string
    {
        $now = time();
        $slot = intdiv($now, 60);

        if ($slot !== self::$cachedMinuteSlot) {
            self::$cachedMinuteSlot = $slot;
            self::$cachedMinute = date('YmdHi', $now);
        }

        return self::$cachedMinute;
    }

    /**
     * 启动定时刷盘
     */
    protected static function startTimer(): void
    {
        if (self::$timerStarted) {
            return;
        }

        self::$timerStarted = true;

        Timer::add(self::$flushInterval, [self::class, 'flush']);
        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * 将内存统计刷入存储
     */
    public static function flush(): void
    {
        if (self::$flushing || empty(self::$buffer) || self::$store === null) {
            return;
        }

        self::$flushing = true;
        $data = self::$buffer;
        self::$buffer = [];

        try {
            foreach ($data as $minute => $fields) {
                self::$store->incrementMinute($minute, $fields, self::$retentionSeconds);
            }
        } catch (\Throwable $e) {
            self::mergeBack($data);
            error_log('[traffic-monitor] flush fail: ' . $e->getMessage());
        } finally {
            self::$flushing = false;
        }
    }

    /**
     * 写入失败时放回缓冲区，避免丢失统计
     */
    protected static function mergeBack(array $data): void
    {
        foreach ($data as $minute => $fields) {
            if (!isset(self::$buffer[$minute])) {
                self::$buffer[$minute] = [];
            }

            foreach ($fields as $field => $value) {
                self::$buffer[$minute][$field] = (self::$buffer[$minute][$field] ?? 0) + $value;
            }
        }
    }
}
