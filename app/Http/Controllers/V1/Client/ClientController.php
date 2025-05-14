<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $this->setSubscribeInfoToServers($servers, $user);
            $servers = $this->filterServers($servers, $request);
        } else {
            $url = config('v2board.app_url');
            $servers = [
                [
                    'type' => 'shadowsocks',
                    'port' => 443,
                    'host' => 'www.google.com',
                    'cipher' => 'aes-128-gcm',
                    'name' => '您的服务已到期',
                ],
                [
                    'type' => 'shadowsocks',
                    'port' => 443,
                    'host' => 'www.google.com',
                    'cipher' => 'aes-128-gcm',
                    'name' => '请登录' . $url . ' 续费',
                ],
            ];
        }
        if ($flag) {
            foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                $file = 'App\\Protocols\\' . basename($file, '.php');
                $class = new $file($user, $servers);
                if (strpos($flag, $class->flag) !== false) {
                    die($class->handle());
                }
            }
        }
        $class = new General($user, $servers);
        die($class->handle());
    }
    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0]))
            return;
        if (!(int) config('v2board.show_info_to_server_enable', 0))
            return;
        $url = config('v2board.app_url');
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "到期:{$expiredDate};剩余:{$remainingTraffic};距离重置:{$resetDay}天",
            ]));
        } else {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "到期:{$expiredDate};剩余:{$remainingTraffic}",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "认准官网唯一渠道;其余均为假冒",
        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "官网:{$url}",
        ]));
    }
    private function filterServers(&$servers, Request $request)
    {
        $include = $request->input('include');
        $exclude = $request->input('exclude');
        $includeArray = preg_split('/[,|]/', $include, -1, PREG_SPLIT_NO_EMPTY);
        $excludeArray = preg_split('/[,|]/', $exclude, -1, PREG_SPLIT_NO_EMPTY);
        $servers = array_filter($servers, function ($item) use ($includeArray, $excludeArray) {
            $includeMatch = empty($includeArray) || array_reduce($includeArray, function ($carry, $word) use ($item) {
                return $carry || (stripos($item['name'], $word) !== false);
            }, false);
            $excludeMatch = empty($excludeArray) || array_reduce($excludeArray, function ($carry, $word) use ($item) {
                return $carry && (stripos($item['name'], $word) === false);
            }, true);

            return $includeMatch && $excludeMatch;
        });
        return $servers;
    }
}
