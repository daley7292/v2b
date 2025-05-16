<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Traffic extends Telegram
{
    public $command = '/traffic';
    public $description = '查询流量信息';

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;
        if (!$message->is_private)
            return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));
        $text = "🚥流量查询\n———————————————\n计划流量：`{$transferEnable}`\n已用上行：`{$up}`\n已用下行：`{$down}`\n剩余流量：`{$remaining}`";
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;

        // 获取发送者 ID
        $fromId = $message->from->id ?? null;

        if (!$fromId) {
            $telegramService->sendMessage($message->chat_id, '无法识别您的身份', 'markdown');
            return;
        }

        // 查找用户
        $user = User::where('telegram_id', $fromId)->first();

        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        // 生成流量信息
        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));

        $text = "🚥流量查询\n———————————————\n计划流量：`{$transferEnable}`\n已用上行：`{$up}`\n已用下行：`{$down}`\n剩余流量：`{$remaining}`";

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
