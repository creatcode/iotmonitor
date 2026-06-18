# creatcode/iotmonitor

`creatcode/iotmonitor` 是面向 Webman / Workerman TCP 服务的物联网协议辅助库，提供协议拆包、流量统计、Redis/DB 常驻连接管理和监控总览能力。

## 环境要求

- PHP >= 7.2
- Webman / Workerman
- ext-redis
- webman/think-cache
- webman/think-orm

## 配置

主项目配置目录：

```text
config/plugin/creatcode/iotmonitor/
```

常用配置：

```php
<?php

return [
    'enable' => false,

    'traffic' => [
        'enable' => false,
        'flush_interval' => 5,
        'retention_seconds' => 86400,
    ],

    'db' => [
        'db_ping_interval' => 30,
        'redis_ping_interval' => 15,
    ],

    'protocol' => [
        'rtu_crc_check' => false,
        'extra_packets' => [
            'imei' => 19,
            'ping' => 4,
        ],
    ],

    'overview' => [
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
    ],
];
```

修改插件、Redis、队列或协议配置后，需要完整重启 Webman / GatewayWorker 常驻进程，避免旧进程继续使用旧配置。

## 协议接入

内置协议类位于：

```php
CreatCode\IotMonitor\Protocol
```

包含：

- `ModbusTcpProtocol`
- `ModbusRtuProtocol`
- `LoRaProtocol`
- `TemperatureProtocol`

GatewayWorker 配置示例：

```php
use CreatCode\IotMonitor\Protocol\ModbusTcpProtocol;
use Webman\GatewayWorker\Gateway;

return [
    'Tcp-Gateway' => [
        'handler' => Gateway::class,
        'listen' => 'tcp://0.0.0.0:2350',
        'protocol' => ModbusTcpProtocol::class,
    ],
];
```

## Redis 连接管理

`RedisManager` 用于常驻进程中的连接复用、定期探活、断线丢弃和一次重连重试。

```php
use CreatCode\IotMonitor\RedisManager;

$value = RedisManager::hGet('RealData:10001', 'temperature');
```

默认规则：

- 只读命令如 `hGet()`、`hGetAll()`、`lLen()` 连接异常时会重连并重试一次。
- 写命令默认不自动重试，避免 Redis 已执行但客户端未收到响应时重复写入。
- `pipeline()` 默认不自动重试，确认批量命令可重放时再传入第二个参数 `true`。

显式安全写入：

```php
RedisManager::safeWrite('zAdd', ['DeviceActiveTime', time(), 'device001'], 0);

RedisManager::safePipeline(function ($redis) {
    $redis->hIncrBy('MonitorTraffic:minute:202606181530', 'rx_packets', 1);
    $redis->expire('MonitorTraffic:minute:202606181530', 86400);
});
```

`safeWrite()` 和 `safePipeline()` 会吞掉异常并返回默认值，适合监控统计、非关键缓存、可接受降级的写入。核心业务写入仍建议显式捕获异常，避免静默丢数据。

## 数据库连接管理

`DbManager::call()` 会在业务 SQL 前按配置间隔执行探活。遇到连接异常时会断开连接并重试一次，业务异常会继续抛出。

```php
use CreatCode\IotMonitor\DbManager;
use think\facade\Db;

$rows = DbManager::call(function () {
    return Db::name('device')->where('status', 'normal')->select();
});
```

## 监控总览

`OverviewReader` 会读取：

- 上报缓存积压：`ReportDataCache`
- MySQL 同步脏集合：`DeviceReportDirty`
- 活跃设备 ZSET：`DeviceActiveTime`
- redis-queue 等待、延迟、失败队列
- GatewayWorker 和 redis-queue 进程数

队列进程名同时兼容旧版 `fast_consumer` / `slow_consumer` 和当前项目使用的 `login_consumer` / `consumer`。

## 流量统计

启用 `traffic.enable` 后，协议收发数据会先写入进程内缓冲，再按 `flush_interval` 批量写入 Redis Hash：

```text
MonitorTraffic:minute:{YmdHi}
```

监控写入使用 `RedisManager::safePipeline()`，Redis 短暂不可用时只影响监控统计，不影响设备上报主链路。

## 日志

库内日志统一通过 `ManagerHelper::log()` 写入：

- 主项目存在 `iotlog()` 时优先使用项目日志。
- 否则写入 `runtime/iotlog/`。

常见日志文件：

- `redis.log`
- `db.log`
- `monitor.log`
