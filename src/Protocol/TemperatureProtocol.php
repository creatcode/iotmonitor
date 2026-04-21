<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Protocol;

use Workerman\Connection\ConnectionInterface;

/**
 * GC433-RX043 LoRa 无线测温接收数据协议
 */
class TemperatureProtocol extends BaseProtocol
{
    const REPORT_PACKET_SIZE = 7;
    const PROTOCOL_NAME = 'temp';

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
            if ($recvLen < self::REPORT_PACKET_SIZE) {
                return 0;
            }
            $frameLength = self::validateReportPacket($buffer);
            if ($frameLength === false) {
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
     * 检测协议数据包
     *
     * @param string $binaryData
     * @return int|false
     */
    public static function validateReportPacket($binaryData)
    {
        $packet = substr($binaryData, 0, self::REPORT_PACKET_SIZE);
        $calculatedFcs = 0;
        $calculatedFcs += ord($packet[0]);
        $calculatedFcs += ord($packet[1]);
        $calculatedFcs += ord($packet[2]);
        $calculatedFcs += ord($packet[3]);
        $calculatedFcs += ord($packet[4]);
        $calculatedFcs &= 0xFF;

        $receivedFcs = ord($packet[5]);
        if ($calculatedFcs !== $receivedFcs) {
            return false;
        }

        return self::REPORT_PACKET_SIZE;
    }
}
