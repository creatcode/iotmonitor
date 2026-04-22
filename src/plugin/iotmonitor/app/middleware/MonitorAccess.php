<?php

namespace plugin\iotmonitor\app\middleware;

use support\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class MonitorAccess implements MiddlewareInterface
{
    /**
     * 监控页面访问验证
     *
     * @param Request $request
     * @param callable $handler
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        $ip = $request->getRemoteIp(true);

        // 本机访问直接放行
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return $handler($request);
        }

        if ($request->expectsJson() || $request->header('accept') === 'application/json') {
            return json([
                'code' => 403,
                'msg' => '无权限访问当前资源',
                'data' => null,
            ]);
        }

        return response('Forbidden', 403);
    }
}
