# creatcode/iotmonitor

`creatcode/iotmonitor` 是一个面向 Workerman/Webman TCP 协议服务的轻量监控包，提供协议流量统计、Redis 写入缓冲、数据库与 Redis 长连接辅助管理，以及常用物联网协议拆包基类。

## 环境要求

- PHP >= 7.2
- Workerman/Webman
- Redis
- `webman/think-cache`
- `webman/think-orm`

依赖版本兼容 PHP 7.2 到 PHP 8.x。低版本 PHP 环境下，Composer 会根据依赖自身约束解析到仍支持 PHP 7.2 的版本。

## Composer 文件

包内保留两类 Composer 配置：

- `composer.json`：正式发布使用。
- `composer-local.json`：本地 path 包开发使用，可保留 `minimum-stability` 等本地开发配置。

主项目本地开发可这样引用：

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

## 安装配置

Webman 插件安装后会复制配置到：

```text
config/plugin/creatcode/iotmonitor/
```

核心配置示例：

```php
<?php

return [
    'enable' => true,

    'traffic' => [
        'enable' => false,
        'flush_interval' => 5,
        'retention_seconds' => 172800,
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
];
```

说明：

- `traffic.enable`：是否启用流量统计。
- `traffic.flush_interval`：内存统计刷新到 Redis 的间隔，单位秒。
- `traffic.retention_seconds`：分钟统计在 Redis 中的保留时间。
- `protocol.extra_packets`：非标准上报包的固定包长配置，key 为包类型标识，value 为完整包长。

也可以通过常量强制控制流量监控：

```php
define('TRAFFIC_MONITOR_ENABLED', true);
```

## 协议接入

内置协议类位于 `CreatCode\IotMonitor\Protocol` 命名空间：

- `ModbusTcpProtocol`
- `ModbusRtuProtocol`
- `TemperatureProtocol`
- `LoRaProtocol`

在 Workerman/Webman TCP 服务中直接指定协议类即可，例如：

```php
use CreatCode\IotMonitor\Protocol\ModbusTcpProtocol;

return [
    'tcp-server' => [
        'handler' => app\process\TcpServer::class,
        'listen' => 'tcp://0.0.0.0:12345',
        'transport' => 'tcp',
        'protocol' => ModbusTcpProtocol::class,
    ],
];
```

协议类继承自 `BaseProtocol`。`decode()` 会自动记录接收流量和上报包数量，`encode()` 会自动记录发送流量。

## 读取监控数据

使用 `TrafficReader` 读取最近 N 分钟的流量统计：

```php
use CreatCode\IotMonitor\Monitor\AppTrafficStore;
use CreatCode\IotMonitor\TrafficReader;

$reader = new TrafficReader(new AppTrafficStore());
$data = $reader->buildTrafficData(5);
```

返回数据包含：

- `current`：最近 1 分钟统计。
- `summary`：指定窗口统计。
- `windows`：多个窗口统计，默认包含 1 分钟、5 分钟和请求窗口。
- `protocols`：按协议拆分的统计。

## 手动记录流量

如果业务代码不走内置协议类，也可以手动记录：

```php
use CreatCode\IotMonitor\TrafficMonitor;

TrafficMonitor::recordIncoming('custom', strlen($buffer), true);
TrafficMonitor::record('custom', 'tx', strlen($response));
TrafficMonitor::flush();
```

## 连接管理

`RedisManager` 和 `DbManager` 用于 Webman 常驻进程中的连接复用与断线重试：

```php
use CreatCode\IotMonitor\RedisManager;
use CreatCode\IotMonitor\DbManager;

$value = RedisManager::hGet('DeviceData', '10001');

$rows = DbManager::call(function () {
    return \think\facade\Db::name('device')->select();
});
```

`RedisManager::pipeline()` 可用于批量 Redis 写入：

```php
RedisManager::pipeline(function ($redis) {
    $redis->hSet('demo', 'count', 1);
    $redis->expire('demo', 60);
});
```

## 存储结构

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
- `{protocol}:report_packets`

## 注意事项

- 生产环境建议开启 Redis 持久化或设置合理的统计保留时间。
- PHP 7.2 环境下，主项目的依赖约束也必须允许解析到低版本兼容包。
- 流量统计采用进程内缓冲并定时写入 Redis，进程异常退出时可能丢失极少量尚未刷新的统计。
