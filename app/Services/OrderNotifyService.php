<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\Coupon;
use App\Services\TelegramService;
use Illuminate\Support\Carbon;

class OrderNotifyService
{
    public function notify(Order $order): void
    {
        // type
        $types = [1 => "新购", 2 => "续费", 3 => "变更", 4 => "流量包"];
        $type = $types[$order->type] ?? "未知";

        // planName
        $planName = "";
        $plan = Plan::find($order->plan_id);
        if ($plan) {
            $planName = $plan->name;
        }

        // period
        $periodMapping = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '2年付',
            'three_year_price' => '3年付',
            'onetime_price' => '一次性付款',
            'setup_price' => '设置费',
            'reset_price' => '流量重置包'
        ];
        $period = $periodMapping[$order->period] ?? '未知';

        // email
        $userEmail = "";
        $user = User::find($order->user_id);
        if ($user) {
            $userEmail = $user->email;
        }

        // inviterEmail  inviterCommission
        $inviterEmail = '';
        $getAmount = 0;
        $anotherInfo = "邀请人：该用户不存在邀请人";

        if (!empty($order->invite_user_id)) {
            $inviter = User::find($order->invite_user_id);
            if ($inviter) {
                $inviterEmail = $inviter->email;
                $getAmount = $this->getCommission($inviter->id, $order);

                if ((int)config('v2board.withdraw_close_enable', 0)) {
                    $inviterBalance = $inviter->balance / 100 + $getAmount;
                    $anotherInfo = "邀请人总余额：" . $inviterBalance . " 元";
                } else {
                    $inviterCommissionBalance = $inviter->commission_balance / 100 + $getAmount;
                    $anotherInfo = "邀请人总佣金：" . $inviterCommissionBalance . " 元";
                }
            }
        }

        $discountAmount = "无";
        $code = "无";
        if ($order->coupon_id !== null) {
            $discountAmount = $order->discount_amount / 100 . " 元";
            $coupon = Coupon::find($order->coupon_id);
            if ($coupon) {
                $code = $coupon->code;
            }
        }

        $signupDate = $user->created_at
            ? Carbon::createFromTimestamp($user->created_at)->toDateString()
            : '未知';

        $message = sprintf(
            "💰成功收款 %s元\n———————————————\n订单号：`%s`\n邮箱： `%s`\n套餐：%s\n类型：%s\n周期：%s\n优惠金额：%s\n优惠码：%s\n本次佣金：%s 元\n邀请人邮箱： `%s`\n%s\n注册日期：%s",
            $order->total_amount / 100,
            $order->trade_no,
            $userEmail,
            $planName,
            $type,
            $period,
            $discountAmount,
            $code,
            $getAmount,
            $inviterEmail,
            $anotherInfo,
            $signupDate
        );
        \Log::info('订单通知消息', [
            'order_id' => $order->id,
            'user_email' => $userEmail,
            'plan_name' => $planName,
            'type' => $type,
            'period' => $period,
            'discount' => $discountAmount,
            'coupon_code' => $code,
            'commission' => $getAmount,
            'inviter_email' => $inviterEmail,
            'extra_info' => $anotherInfo,
            'signup_date' => $signupDate,
            'message' => $message,
        ]);
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message, true);
    }

    private function getCommission($inviteUserId, $order)
    {
        $getAmount = 0;
        $level = 3;
        if ((int)config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            if (!$inviter) continue;
            if (!isset($commissionShareLevels[$l])) continue;
            $getAmount = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$getAmount) continue;
        }
        return $getAmount / 100;
    }
}
