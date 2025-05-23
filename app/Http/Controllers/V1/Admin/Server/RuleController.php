<?php

namespace App\Http\Controllers\V1\Admin\Server;
use App\Http\Requests\Admin\PlanSort;
use App\Http\Requests\Admin\RuleSort;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Models\ServerRule;
class RuleController extends Controller
{

    public function fetch(Request $request)
    {
        $routes = ServerRule::orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')  // 第二排序依据
            ->get();
            
        return [
            'data' => $routes
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required',                   //规则名称
            'domain' => 'required',                 //替换域名             //端口
            'server_arr' => 'required',              //服务器分组ID，逗号分隔
            'ua'         =>'required' ,               //UA匹配信息
        ], [
            'name.required' => '规则名称不能为空',
            'domain.required' => '域名不能为空',
            'server_arr.required' => '多选服务器分组不能为空',
            'ua.required' => 'Ua信息不能为空'
        ]);

        try {
            // 添加时间戳
            $currentTime = time();
            
            // 更新操作
            if ($request->input('id')) {
                $route = ServerRule::find($request->input('id'));
                if (!$route) {
                    throw new \Exception('规则不存在');
                }
                $params['updated_at'] = $currentTime;
                $params['ua']= $params['ua'];
                $params['prot']= $params['prot']??null;
                $params['sort']= $params['sort']??0;
                $route->update($params);
                return [
                    'data' => true,
                    'message' => '更新成功'
                ];
            }

            // 创建操作
            $params['created_at'] = $currentTime;
            $params['updated_at'] = $currentTime;
            
            if (!ServerRule::create($params)) {
                throw new \Exception('创建失败');
            }

            return [
                'data' => true,
                'message' => '创建成功'
            ];

        } catch (\Exception $e) {
            \Log::error('保存服务器规则失败:', [
                'message' => $e->getMessage(),
                'params' => $params
            ]);
            
            return response([
                'data' => false,
                'message' => '操作失败：' . $e->getMessage()
            ], 500);
        }
    }

    public function drop(Request $request){

        $route = ServerRule::find($request->input('id'));
        if (!$route) abort(500, '规则不存在');
        if (!$route->delete()) abort(500, '删除失败');
        return [
            'data' => true
        ];
    }


    public function sort(RuleSort $request)
    {
        DB::beginTransaction();
        foreach ($request->input('ids') as $k => $v) {
            if (!ServerRule::find($v)->update(['sort' => $k + 1])) {
                DB::rollBack();
                abort(500, '保存失败');
            }
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }

}
