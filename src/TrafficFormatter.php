<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor;

class TrafficFormatter
{
    /**
     * 格式化窗口流量
     */
    public static function formatWindow(array $data, int $minutes): array
    {
        $seconds = max($minutes * 60, 1);
        $rxBytes = (int)($data['rx_bytes'] ?? 0);
        $txBytes = (int)($data['tx_bytes'] ?? 0);
        $rxPackets = (int)($data['rx_packets'] ?? 0);
        $txPackets = (int)($data['tx_packets'] ?? 0);
        $reportPackets = (int)($data['report_packets'] ?? 0);

        return [
            'window_minutes' => $minutes,
            'rx_bytes' => $rxBytes,
            'tx_bytes' => $txBytes,
            'total_bytes' => $rxBytes + $txBytes,
            'rx_packets' => $rxPackets,
            'tx_packets' => $txPackets,
            'total_packets' => $rxPackets + $txPackets,
            'report_packets' => $reportPackets,
            'rx_speed' => round($rxBytes / $seconds, 2),
            'tx_speed' => round($txBytes / $seconds, 2),
            'total_speed' => round(($rxBytes + $txBytes) / $seconds, 2),
            'report_speed' => round($reportPackets / $seconds, 2),
            'rx_format' => self::formatBytes($rxBytes),
            'tx_format' => self::formatBytes($txBytes),
            'total_format' => self::formatBytes($rxBytes + $txBytes),
            'rx_speed_format' => self::formatBytes((int)round($rxBytes / $seconds)) . '/s',
            'tx_speed_format' => self::formatBytes((int)round($txBytes / $seconds)) . '/s',
            'total_speed_format' => self::formatBytes((int)round(($rxBytes + $txBytes) / $seconds)) . '/s',
        ];
    }

    /**
     * 解析协议维度统计
     */
    public static function parseProtocols(array $data): array
    {
        $protocols = [];

        foreach ($data as $field => $value) {
            $pos = strpos($field, ':');

            if ($pos === false) {
                continue;
            }

            $protocol = substr($field, 0, $pos);
            $metric = substr($field, $pos + 1);

            if (!isset($protocols[$protocol])) {
                $protocols[$protocol] = self::emptyProtocol($protocol);
            }

            if (array_key_exists($metric, $protocols[$protocol])) {
                $protocols[$protocol][$metric] = (int)$value;
            }
        }

        foreach ($protocols as &$item) {
            $item['total_bytes'] = $item['rx_bytes'] + $item['tx_bytes'];
            $item['total_packets'] = $item['rx_packets'] + $item['tx_packets'];
            $item['rx_format'] = self::formatBytes($item['rx_bytes']);
            $item['tx_format'] = self::formatBytes($item['tx_bytes']);
            $item['total_format'] = self::formatBytes($item['total_bytes']);
        }
        unset($item);

        return array_values($protocols);
    }

    /**
     * 空协议统计结构
     */
    protected static function emptyProtocol(string $protocol): array
    {
        return [
            'protocol' => $protocol,
            'rx_bytes' => 0,
            'tx_bytes' => 0,
            'total_bytes' => 0,
            'rx_packets' => 0,
            'tx_packets' => 0,
            'total_packets' => 0,
            'report_packets' => 0,
            'rx_format' => '0 B',
            'tx_format' => '0 B',
            'total_format' => '0 B',
        ];
    }

    /**
     * 格式化字节数
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
