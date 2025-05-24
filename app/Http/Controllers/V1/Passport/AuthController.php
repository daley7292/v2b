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
use App\Models\Order;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\OrderNotifyService;
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
        if (!(int) config('v2board.login_with_mail_link_enable')) {
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
            app(OrderNotifyService::class)->notify($order);
        }
        DB::commit();
        $authService = new AuthService($user);
        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }
   
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int) config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int) config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            if ((int) config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int) $passwordErrorCount + 1,
                    60 * (int) config('v2board.password_limit_expire', 60)
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
            $key = CacheKey::get('TEMP_TOKEN', $request->input('verify'));
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
        if (!$authorization)
            abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user)
            abort(403, '未登录或登陆已过期');

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
        $forgetRequestLimit = (int) Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3)
            abort(500, __('Reset failed, Please try again later'));
        if ((string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string) $request->input('email_code')) {
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
