<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Protocol;

use Workerman\Connection\ConnectionInterface;

/**
 * Modbus RTU 网络协议
 */
class ModbusRtuProtocol extends BaseProtocol
{
    private static $crcTable = null;

    const READ_COILS = 1;
    const READ_INPUT_DISCRETES = 2;
    const READ_HOLDING_REGISTERS = 3;
    const READ_INPUT_REGISTERS = 4;
    const WRITE_SINGLE_COIL = 5;
    const WRITE_SINGLE_REGISTER = 6;
    const WRITE_MULTIPLE_COILS = 15;
    const WRITE_MULTIPLE_REGISTERS = 16;
    const MASK_WRITE_REGISTER = 22;
    const READ_WRITE_MULTIPLE_REGISTERS = 23;
    const EXCEPTION_BITMASK = 128;
    const PROTOCOL_NAME = 'mrtu';

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
            if ($recvLen < 5) {
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
        $functionCode = ord($binaryData[1]);

        if ($functionCode & self::EXCEPTION_BITMASK) {
            return 5;
        }

        $frameLength = -1;
        switch ($functionCode) {
            case self::READ_COILS:
            case self::READ_INPUT_DISCRETES:
                $byteCount = ord($binaryData[2]);
                if ($byteCount >= 1 && $byteCount <= 250) {
                    $frameLength = 5 + $byteCount;
                }
                break;

            case self::READ_HOLDING_REGISTERS:
            case self::READ_INPUT_REGISTERS:
            case self::READ_WRITE_MULTIPLE_REGISTERS:
                $byteCount = ord($binaryData[2]);
                if ($byteCount >= 2 && $byteCount <= 250 && ($byteCount & 1) === 0) {
                    $frameLength = 5 + $byteCount;
                }
                break;

            case self::WRITE_SINGLE_COIL:
            case self::WRITE_SINGLE_REGISTER:
            case self::WRITE_MULTIPLE_COILS:
            case self::WRITE_MULTIPLE_REGISTERS:
                $frameLength = 8;
                break;

            case self::MASK_WRITE_REGISTER:
                $frameLength = 10;
                break;
        }

        return $frameLength;
    }

    /**
     * 初始化 CRC 表
     *
     * @return void
     */
    private static function initCrcTable()
    {
        if (self::$crcTable !== null) {
            return;
        }

        self::$crcTable = [];
        for ($i = 0; $i < 256; $i++) {
            $crc = $i;
            for ($j = 8; $j !== 0; $j--) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
            self::$crcTable[$i] = $crc;
        }
    }

    /**
     * 查表计算 CRC16
     *
     * @param string $data
     * @param int|null $length
     * @return int
     */
    public static function crc16Modbus(string $data, int $length = null)
    {
        self::initCrcTable();
        $crc = 0xFFFF;
        $len = $length ?? strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $crc = ($crc >> 8) ^ self::$crcTable[($crc ^ ord($data[$i])) & 0xFF];
        }

        return $crc;
    }
}
