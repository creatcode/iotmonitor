<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor;

class TrafficReader
{
    /**
     * 存储实现
     *
     * @var StoreInterface
     */
    protected $store;

    public function __construct(StoreInterface $store)
    {
        $this->store = $store;
    }

    /**
     * 构建流量监控数据
     */
    public function buildTrafficData(int $minutes): array
    {
        $minutes = max(1, min($minutes, 1440));

        $windowSet = array_values(array_unique(array_filter([1, 5, $minutes], function ($value) {
            return $value > 0;
        })));
        sort($windowSet);

        $windows = [];
        $stats = [];
        foreach ($windowSet as $windowMinutes) {
            $stats[$windowMinutes] = $this->sumMinuteTraffic($windowMinutes);
            $windows["{$windowMinutes}m"] = TrafficFormatter::formatWindow($stats[$windowMinutes], $windowMinutes);
        }

        $current = $windows['1m'] ?? TrafficFormatter::formatWindow([], 1);
        $mainWindowKey = "{$minutes}m";
        $summaryData = $stats[$minutes] ?? [];

        return [
            'current' => $current,
            'main_window' => $mainWindowKey,
            'summary' => $windows[$mainWindowKey] ?? $current,
            'windows' => $windows,
            'protocols' => TrafficFormatter::parseProtocols($summaryData),
        ];
    }

    /**
     * 汇总最近 N 分钟流量
     */
    public function sumMinuteTraffic(int $minutes): array
    {
        $now = time();
        $slot = intdiv($now, 60) * 60;
        $summary = [];

        for ($i = 0; $i < $minutes; $i++) {
            $minute = date('YmdHi', $slot - $i * 60);
            $data = $this->store->getMinute($minute);

            foreach ($data as $field => $value) {
                $summary[$field] = ($summary[$field] ?? 0) + (int)$value;
            }
        }

        return $summary;
    }
}
