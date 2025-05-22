<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetTraffic extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:traffic';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量清空';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->builder = User::where('expired_at', '!=', NULL)
            ->where('expired_at', '>', time());
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        $resetMethods = Plan::select(
            DB::raw("GROUP_CONCAT(`id`) as plan_ids"),
            DB::raw("reset_traffic_method as method")
        )
            ->groupBy('reset_traffic_method')
            ->get()
            ->toArray();
        foreach ($resetMethods as $resetMethod) {
            $planIds = explode(',', $resetMethod['plan_ids']);
            switch (true) {
                case ($resetMethod['method'] === NULL): {
                    $resetTrafficMethod = config('v2board.reset_traffic_method', 0);
                    $builder = with(clone ($this->builder))->whereIn('plan_id', $planIds);
                    switch ((int) $resetTrafficMethod) {
                        case 0:
                            $this->resetByMonthFirstDay($builder);
                            break;
                        case 1:
                            $this->resetByExpireDay($builder);
                            break;
                        case 2:
                            break;
                        case 3:
                            $this->resetByYearFirstDay($builder);
                        case 4:
                            $this->resetByExpireYear($builder);
                            break;
                        case 5:
                            $this->resetByQuarterCycle($builder);
                            break;
                        case 6:
                            $this->resetByHalfYearCycle($builder);
                            break;
                    }
                    break;
                }
                case ($resetMethod['method'] === 0): {
                    $builder = with(clone ($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByMonthFirstDay($builder);
                    break;
                }
                case ($resetMethod['method'] === 1): {
                    $builder = with(clone ($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByExpireDay($builder);
                    break;
                }
                case ($resetMethod['method'] === 2): {
                    break;
                }
                case ($resetMethod['method'] === 3): {
                    $builder = with(clone ($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByYearFirstDay($builder);
                    break;
                }
                case ($resetMethod['method'] === 4): {
                    $builder = with(clone ($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByExpireYear($builder);
                    break;
                }
                case ($resetMethod['method'] === 5): {
                    $builder = with(clone ($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByQuarterCycle($builder);
                    break;
                }
                case ($resetMethod['method'] === 6): {
                    $builder = with(clone ($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByHalfYearCycle($builder);
                    break;
                }
            }
        }
    }

    private function resetByExpireYear($builder): void
    {
        $today = date('m-d');
        $users = [];

        foreach ($builder->get() as $item) {
            if (date('m-d', $item->expired_at) === $today) {
                $users[] = $item;
            }
        }

        $this->resetUserTraffic($users);
    }

    private function resetByYearFirstDay($builder): void
    {
        if ((string) date('md') === '0101') {
            $this->resetUserTraffic($builder->get());
        }
    }

    private function resetByMonthFirstDay($builder): void
    {
        if ((string) date('d') === '01') {
            $this->resetUserTraffic($builder->get());
        }
    }

    private function resetByExpireDay($builder): void
    {
        $lastDay = date('d', strtotime('last day of +0 months'));
        $today = date('d');
        $users = [];

        foreach ($builder->get() as $item) {
            $expireDay = date('d', $item->expired_at);
            if ($expireDay === $today || ($today === $lastDay && $expireDay >= $lastDay)) {
                $users[] = $item;
            }
        }

        $this->resetUserTraffic($users);
    }
    private function resetByQuarterCycle($builder): void
    {
        $today = date('m-d');
        $users = [];
    
        foreach ($builder->get() as $user) {
            $expiredMonth = (int)date('m', $user->expired_at);
            $expiredDay = date('d', $user->expired_at);
            for ($i = 0; $i < 4; $i++) {
                $checkMonth = ($expiredMonth - $i * 3);
                if ($checkMonth <= 0) {
                    $checkMonth += 12;
                }
    
                if ((int)date('m') === $checkMonth && date('d') === $expiredDay) {
                    $users[] = $user;
                    break;
                }
            }
        }
    
        $this->resetUserTraffic($users);
    }
    private function resetByHalfYearCycle($builder): void
    {
        $today = date('m-d');
        $users = [];
    
        foreach ($builder->get() as $user) {
            $expiredMonth = (int)date('m', $user->expired_at);
            $expiredDay = date('d', $user->expired_at);
            for ($i = 0; $i < 2; $i++) {
                $checkMonth = ($expiredMonth - $i * 6);
                if ($checkMonth <= 0) {
                    $checkMonth += 12;
                }
                if ((int)date('m') === $checkMonth && date('d') === $expiredDay) {
                    $users[] = $user;
                    break;
                }
            }
        }
    
        $this->resetUserTraffic($users);
    }
        
    private function resetUserTraffic($users): void
    {
        foreach ($users as $user) {
            $plan = Plan::find($user->plan_id);
            if (!$plan) {
                continue;
            }
            $user->update([
                'u' => 0,
                'd' => 0,
                'transfer_enable' => $plan->transfer_enable * 1024 * 1024 * 1024
            ]);
        }
    }
}
