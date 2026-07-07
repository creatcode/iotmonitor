<?php

namespace CreatCode\IotMonitor;

/**
 * 管理器辅助方法
 */
class ManagerHelper
{
    /**
     * 获取数据库相关配置
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function dbConfig($name, $default = null)
    {
        return self::config('db.' . $name, $default);
    }

    /**
     * 获取插件配置
     *
     * @return array
     */
    public static function pluginConfig(): array
    {
        if (function_exists('config')) {
            $config = config('plugin.creatcode.iotmonitor.app');
            if (is_array($config)) {
                return $config;
            }
        }

        return [];
    }

    /**
     * 获取插件配置项，支持点号路径
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function config(string $name, $default = null)
    {
        $value = self::pluginConfig();

        foreach (explode('.', $name) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * 获取布尔配置项，兼容字符串关闭值
     *
     * @param string $name
     * @param bool $default
     * @return bool
     */
    public static function boolConfig(string $name, bool $default = false): bool
    {
        $value = self::config($name, $default);

        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'off', 'no', 'close'], true);
    }

    /**
     * 判断插件总开关是否开启
     *
     * @return bool
     */
    public static function pluginEnabled(): bool
    {
        return self::boolConfig('enable', true);
    }

    /**
     * 判断流量监控是否开启
     *
     * @return bool
     */
    public static function trafficEnabled(): bool
    {
        return self::pluginEnabled() && self::boolConfig('traffic.enable', false);
    }

    /**
     * 获取设备活跃时间 Redis key
     *
     * @return string
     */
    public static function deviceActiveTimeKey(): string
    {
        if (!self::pluginEnabled() || !self::boolConfig('overview.enable', false)) {
            return '';
        }

        return (string)self::config('overview.redis_keys.device_active_time', 'DeviceActiveTime');
    }

    /**
     * 写入运行日志
     * 项目存在 iotlog() 时优先使用项目日志，不存在时使用包内默认日志
     *
     * @param string $logName
     * @param mixed $data
     * @return void
     */
    public static function log($logName, $data): void
    {
        // getcwd() . DIRECTORY_SEPARATOR . 'runtime';
        $dir = rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'iotlog' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . $logName, print_r($data, true) . PHP_EOL, FILE_APPEND);
    }

    /**
     * 获取运行目录
     * 项目存在 runtime_path() 时优先使用项目运行目录，不存在时使用当前工作目录下的 runtime
     *
     * @return string
     */
    public static function runtimePath(): string
    {
        if (function_exists('runtime_path')) {
            return rtrim(runtime_path(), '/\\');
        }

        return getcwd() . DIRECTORY_SEPARATOR . 'runtime';
    }
}
