<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Coupon;
use App\Services\AuthService;
use App\Services\UserService;
use App\Services\RedemptionCodeService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\TelegramService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Jobs\OrderHandleJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessions()
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($request->input('session_id'))
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        return response([
            'data' => $data
        ]);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('old_password'),
            $user->password)
        ) {
            abort(500, __('The old password is wrong'));
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        return response([
            'data' => $user
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        return response([
            'data' => Helper::getSubscribeUrl($user['token'])
        ]);
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($request->input('transfer_amount') > $user->commission_balance) {
            abort(500, __('Insufficient commission balance'));
        }
        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        if (!$user->save()) {
            abort(500, __('Transfer failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }
    public function redeemPlan(Request $request)
    {
        //兑换码验证
        $code=$request->input('redeem_code');
        $user_id = $request->user['id'];
        $user = User::find($user_id);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $redemptionCodeService = new RedemptionCodeService();
        $redeemData = $redemptionCodeService->validate($code);
        $plan = Plan::find($redeemData['plan_id']);
        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }
        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            abort(500, __('This subscription has been sold out, please choose another subscription'));
        }
        if ($plan[$redeemData['period']] === NULL) {
            abort(500, __('This payment period cannot be purchased, please choose another cycle'));
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = $redeemData['period'];
        $order->trade_no = Helper::guid();
        $order->total_amount = 0;
        $order->status = 1;
        $order->invite_user_id = $user->invite_user_id;
        $couponService = new CouponService($code);
        if (!$couponService->use($order)) {
            DB::rollBack();
            abort(500, __('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
        $orderService->setOrderType($user);
        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to update order amount'));
        }
        OrderHandleJob::dispatchNow($order->trade_no);
        $this->notify($order);
        DB::commit();
        return response([
            'data' => [
                'state' => true,
                'msg' => '兑换成功'
            ]
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
