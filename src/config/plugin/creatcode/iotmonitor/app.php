<?php

return [
    // 插件总开关，关闭后 Webman 将跳过当前插件目录下的全部配置加载
    // 会同时影响 protocol、db、traffic、overview 等配置读取
    'enable' => true,

    'traffic' => [
        // 是否启用接口流量监控；同时受上方监控功能总开关控制。
        'enable' => false,

        // 刷新间隔，单位秒。
        'flush_interval' => 5,

        // 监控数据保留时间，单位秒。
        'retention_seconds' => 86400,
    ],

    'db' => [
        // 数据库探活间隔，单位秒。
        'db_ping_interval' => 30,

        // Redis 探活间隔，单位秒。
        'redis_ping_interval' => 15,
    ],

    'protocol' => [
        // 是否校验 Modbus RTU CRC，默认关闭以兼容部分设备非标准报文。
        'rtu_crc_check' => false,

        // 协议特殊包长度，key 为包类型标识，value 为完整包长度。
        'extra_packets' => [
            'imei' => 19,
            'ping' => 4,
        ],
    ],

    'overview' => [
        // 是否启用监控总览写入；同时受上方监控功能总开关控制。
        'enable' => false,

        // 需要监控的 redis-queue 队列名。
        'queues' => [
            'login_command',
            'exam_report_data',
            'energy_data_check',
            'energy_record',
            'dev_link',
            'dev_alarm',
            'food_command',
            'third_device',
        ],

        // 监控总览依赖的 Redis key。
        'redis_keys' => [
            'report_cache' => 'ReportDataCache',
            'device_report_dirty' => 'DeviceReportDirty',
            'device_active_time' => 'DeviceActiveTime',
            'queue_waiting_prefix' => '{redis-queue}-waiting',
            'queue_delayed' => '{redis-queue}-delayed',
            'queue_failed' => '{redis-queue}-failed',
        ],

        // 返回字段到 gateway-worker 进程名的映射。
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
