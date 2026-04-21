<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Protocol;

use CreatCode\IotMonitor\TrafficMonitor;
use Workerman\Connection\ConnectionInterface;

/**
 * 协议基类
 */
abstract class BaseProtocol
{
    /**
     * 协议特殊包长配置缓存，避免高频拆包时重复读取配置
     *
     * @var array|null
     */
    protected static $extraPacketsCache = null;

    /**
     * 检查数据包完整性
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    abstract public static function input($buffer, ConnectionInterface $connection);

    /**
     * 协议解析
     *
     * @param string $buffer
     * @return array
     */
    abstract protected static function decodePayload($buffer): array;

    /**
     * 请求数据解包
     *
     * @param string $buffer
     * @return array
     */
    public static function decode($buffer)
    {
        $result = static::decodePayload($buffer);

        if (TrafficMonitor::isEnabled()) {
            TrafficMonitor::recordIncoming(
                static::protocolName(),
                strlen($buffer),
                ($result['type'] ?? '') === 'report'
            );
        }

        return $result;
    }

    /**
     * 请求数据打包
     *
     * @param string $buffer
     * @return string|false
     */
    public static function encode($buffer)
    {
        $buffer = preg_replace('/[^a-fA-F0-9]/', '', $buffer);
        if ($buffer === '') {
            return '';
        }
        if ((strlen($buffer) & 1) !== 0) {
            $buffer = '0' . $buffer;
        }

        $binary = hex2bin($buffer);
        if ($binary !== false && TrafficMonitor::isEnabled()) {
            // 发送流量按最终写入连接的二进制字节数统计
            TrafficMonitor::record(static::protocolName(), 'tx', strlen($binary));
        }

        return $binary;
    }

    /**
     * 获取协议特殊包长度
     *
     * @param string $tag
     * @return int|null
     */
    protected static function extraPacketLength($tag): ?int
    {
        $extraPackets = static::extraPacketsConfig();
        if (array_key_exists($tag, $extraPackets)) {
            return (int)$extraPackets[$tag];
        }

        return null;
    }

    /**
     * 获取特殊包长度配置
     * 配置缺失时直接抛出异常，避免协议以错误配置运行
     *
     * @return array
     */
    protected static function extraPacketsConfig(): array
    {
        if (static::$extraPacketsCache !== null) {
            return static::$extraPacketsCache;
        }

        $config = static::pluginConfig();
        if (isset($config['protocol']['extra_packets']) && is_array($config['protocol']['extra_packets'])) {
            static::$extraPacketsCache = $config['protocol']['extra_packets'];
            return static::$extraPacketsCache;
        }

        throw new \RuntimeException('缺少配置 plugin.creatcode.iotmonitor.app.protocol.extra_packets');
    }

    /**
     * 获取插件配置
     *
     * @return array
     */
    protected static function pluginConfig(): array
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
     * 非法数据包处理：关闭连接并静默返回
     *
     * @param ConnectionInterface $connection
     * @return int
     */
    protected static function closeInvalidConnection(ConnectionInterface $connection)
    {
        $connection->close();
        return 0;
    }

    /**
     * 获取协议监控标识
     *
     * @return string
     */
    protected static function protocolName(): string
    {
        return static::PROTOCOL_NAME;
    }
}
