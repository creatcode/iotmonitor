<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor\Protocol;

use Workerman\Connection\ConnectionInterface;

/**
 * Modbus RTU 网络协议
 */
class ModbusRtuProtocol extends BaseProtocol
{
    /**
     * CRC 表
     *
     * @return array
     */
    private static $crcTable = null;

    /**
     * 是否校验 CRC
     *
     * @return bool
     */
    private static $checkCrc = null;

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
    protected static function inputPayload($buffer, ConnectionInterface $connection)
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

        // 仅普通 RTU 报文按配置校验 CRC，imei/ping 等特殊包不参与
        if ($extraLength === null && self::shouldCheckCrc() && !self::checkCrc($buffer, $frameLength)) {
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
        $tag = substr($buffer, 0, 4);
        $type = 'report';
        $data = bin2hex($buffer);

        if (static::extraPacketLength($tag) !== null) {
            $type = $tag;
            $data = $buffer;
        }

        return compact('type', 'data');
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
     * 是否校验 Modbus RTU CRC，默认关闭以兼容非标准设备报文
     */
    protected static function shouldCheckCrc(): bool
    {
        if (self::$checkCrc !== null) {
            return self::$checkCrc;
        }

        $value = static::protocolConfig('rtu_crc_check', false);
        if (is_bool($value)) {
            return self::$checkCrc = $value;
        }

        if (is_numeric($value)) {
            return self::$checkCrc = ((int)$value !== 0);
        }

        if (!is_string($value)) {
            return self::$checkCrc = false;
        }

        // 字符串容错：空字符串和常见关闭值均视为关闭
        $value = strtolower(trim($value));
        return self::$checkCrc = ($value !== '' && !in_array($value, ['0', 'false', 'off', 'no', 'close'], true));
    }


    /**
     * 校验 Modbus RTU CRC16
     *
     * @param string $buffer 原始二进制报文
     * @param int $frameLength 完整帧长度
     * @return bool
     */
    protected static function checkCrc(string $buffer, int $frameLength): bool
    {
        if ($frameLength < 4 || strlen($buffer) < $frameLength) {
            return false;
        }

        $crc = self::crc16Modbus(substr($buffer, 0, $frameLength - 2));
        $receiveCrc = ord($buffer[$frameLength - 2]) | (ord($buffer[$frameLength - 1]) << 8);

        return $crc === $receiveCrc;
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
