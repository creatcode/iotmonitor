<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Protocol;

use CreatCode\IotMonitor\TrafficMonitor;
use Workerman\Connection\ConnectionInterface;
use CreatCode\IotMonitor\ManagerHelper;

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
     * 检查数据包完整性。
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    final public static function input($buffer, ConnectionInterface $connection)
    {
        $constant = static::class . '::PROTOCOL_NAME';

        if (!defined($constant) || !is_string(constant($constant)) || constant($constant) === '') {
            echo '[IotMonitor] 协议类 ' . static::class . ' 未定义非空 PROTOCOL_NAME，连接已关闭。' . PHP_EOL;

            return static::closeInvalidConnection($connection);
        }

        return static::inputPayload($buffer, $connection);
    }

    /**
     * 具体协议的拆包实现。
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    abstract protected static function inputPayload($buffer, ConnectionInterface $connection);

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
        $protocol = static::protocolName();
        $result = static::decodePayload($buffer);
        $result['protocol'] = $protocol;

        if (TrafficMonitor::isEnabled()) {
            TrafficMonitor::recordIncoming(
                $protocol,
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
        $buffer = (string)$buffer;

        if ($buffer === '') {
            return '';
        }

        if (!ctype_xdigit($buffer)) {
            $buffer = preg_replace('/[^a-fA-F0-9]/', '', $buffer);
            if ($buffer === '') {
                return '';
            }
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

        $extraPackets = ManagerHelper::config('protocol.extra_packets');
        if (is_array($extraPackets)) {
            static::$extraPacketsCache = $extraPackets;
            return static::$extraPacketsCache;
        }

        throw new \RuntimeException('缺少配置 plugin.creatcode.iotmonitor.app.protocol.extra_packets');
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

    /**
     * 读取协议配置项
     *
     * @param string $name 配置名
     * @param mixed $default 默认值
     * @return mixed
     */
    protected static function protocolConfig(string $name, $default = null)
    {
        return ManagerHelper::config('protocol.' . $name, $default);
    }
}
