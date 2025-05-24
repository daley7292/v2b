<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Models\CommissionLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\TelegramService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Library\BitpayX;
use Library\Epay;
use Library\MGate;
use Omnipay\Omnipay;
use Stripe\Source;
use Stripe\Stripe;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $model = Order::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC');
        if ($request->input('status') !== null) {
            $model->where('status', $request->input('status'));
        }
        $order = $model->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($order); $i++) {
            for ($x = 0; $x < count($plan); $x++) {
                if ($order[$i]['plan_id'] === $plan[$x]['id']) {
                    $order[$i]['plan'] = $plan[$x];
                }
            }
        }
        return response([
            'data' => $order->makeHidden(['id', 'user_id'])
        ]);
    }

    public function detail(Request $request)
    {
        $order = Order::where('user_id', $request->user['id'])
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int)config('v2board.try_out_plan_id');
        if (!$order['plan']) {
            abort(500, __('Subscription plan does not exist'));
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return response([
            'data' => $order
        ]);
    }

    public function save(OrderSave $request)
    {
        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($request->user['id'])) {
            abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $planService = new PlanService($request->input('plan_id'));

        $plan = $planService->plan;
        $user = User::find($request->user['id']);

        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }

        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $request->input('period') !== 'reset_price') {
            abort(500, __('Current product is sold out'));
        }

        if ($plan[$request->input('period')] === NULL) {
            abort(500, __('This payment period cannot be purchased, please choose another period'));
        }

        if ($request->input('period') === 'reset_price') {
            if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                abort(500, __('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
            }
        }

        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($request->input('period') !== 'reset_price') {
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }
        }

        if (!$plan->renew && $user->plan_id == $plan->id && $request->input('period') !== 'reset_price') {
            abort(500, __('This subscription cannot be renewed, please change to another subscription'));
        }


        if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
            abort(500, __('This subscription has expired, please change to another subscription'));
        }

        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = $plan->id;
        $order->period = $request->input('period');
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $plan[$request->input('period')];

        if ($request->input('coupon_code')) {
            $couponService = new CouponService($request->input('coupon_code'));
            if (!$couponService->use($order)) {
                DB::rollBack();
                abort(500, __('Coupon failed'));
            }
            $order->coupon_id = $couponService->getId();
        }

        $orderService->setVipDiscount($user);
        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        if ($user->balance && $order->total_amount > 0) {
            $remainingBalance = $user->balance - $order->total_amount;
            $userService = new UserService();
            if ($remainingBalance > 0) {
                if (!$userService->addBalance($order->user_id, - $order->total_amount)) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $order->total_amount;
                $order->total_amount = 0;
            } else {
                if (!$userService->addBalance($order->user_id, - $user->balance)) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $user->balance;
                $order->total_amount = $order->total_amount - $user->balance;
            }
        }

        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to create order'));
        }

        DB::commit();

        return response([
            'data' => $order->trade_no
        ]);
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->where('status', 0)
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no)) abort(500, '');
            $this->notify($order);
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || $payment->enable !== 1) abort(500, __('Payment method is not available'));
        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save()) abort(500, __('Request failed, please try again later'));
        $result = $paymentService->pay([
            'trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ]);
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        return response([
            'data' => $order->status
        ]);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        return response([
            'data' => $methods
        ]);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            abort(500, __('Invalid parameter'));
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        if ($order->status !== 0) {
            abort(500, __('You can only cancel pending orders'));
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            abort(500, __('Cancel failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function notify(Order $order){
        // type
        $types = [1 => "新购", 2 => "续费", 3 => "变更" , 4 => "流量包"];
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
        if ($user){
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

                if ((int)config('v2board.withdraw_close_enable', 0)) {
                    $inviterBalance = $inviter->balance / 100 + $getAmount; // 总余额 （关闭提现）
                    $anotherInfo = "邀请人总余额：" . $inviterBalance. " 元";
                } else {
                    $inviterCommissionBalance = $inviter->commission_balance / 100 + $getAmount; // 总佣金 （允许提现）
                    $anotherInfo = "邀请人总佣金：" . $inviterCommissionBalance. " 元";

                }
            }
        }

        $discountAmount = "无";
        $code = "无";
        $couponID = $order->coupon_id;
        if ($couponID !== null){

            //优惠金额
            $discountAmount = $order->discount_amount / 100 . " 元" ;

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
        $telegramService->sendMessageWithAdmin($message,true);
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
    public function handleFirstOrderReward(Order $order)
    {
        // 1. 获取订单用户和其邀请人
        $user = User::find($order->user_id);
        if (!$user || !$user->invite_user_id) {
            \Log::info('用户不存在或无邀请人', [
                'order_id' => $order->id,
                'user_id' => $order->user_id
            ]);
            return;
        }

        // 2. 获取邀请人信息
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) {
            \Log::info('邀请人不存在', [
                'user_id' => $user->id,
                'invite_user_id' => $user->invite_user_id
            ]);
            return;
        }

        // 3. 检查是否是首次付费订单
        $hasOtherPaidOrders = Order::where('user_id', $user->id)
            ->where('id', '!=', $order->id)
            ->where('status', 3) // 已支付
            ->where('total_amount', '>', 0) // 只检查付费订单
            ->exists();

        if ($hasOtherPaidOrders) {
            \Log::info('非首次付费订单，不触发邀请奖励', [
                'user_id' => $user->id,
                'order_id' => $order->id
            ]);
            return;
        }

        // 4. 检查被邀请用户是否已经触发过奖励
        if ($user->has_triggered_invite_reward) {
            \Log::info('该用户已经为邀请人带来过奖励', [
                'user_id' => $user->id,
                'inviter_id' => $inviter->id
            ]);
            return;
        }

        // 5. 处理邀请奖励
        $plan = Plan::find((int)config('v2board.complimentary_packages'));
        if (!$plan) {
            \Log::error('赠送套餐不存在');
            return;
        }

        try {
            // 创建赠送订单
            $rewardOrder = new Order();
            $orderService = new OrderService($rewardOrder);
            $rewardOrder->user_id = $inviter->id;
            $rewardOrder->plan_id = $plan->id;

            // 从配置中读取赠送时长（小时）
            $giftHours = (int)config('v2board.complimentary_package_duration', 720); // 默认30天

            // 根据时长确定period
            if ($giftHours <= 24 * 30) {
                $rewardOrder->period = 'month_price';
                $periodLabel = '月付';
            } else if ($giftHours <= 24 * 90) {
                $rewardOrder->period = 'quarter_price';
                $periodLabel = '季付';
            } else if ($giftHours <= 24 * 180) {
                $rewardOrder->period = 'half_year_price';
                $periodLabel = '半年付';
            } else {
                $rewardOrder->period = 'year_price';
                $periodLabel = '年付';
            }

            // 计算赠送天数并设置
            $giftDays = round($giftHours / 24, 2);
            $rewardOrder->gift_days = $giftDays;

            // ...其他设置不变
            $rewardOrder->trade_no = Helper::guid();
            $rewardOrder->total_amount = 0;
            $rewardOrder->status = 3;
            $rewardOrder->type = 6; // 首单奖励类型
            $rewardOrder->invited_user_id = $user->id;
            $orderService->setInvite($user);
            $rewardOrder->save();

            // 更新邀请人有效期 - 保持不变
            $this->updateInviterExpiry($inviter, $plan, $rewardOrder);

            \Log::info('首次付费邀请奖励发放成功', [
                'user_id' => $user->id,
                'inviter_id' => $inviter->id,
                'order_id' => $rewardOrder->id
            ]);

            // 处理订单佣金
            $this->processCommissionForOrder($order);
        } catch (\Exception $e) {
            \Log::error('首次付费邀请奖励发放失败', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'inviter_id' => $inviter->id
            ]);
        }
    }
    public function processCommissionForOrder(Order $order)
    {
        // 检查订单状态和金额
        if ($order->status !== 3 || $order->total_amount <= 0) {
            return;
        }
        
        // 检查佣金系统是否启用
        if ((int)config('v2board.commission_status', 0) !== 1) {
            return;
        }
        
        // 获取用户信息
        $user = User::find($order->user_id);
        if (!$user || !$user->invite_user_id) {
            return;
        }
        
        // 查找邀请人
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) {
            return;
        }
        
        // 检查是否已发放过该订单佣金
        $hasCommissionLog = \App\Models\CommissionLog::where('trade_no', $order->trade_no)
            ->where('type', 1)
            ->exists();
        if ($hasCommissionLog) {
            return;
        }
        
        // 计算佣金金额
        $commissionRate = (float)config('v2board.invite_commission', 10) / 100;
        $commissionAmount = $order->total_amount * $commissionRate;
        
        // 佣金金额过小则忽略
        if ($commissionAmount < 0.01) {
            return;
        }
        
        // 创建佣金记录
        $commissionLog = new \App\Models\CommissionLog();
        $commissionLog->invite_user_id = $inviter->id; // 邀请人ID
        $commissionLog->user_id = $user->id; // 被邀请人ID
        $commissionLog->trade_no = $order->trade_no;
        $commissionLog->order_amount = $order->total_amount;
        $commissionLog->commission_amount = $commissionAmount;
        $commissionLog->type = 1; // 1表示订单佣金
        $commissionLog->created_at = time();
        $commissionLog->updated_at = time();
        
        // 更新邀请人佣金余额
        if ($commissionLog->save()) {
            $inviter->commission_balance = $inviter->commission_balance + $commissionAmount;
            $inviter->save();
            
            \Log::info('订单佣金发放成功', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'inviter_id' => $inviter->id,
                'amount' => $commissionAmount
            ]);
        }
    }
}
