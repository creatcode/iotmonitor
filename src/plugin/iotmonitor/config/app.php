<?php

return [
    // 插件启用开关
    'enable' => true,

    // 控制器后缀，保持和常规Webman项目一致
    'controller_suffix' => 'Controller',

    // 监控页面不复用控制器实例，避免常驻进程中残留请求状态
    'controller_reuse' => false,

    // 监控页面访问白名单，支持单个 IP 和 CIDR 网段
    'allow_ips' => [
        '127.0.0.1',
        '::1',
    ],
];
