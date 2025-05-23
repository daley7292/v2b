<?php

namespace App\Http\Controllers\V1\Admin;

use App\Models\CommissionLog;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\StatUser;
use App\Services\ServerService;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use App\Models\ServerVmess;
use App\Models\Plan;
use App\Models\User;
use App\Models\Ticket;
use App\Models\Order;
use App\Models\Stat;
use App\Models\StatServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
class StatController extends Controller
{
    public function getOverride(Request $request)
    {
        return [
            'data' => [
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
            ]
        ];
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    public function getServerLastRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(10)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }
    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $builder = StatUser::orderBy('record_at', 'DESC')->where('user_id', $request->input('user_id'));

        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)
            ->get();
        return [
            'data' => $records,
            'total' => $total
        ];
    }
    private function getPeriodData($query, $startTime, $endTime)
    {
        return (clone $query)
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, SUM(total_amount) as total_amount')
            ->where('created_at', '>=', $startTime)
            ->where('created_at', '<=', $endTime)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
    private function getPreviousPeriodData($startTime, $endTime)
    {
        $periodLength = $endTime - $startTime;
        $previousStartTime = $startTime - $periodLength;
        $previousEndTime = $endTime - $periodLength;

        return Order::where('status', 3)
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, SUM(total_amount) as total_amount')
            ->where('created_at', '>=', $previousStartTime)
            ->where('created_at', '<=', $previousEndTime)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
    private function getLastYearPeriodData($startTime, $endTime)
    {
        $lastYearStartTime = strtotime('-1 year', $startTime);
        $lastYearEndTime = strtotime('-1 year', $endTime);

        return Order::where('status', 3)
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, SUM(total_amount) as total_amount')
            ->where('created_at', '>=', $lastYearStartTime)
            ->where('created_at', '<=', $lastYearEndTime)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
    private function prepareChartData($currentData, $previousData, $lastYearData, $startTime, $endTime)
    {
        $chartData = [];
        $current = $startTime;

        while ($current <= $endTime) {
            $date = date('Y-m-d', $current);
            $previousDate = date('Y-m-d', $current - ($endTime - $startTime));
            $lastYearDate = date('Y-m-d', strtotime('-1 year', $current));

            $chartData[] = [
                'date' => $date,
                'current' => ($currentData->firstWhere('date', $date)->total_amount ?? 0) / 100,
                'previous' => ($previousData->firstWhere('date', $previousDate)->total_amount ?? 0) / 100,
                'lastYear' => ($lastYearData->firstWhere('date', $lastYearDate)->total_amount ?? 0) / 100
            ];

            $current = strtotime('+1 day', $current);
        }

        return $chartData;
    }
    private function calculateGrowthRate($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round(($current - $previous) / $previous * 100, 2);
    }
    public function getOnlinePresence()
    {
        // 获取当前时间戳作为基准时间
        $baseTime = time();

        // 定义时间范围
        $timeRanges = [
            'current' => $baseTime - 600, // 最近10分钟
            'today' => strtotime('today'),
            'three_days' => $baseTime - (3 * 86400),
            'seven_days' => $baseTime - (7 * 86400),
            'fifteen_days' => $baseTime - (15 * 86400),
            'thirty_days' => $baseTime - (30 * 86400)
        ];

        // 构建基础查询 - 只查询有流量变化的记录
        $baseQuery = StatUser::where(function ($query) {
            $query->where('u', '>', 0)
                ->orWhere('d', '>', 0);
        });

        // 获取各时间段的活跃用户数
        $stats = [
            'current_online' => (clone $baseQuery)
                ->where('created_at', '>=', $timeRanges['current'])
                ->distinct('user_id')
                ->count('user_id'),
            'today_online' => (clone $baseQuery)
                ->where('created_at', '>=', $timeRanges['today'])
                ->distinct('user_id')
                ->count('user_id'),
            'three_days_online' => (clone $baseQuery)
                ->where('created_at', '>=', $timeRanges['three_days'])
                ->distinct('user_id')
                ->count('user_id'),
            'seven_days_online' => (clone $baseQuery)
                ->where('created_at', '>=', $timeRanges['seven_days'])
                ->distinct('user_id')
                ->count('user_id'),
            'fifteen_days_online' => (clone $baseQuery)
                ->where('created_at', '>=', $timeRanges['fifteen_days'])
                ->distinct('user_id')
                ->count('user_id'),
            'thirty_days_online' => (clone $baseQuery)
                ->where('created_at', '>=', $timeRanges['thirty_days'])
                ->distinct('user_id')
                ->count('user_id')
        ];

        return [
            'data' => [
                'statistics' => $stats,
                'last_updated' => date('Y-m-d H:i:s')
            ]
        ];
    }
    private function getOnlineCount($startTime)
    {
        return StatUser::where('created_at', '>=', $startTime)
            ->distinct('user_id')
            ->count('user_id');
    }

    public function getNodalFlow()
    {
        try {
            // 获取当前时间戳作为基准时间
            $baseTime = time();

            // 定义时间范围
            $timeRanges = [
                'today' => [strtotime('today'), $baseTime],
                'week' => [strtotime('-7 days'), $baseTime],
                'half_month' => [strtotime('-15 days'), $baseTime],
                'month' => [strtotime('-30 days'), $baseTime],
                'quarter' => [strtotime('-90 days'), $baseTime],
                'half_year' => [strtotime('-180 days'), $baseTime],
                'year' => [strtotime('-365 days'), $baseTime]
            ];

            // 获取所有服务器信息
            $servers = [
                'hysteria' => DB::table('v2_server_hysteria')->select(['id', 'name'])->get()->keyBy('id'),
                'shadowsocks' => DB::table('v2_server_shadowsocks')->select(['id', 'name'])->get()->keyBy('id'),
                'trojan' => DB::table('v2_server_trojan')->select(['id', 'name'])->get()->keyBy('id'),
                'vmess' => DB::table('v2_server_vmess')->select(['id', 'name'])->get()->keyBy('id')
            ];

            // 初始化结果数组
            $result = [];

            // 遍历每个时间范围获取统计数据
            foreach ($timeRanges as $period => $range) {
                // 获取该时间范围内的流量数据
                $stats = StatServer::select([
                    'server_id',
                    'server_type',
                    DB::raw('SUM(u + d) as total_traffic')
                ])
                    ->whereBetween('record_at', $range)
                    ->groupBy('server_id', 'server_type')
                    ->get();

                // 处理统计数据
                $periodStats = [];
                foreach ($stats as $stat) {
                    // 获取对应服务器信息
                    $serverInfo = $servers[$stat->server_type][$stat->server_id] ?? null;
                    if (!$serverInfo)
                        continue;

                    $periodStats[] = [
                        'server_id' => $stat->server_id,
                        'server_name' => $serverInfo->name,
                        'server_type' => $stat->server_type,
                        'traffic' => [
                            'bytes' => $stat->total_traffic,
                            'mb' => round($stat->total_traffic / 1024 / 1024, 2),
                            'gb' => round($stat->total_traffic / 1024 / 1024 / 1024, 2)
                        ]
                    ];
                }

                // 按流量降序排序
                usort($periodStats, function ($a, $b) {
                    return $b['traffic']['bytes'] - $a['traffic']['bytes'];
                });

                $result[$period] = [
                    'time_range' => [
                        'start' => date('Y-m-d H:i:s', $range[0]),
                        'end' => date('Y-m-d H:i:s', $range[1])
                    ],
                    'total_traffic' => array_sum(array_column(array_column($periodStats, 'traffic'), 'bytes')),
                    'servers' => $periodStats
                ];
            }

            // 计算总计数据
            $totalStats = [
                'total_servers' => count($stats),
                'total_traffic' => array_sum(array_column($result, 'total_traffic')),
                'server_types' => [
                    'hysteria' => count($servers['hysteria']),
                    'shadowsocks' => count($servers['shadowsocks']),
                    'trojan' => count($servers['trojan']),
                    'vmess' => count($servers['vmess'])
                ]
            ];

            return [
                'data' => [
                    'statistics' => $result,
                    'summary' => $totalStats,
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ];

        } catch (\Exception $e) {
            \Log::error('获取节点流量统计失败:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'data' => [
                    'error' => '获取统计数据失败: ' . $e->getMessage(),
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ];
        }
    }

    public function getFinances(Request $request)
    {
        $currentPeriod = [];
        $previousPeriod = [];
        $lastYearPeriod = [];

        // 获取起止时间
        $startTime = $request->input('start_time') ? strtotime($request->input('start_time')) : strtotime('first day of this month');
        $endTime = $request->input('end_time') ? strtotime($request->input('end_time')) : time();

        // 获取订单数据
        $currentData = $this->getPeriodData(Order::where('status', 3), $startTime, $endTime);
        $previousData = $this->getPreviousPeriodData($startTime, $endTime);
        $lastYearData = $this->getLastYearPeriodData($startTime, $endTime);

        // 准备图表数据
        $chartData = $this->prepareChartData($currentData, $previousData, $lastYearData, $startTime, $endTime);

        // 计算总金额
        $currentTotal = $currentData->sum('total_amount') / 100;
        $previousTotal = $previousData->sum('total_amount') / 100;
        $lastYearTotal = $lastYearData->sum('total_amount') / 100;

        // 计算环比和同比增长率
        $momGrowthRate = $this->calculateGrowthRate($currentTotal, $previousTotal);
        $yoyGrowthRate = $this->calculateGrowthRate($currentTotal, $lastYearTotal);

        return [
            'data' => [
                'current_period' => [
                    'start_time' => date('Y-m-d', $startTime),
                    'end_time' => date('Y-m-d', $endTime),
                    'total' => $currentTotal
                ],
                'previous_period' => [
                    'start_time' => date('Y-m-d', $startTime - ($endTime - $startTime)),
                    'end_time' => date('Y-m-d', $endTime - ($endTime - $startTime)),
                    'total' => $previousTotal
                ],
                'last_year_period' => [
                    'start_time' => date('Y-m-d', strtotime('-1 year', $startTime)),
                    'end_time' => date('Y-m-d', strtotime('-1 year', $endTime)),
                    'total' => $lastYearTotal
                ],
                'growth_rate' => [
                    'mom' => $momGrowthRate,  // 环比增长率
                    'yoy' => $yoyGrowthRate   // 同比增长率
                ],
                'chart_data' => $chartData    // 图表数据
            ]
        ];
    }

    private function getNeedRenewOrders($startTime, $endTime)
    {
        return Order::where('type', 1) // 新购订单
            ->where('status', 3)  // 已完成订单
            ->where(function ($query) use ($startTime, $endTime) {
                $query->whereRaw('DATE_ADD(FROM_UNIXTIME(created_at), INTERVAL CASE 
                WHEN period = "month_price" THEN 30
                WHEN period = "quarter_price" THEN 90
                WHEN period = "half_year_price" THEN 180
                WHEN period = "year_price" THEN 365
                ELSE 30 END DAY) 
                BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)', [
                    $startTime,
                    $endTime
                ]);
            })
            ->count();
    }

    private function groupDataByPeriod($orders, $startTime, $endTime, $type)
    {
        try {
            $chartData = [];
            $current = $startTime;
            $interval = $this->getIntervalByType($type);
            $maxIterations = 1000;
            $iteration = 0;

            \Log::info('开始分组数据:', [
                'startTime' => date('Y-m-d H:i:s', $startTime),
                'endTime' => date('Y-m-d H:i:s', $endTime),
                'type' => $type
            ]);

            while ($current < $endTime && $iteration < $maxIterations) {
                $periodEnd = min($current + $interval, $endTime);

                // 获取该时间段的订单
                $periodOrders = DB::table('v2_order')
                    ->select(
                        DB::raw('COUNT(CASE WHEN type = 1 THEN 1 END) as new_count'),
                        DB::raw('COUNT(CASE WHEN type = 2 THEN 1 END) as renewal_count'),
                        DB::raw('SUM(CASE WHEN type = 1 THEN total_amount ELSE 0 END) as new_amount'),
                        DB::raw('SUM(CASE WHEN type = 2 THEN total_amount ELSE 0 END) as renewal_amount')
                    )
                    ->where('created_at', '>=', $current)
                    ->where('created_at', '<', $periodEnd)
                    ->where('status', 3)
                    ->first();

                // 获取需要续费的订单数
                $needRenewOrders = $this->getNeedRenewOrders($current, $periodEnd);

                // 新购数据点
                $chartData[] = [
                    'date' => date('Y-m-d', $current),
                    'type' => '新购',
                    'value' => (int) $periodOrders->new_count
                ];

                // 续费数据点
                $chartData[] = [
                    'date' => date('Y-m-d', $current),
                    'type' => '续费',
                    'value' => (int) $periodOrders->renewal_count
                ];

                $current = $periodEnd;
                $iteration++;
            }

            if ($iteration >= $maxIterations) {
                \Log::warning('达到最大循环次数限制', [
                    'maxIterations' => $maxIterations,
                    'type' => $type
                ]);
            }

            return $chartData;

        } catch (\Exception $e) {
            \Log::error('groupDataByPeriod执行失败:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    private function getTimeRange($type, $startTime = null, $endTime = null)
    {
        // 如果传入了时间范围（时间戳格式），直接使用
        if ($startTime && $endTime) {
            \Log::info('使用传入的时间范围:', [
                'start' => date('Y-m-d H:i:s', $startTime),
                'end' => date('Y-m-d H:i:s', $endTime)
            ]);
            return [(int) $startTime, (int) $endTime];
        }

        // 如果未传入时间，默认为最近一周
        $now = time();
        $end = strtotime(date('Y-m-d 23:59:59', $now));

        switch ($type) {
            case 'day':
                $start = strtotime('today');
                break;
            case 'week':
                $start = strtotime('monday this week');
                break;
            case 'month':
                $start = strtotime(date('Y-m-01'));
                break;
            case 'quarter':
                $start = strtotime('-90 days', strtotime(date('Y-m-d')));
                break;
            case 'half_year':
                $start = strtotime('-180 days', strtotime(date('Y-m-d')));
                break;
            case 'year':
                $start = strtotime(date('Y-01-01'));
                break;
            default:
                $start = strtotime('-7 days', strtotime(date('Y-m-d')));
        }

        \Log::info('使用默认时间范围:', [
            'type' => $type,
            'start' => date('Y-m-d H:i:s', $start),
            'end' => date('Y-m-d H:i:s', $end)
        ]);

        return [$start, $end];
    }

    public function getColumnChart(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|in:day,week,month,quarter,year,half_year',
                'start_time' => 'required|integer|min:0',
                'end_time' => 'required|integer|min:0'
            ]);

            $startTime = (int) $request->input('start_time');
            $endTime = (int) $request->input('end_time');
            $type = $request->input('type');

            $processedData = null;

            switch ($type) {
                case 'day':
                    $dayData = DB::table('v2_order')
                        ->select(
                            DB::raw('DATE(FROM_UNIXTIME(created_at)) as date'),
                            'type',
                            DB::raw('COUNT(*) as count'),
                            DB::raw('SUM(total_amount) as amount')
                        )
                        ->where('created_at', '>=', $startTime)
                        ->where('created_at', '<=', $endTime)
                        ->where('status', 3)
                        ->groupBy('date', 'type')
                        ->orderBy('date')
                        ->get();

                    $processedData = $this->processChartData($dayData, function ($data) {
                        return date('m-d', strtotime($data->date));
                    });
                    break;

                case 'week':
                    // 周统计代码保持不变
                    $weekData = DB::table('v2_order')
                        ->select(
                            DB::raw('YEARWEEK(FROM_UNIXTIME(created_at), 1) as yearweek'),
                            DB::raw('YEAR(FROM_UNIXTIME(created_at)) as year'),
                            DB::raw('WEEK(FROM_UNIXTIME(created_at), 1) as week'),
                            'type',
                            DB::raw('SUM(total_amount) as amount'),
                            DB::raw('COUNT(*) as count')
                        )
                        ->where('created_at', '>=', $startTime)
                        ->where('created_at', '<=', $endTime)
                        ->where('status', 3)
                        ->groupBy(
                            DB::raw('YEARWEEK(FROM_UNIXTIME(created_at), 1)'),
                            DB::raw('YEAR(FROM_UNIXTIME(created_at))'),
                            DB::raw('WEEK(FROM_UNIXTIME(created_at), 1)'),
                            'type'
                        )
                        ->orderBy('yearweek')
                        ->get();

                    $processedData = $this->processChartData($weekData, function ($data) {
                        return $data->year . '年第' . sprintf("%02d", $data->week) . '周';
                    });
                    break;

                case 'month':
                    $monthData = DB::table('v2_order')
                        ->select(
                            DB::raw('YEAR(FROM_UNIXTIME(created_at)) as year'),
                            DB::raw('MONTH(FROM_UNIXTIME(created_at)) as month'),
                            'type',
                            DB::raw('COUNT(*) as count'),
                            DB::raw('SUM(total_amount) as amount')
                        )
                        ->where('created_at', '>=', $startTime)
                        ->where('created_at', '<=', $endTime)
                        ->where('status', 3)
                        ->groupBy(
                            DB::raw('YEAR(FROM_UNIXTIME(created_at))'),
                            DB::raw('MONTH(FROM_UNIXTIME(created_at))'),
                            'type'
                        )
                        ->orderBy(DB::raw('YEAR(FROM_UNIXTIME(created_at))'))
                        ->orderBy(DB::raw('MONTH(FROM_UNIXTIME(created_at))'))
                        ->get();

                    $processedData = $this->processChartData($monthData, function ($data) {
                        return $data->year . '年' . $data->month . '月';
                    });
                    break;

                case 'quarter':
                    $quarterData = DB::table('v2_order')
                        ->select(
                            DB::raw('YEAR(FROM_UNIXTIME(created_at)) as year'),
                            DB::raw('QUARTER(FROM_UNIXTIME(created_at)) as quarter'),
                            'type',
                            DB::raw('COUNT(*) as count'),
                            DB::raw('SUM(total_amount) as amount')
                        )
                        ->where('created_at', '>=', $startTime)
                        ->where('created_at', '<=', $endTime)
                        ->where('status', 3)
                        ->groupBy('year', 'quarter', 'type')
                        ->orderBy('year')
                        ->orderBy('quarter')
                        ->get();

                    $processedData = $this->processChartData($quarterData, function ($data) {
                        return $data->year . '年Q' . $data->quarter;
                    });
                    break;

                case 'half_year':
                    $halfYearData = DB::table('v2_order')
                        ->select(
                            DB::raw('YEAR(FROM_UNIXTIME(created_at)) as year'),
                            DB::raw('IF(MONTH(FROM_UNIXTIME(created_at)) <= 6, 1, 2) as half'),
                            'type',
                            DB::raw('COUNT(*) as count'),
                            DB::raw('SUM(total_amount) as amount')
                        )
                        ->where('created_at', '>=', $startTime)
                        ->where('created_at', '<=', $endTime)
                        ->where('status', 3)
                        ->groupBy('year', 'half', 'type')
                        ->orderBy('year')
                        ->orderBy('half')
                        ->get();

                    $processedData = $this->processChartData($halfYearData, function ($data) {
                        return $data->year . '年' . ($data->half == 1 ? '上' : '下') . '半年';
                    });
                    break;

                case 'year':
                    $yearData = DB::table('v2_order')
                        ->select(
                            DB::raw('YEAR(FROM_UNIXTIME(created_at)) as year'),
                            'type',
                            DB::raw('COUNT(*) as count'),
                            DB::raw('SUM(total_amount) as amount')
                        )
                        ->where('created_at', '>=', $startTime)
                        ->where('created_at', '<=', $endTime)
                        ->where('status', 3)
                        ->groupBy('year', 'type')
                        ->orderBy('year')
                        ->get();

                    $processedData = $this->processChartData($yearData, function ($data) {
                        return $data->year . '年';
                    });
                    break;
            }

            return [
                'data' => [
                    'chart_data' => $processedData['chart_data'],
                    'time_range' => [
                        'start_time' => date('Y-m-d', $startTime),
                        'end_time' => date('Y-m-d', $endTime)
                    ]
                ],
                'renewal_rate' => [
                    'chart_data' => $processedData['renewal_rate'],
                    'time_range' => [
                        'start_time' => date('Y-m-d', $startTime),
                        'end_time' => date('Y-m-d', $endTime)
                    ]
                ]
            ];

        } catch (\Exception $e) {
            \Log::error('获取柱状图数据失败:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    private function getPeriodName($period)
    {
        $periodMap = [
            'month_price' => '月',
            'quarter_price' => '季',
            'half_year_price' => '半年',
            'year_price' => '年'
        ];

        return $periodMap[$period] ?? '';
    }
    private function getTypesByPeriod($period)
    {
        switch ($period) {
            case 'week':
                return [
                    ['name' => '续费', 'type' => 2, 'period' => $period],
                    ['name' => '新购', 'type' => 1, 'period' => $period]
                ];
            case 'month':
                return [
                    ['name' => '月续费', 'type' => 2, 'period' => $period],
                    ['name' => '月新购', 'type' => 1, 'period' => $period]
                ];
            case 'quarter':
                return [
                    ['name' => '季度续费', 'type' => 2, 'period' => $period],
                    ['name' => '季度新购', 'type' => 1, 'period' => $period]
                ];
            // ...其他类型
            default:
                return [
                    ['name' => '续费', 'type' => 2, 'period' => $period],
                    ['name' => '新购', 'type' => 1, 'period' => $period]
                ];
        }
    }
    private function getIntervalByType($type)
    {
        switch ($type) {
            case 'day':
                return 86400;  // 1天
            case 'week':
                return 604800;  // 7天
            case 'month':
                return 2592000;  // 30天
            case 'quarter':
                return 7776000;  // 90天
            case 'half_year':
                return 15552000;  // 180天
            case 'year':
                return 31536000;  // 365天
            default:
                return 86400;
        }
    }
    private function processChartData($rawData, $dateFormatter)
    {
        $processedData = [];
        foreach ($rawData as $data) {
            $date = $dateFormatter($data);

            if (!isset($processedData[$date])) {
                $processedData[$date] = [
                    'new_order' => 0,
                    'new_amount' => 0,
                    'renew_order' => 0,
                    'renew_amount' => 0
                ];
            }

            if ($data->type == 1) { // 新购
                $processedData[$date]['new_order'] = $data->count;
                $processedData[$date]['new_amount'] = $data->amount;
            } else { // 续费
                $processedData[$date]['renew_order'] = $data->count;
                $processedData[$date]['renew_amount'] = $data->amount;
            }
        }

        // 转换为前端需要的格式
        $chartData = [];
        $renewalRate = [];
        foreach ($processedData as $date => $stats) {
            // 新购订单数据
            $chartData[] = [
                'type' => '新购',
                'date' => $date,
                'stack' => '订单',
                'value' => (int) $stats['new_order']
            ];

            // 新购金额数据
            $chartData[] = [
                'type' => '新购金额',
                'date' => $date,
                'stack' => '金额',
                'value' => round($stats['new_amount'] / 100, 2)
            ];

            // 续费订单数据
            $chartData[] = [
                'type' => '续费',
                'date' => $date,
                'stack' => '订单',
                'value' => (int) $stats['renew_order']
            ];

            // 续费金额数据
            $chartData[] = [
                'type' => '续费金额',
                'date' => $date,
                'stack' => '金额',
                'value' => round($stats['renew_amount'] / 100, 2)
            ];

            // 计算续费率
            $rate = 0;
            if ($stats['new_order'] > 0) {
                $rate = round(($stats['renew_order'] / $stats['new_order']) * 100, 2);
            }

            // 添加续费率数据
            $renewalRate[] = [
                'type' => '续费率',
                'date' => $date,
                'stack' => '比率',
                'value' => $rate
            ];
        }

        return [
            'chart_data' => $chartData,
            'renewal_rate' => $renewalRate
        ];
    }
}
