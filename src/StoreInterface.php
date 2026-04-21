<?php

declare(strict_types=1);

namespace CreatCode\IotMonitor;

interface StoreInterface
{
    /**
     * 累加某一分钟的统计字段
     *
     * @param string $minute 分钟槽，格式：YmdHi
     * @param array $fields 统计字段
     * @param int $ttl 过期秒数
     */
    public function incrementMinute(string $minute, array $fields, int $ttl): void;

    /**
     * 读取某一分钟的统计字段
     *
     * @param string $minute 分钟槽，格式：YmdHi
     */
    public function getMinute(string $minute): array;
}
