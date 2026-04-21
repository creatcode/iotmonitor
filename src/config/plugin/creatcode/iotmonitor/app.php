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
        'retention_seconds' => 86400,
    ],

    'db' => [
        // 数据库探活间隔，单位秒
        'db_ping_interval' => 30,

        // Redis探活间隔，单位秒
        'redis_ping_interval' => 15,
    ],

    'protocol' => [
        // 协议特殊包长度，key为包类型标识，value为完整包长
        'extra_packets' => [
            'imei' => 19,
            'ping' => 4,
        ],
    ],

    'overview' => [
        // 需要监控的redis-queue队列名
        'queues' => [
            'login_command',
            'check_report_data',
        ],

        // 监控总览依赖的Redis key
        'redis_keys' => [
            'report_cache' => 'ReportDataCache',
            'device_report_dirty' => 'DeviceReportDirty',
            'device_active_time' => 'DeviceActiveTime',
            'queue_waiting_prefix' => '{redis-queue}-waiting',
            'queue_delayed' => '{redis-queue}-delayed',
            'queue_failed' => '{redis-queue}-failed',
        ],

        // 返回字段到gateway-worker进程名的映射
        'gateway_process' => [
            'rtu_count' => 'Rtu-Gateway',
            'tcp_count' => 'Tcp-Gateway',
            'lora_count' => 'LoRa-Gateway',
            'temp_count' => 'Temp-Gateway',
            'websocket_count' => 'websocket',
            'business_worker_count' => 'worker',
        ],
    ],
];
