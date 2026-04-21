<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Protocol;

use Workerman\Connection\ConnectionInterface;

/**
 * LoRa 网络协议
 */
class LoRaProtocol extends BaseProtocol
{
    const START_FLAG = 0x6C;
    const END_FLAG = 0x16;
    const SCHEME = 0x11;
    const PROTOCOL_NAME = 'lora';

    /**
     * 检查数据包完整性
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        $length = strlen($buffer);
        if ($length < 7) {
            return 0;
        }

        // 起始符或方案错误
        if (ord($buffer[0]) !== self::START_FLAG || ord($buffer[2]) !== self::SCHEME) {
            return self::closeInvalidConnection($connection);
        }

        // 载荷长度判定
        $payloadLength = ord($buffer[1]);
        if ($payloadLength < 2 || $payloadLength > 250) {
            return self::closeInvalidConnection($connection);
        }

        $frameLength = 5 + $payloadLength;
        if ($length < $frameLength) {
            return 0;
        }

        // 结尾符校验
        if (ord($buffer[$frameLength - 1]) !== self::END_FLAG) {
            return self::closeInvalidConnection($connection);
        }

        // 累加和校验
        $checksum = 0;
        for ($i = 0; $i < $frameLength - 2; $i++) {
            $checksum += ord($buffer[$i]);
        }
        $checksum &= 0xFF;
        if ($checksum !== ord($buffer[$frameLength - 2])) {
            return self::closeInvalidConnection($connection);
        }

        return $frameLength;
    }

    /**
     * 请求数据解包
     *
     * @param string $buffer
     * @return array
     */
    protected static function decodePayload($buffer): array
    {
        $type = 'report';
        $command1 = $buffer[3];
        $command2 = $buffer[4];
        if (ord($command1) === 0x04 && ord($command2) === 0x01) {
            $type = 'imei';
        } elseif (ord($command1) === 0x04 && ord($command2) === 0x02) {
            $type = 'ping';
        }

        $data = bin2hex($buffer);
        $protocol = static::protocolName();
        return compact('type', 'data', 'protocol');
    }
}
