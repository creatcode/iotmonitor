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
        $config = self::pluginConfig();
        if (isset($config['db']) && is_array($config['db']) && array_key_exists($name, $config['db'])) {
            return $config['db'][$name];
        }

        return $default;
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
     * 写入运行日志
     * 项目存在 iotlog() 时优先使用项目日志，不存在时使用包内默认日志
     *
     * @param string $logName
     * @param mixed $data
     * @return void
     */
    public static function log($logName, $data): void
    {
        if (function_exists('iotlog')) {
            iotlog($logName, $data);
            return;
        }

        $dir = self::runtimePath() . DIRECTORY_SEPARATOR . 'iotlog' . DIRECTORY_SEPARATOR;
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
