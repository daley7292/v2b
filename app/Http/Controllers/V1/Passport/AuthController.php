<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;

class AuthController extends Controller
{
    public function loginWithMailLink(Request $request)
    {
        if (!(int)config('v2board.login_with_mail_link_enable')) {
            abort(404);
        }
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        if (Cache::get(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']))) {
            abort(500, __('Sending frequently, please try again later'));
        }

        $user = User::where('email', $params['email'])->first();
        if (!$user) {
            return response([
                'data' => true
            ]);
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);
        Cache::put(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']), time(), 60);


        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $link = config('v2board.app_url') . $redirect;
        } else {
            $link = url($redirect);
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => config('v2board.app_name', 'V2Board')
            ]),
            'template_name' => 'login',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'link' => $link,
                'url' => config('v2board.app_url')
            ]
        ]);

        return response([
            'data' => $link
        ]);

    }

    public function register(AuthRegister $request)
    {
        // 1. 验证注册限制
        $this->validateRegistrationRestrictions($request);
        
        // 2. 创建用户
        $user = $this->createUser($request);
        
        // 3. 处理邀请码
        if ($request->input('invite_code')) {
            $this->handleInviteCode($request, $user);
        }

        // 4. 保存用户并处理后续操作
        if (!$user->save()) {
            abort(500, __('Register failed'));
        }

        // 5. 处理试用计划
        $this->handleTrialPlan($user);


        // 6. 清理验证码和更新登录时间
        $this->handlePostRegistration($request, $user);
        
        // 7. 返回认证数据
        $authService = new AuthService($user);
        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }
    private function validateRegistrationRestrictions(Request $request)
    {
        // IP限制检查
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }
        // 邮箱白名单检查
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }

        // Gmail别名限制
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }

        // 注册开关检查
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }

        // 强制邀请码检查
        if ((int)config('v2board.invite_force', 0) && empty($request->input('invite_code'))) {
            abort(500, __('You must use the invitation code to register'));
        }

        // 邮箱验证码检查
        if ((int)config('v2board.email_verify', 0)) {
            if (empty($request->input('email_code'))) {
                abort(500, __('Email verification code cannot be empty'));
            }
            if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string)$request->input('email_code')) {
                abort(500, __('Incorrect email verification code'));
            }
        }
    }

    private function createUser(Request $request)
    {
        // 检查邮箱是否已存在
        $email = $request->input('email');
        if (User::where('email', $email)->exists()) {
            abort(500, __('Email already exists'));
        }

        // 创建新用户
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        // 添加默认值设置
        $user->is_admin = 0;
        $user->is_staff = 0;
        $user->last_login_at = time();
        $user->created_at = time();
        return $user;
    }

    public function handleInviteCode(Request $request, User $user)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))
            ->where('status', 0)
            ->first();

        if (!$inviteCode) {
            if ((int)config('v2board.invite_force', 0)) {
                abort(500, __('Invalid invitation code'));
            }
            return;
        }

        // 设置邀请关系
        $user->invite_user_id = $inviteCode->user_id;
        if (!(int)config('v2board.invite_never_expire', 0)) {
            $inviteCode->status = 1;
            $inviteCode->save();
        }

        // 处理邀请奖励
        $inviteGiveType = (int)config('v2board.is_Invitation_to_give', 0);
        if ($inviteGiveType === 1 || $inviteGiveType === 3) {
            $this->handleInviteReward($user);
        }
    }

    // 处理邀请奖励 - 根据套餐价值比例折算
    public function handleInviteReward(User $user)
    {
        try {
            // 获取邀请人
            $inviter = User::find($user->invite_user_id);
            if (!$inviter || (int)config('v2board.try_out_plan_id') == $inviter->plan_id) {
                return;
            }
            
            // 获取奖励套餐(配置中设置的赠送套餐)
            $rewardPlan = Plan::find((int)config('v2board.complimentary_packages'));
            if (!$rewardPlan) {
                return;
            }
            
            // 获取邀请人当前套餐
            $inviterCurrentPlan = Plan::find($inviter->plan_id);
            if (!$inviterCurrentPlan) {
                return;
            }

            // 检查套餐价格有效性
            $rewardHasValidPrice = $rewardPlan->month_price > 0 || 
                $rewardPlan->quarter_price > 0 || 
                $rewardPlan->half_year_price > 0 || 
                $rewardPlan->year_price > 0 || 
                $rewardPlan->two_year_price > 0 || 
                $rewardPlan->three_year_price > 0 || 
                $rewardPlan->onetime_price > 0;

            $inviterHasValidPrice = $inviterCurrentPlan->month_price > 0 || 
                $inviterCurrentPlan->quarter_price > 0 || 
                $inviterCurrentPlan->half_year_price > 0 || 
                $inviterCurrentPlan->year_price > 0 || 
                $inviterCurrentPlan->two_year_price > 0 || 
                $inviterCurrentPlan->three_year_price > 0 || 
                $inviterCurrentPlan->onetime_price > 0;

            if (!$inviterHasValidPrice || !$rewardHasValidPrice) {
                \Log::warning('套餐价格异常，无法计算奖励', [
                    'inviter_id' => $inviter->id,
                    'reward_plan_id' => $rewardPlan->id,
                    'current_plan_id' => $inviter->plan_id
                ]);
                return; // 避免除零错误
            }
            
            DB::transaction(function () use ($user, $rewardPlan, $inviterCurrentPlan, $inviter) {
                // 初始化时间
                $currentTime = time();
                if ($inviter->expired_at === null || $inviter->expired_at < $currentTime) {
                    $inviter->expired_at = $currentTime;
                }
                
                // 计算奖励套餐的月均价值
                $rewardMonthlyValue = $this->getMonthlyValue($rewardPlan);
                
                // 计算邀请人当前套餐的月均价值
                $inviterMonthlyValue = $this->getMonthlyValue($inviterCurrentPlan);
                
                // 计算套餐价值比例：奖励套餐月均价值 / 邀请人套餐月均价值
                $priceRatio = $rewardMonthlyValue / $inviterMonthlyValue;
                
                // 配置的赠送小时数
                $configHours = (int)config('v2board.complimentary_package_duration', 1);
                
                // 根据价值比例折算实际赠送时间
                $adjustedHours = $configHours * $priceRatio;
                $add_seconds = $adjustedHours * 3600; // 转换为秒
                
                // 更新邀请人到期时间
                $inviter->expired_at = $inviter->expired_at + $add_seconds;
                
                // 将秒数转换为天数（用于显示）
                $calculated_days = $add_seconds / 86400;
                $formatted_days = number_format($calculated_days, 2, '.', '');
                
                // 创建赠送订单
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $inviter->id;
                $order->plan_id = $rewardPlan->id;
                $order->period = 'try_out';  // 赠送标记位
                $order->trade_no = Helper::guid();
                $order->total_amount = 0;
                $order->status = 3;
                $order->type = 6;
                $order->invited_user_id = $user->id;
                $order->redeem_code = null;
                $order->gift_days = $formatted_days;
                $orderService->setInvite($user);
                $order->save();
                
                // 更新邀请人状态
                $inviter->has_received_inviter_reward = 1;
                $inviter->save();
                
                \Log::info('注册邀请奖励发放成功', [
                    'user_id' => $user->id,
                    'inviter_id' => $inviter->id,
                    'order_id' => $order->id,
                    'reward_monthly_value' => $rewardMonthlyValue,
                    'inviter_monthly_value' => $inviterMonthlyValue,
                    'price_ratio' => $priceRatio,
                    'config_hours' => $configHours,
                    'adjusted_hours' => $adjustedHours,
                    'gift_days' => $formatted_days
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('处理邀请奖励失败', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 计算套餐的月均价值
     * 考虑所有付费周期，取最优惠的月均价值（通常是更长周期付费）
     */
    private function getMonthlyValue($plan)
    {
        $monthlyValues = [];
        
        // 月付价格
        if ($plan->month_price > 0) {
            $monthlyValues[] = $plan->month_price;
        }
        
        // 季付价格折算为月价格
        if ($plan->quarter_price > 0) {
            $monthlyValues[] = $plan->quarter_price / 3;
        }
        
        // 半年付价格折算为月价格
        if ($plan->half_year_price > 0) {
            $monthlyValues[] = $plan->half_year_price / 6;
        }
        
        // 年付价格折算为月价格
        if ($plan->year_price > 0) {
            $monthlyValues[] = $plan->year_price / 12;
        }
        
        // 两年付价格折算为月价格
        if ($plan->two_year_price > 0) {
            $monthlyValues[] = $plan->two_year_price / 24;
        }
        
        // 三年付价格折算为月价格
        if ($plan->three_year_price > 0) {
            $monthlyValues[] = $plan->three_year_price / 36;
        }
        
        // 一次性付费，假设等同于年付折算
        if ($plan->onetime_price > 0) {
            $monthlyValues[] = $plan->onetime_price / 12;
        }
        
        // 如果没有有效价格，返回1以避免除零错误
        if (empty($monthlyValues)) {
            return 1;
        }
        
        // 返回最优惠的月均价值（最小值）
        return min($monthlyValues);
    }

    private function handleTrialPlan(User $user)
    {
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                //判断试用计划是否存在并且进行更新
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        } else {
            $user->transfer_enable = 0;
            $user->plan_id = 0;
            $user->group_id = 0;
            $user->expired_at = 0;
            $user->speed_limit = 0;
            $user->is_admin = 0;
        }
    }

    private function handlePostRegistration(Request $request, User $user)
    {
        // 清理邮箱验证码缓存
        if ((int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        }

        // 更新登录时间
        $user->last_login_at = time();
        $user->save();

        // 更新IP限制缓存
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }
    }
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location)->send();
        }

        if ($request->input('verify')) {
            $key =  CacheKey::get('TEMP_TOKEN', $request->input('verify'));
            $userId = Cache::get($key);
            if (!$userId) {
                abort(500, __('Token error'));
            }
            $user = User::find($userId);
            if (!$user) {
                abort(500, __('The user does not '));
            }
            if ($user->banned) {
                abort(500, __('Your account has been suspended'));
            }
            Cache::forget($key);
            $authService = new AuthService($user);
            return response([
                'data' => $authService->generateAuthData($request)
            ]);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user['id'], 60);
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

    public function forget(AuthForget $request)
    {
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $request->input('email'));
        $forgetRequestLimit = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) abort(500, __('Reset failed, Please try again later'));
        if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string)$request->input('email_code')) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit ? $forgetRequestLimit + 1 : 1, 300);
            abort(500, __('Incorrect email verification code'));
        }
        $user = User::where('email', $request->input('email'))->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        return response([
            'data' => true
        ]);
    }

}
