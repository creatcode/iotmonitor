<?php

namespace plugin\iotmonitor\app\controller;

use CreatCode\IotMonitor\Monitor\OverviewReader;
use plugin\iotmonitor\app\middleware\MonitorAccess;
use support\Request;

class IndexController
{
    /**
     * 中间件
     */
    protected $middleware = [
        MonitorAccess::class,
    ];

    /**
     * 监控页面
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        return view('index/index');
    }


    /**
     * 监控数据
     *
     * @param Request $request
     */
    public function overview(Request $request)
    {
        $allowMinutes = [5, 60, 360, 1440];
        $minutes = (int)$request->get('minutes', 60);

        if (!in_array($minutes, $allowMinutes)) {
            $minutes = 60;
        }

        return json([
            'code' => 200,
            'msg' => 'ok',
            'data' => (new OverviewReader())->build($minutes),
        ]);
    }
}
