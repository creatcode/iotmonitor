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
     * 构建监控总览数据。
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
     * 构建上报积压和活跃设备统计。
     */
    protected function buildReportData(): array
    {
        $now = time();
        $reportCachePending = $this->safeRedisInt(function () {
            return RedisManager::lLen(ManagerHelper::config('overview.redis_keys.report_cache', 'ReportDataCache'));
        });
        $dirtyDeviceCount = $this->safeRedisInt(function () {
            return RedisManager::sCard(ManagerHelper::config('overview.redis_keys.device_report_dirty', 'DeviceReportDirty'));
        });

        return [
            // TSDB 待写入点位数量，不等同于原始上报包数量。
            'report_cache_pending' => $reportCachePending,
            'report_cache_pending_format' => number_format($reportCachePending),

            // MySQL 实时数据待同步设备属性数量。
            'dirty_device_count' => $dirtyDeviceCount,

            // 最近活跃设备数量。
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
     * 构建队列运行统计。
     */
    protected function buildQueueData(): array
    {
        $queueNames = ManagerHelper::config('overview.queues', []);
        $waitingPrefix = ManagerHelper::config('overview.redis_keys.queue_waiting_prefix', '{redis-queue}-waiting');

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
            return RedisManager::zCard(ManagerHelper::config('overview.redis_keys.queue_delayed', '{redis-queue}-delayed'));
        });
        $failed = $this->safeRedisInt(function () {
            return RedisManager::lLen(ManagerHelper::config('overview.redis_keys.queue_failed', '{redis-queue}-failed'));
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
     * 构建运行进程配置。
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
                // 兼容旧版 fast_consumer/slow_consumer 和当前项目 login_consumer/consumer 命名。
                'fast_consumer_count' => $this->processCount($queueProcess, ['fast_consumer', 'login_consumer']),
                'slow_consumer_count' => $this->processCount($queueProcess, ['slow_consumer', 'consumer']),
            ],
            'gateway_process' => $this->formatGatewayProcess($gatewayProcess),
        ];
    }

    /**
     * 统计指定时间窗口内的活跃设备数。
     */
    protected function activeDeviceCount(int $now, int $seconds): int
    {
        return $this->safeRedisInt(function () use ($now, $seconds) {
            return RedisManager::zCount(
                ManagerHelper::config('overview.redis_keys.device_active_time', 'DeviceActiveTime'),
                $now - $seconds,
                $now
            );
        });
    }

    /**
     * 格式化网关进程数量，返回字段兼容现有监控接口。
     */
    protected function formatGatewayProcess(array $gatewayProcess): array
    {
        $mapping = ManagerHelper::config('overview.gateway_process', [
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
     * 读取进程数量，兼容多个进程配置名称。
     */
    protected function processCount(array $process, array $names): int
    {
        foreach ($names as $name) {
            if (isset($process[$name]['count'])) {
                return (int)$process[$name]['count'];
            }
        }

        return 0;
    }

    /**
     * 安全读取 Redis 整数，避免监控接口影响业务请求。
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
     * 判断健康状态。
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
     * 读取 Webman 配置。
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
