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
use Illuminate\Support\Facades\DB;
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
        \Log::info('邀请码无效或已使用', [
            'invite_code' => $request->input('invite_code'),
            'force' => config('v2board.invite_force')
        ]);

        if ((int)config('v2board.invite_force', 0)) {
            abort(500, __('Invalid invitation code'));
        }
        return;
    }

    \Log::info('邀请码有效，建立邀请关系', [
        'invite_code' => $inviteCode->code,
        'inviter_id' => $inviteCode->user_id,
        'user_id' => $user->id
    ]);

    $user->invite_user_id = $inviteCode->user_id;
    if (!(int)config('v2board.invite_never_expire', 0)) {
        $inviteCode->status = 1;
        $inviteCode->save();
    }

    $inviteGiveType = (int)config('v2board.is_Invitation_to_give', 0);
    \Log::info('邀请奖励配置类型', ['type' => $inviteGiveType]);

    if ($inviteGiveType === 1 || $inviteGiveType === 3) {
        $this->handleInviteReward($user);
    } else {
        \Log::info('不发放邀请奖励，跳过', ['type' => $inviteGiveType]);
    }
}

public function handleInviteReward(User $user)
{
    try {
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) {
            \Log::warning('邀请人不存在', ['invite_user_id' => $user->invite_user_id]);
            return;
        }

        if ((int)config('v2board.try_out_plan_id') == $inviter->plan_id) {
            \Log::info('邀请人是试用套餐用户，跳过奖励', [
                'inviter_id' => $inviter->id,
                'plan_id' => $inviter->plan_id
            ]);
            return;
        }

        $rewardPlan = Plan::find((int)config('v2board.complimentary_packages'));
        if (!$rewardPlan) {
            \Log::warning('奖励套餐未配置或不存在');
            return;
        }

        $inviterCurrentPlan = Plan::find($inviter->plan_id);
        if (!$inviterCurrentPlan) {
            \Log::warning('邀请人当前套餐不存在', ['plan_id' => $inviter->plan_id]);
            return;
        }

        // 检查价格有效性
        $rewardHasValidPrice = $this->hasValidPrice($rewardPlan);
        $inviterHasValidPrice = $this->hasValidPrice($inviterCurrentPlan);

        if (!$rewardHasValidPrice || !$inviterHasValidPrice) {
            \Log::warning('套餐价格无效，无法计算奖励', [
                'inviter_id' => $inviter->id,
                'reward_plan_id' => $rewardPlan->id,
                'inviter_plan_id' => $inviter->plan_id
            ]);
            return;
        }

        \Log::info('开始发放奖励', [
            'inviter_id' => $inviter->id,
            'user_id' => $user->id
        ]);

        DB::transaction(function () use ($user, $rewardPlan, $inviterCurrentPlan, $inviter) {
            // 省略不变部分...
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

private function hasValidPrice(Plan $plan): bool
{
    return $plan->month_price > 0 ||
           $plan->quarter_price > 0 ||
           $plan->half_year_price > 0 ||
           $plan->year_price > 0 ||
           $plan->two_year_price > 0 ||
           $plan->three_year_price > 0 ||
           $plan->onetime_price > 0;
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
        // if ((int)config('v2board.email_verify', 0)) {
        //     Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        // }

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
