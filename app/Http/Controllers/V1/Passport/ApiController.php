<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Services\RedemptionCodeService;
use App\Services\AuthService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\TelegramService;
use App\Models\Coupon;
use App\Models\User;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\Order;
use App\Models\CommissionLog;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use App\Jobs\OrderHandleJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => ['required', 'string', 'min:8'],  // 密码必须至少8位
        ], [
            'email.required' => '邮箱地址不能为空',
            'email.email' => '邮箱格式不正确',
            'password.required' => '密码不能为空',
            'password.min' => '密码长度不能少于8位',
        ]);
        $email = $request->input('email');
        $password = $request->input('password');
        $code = $request->input('code');
        $inviteCode = $request->input('invite_code');
        $emailCode = $request->input('email_code');
        // 检查邮箱格式规则
        if ((int) config('v2board.email_whitelist_enable', 0)) {
            if (
                !Helper::emailSuffixVerify(
                    $email,
                    config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT)
                )
            ) {
                abort(500, '该邮箱后缀不在白名单内');
            }
        }

        // 检查 Gmail 别名限制
        if ((int) config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, '不支持 Gmail 别名');
            }
        }

        // 检查邮箱是否已存在
        if (User::where('email', $email)->exists()) {
            abort(500, '该邮箱已被注册');
        }
        //邮箱验证码
        if ((int) config('v2board.email_verify', 0)) {
            if (empty($emailCode)) {
                abort(500, __('Email verification code cannot be empty'));
            }
            if ((string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email)) !== (string) $emailCode) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        //邀请码
        if ((int) config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }

        if ((int) config('v2board.invite_force', 0)) {
            if (empty($inviteCode)) {
                abort(500, __('You must use the invitation code to register'));
            }
        }
        if ($inviteCode) {
            $inviteCode = InviteCode::where('code', $inviteCode)->where('status', 0)->first();
            if (!$inviteCode) {
                abort(500, __('Invalid invitation code'));
            }
        }
        if (!(int) config('v2board.invite_never_expire', 0)) {
            $inviteCode->status = 1;
            $inviteCode->save();
        }
        
        //注册
        DB::beginTransaction();
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 0;
        if ($inviteCode) {
            $user->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
        }
        if (!$user->save()) {
            DB::rollBack();
            abort(500, __('Register failed'));
        }
        //订单
        if ($code) {
            $redemptionCodeService = new RedemptionCodeService();
            $redeemData = $redemptionCodeService->validate($code);
            
            $plan = Plan::find($redeemData['plan_id']);
            if (!$plan) {
                DB::rollBack();
                abort(500, __('Subscription plan does not exist'));
            }

            if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
                DB::rollBack();
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }

            if ($plan[$redeemData['period']] === NULL) {
                DB::rollBack();
                abort(500, __('This payment period cannot be purchased, please choose another cycle'));
            }

            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $redeemData['period'];
            $order->trade_no = Helper::guid();
            $order->total_amount = 0;
            $order->status = 1;
            if ($inviteCode) {
                $order->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
            }
            if ($code) {
                $couponService = new CouponService($code);
                if (!$couponService->use($order)) {
                    DB::rollBack();
                    abort(500, __('Coupon failed'));
                }
                $order->coupon_id = $couponService->getId();
            }
            $orderService->setOrderType($user);
            if (!$order->save()) {
                DB::rollback();
                abort(500, __('Failed to update order amount'));
            }
            OrderHandleJob::dispatchNow($order->trade_no);
            $this->notify($order);
        }
        DB::commit();
        $authService = new AuthService($user);
        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }
    private function notify(Order $order)
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
        // 定义英文到中文的映射关系
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
        $period = $periodMapping[$order->period];

        // email
        $userEmail = "";
        $user = User::find($order->user_id);
        if ($user) {
            $userEmail = $user->email;
        }

        // inviterEmail  inviterCommission
        $inviterEmail = '';
        $getAmount = 0; // 本次佣金
        $anotherInfo = "邀请人：该用户不存在邀请人";

        if (!empty($order->invite_user_id)) {
            $inviter = User::find($order->invite_user_id);
            if ($inviter) {
                $inviterEmail = $inviter->email;
                $getAmount = $this->getCommission($inviter->id, $order); // 本次佣金

                if ((int) config('v2board.withdraw_close_enable', 0)) {
                    $inviterBalance = $inviter->balance / 100 + $getAmount; // 总余额 （关闭提现）
                    $anotherInfo = "邀请人总余额：" . $inviterBalance . " 元";
                } else {
                    $inviterCommissionBalance = $inviter->commission_balance / 100 + $getAmount; // 总佣金 （允许提现）
                    $anotherInfo = "邀请人总佣金：" . $inviterCommissionBalance . " 元";

                }
            }
        }

        $discountAmount = "无";
        $code = "无";
        $couponID = $order->coupon_id;
        if ($couponID !== null) {

            //优惠金额
            $discountAmount = $order->discount_amount / 100 . " 元";

            // 优惠码
            $coupon = Coupon::where('id', $couponID)
                ->first();

            $code = $coupon->code;
        }

        //注册日期
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
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message, true);
    }

    private function getCommission($inviteUserId, $order)
    {
        $getAmount = 0;
        $level = 3;
        if ((int) config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int) config('v2board.commission_distribution_l1'),
                1 => (int) config('v2board.commission_distribution_l2'),
                2 => (int) config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            if (!$inviter)
                continue;
            if (!isset($commissionShareLevels[$l]))
                continue;
            $getAmount = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$getAmount)
                continue;
        }
        return $getAmount / 100;
    }
}