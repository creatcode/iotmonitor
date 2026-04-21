<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Protocol;

use Workerman\Connection\ConnectionInterface;

/**
 * Modbus TCP 网络协议
 */
class ModbusTcpProtocol extends BaseProtocol
{
    const PROTOCOL_NAME = 'mtcp';

    /**
     * 检查数据包完整性
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        $recvLen = strlen($buffer);
        if ($recvLen < 4) {
            return 0;
        }

        $ascTag = substr($buffer, 0, 4);
        $extraLength = static::extraPacketLength($ascTag);

        if ($extraLength !== null) {
            $frameLength = $extraLength;
        } else {
            if ($recvLen < 6) {
                return 0;
            }
            $frameLength = self::getFrameLength($buffer);
            if ($frameLength === -1) {
                return self::closeInvalidConnection($connection);
            }
        }

        if ($recvLen < $frameLength) {
            return 0;
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
        $tag = substr($buffer, 0, 4);
        $type = 'report';
        $data = bin2hex($buffer);

        if (static::extraPacketLength($tag) !== null) {
            $type = $tag;
            $data = $buffer;
        }

        $protocol = static::protocolName();
        return compact('type', 'data', 'protocol');
    }

    /**
     * 获取协议包长度
     *
     * @param string $binaryData
     * @return int
     */
    public static function getFrameLength($binaryData)
    {
        // 协议标识符必须为 0x0000
        if ($binaryData[2] !== "\x00" || $binaryData[3] !== "\x00") {
            return -1;
        }

        $length = (ord($binaryData[4]) << 8) | ord($binaryData[5]);
        if ($length < 2 || $length > 254) {
            return -1;
        }

        return 6 + $length;
    }
}
