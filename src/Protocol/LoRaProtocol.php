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

        if ($length === 0) {
            return 0;
        }

        // LoRa 帧必须从固定起始符开始，错误前缀无法在协议层安全跳过，直接关闭
        if (ord($buffer[0]) !== self::START_FLAG) {
            return self::closeInvalidConnection($connection);
        }

        if ($length < 3) {
            return 0;
        }

        // 第 3 字节为方案码，长度足够后即可判定协议是否匹配
        if (ord($buffer[2]) !== self::SCHEME) {
            return self::closeInvalidConnection($connection);
        }

        if ($length < 7) {
            return 0;
        }

        // 载荷至少包含两个命令字节，最大长度限制避免异常大包占用内存
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

        // 累加和校验：起始符到载荷末尾参与计算，不包含校验位和结尾符
        $checksum = 0;
        for ($i = 0; $i < $frameLength - 2; $i++) {
            $checksum += ord($buffer[$i]);
        }

        if (($checksum & 0xFF) !== ord($buffer[$frameLength - 2])) {
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
        // $command1 = $buffer[3];
        // $command2 = $buffer[4];
        // if (ord($command1) === 0x04 && ord($command2) === 0x01) {
        //     $type = 'imei';
        // } elseif (ord($command1) === 0x04 && ord($command2) === 0x02) {
        //     $type = 'ping';
        // }
        if (isset($buffer[4])) {
            $command = substr($buffer, 3, 2);
            if ($command === "\x04\x01") {
                $type = 'imei';
            } elseif ($command === "\x04\x02") {
                $type = 'ping';
            }
        }

        $data = bin2hex($buffer);
        $protocol = static::protocolName();
        return compact('type', 'data', 'protocol');
    }
}
