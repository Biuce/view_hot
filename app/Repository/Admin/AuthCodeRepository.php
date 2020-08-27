<?php
/**
 * Date: 2019/2/25 Time: 16:15
 *
 * @author  Eddy <cumtsjh@163.com>
 * @version v1.0.0
 */

namespace App\Repository\Admin;

use App\Model\Admin\AuthCode;
use App\Repository\Searchable;
use Illuminate\Support\Facades\DB;

class AuthCodeRepository
{
    use Searchable;

    public static function list($perPage, $condition = [])
    {
//        DB::connection()->enableQueryLog();#开启执行日志
        $data = AuthCode::query()->where(function ($query) use ($condition) {
            if (isset($condition['auth_code']) && $condition['auth_code'] != "") {
                $query->where('auth_code', 'like', "%{$condition['auth_code']}%")->orWhere('remark', 'like', "%{$condition['auth_code']}%");
            }
        })->where(function ($query) use ($condition) {
            unset($condition['auth_code']);
            Searchable::buildQuery($query, $condition);
        })
            ->orderBy('id', 'desc')
            ->paginate($perPage);
//        print_r(DB::getQueryLog());   //获取查询语句、参数和执行时间
        return $data;
    }

    public static function add($data)
    {
        return AuthCode::query()->create($data);
    }

    public static function update($id, $data)
    {
        return AuthCode::query()->where('id', $id)->update($data);
    }

    public static function updateByWhere($where, $data)
    {
        return AuthCode::query()->where($where)->update($data);
    }

    public static function find($id)
    {
        return AuthCode::query()->find($id);
    }

    public static function findByWhere($where)
    {
        return AuthCode::query()->where($where)->first();
    }

    public static function delete($id)
    {
        return AuthCode::destroy($id);
    }

    /**
     * @Title: incrementAuthCode
     * @Description: 定义自增字段
     * @param $where
     * @param $value
     * @return int
     * @Author: 李军伟
     */
    public static function incrementAuthCode($where, $value)
    {
        return AuthCode::query()->where($where)->increment($value);
    }

    /**
     * @Title: decrementAuthCode
     * @Description: 定义自减字段
     * @param $where
     * @param $value
     * @return int
     * @Author: 李军伟
     */
    public static function decrementAuthCode($where, $value)
    {
        return AuthCode::query()->where($where)->decrement($value);
    }

    // 获取授权码数量
    public static function lowerByCode($where, $month)
    {
        return AuthCode::query()->where($where)->whereMonth('created_at', $month)->count();
    }
}
