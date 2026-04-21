<?php

return [
    // 插件启用开关
    'enable' => true,

    'traffic' => [
        // 是否启用流量监控，默认不启用
        'enable' => false,

        // 刷新间隔，单位秒
        'flush_interval' => 5,

        // 监控数据保留时间，单位秒
        'retention_seconds' => 172800,
    ],

    'db' => [
        // 数据库探活间隔，单位秒
        'db_ping_interval' => 30,

        // Redis 探活间隔，单位秒
        'redis_ping_interval' => 15,
    ],

    'protocol' => [
        // 协议特殊包长度，key 为包类型标识，value 为完整包长
        'extra_packets' => [
            'imei' => 19,
            'ping' => 4,
        ],
    ],
];
