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

        $config = self::validateConfig();

        TrafficMonitor::init(new AppTrafficStore(), [
            'enable' => self::isEnabled($config),
            'flush_interval' => self::arrayGet($config, 'traffic.flush_interval', 5),
            'retention_seconds' => self::arrayGet($config, 'traffic.retention_seconds', 172800),
        ]);

        self::$initialized = true;
    }

    /**
     * 校验插件配置
     *
     * @return array
     */
    protected static function validateConfig(): array
    {
        $config = self::pluginConfig();
        if (!$config) {
            throw new \RuntimeException('缺少配置 plugin.creatcode.iotmonitor.app，请确认 config/plugin/creatcode/iotmonitor/app.php 已安装且 enable=true');
        }

        if (!isset($config['protocol']['extra_packets']) || !is_array($config['protocol']['extra_packets'])) {
            throw new \RuntimeException('缺少配置 plugin.creatcode.iotmonitor.app.protocol.extra_packets');
        }

        foreach ($config['protocol']['extra_packets'] as $tag => $length) {
            if (!is_string($tag) || $tag === '' || !is_numeric($length) || (int)$length <= 0) {
                throw new \RuntimeException('配置 plugin.creatcode.iotmonitor.app.protocol.extra_packets 格式错误');
            }
        }

        return $config;
    }

    /**
     * 判断流量监控是否开启
     *
     * @return bool
     */
    protected static function isEnabled(array $config): bool
    {
        if (defined('TRAFFIC_MONITOR_ENABLED')) {
            return (bool)TRAFFIC_MONITOR_ENABLED;
        }

        $value = self::arrayGet($config, 'traffic.enable', null);
        if ($value === null) {
            $value = self::env('monitor.traffic_enable', false);
        }

        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string)$value));
        return !in_array($value, ['0', 'false', 'off', 'no', 'close'], true);
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

    /**
     * 获取环境配置
     * 仅用于兼容旧 .env 配置，不依赖项目 EnvService
     *
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    protected static function env($name = null, $default = null)
    {
        static $data = null;

        if ($data === null) {
            $data = [];

            $envFile = self::basePath() . DIRECTORY_SEPARATOR . '.env';
            if (is_file($envFile)) {
                $data = parse_ini_file($envFile, true) ?: [];
            }
        }

        if ($name === null) {
            return $data;
        }

        $keys = explode('.', $name);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * 获取项目根目录
     * Webman 项目存在 base_path() 时使用项目根目录，否则使用当前工作目录
     *
     * @return string
     */
    protected static function basePath(): string
    {
        if (function_exists('base_path')) {
            return rtrim(base_path(), '/\\');
        }

        return getcwd();
    }
}
