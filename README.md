# creatcode/iotmonitor

`creatcode/iotmonitor` 是面向 Webman / Workerman 的物联网 TCP 协议辅助插件，主要提供协议拆包、流量统计、Redis 写入缓冲、数据库与 Redis 长连接管理等能力。

## 环境要求

- PHP >= 7.2
- Webman / Workerman
- Redis
- webman/think-cache
- webman/think-orm

## 安装

本地 path 包开发时，主项目 `composer.json` 可这样引用：

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../packages/iotmonitor",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "creatcode/iotmonitor": "*"
  }
}
```

安装或更新：

```bash
composer update creatcode/iotmonitor
composer dump-autoload
```

## 插件配置

配置目录：

```text
config/plugin/creatcode/iotmonitor/
```

常用配置示例：

```php
<?php

return [
    'enable' => true,

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
        'extra_packets' => [
            'imei' => 19,
            'ping' => 4,
        ],
    ],

    'overview' => [
        'queues' => [
            'login_command',
            'check_report_data',
        ],
    ],
];
```

说明：

- `enable`：插件总开关。
- `traffic.enable`：是否启用协议流量统计，默认关闭。
- `traffic.flush_interval`：进程内统计数据写入 Redis 的间隔，单位秒。
- `traffic.retention_seconds`：分钟流量统计在 Redis 中的保留时间。
- 修改配置后需要重启 Webman / GatewayWorker 常驻进程。

插件不再读取 `.env` 中的 `monitor.traffic_enable`，也不依赖 `TRAFFIC_MONITOR_ENABLED` 常量。

## 协议接入

内置协议类位于命名空间：

```php
CreatCode\IotMonitor\Protocol
```

当前包含：

- `ModbusTcpProtocol`
- `ModbusRtuProtocol`
- `TemperatureProtocol`
- `LoRaProtocol`

在 GatewayWorker 或 Workerman TCP 服务中指定协议类即可：

```php
use CreatCode\IotMonitor\Protocol\ModbusTcpProtocol;

return [
    'Tcp-Gateway' => [
        'handler' => Webman\GatewayWorker\Gateway::class,
        'listen' => 'tcp://0.0.0.0:2404',
        'protocol' => ModbusTcpProtocol::class,
    ],
];
```

## 流量统计

启用 `traffic.enable` 后，协议收发数据会自动记录到 Redis。

读取最近 N 分钟统计：

```php
use CreatCode\IotMonitor\Monitor\AppTrafficStore;
use CreatCode\IotMonitor\TrafficReader;

$reader = new TrafficReader(new AppTrafficStore());
$data = $reader->buildTrafficData(60);
```

返回数据主要包含：

- `current`：最近 1 分钟统计。
- `summary`：指定时间窗口汇总。
- `windows`：多个时间窗口统计。
- `protocols`：按协议拆分的统计。

手动记录流量：

```php
use CreatCode\IotMonitor\TrafficMonitor;

TrafficMonitor::recordIncoming('custom', strlen($buffer), true);
TrafficMonitor::record('custom', 'tx', strlen($response));
TrafficMonitor::flush();
```

## Redis 存储

流量统计默认写入 Redis Hash：

```text
MonitorTraffic:minute:{YmdHi}
```

常见字段：

- `rx_bytes`
- `tx_bytes`
- `rx_packets`
- `tx_packets`
- `report_packets`
- `{protocol}:rx_bytes`
- `{protocol}:tx_bytes`
- `{protocol}:rx_packets`
- `{protocol}:tx_packets`
- `{protocol}:report_packets`

默认保留 1 天，Redis 库编号由主项目 `config/thinkcache.php` 决定。

## 连接管理

插件提供 `RedisManager` 和 `DbManager`，用于常驻进程中的连接复用、探活和断线重试。

```php
use CreatCode\IotMonitor\DbManager;
use CreatCode\IotMonitor\RedisManager;

$value = RedisManager::hGet('DeviceData', '10001');

$rows = DbManager::call(function () {
    return \think\facade\Db::name('device')->select();
});
```

Redis 批量写入建议使用 pipeline：

```php
RedisManager::pipeline(function ($redis) {
    $redis->hSet('demo', 'count', 1);
    $redis->expire('demo', 60);
});
```

## 日志

插件内部日志统一通过 `ManagerHelper::log()` 写入：

- 主项目存在 `iotlog()` 时，优先使用项目日志。
- 不存在 `iotlog()` 时，写入 `runtime/iotlog/`。

流量刷盘失败会写入 `monitor.log`，用于排查 Redis 连接或写入异常。

## 注意事项

- `traffic.enable` 默认关闭，生产环境按需开启。
- 统计数据先写入进程内缓冲，再定时刷入 Redis。
- 修改插件配置、Redis 配置或协议类后，需要重启常驻进程。
- Redis 连接配置以主项目 `config/thinkcache.php` 为准。
