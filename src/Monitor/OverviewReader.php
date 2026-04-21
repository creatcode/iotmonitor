<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Monitor;

use CreatCode\IotMonitor\ManagerHelper;
use CreatCode\IotMonitor\RedisManager;
use CreatCode\IotMonitor\TrafficMonitor;
use CreatCode\IotMonitor\TrafficReader;

class OverviewReader
{
    /**
     * 构建监控总览数据
     */
    public function build(int $minutes = 60): array
    {
        $minutes = max(1, min($minutes, 1440));

        return [
            'traffic' => (new TrafficReader(new AppTrafficStore()))->buildTrafficData($minutes),
            'reports' => $this->buildReportData(),
            'queues' => $this->buildQueueData(),
            'runtime' => $this->buildRuntimeData(),
            'time' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
        ];
    }

    /**
     * 构建上报积压和活跃设备统计
     */
    protected function buildReportData(): array
    {
        $now = time();
        $reportCachePending = $this->safeRedisInt(function () {
            return RedisManager::lLen($this->config('overview.redis_keys.report_cache', 'ReportDataCache'));
        });
        $dirtyDeviceCount = $this->safeRedisInt(function () {
            return RedisManager::sCard($this->config('overview.redis_keys.device_report_dirty', 'DeviceReportDirty'));
        });

        return [
            // TSDB待写入点位数量，不等同于原始上报包数量
            'report_cache_pending' => $reportCachePending,
            'report_cache_pending_format' => number_format($reportCachePending),

            // MySQL实时数据待同步设备数量
            'dirty_device_count' => $dirtyDeviceCount,

            // 最近活跃设备数量
            'active_3m' => $this->activeDeviceCount($now, 180),
            'active_5m' => $this->activeDeviceCount($now, 300),
            'active_30m' => $this->activeDeviceCount($now, 1800),

            'health' => [
                'report_cache_pending' => $this->judgeHealth($reportCachePending, 5000, 20000),
                'dirty_device_count' => $this->judgeHealth($dirtyDeviceCount, 1000, 5000),
            ],
        ];
    }

    /**
     * 构建队列运行统计
     */
    protected function buildQueueData(): array
    {
        $queueNames = $this->config('overview.queues', ['login_command', 'check_report_data']);
        $waitingPrefix = $this->config('overview.redis_keys.queue_waiting_prefix', '{redis-queue}-waiting');

        $items = [];
        foreach ((array)$queueNames as $queue) {
            $queue = (string)$queue;
            if ($queue === '') {
                continue;
            }

            $waitingKey = $waitingPrefix . $queue;
            $waiting = $this->safeRedisInt(function () use ($waitingKey) {
                return RedisManager::lLen($waitingKey);
            });

            $items[] = [
                'queue' => $queue,
                'waiting_key' => $waitingKey,
                'waiting' => $waiting,
                'health' => $this->judgeHealth($waiting, 1000, 5000),
            ];
        }

        $delayed = $this->safeRedisInt(function () {
            return RedisManager::zCard($this->config('overview.redis_keys.queue_delayed', '{redis-queue}-delayed'));
        });
        $failed = $this->safeRedisInt(function () {
            return RedisManager::lLen($this->config('overview.redis_keys.queue_failed', '{redis-queue}-failed'));
        });

        return [
            'items' => $items,
            'delayed' => $delayed,
            'failed' => $failed,
            'health' => [
                'delayed' => $this->judgeHealth($delayed, 1000, 5000),
                'failed' => $this->judgeHealth($failed, 1, 100),
            ],
        ];
    }

    /**
     * 构建运行进程配置
     */
    protected function buildRuntimeData(): array
    {
        TrafficMonitorBootstrap::init();

        $queueProcess = $this->webmanConfig('plugin.webman.redis-queue.process', []);
        $gatewayProcess = $this->webmanConfig('plugin.webman.gateway-worker.process', []);

        return [
            'monitor' => [
                'traffic_enable' => TrafficMonitor::isEnabled(),
            ],
            'redis_queue_process' => [
                'fast_consumer_count' => (int)($queueProcess['fast_consumer']['count'] ?? 0),
                'slow_consumer_count' => (int)($queueProcess['slow_consumer']['count'] ?? 0),
            ],
            'gateway_process' => $this->formatGatewayProcess($gatewayProcess),
        ];
    }

    /**
     * 统计指定时间窗口内的活跃设备数
     */
    protected function activeDeviceCount(int $now, int $seconds): int
    {
        return $this->safeRedisInt(function () use ($now, $seconds) {
            return RedisManager::zCount(
                $this->config('overview.redis_keys.device_active_time', 'DeviceActiveTime'),
                $now - $seconds,
                $now
            );
        });
    }

    /**
     * 格式化网关进程数量，返回字段兼容现有监控接口
     */
    protected function formatGatewayProcess(array $gatewayProcess): array
    {
        $mapping = $this->config('overview.gateway_process', [
            'rtu_count' => 'Rtu-Gateway',
            'tcp_count' => 'Tcp-Gateway',
            'lora_count' => 'LoRa-Gateway',
            'temp_count' => 'Temp-Gateway',
            'websocket_count' => 'websocket',
            'business_worker_count' => 'worker',
        ]);

        $result = [];
        foreach ((array)$mapping as $field => $processName) {
            $result[(string)$field] = (int)($gatewayProcess[$processName]['count'] ?? 0);
        }

        return $result;
    }

    /**
     * 安全读取Redis整数，避免监控接口影响业务请求
     */
    protected function safeRedisInt(callable $callback): int
    {
        try {
            return (int)$callback();
        } catch (\Throwable $e) {
            ManagerHelper::log('monitor.log', '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 判断健康状态
     */
    protected function judgeHealth(int $value, int $warningValue, int $dangerValue): string
    {
        if ($value >= $dangerValue) {
            return 'danger';
        }

        if ($value >= $warningValue) {
            return 'warning';
        }

        return 'normal';
    }

    /**
     * 读取插件配置
     *
     * @param mixed $default
     * @return mixed
     */
    protected function config(string $name, $default = null)
    {
        $value = ManagerHelper::pluginConfig();
        foreach (explode('.', $name) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * 读取Webman配置
     *
     * @param mixed $default
     * @return mixed
     */
    protected function webmanConfig(string $name, $default = null)
    {
        if (!function_exists('config')) {
            return $default;
        }

        return config($name, $default);
    }
}
