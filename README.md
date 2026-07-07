# creatcode/iotmonitor

面向 Webman / Workerman TCP 服务的物联网协议辅助库。

## 功能

- 协议拆包（Modbus TCP / RTU、LoRa、温湿度）
- 流量统计（进程内缓冲 + 批量写入 Redis）
- Redis / 数据库连接管理（探活、断线重连）
- 监控总览（上报积压、队列、活跃设备、进程数）
- Web 监控面板（ECharts 可视化）

## 环境

- PHP >= 7.2
- ext-redis
- Webman ^1.5 / Workerman ^4.0 || ^5.0
- webman/think-cache ^1.0
- webman/think-orm ^1.1

## 安装

```bash
composer require creatcode/iotmonitor
```

安装后重启 Webman。配置文件位于 `config/plugin/creatcode/iotmonitor/app.php`。

## 配置

```php
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
        'rtu_crc_check' => false,
        'extra_packets' => [
            'imei' => 19,
            'ping' => 4,
        ],
    ],

    'overview' => [
        'enable' => false,
        'queues' => ['login_command', 'dev_link', 'dev_alarm'],
    ],
];
```

> 修改配置后需完整重启 Webman 常驻进程。

## 协议接入

GatewayWorker 配置示例：

```php
use CreatCode\IotMonitor\Protocol\ModbusTcpProtocol;

return [
    'Tcp-Gateway' => [
        'handler' => \Webman\GatewayWorker\Gateway::class,
        'listen' => 'tcp://0.0.0.0:2350',
        'protocol' => ModbusTcpProtocol::class,
    ],
];
```

内置协议：`ModbusTcpProtocol`、`ModbusRtuProtocol`、`LoRaProtocol`、`TemperatureProtocol`。

## Redis 连接管理

```php
use CreatCode\IotMonitor\RedisManager;

// 读操作自动重连重试一次
$value = RedisManager::hGet('key', 'field');

// 安全写入（吞异常，返回默认值）
RedisManager::safeWrite('zAdd', ['Key', time(), 'member'], 0);

// 安全批量写入
RedisManager::safePipeline(function ($redis) {
    $redis->incr('counter');
});
```

## 数据库连接管理

```php
use CreatCode\IotMonitor\DbManager;

$rows = DbManager::call(function () {
    return \think\facade\Db::name('device')->select();
});
```

## 监控总览

```php
use CreatCode\IotMonitor\Monitor\OverviewReader;

$data = (new OverviewReader())->build(60);
```

返回流量、上报积压、队列状态、进程数等数据。

## 流量统计

启用后协议收发数据按分钟写入 Redis：

```text
MonitorTraffic:minute:{YmdHi}
```

包含总收发字节/包数、各协议分项、上报包数统计。

## 监控面板

访问路径：`/iotmonitor`

基于 ECharts 的可视化监控页面，展示流量趋势、上报积压、队列状态、健康状态等。

## 辅助方法

`ManagerHelper` 提供以下公开方法：

```php
use CreatCode\IotMonitor\ManagerHelper;

// 读取配置（支持点号路径）
ManagerHelper::config('traffic.flush_interval', 5);
ManagerHelper::boolConfig('traffic.enable', false);
ManagerHelper::dbConfig('redis_ping_interval', 15);
ManagerHelper::pluginConfig(); // 完整配置数组

// 状态判断
ManagerHelper::pluginEnabled();
ManagerHelper::trafficEnabled();

// 工具方法
ManagerHelper::deviceActiveTimeKey(); // 活跃设备 Redis key
ManagerHelper::log('monitor.log', 'message'); // 写日志
```

## 日志

- 写入 `runtime/iotlog/`
- 日志文件：`redis.log`、`db.log`、`monitor.log`

## 许可证

MIT
