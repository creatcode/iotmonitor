<?php

namespace plugin\iotmonitor\app\controller;

use CreatCode\IotMonitor\Monitor\OverviewReader;
use support\Request;

class IndexController
{
    /**
     * 监控页面
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        $showiot = $request->get('showiot');
        $view = 'index/index';
        if ($showiot === 'overview') {
            $view = 'index/overview';
        }
        return raw_view($view);
    }


    /**
     * 监控数据
     *
     * @param Request $request
     */
    public function overview(Request $request)
    {
        $minutes = (int)$request->get('minutes', 60);

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => (new OverviewReader())->build($minutes),
        ]);
    }
}
