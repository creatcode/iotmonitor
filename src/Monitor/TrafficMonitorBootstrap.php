<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Monitor;

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
     * 插件配置缓存，启动阶段读取一次即可
     *
     * @var array|null
     */
    protected static $config = null;

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

        $config = self::pluginConfig();

        TrafficMonitor::init(new AppTrafficStore(), [
            'enable' => self::isEnabled($config),
            'flush_interval' => self::arrayGet($config, 'traffic.flush_interval', 5),
            'retention_seconds' => self::arrayGet($config, 'traffic.retention_seconds', 86400),
        ]);

        self::$initialized = true;
    }

    /**
     * 判断流量监控是否开启
     *
     * @return bool
     */
    protected static function isEnabled(array $config): bool
    {
        $pluginEnable = self::arrayGet($config, 'enable', true);
        if (!is_bool($pluginEnable)) {
            $pluginEnable = !in_array(strtolower(trim((string)$pluginEnable)), ['0', 'false', 'off', 'no', 'close'], true);
        }

        if (!$pluginEnable) {
            return false;
        }

        $trafficEnable = self::arrayGet($config, 'traffic.enable', false);
        if (is_bool($trafficEnable)) {
            return $trafficEnable;
        }

        $trafficEnable = strtolower(trim((string)$trafficEnable));
        return !in_array($trafficEnable, ['0', 'false', 'off', 'no', 'close'], true);
    }

    /**
     * 获取插件配置项
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    protected static function arrayGet(array $config, $name, $default = null)
    {
        $value = $config;
        foreach (explode('.', $name) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * 获取插件配置
     *
     * @return array
     */
    protected static function pluginConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        if (!function_exists('config')) {
            self::$config = [];
            return [];
        }

        $config = config('plugin.creatcode.iotmonitor.app');
        self::$config = is_array($config) ? $config : [];

        return self::$config;
    }
}
