<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Monitor;

use CreatCode\IotMonitor\ManagerHelper;
use CreatCode\IotMonitor\TrafficMonitor;
use Webman\Bootstrap;

class TrafficMonitorBootstrap implements Bootstrap
{
    /**
     * 是否已经初始化
     *
     * @var bool
     */
    protected static $initialized = false;

    /**
     * Webman 插件启动入口
     *
     * @param mixed $worker
     * @return void
     */
    public static function start($worker)
    {
        self::init();
    }

    /**
     * 初始化流量监控
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        TrafficMonitor::init(new AppTrafficStore(), [
            'enable' => ManagerHelper::trafficEnabled(),
            'flush_interval' => ManagerHelper::config('traffic.flush_interval', 5),
            'retention_seconds' => ManagerHelper::config('traffic.retention_seconds', 86400),
        ]);

        self::$initialized = true;
    }
}
