<?php

namespace CreatCode\IotMonitor;

class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * 需要复制到项目的插件文件
     *
     * @var array
     */
    protected static $pathRelation = [
        'config/plugin/creatcode/iotmonitor' => 'config/plugin/creatcode/iotmonitor',
        'plugin/iotmonitor' => 'plugin/iotmonitor',
    ];

    /**
     * 安装插件
     *
     * @return void
     */
    public static function install()
    {
        static::installByRelation();
    }

    /**
     * 卸载插件
     *
     * @return void
     */
    public static function uninstall()
    {
        static::uninstallByRelation();
    }

    /**
     * 复制插件文件
     *
     * @return void
     */
    protected static function installByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parentDir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0777, true);
                }
            }

            copy_dir(__DIR__ . '/' . $source, base_path() . '/' . $dest);
            echo "Create $dest\n";
        }
    }

    /**
     * 删除插件文件
     *
     * @return void
     */
    protected static function uninstallByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            $path = base_path() . '/' . $dest;
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }

            echo "Remove $dest\n";

            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }

            remove_dir($path);
        }
    }
}
