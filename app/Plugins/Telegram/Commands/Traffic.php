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

        if (!$message->is_private && !isset($message->from)) {
            $telegramService->sendMessage($message->chat_id, '无法识别用户信息', 'markdown');
            return;
        }

        $telegramId = $message->from->id ?? $message->chat_id;

        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));

        // 获取用户名
        $username = $message->from->username ?? ($message->from->first_name ?? '未知用户');

        $text = "🚥 流量查询\n"
            . "———————————————\n"
            . "👤 用户：`{$username}`\n"
            . "📦 计划流量：`{$transferEnable}`\n"
            . "📤 已用上行：`{$up}`\n"
            . "📥 已用下行：`{$down}`\n"
            . "📉 剩余流量：`{$remaining}`";

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
