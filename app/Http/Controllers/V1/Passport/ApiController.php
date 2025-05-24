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
            'password' => ['required', 'string', 'min:4'],  // 密码必须至少8位
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
        //试用
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
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
        }
        //邀请奖励
        if ((int)config('v2board.invite_force_present') == 1) {
            $plan = Plan::find((int)config('v2board.complimentary_packages'));
            if ($plan && $user->invite_user_id) {
                $inviter = User::find($user->invite_user_id);

                // 判断邀请人存在且不是体验套餐用户
                if ($inviter && (int)config('v2board.try_out_plan_id') != $inviter->plan_id) {

                    // 判断是否已奖励过（自定义判断逻辑，可以存在字段如 has_received_inviter_reward）
                    if (!$inviter->has_received_inviter_reward) {

                        // 创建赠送订单
                        $rewardOrder = new Order();
                        $orderService = new OrderService($rewardOrder);
                        $rewardOrder->user_id = $inviter->id;
                        $rewardOrder->plan_id = $plan->id;

                        // 配置中读取赠送时长（小时）
                        $giftHours = (int)config('v2board.complimentary_package_duration', 720); // 默认30天

                        // 根据时长选择周期
                        if ($giftHours <= 24 * 30) {
                            $rewardOrder->period = 'month_price';
                        } else if ($giftHours <= 24 * 90) {
                            $rewardOrder->period = 'quarter_price';
                        } else if ($giftHours <= 24 * 180) {
                            $rewardOrder->period = 'half_year_price';
                        } else {
                            $rewardOrder->period = 'year_price';
                        }

                        // 设置赠送天数
                        $rewardOrder->gift_days = round($giftHours / 24, 2);
                        $rewardOrder->trade_no = Helper::guid();
                        $rewardOrder->total_amount = 0;
                        $rewardOrder->status = 3;
                        $rewardOrder->type = 6; // 首单奖励类型
                        $rewardOrder->invited_user_id = $user->id;

                        $orderService->setInvite($user);
                        $rewardOrder->save();

                        // 更新邀请人套餐有效期
                        $this->updateInviterExpiry($inviter, $plan, $rewardOrder);
                    } else {
                        \Log::info('邀请人已获得过该用户的奖励', [
                            'inviter_id' => $inviter->id,
                            'user_id' => $user->id
                        ]);
                    }
                }
            }
        }
        DB::commit();
        $authService = new AuthService($user);
        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }
}
