<?php
/**
 * Date: 2019/2/25 Time: 14:49
 *
 * @author  Eddy <cumtsjh@163.com>
 * @version v1.0.0
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserRequest;
use App\Http\Requests\Admin\LogoffUserRequest;
use App\Http\Requests\Admin\PasswordRequest;
use App\Model\Admin\AdminUser;
use App\Model\Admin\Level;
use App\Model\Admin\Assort;
use App\Model\Admin\Equipment;
use App\Model\Admin\Defined;
use App\Repository\Admin\AdminUserRepository;
use App\Repository\Admin\LevelRepository;
use App\Repository\Admin\LogoffUserRepository;
use App\Repository\Admin\HuobiRepository;
use App\Repository\Admin\AssortRepository;
use App\Repository\Admin\RoleRepository;
use App\Repository\Admin\EquipmentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Model\Admin\Huobi;
use App\Repository\APIHelper;
use App\Model\Admin\AdminUser as AdminUserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;

class AdminUserController extends Controller
{
    protected $formNames = ['id', 'name', 'password', 'status', 'level_id', 'account', 'photo', 'remark', 'balance', 'recharge', 'phone', 'email', 'channel_id', 'agency', 'own', 'choice', 'assort', 'price'];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @Title: index
     * @Description: 代理人列表
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function index(Request $request)
    {
        $perPage = (int)$request->get('limit', env('APP_PAGE'));
        $this->formNames[] = 'created_at';
        $keyword = $request->only($this->formNames);
        // 只显示没有注销的用户
        $param['is_cancel'] = ['=', 0];
        if (\Auth::guard('admin')->user()->name != 'admin') {
            $param['pid'] = ['=', \Auth::guard('admin')->user()->id];
        }
        $this->getLowerId(\Auth::guard('admin')->user()->id);

        $data = AdminUserRepository::list($perPage, $this->ids, $param, $keyword);
        $data->name = $request->name;

        return view('admin.adminUser.index', [
            'lists' => $data,  //列表数据
            'condition' => $keyword,
        ]);
    }

    /**
     * @Title: all
     * @Description: 全部代理人
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function all(Request $request)
    {
        $perPage = (int)$request->get('limit', env('APP_PAGE'));
        $this->formNames[] = 'created_at';
        $keyword = $request->only($this->formNames);
        // 只显示没有注销的用户
        $param['is_cancel'] = ['=', 0];
        if (\Auth::guard('admin')->user()->name != 'admin') {
            $param['pid'] = ['=', \Auth::guard('admin')->user()->id];
        }
        $this->getLowerIds(\Auth::guard('admin')->user()->id);
        $data = AdminUserRepository::list($perPage, $this->ids_list, $param, $keyword);
        $data->name = $request->name;

        return view('admin.adminUser.all', [
            'lists' => $data,  //列表数据
            'condition' => $keyword,
        ]);
    }

    /**
     * @Title: logoff
     * @Description: 注销代理人管理-注销代理人员列表
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function logoff(Request $request)
    {
        $perPage = (int)$request->get('limit', env('APP_PAGE'));
        $this->formNames[] = 'created_at';
        $condition = $request->only($this->formNames);
        // 只显示注销的用户
        if (\Auth::guard('admin')->user()->name != 'admin') {
            $condition['pid'] = ['=', \Auth::guard('admin')->user()->id];
        }
        $this->getLowerId(\Auth::guard('admin')->user()->id);
        $data = AdminUserRepository::logoff($perPage, $this->ids);
        $data->name = $request->name;

        return view('admin.adminUser.logoff', [
            'lists' => $data,  //列表数据
            'condition' => $condition,
        ]);
    }

    /**
     * 管理员管理-新增管理员
     *
     */
    public function create()
    {
        // 获取级别信息
        $id = \Auth::guard('admin')->user()->id;
        if ($id == 1) {
            $where[] = ['id', '=', 3];
        } elseif (\Auth::guard('admin')->user()->level_id == 5) {
            $where[] = ['id', '>=', \Auth::guard('admin')->user()->level_id];
        } elseif (\Auth::guard('admin')->user()->level_id == 8) {
            $where[] = ['id', '=', 8];
        } else {
            $where[] = ['id', '>', \Auth::guard('admin')->user()->level_id];
        }
        $level = Level::query()->select(['id', 'level_name', 'mini_amount'])->where($where)->get();

        // 展示渠道列表
        $apiStr = 'channels';
        $api = new APIHelper();
        $res = $api->get($apiStr);
        $data = json_decode($res, true);

        return view('admin.adminUser.add', [
            'level' => $level,
            'channels' => $data['data'],
            'list' => $this->list
        ]);
    }

    /**
     * @Title: save
     * @Description: 管理员管理-保存管理员
     * @param AdminUserRequest $request
     * @return array
     * @Author: 李军伟
     */
    public function save(AdminUserRequest $request)
    {
        DB::beginTransaction(); //开启事务
        try {
            // 如果不是ajax方式，则非法请求
            $this->isAjax($request);
//            $this->formNames[] = 'created_at';
            $parameter = $request->only($this->formNames);
            dd($parameter);
            $parent_id = $this->getParentId(\Auth::guard('admin')->user()->id);
            if (strstr($parameter['balance'], ",")) {
                $balance = str_replace(",", "", $parameter['balance']);
                $parameter['balance'] = str_replace(",", "", $parameter['balance']);
            } else {
                $balance = $parameter['balance'];
            }
            // 获取提交级别所需最低金额
            $mini = LevelRepository::find($parameter['level_id']);
            if ($balance < $mini->mini_amount) {
                // 如果提交金额低于该等级最低金额，则提示
                return [
                    'code' => 1,
                    'msg' => trans('adminUser.recharge_tips1'),
                    'redirect' => false
                ];
            } elseif ($balance > \Auth::guard('admin')->user()->balance) {
                // 如果提交金额大于自己所拥有的金额，则提示
                return [
                    'code' => 1,
                    'msg' => trans('adminUser.recharge_tips'),
                    'redirect' => false
                ];
            }
            // 如果当前登录用户是超级管理员，则渠道id必填
            if (\Auth::guard('admin')->user()->id == 1) {
                if (isset($parameter['channel_id']) && $parameter['channel_id'] <= 0) {
                    return [
                        'code' => 1,
                        'msg' => trans('adminUser.channel_require'),
                        'redirect' => false
                    ];
                }
            }
            $i = 0;
            // 如果当前登录用户是自定义用户，则验证数据的正确性
            if ($parameter['level_id'] == 8) {
                // 先验证数据的完整性
                if ($parameter['agency'] == "") {
                    return [
                        'code' => 1,
                        'msg' => trans('adminUser.define_empty'),
                        'redirect' => false
                    ];
                }

                $agency = $parameter['agency'];
                $choice = $parameter['choice'];
                $assort = $parameter['assort'];
                $own = $parameter['own'];
                if (count($agency) < 4) {
                    return [
                        'code' => 1,
                        'msg' => trans('adminUser.define_set'),
                        'redirect' => false
                    ];
                }
                $special = [];
                foreach ($agency as $key => $v) {
                    // 验证代理金额是否是数字
                    if (!is_numeric($v)) {
                        return [
                            'code' => 1,
                            'msg' => trans('general.not_cost'),
                            'redirect' => false
                        ];
                    }

                    // 验证零售价减去当前登录用户的价格差额是否小于2。如果是，则添加的新代理人的金额为零售价减1
                    if (($this->list[$key] - $own[$key]) < 2) {
                        $i++;
                        $special[$key] = ($this->list[$key] - 1);
                    } else {
                        // 自定义的金额和最低限度金额进行比较
                        if ($v < $choice[$key]) {
                            return [
                                'code' => 1,
                                'msg' => trans('adminUser.define_cost'),
                                'redirect' => false
                            ];
                        }

                        // 代理成本大于或等于零售价
                        if ($v >= $this->list[$key]) {
                            return [
                                'code' => 1,
                                'msg' => trans('equipment.gltPrice'),
                                'redirect' => false
                            ];
                        } elseif (bcsub($this->list[$key], $v, 2) < 1) {
                            // 代理成本与零售价的差额不能低于1
                            return [
                                'code' => 1,
                                'msg' => trans('equipment.gltPrice'),
                                'redirect' => false
                            ];
                        }
                        // 自定义的金额和和自己的差值进行比较（不能低于1）
                        if (bcsub($v, $own[$key], 2) < 1) {
                            return [
                                'code' => 1,
                                'msg' => trans('equipment.gltZero'),
                                'redirect' => false
                            ];
                        }
                        $special[$key] = $v;
                    }
                }
            }
            // 如果i等于4说明所有配套都已经封顶，不能再创建代理人了
            if ($i == 4) {
                return [
                    'code' => 1,
                    'msg' => trans('equipment.gltLower'),
                    'redirect' => false
                ];
            }
            $parameter['recharge'] = $balance;
            $parameter['pid'] = \Auth::guard('admin')->user()->id;
            if (\Auth::guard('admin')->user()->id != 1) {
                $parameter['channel_id'] = \Auth::guard('admin')->user()->channel_id;
            }
            if ($balance > \Auth::guard('admin')->user()->balance) {
                return [
                    'code' => 1,
                    'msg' => trans('adminUser.recharge_tips'),
                    'redirect' => false
                ];
            }
            $parameter['account'] = getRandChar(6);
            $parameter['password'] = getRandChar(8);
            // 如果当前登录用户是金级用户，新增用户也为金级用户，则当前登录用户增加人员数量+1
            if ($parameter['level_id'] == 5 && \Auth::guard('admin')->user()->level_id == 5) {
                $person_where = ['id' => \Auth::guard('admin')->user()->id];
                AdminUserRepository::personIncr($person_where);
            }
            // 添加一个不加密的代理人
            $user = AdminUserRepository::addByPass($parameter);
            // 减去上级相应的金额
            $where_user = ['id' => \Auth::guard('admin')->user()->id];
            AdminUserRepository::decr($where_user, $balance);
            AdminUserRepository::setDefaultPermission($user);
            // 把记录添加到火币记录明细表里面
            $huo_list = [
                [
                    'user_id' => \Auth::guard('admin')->user()->id,
                    'money' => $balance,
                    'status' => 1,
                    'type' => 2,
                    'event' => trans('adminUser.by') . $user->account . trans('adminUser.lower'),
                    'own_id' => $user->id,
                    'created_at' => date("Y-m-d H:i:s", time()),
                    'updated_at' => date("Y-m-d H:i:s", time()),
                ],
                [
                    'user_id' => $user->id,
                    'money' => $balance,
                    'status' => 1,
                    'type' => 1,
                    'event' => trans('adminUser.myself'),
                    'own_id' => 0,
                    'created_at' => date("Y-m-d H:i:s", time()),
                    'updated_at' => date("Y-m-d H:i:s", time()),
                ]
            ];
            Huobi::query()->insert($huo_list);
            if ($parameter['level_id'] == 8) {
                $define = [];
                // 把自定义的配置级别添加到表里面去
                foreach ($assort as $k => $item) {
                    $ass_where = ['assort_name' => $item];
                    $ass_info = AssortRepository::findByWhere($ass_where);
                    $defined = [
                        'user_id' => $user->id,
                        'assort_id' => $ass_info->id,
                        'money' => empty($special) ? $agency[$k] : $special[$k],
                        'generation_id' => $parent_id,
                        'created_at' => date("Y-m-d H:i:s", time()),
                        'updated_at' => date("Y-m-d H:i:s", time()),
                    ];
                    $define[] = $defined;
                }
                Defined::query()->insert($define);
            }

            DB::commit();  //提交
            return [
                'code' => 0,
                'msg' => trans('general.createSuccess'),
                'redirect' => true,
                'id' => $user->id
            ];
        } catch (QueryException $e) {
            DB::rollback();  //回滚
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: edit
     * @Description: 管理员管理-编辑管理员
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function edit($id)
    {
        $user = AdminUserRepository::find($id);
        return view('admin.adminUser.add', ['id' => $id, 'user' => $user]);
    }

    /**
     * 管理员管理-更新管理员
     *
     * @param Request $request
     * @param int $id
     * @return array
     */
    public function update(Request $request, $id)
    {
        // 如果不是ajax方式，则非法请求
        $this->isAjax($request);
        $data = $request->only($this->formNames);
        if ($request->input('password') == '') {
            unset($data['password']);
        }
        // 金额如果为空则报错
        if ($data['balance'] == "") {
            return [
                'code' => 1,
                'msg' => trans('adminUser.amount_require'),
                'redirect' => false
            ];
        }
        // 替换掉千分位分隔符
        if (strstr($data['balance'], ",")) {
            $data['balance'] = str_replace(",", "", $data['balance']);
        }

        DB::beginTransaction(); //开启事务
        try {
            // 当前用户的级别必须存在
            if ($data['level_id'] == 0) {
                throw new \Exception(trans('adminUser.select_level'));
            }
            // 如果级别调整的金额大于当前用户所拥有的金额，则报错
            if ($data['balance'] > \Auth::guard('admin')->user()->balance) {
                throw new \Exception(trans('adminUser.recharge_tips'));
            }
            $level = LevelRepository::find($data['level_id']);
            // 如果所填金额小于该级别的最低金额则报错
            if ($data['balance'] < $level['mini_amount']) {
                throw new \Exception(trans('adminUser.recharge_tips1'));
            }
            // 减去上级相应的金额
            $where_user = ['id' => \Auth::guard('admin')->user()->id];
            AdminUserRepository::decr($where_user, $data['balance']);
            // 获取当前用户信息
            $info = AdminUserRepository::find($id);
            $balance = $data['balance'];
            $data['recharge'] = $info->recharge + $data['balance'];
            $data['balance'] = $info->balance + $data['balance'];
            AdminUserRepository::update($id, $data);
            // 把记录添加到火币记录明细表里面
            $huo_list = [
                [
                    'user_id' => $info->id,
                    'money' => $balance,
                    'status' => 1,
                    'type' => 1,
                    'event' => trans('adminUser.myself'),
                    'own_id' => 0,
                    'created_at' => date("Y-m-d H:i:s", time()),
                    'updated_at' => date("Y-m-d H:i:s", time()),
                ],
                [
                    'user_id' => \Auth::guard('admin')->user()->id,
                    'money' => $balance,
                    'status' => 1,
                    'type' => 2,
                    'event' => $info->account . trans('adminUser.lower'),
                    'own_id' => $info->id,
                    'created_at' => date("Y-m-d H:i:s", time()),
                    'updated_at' => date("Y-m-d H:i:s", time()),
                ]
            ];
            Huobi::query()->insert($huo_list);
            DB::commit();  //提交
            return [
                'code' => 0,
                'msg' => trans('general.updateSuccess'),
                'redirect' => true
            ];
        } catch (\Exception $e) {
            DB::rollback();  //回滚
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * 管理员管理-分配角色
     *
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function role($id)
    {
        $roles = RoleRepository::all();
        $userRoles = AdminUserRepository::find($id)->getRoleNames();
        return view('admin.adminUser.role', [
            'id' => $id,
            'roles' => $roles,
            'userRoles' => $userRoles,
        ]);
    }

    /**
     * 管理员管理-更新用户角色
     *
     * @param Request $request
     * @param $id
     * @return array
     */
    public function updateRole(Request $request, $id)
    {
        try {
            $user = AdminUserRepository::find($id);
            $user->syncRoles(array_values($request->input('role')));
            return [
                'code' => 0,
                'msg' => trans('general.updateSuccess'),
                'redirect' => true
            ];
        } catch (\Throwable $e) {
            return [
                'code' => 1,
                'msg' => trans('general.updateFailed'),
            ];
        }
    }

    /**
     * 管理员管理-删除管理员
     *
     * @param int $id
     * @return array
     */
    public function delete($id)
    {
        try {
//            $user = AdminUserRepository::find($id);
//            $userRoles = AdminUserRepository::roles($user);
//            $user->removeRole($userRoles);
            AdminUserRepository::delete($id);
            return [
                'code' => 0,
                'msg' => trans('general.deleteSuccess'),
                'redirect' => true
            ];
        } catch (\RuntimeException $e) {
            return [
                'code' => 1,
                'msg' => trans('general.deleteFailed') . ":" . $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: info
     * @Description: 根据选择的级别显示相应的金额
     * @param Request $request
     * @return array
     * @Author: 李军伟
     */
    public function info(Request $request)
    {
        $level_id = (int)$request->get('level_id');
        if ($level_id > 3) {
            $result = $this->agencyInfo($level_id);

            return view('admin.adminUser.ajax_info', [
                'lists' => $result['lists'],
                'level_id' => $result['level_id'],
                'prices' => $result['prices'],
            ]);
        } else {
            $result = $this->countryInfo($level_id);

            return view('admin.adminUser.ajax_country_info', [
                'lists' => $result['assort'],
                'level_id' => $result['level_id'],
                'prices' => $result['prices'],
            ]);
        }
    }

    /**
     * @Title: countryInfo
     * @Description: 国级代理人信息
     * @param $level_id
     * @return array
     * @Author: 李军伟
     */
    public function countryInfo($level_id)
    {
        // 获取配置列表
        $assort = Assort::query()->pluck('assort_name');

        return $list = [
            'assort' => $assort,
            'level_id' => $level_id,
            'prices' => $this->list,
        ];
    }

    /**
     * @Title: agencyInfo
     * @Description: 国级以外代理人信息
     * @param $level_id
     * @return array
     * @Author: 李军伟
     */
    public function agencyInfo($level_id)
    {
        $parent_id = $this->getParentId(\Auth::guard('admin')->user()->id);
        // 先验证该国代有没有自定义级别配置管理,如果没有则获取默认级别配置
        $guodai_where = ['user_id' => $parent_id];
        $res = EquipmentRepository::findByWhere($guodai_where);
        if ($res) {
            // 获取选择的级别对应配置的金额
            $choice_money = Equipment::query()->where(['level_id' => $level_id, 'user_id' => $parent_id])->pluck('money');
            // 获取自己的级别对应配置的金额
            if (\Auth::guard('admin')->user()->level_id == 8) {
                $own_money = Defined::query()->where(['user_id' => \Auth::guard('admin')->user()->id])->pluck('money');
            } else {
                $own_money = Equipment::query()->where(['level_id' => \Auth::guard('admin')->user()->level_id, 'user_id' => $parent_id])->pluck('money');
            }
        } else {
            // 获取选择的级别对应配置的金额
            $choice_money = Equipment::query()->where(['level_id' => $level_id, 'user_id' => 1])->pluck('money');
            // 获取自己的级别对应配置的金额
            if (\Auth::guard('admin')->user()->level_id == 8) {
                $own_money = Defined::query()->where(['user_id' => \Auth::guard('admin')->user()->id])->pluck('money');
            } else {
                $own_money = Equipment::query()->where(['level_id' => \Auth::guard('admin')->user()->level_id, 'user_id' => 1])->pluck('money');
            }
        }
        // 获取配置列表
        $assort = Assort::query()->pluck('assort_name');
        $data = $result = [];
        if (count($own_money->toArray()) == count($choice_money->toArray())) {
            for ($i = 0; $i < count($own_money->toArray()); $i++) {
                $result[] = $choice_money->toArray()[$i] - $own_money->toArray()[$i];
            }
        }

        foreach ($assort as $key => $item) {
            $data[$key]['assort'] = $assort->toArray();
            $data[$key]['own'] = $own_money->toArray();
            $data[$key]['choice'] = $choice_money->toArray();
            if ($level_id == 8) {
                $data[$key]['diff'] = 0;
            } else {
                $data[$key]['diff'] = $result;
            }
        }

        return $list = [
            'lists' => $data,
            'level_id' => $level_id,
            'prices' => $this->list,
        ];
//        return view('admin.adminUser.ajax_info', [
//            'lists' => $data,
//            'level_id' => $level_id,
//            'prices' => $this->list,
//        ]);
    }

    /**
     * @Title: check
     * @Description: 查看代理人信息
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function check(Request $request, $id)
    {
//        $params = $request->input();
        $perPage = (int)$request->get('limit', env('APP_PAGE'));
        // 获取当前代理人信息
        $info = AdminUserRepository::find($id);
        if (($info->level_id - \Auth::guard('admin')->user()->level_id) > 1) {
            if ($info->level_id == 5 && $info->person_num < 10) {
                $type = 2;  // 调整级别不可编辑
            } else {
                $type = 1;  // 调整级别可编辑
            }
        } else {
            if ($info->level_id == 6) {
                $type = 1;  // 调整级别可编辑
            } else {
                $type = 2;  // 调整级别不可编辑
            }
        }
        // 获取当前代理人利润记录
        $where = ["create_id" => $info->id, 'status' => 0, 'type' => 1, 'user_id' => \Auth::guard('admin')->user()->id];
        $profit = HuobiRepository::levelByRecord($where);
        // 当前代理人为自己创造的收益
        $condition_profit = ['create_id' => $info->id, 'type' => 1, 'status' => 0, 'user_id' => \Auth::guard('admin')->user()->id];

        $user_profit = HuobiRepository::lists($perPage, $condition_profit);
        // 给当前代理人充值记录
        $condition_recharge = ['user_id' => $id, 'type' => 1, 'status' => 1];
        $user_recharge = HuobiRepository::lists_two($perPage, $condition_recharge);

        return view('admin.adminUser.check', [
            'type' => $type,
            'info' => $info,
            'profit' => $profit,
            'user_profit' => $user_profit,
            'user_recharge' => $user_recharge,
            'tags' => isset($params['profit']) ? $params['profit'] : 0,
        ]);
    }

    /**
     * @Title: look
     * @Description: 查看代理人信息(管理员查看)
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function look(Request $request, $id)
    {
        // 获取当前代理人信息
        $info = AdminUserRepository::find($id);

        return view('admin.adminUser.look', [
            'info' => $info,
        ]);
    }

    /**
     * @Title: recharge
     * @Description: 给代理人充值
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function recharge($id)
    {
        // 获取用户信息
        $info = AdminUserRepository::find($id);
        return view('admin.adminUser.recharge', [
            'info' => $info,
        ]);
    }

    /**
     * @Title: pay
     * @Description: 代理人充值提交
     * @param Request $request
     * @return array
     * @Author: 李军伟
     */
    public function pay(Request $request)
    {
        DB::beginTransaction(); //开启事务
        try {
            // 如果不是ajax方式，则非法请求
            $this->isAjax($request);
            $data = $request->only($this->formNames);
            if (strstr($data['balance'], ",")) {
                $data['balance'] = str_replace(",", "", $data['balance']);
            }
            // 验证充值金额是否大于当前自己所拥有的金额
            if ($data['balance'] > \Auth::guard('admin')->user()->balance) {
                throw new \Exception(trans('adminUser.recharge_tips'));
            }
            // 减去上级相应的金额
            $where_user = ['id' => \Auth::guard('admin')->user()->id];
            AdminUserRepository::decr($where_user, $data['balance']);
            // 获取当前用户信息
            $info = AdminUserRepository::find($data['id']);
            $balance = $data['balance'];
            $data['recharge'] = $info->recharge + $data['balance'];
            $data['balance'] = $info->balance + $data['balance'];
            AdminUserRepository::update($data['id'], $data);
            // 把记录添加到火币记录明细表里面
            $huo_list = [
                [
                    'user_id' => $info->id,
                    'money' => $balance,
                    'status' => 1,
                    'type' => 1,
                    'event' => trans('adminUser.myself'),
                    'own_id' => $info->id,
                    'created_at' => date("Y-m-d H:i:s", time()),
                    'updated_at' => date("Y-m-d H:i:s", time()),
                ],
                [
                    'user_id' => \Auth::guard('admin')->user()->id,
                    'money' => $balance,
                    'status' => 1,
                    'type' => 2,
                    'event' => trans('adminUser.by') . $info->name . trans('adminUser.lower'),
                    'own_id' => $info->id,
                    'created_at' => date("Y-m-d H:i:s", time()),
                    'updated_at' => date("Y-m-d H:i:s", time()),
                ]
            ];
            Huobi::query()->insert($huo_list);
            DB::commit();  //提交
            return [
                'code' => 0,
                'msg' => trans('general.updateSuccess'),
                'redirect' => true
            ];
        } catch (\Exception $e) {
            DB::rollback();  //回滚
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: level
     * @Description: 调整用户级别
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function level($id)
    {
        // 获取用户信息
        $info = AdminUserRepository::find($id);
        // 获取级别信息
        $level_id = $info->level_id;
        // 如果级别为5（金牌代理）则可以给自己的下级代理升级为和自己同级
        if (\Auth::guard('admin')->user()->level_id == 5) {
            $level = Level::query()
                ->select(['id', 'level_name', 'mini_amount'])
                ->where('id', '<', $level_id)
                ->where('id', '>=', \Auth::guard('admin')->user()->level_id)
                ->get();
            // 当前登录用户增加人员数量+1
            $person_where = ['id' => \Auth::guard('admin')->user()->id];
            AdminUserRepository::personIncr($person_where);
        } else {
            $level = Level::query()
                ->select(['id', 'level_name', 'mini_amount'])
                ->where('id', '<', $level_id)
                ->where('id', '>', \Auth::guard('admin')->user()->level_id)
                ->get();
        }

        return view('admin.adminUser.level', [
            'info' => $info,
            'level' => $level,
        ]);
    }

    /**
     * @Title: userInfo
     * @Description: 登录后台用户信息
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function userInfo()
    {
        $id = \Auth::guard('admin')->user()->id;
        $info = AdminUserRepository::find($id);
        return view('admin.adminUser.userInfo', [
            'info' => $info,
        ]);
    }

    /**
     * @Title: userEdit
     * @Description: 用户信息编辑
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function userEdit()
    {
        $id = \Auth::guard('admin')->user()->id;
        $info = AdminUserRepository::find($id);
        return view('admin.adminUser.userEdit', [
            'info' => $info,
        ]);
    }

    /**'
     * @Title: userUpdate
     * @Description: 用户信息编辑提交
     * @param Request $request
     * @return array
     * @Author: 李军伟
     */
    public function userUpdate(Request $request)
    {
        try {
            $data = $request->only($this->formNames);
            unset($data['level_id']);

            AdminUserRepository::update(\Auth::guard('admin')->user()->id, $data);
            return [
                'code' => 0,
                'msg' => trans('general.updateSuccess'),
                'redirect' => true
            ];
        } catch (\Exception $e) {
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: changePwd
     * @Description: 修改密码
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function changePwd()
    {
        return view('admin.adminUser.changePwd');
    }

    /**
     * @Title: savePwd
     * @Description: 修改密码提交
     * @param PasswordRequest $request
     * @return array
     * @Author: 李军伟
     */
    public function savePwd(PasswordRequest $request)
    {
        try {
            $data = $request->input();
            //验证原密码
            $old_password = $data['old_password'];
            if (!Hash::check($old_password, \Auth::guard('admin')->user()->password)) {
                return [
                    'code' => 1,
                    'msg' => trans('adminUser.old_password_fail'),
                    'redirect' => false
                ];
            }
            // 验证新密码和确认密码是否一致
            if ($data['password'] != $data['password_confirmation']) {
                return [
                    'code' => 1,
                    'msg' => trans('adminUser.password_disagree'),
                    'redirect' => false
                ];
            }
            $param['password'] = $data['password'];
            AdminUserRepository::update(\Auth::guard('admin')->user()->id, $param);
            (new LoginController())->logout($request);
            return [
                'code' => 0,
                'msg' => trans('general.updateSuccess'),
                'redirect' => true
            ];
        } catch (\Exception $e) {
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: cancel
     * @Description: 注销账号提示
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function cancel()
    {
        return view('admin.adminUser.cancel');
    }

    public function code()
    {
        // 发送验证码
        $code = getRandChar(6);
        $email = \Auth::guard('admin')->user()->email;
        // 把code放入到redis中，保存10分钟
        if (!Redis::get($email)) {
            $to = $email;
            $name = trans('general.email_tips') . $code;
            $subject = trans('general.cancel_account');
//            $send = $this->send($name, $to, $subject);
            $send = send_email($to, $subject, $name);
            // 如果send为null，则说明发送成功，进行缓存10分钟
            if ($send['status'] == 1) {
                Redis::setex($email, 600, $code);
            }
        }

        return view('admin.adminUser.code');
    }

    public function checkEmail(Request $request)
    {
        try {
            $code = $request->input('code');
            $email = \Auth::guard('admin')->user()->email;
            $code_1 = Redis::get($email);
            if (!$code_1 || strtoupper($code) != $code_1) {
                throw new \Exception(trans('home.code_exp'));
            }
            return [
                'code' => 0,
                'msg' => trans('home.sub_success'),
                'redirect' => true,
            ];
        } catch (\Exception $e) {
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: reCancel
     * @Description: 注销账号
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function reCancel()
    {
        $money = \Auth::guard('admin')->user()->balance;
        return view('admin.adminUser.reCancel', ['money' => $money]);
    }

    /**
     * @Title: saveCancel
     * @Description: 注销账号提交
     * @param LogoffUserRequest $request
     * @return array
     * @Author: 李军伟
     */
    public function saveCancel(LogoffUserRequest $request)
    {
        try {
            $parameter = $request->input();
            $parameter['user_id'] = \Auth::guard('admin')->user()->id;
            LogoffUserRepository::add($parameter);
            $data = ['is_cancel' => 1, 'is_relation' => 1];
            AdminUserRepository::update(\Auth::guard('admin')->user()->id, $data);
            (new Auth\LoginController())->guard()->logout();
            $request->session()->invalidate();
            return [
                'code' => 0,
                'msg' => trans('adminUser.cancel_success'),
                'redirect' => true
            ];
        } catch (\Exception $e) {
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: material
     * @Description: 图片上传
     * @param Request $request
     * @return array
     * @Author: 李军伟
     */
    public function material(Request $request)
    {
        $file = $request->file('file');
        // 此时 $this->upload如果成功就返回文件名不成功返回false
//        $data = getimagesize($file);
//        $width = $data[0];
//        $height = $data[1];
//        if ($width != $height) {
//            return [
//                'code' => 1,
//                'msg' => trans('adminUser.inconformity'),
//                'redirect' => false
//            ];
//        }
        $fileName = $this->upload($file);
        if ($fileName) {
            return json_encode([
                'code' => 0,
                'msg' => trans('general.upload_success'),
                'redirect' => true,
                'path' => $fileName
            ]);
        }
        return [
            'code' => 1,
            'msg' => trans('general.upload_fail'),
            'redirect' => false
        ];
    }

    /**
     * @Title: upload
     * @Description: 验证文件是否合法
     * @param $file
     * @param string $disk
     * @return bool
     * @Author: 李军伟
     */
    public function upload($file, $disk = 'public')
    {
        // 1.是否上传成功
        if (!$file->isValid()) {
            return false;
        }

        // 2.是否符合文件类型 getClientOriginalExtension 获得文件后缀名
        $fileExtension = $file->getClientOriginalExtension();
        if (!in_array(strtolower($fileExtension), ['png', 'jpg', 'gif', 'jpeg'])) {
            return json_encode(['error' => 'You may only upload png, jpg or gif or jpeg or bmp.']);
//            return false;
        }

        // 3.判断大小是否符合 8M
        $tmpFile = $file->getRealPath();
        if (filesize($tmpFile) >= 8182000) {
            return false;
        }

        // 4.是否是通过http请求表单提交的文件
        if (!is_uploaded_file($tmpFile)) {
            return false;
        }

        // 5.每天一个文件夹,分开存储, 生成一个随机文件名
        $fileName = date('Y_m_d') . '/' . md5(time()) . mt_rand(0, 9999) . '.' . $fileExtension;
        if (Storage::disk($disk)->put($fileName, file_get_contents($tmpFile))) {
            return Storage::url($fileName);
        }
    }

    /**
     * @Title: remark
     * @Description: 首页更新备注
     * @param Request $request
     * @return array
     * @Author: 李军伟
     */
    public function remark(Request $request, $id)
    {
        $data = $request->only($this->formNames);
        try {
            if (mb_strlen($data['remark']) > 128) {
                return [
                    'code' => 1,
                    'msg' => trans('general.max_length'),
                    'redirect' => false
                ];
            }
            $where = ['id' => $id];
            $params = ['remark' => $data['remark']];
            AdminUserRepository::updateByWhere($where, $params);
            return [
                'code' => 0,
                'msg' => trans('general.updateSuccess'),
                'redirect' => true
            ];
        } catch (QueryException $e) {
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: detail
     * @param Request $request
     * @Description: 创建新用户信息
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @Author: 李军伟
     */
    public function detail(Request $request)
    {
        $id = $request->input('id');
        $info = AdminUserRepository::find($id);
        return view('admin.adminUser.detail', [
            'info' => $info,
        ]);
    }

    public function cost($id)
    {
        $parent_id = $this->getParentId(\Auth::guard('admin')->user()->id);
        $guodai_where = ['user_id' => $parent_id];
        $res = EquipmentRepository::findByWhere($guodai_where);
        // 获取选择的级别对应配置的金额
        // 国代有配置
        if ($res) {
            $choice_where = ['level_id' => 8, 'user_id' => $parent_id];
            if (\Auth::guard('admin')->user()->level_id == 8) {
                $own_money = Defined::query()->where(['user_id' => \Auth::guard('admin')->user()->id])->pluck('money');
            } else {
                $own_money = Equipment::query()->where(['level_id' => \Auth::guard('admin')->user()->level_id, 'user_id' => $parent_id])->pluck('money');
            }
        } else {
            $choice_where = ['level_id' => 8, 'user_id' => 1];
            // 国代无配置
            // 获取自己的级别对应配置的金额
            if (\Auth::guard('admin')->user()->level_id == 8) {
                $own_money = Defined::query()->where(['user_id' => \Auth::guard('admin')->user()->id])->pluck('money');
            } else {
                $own_money = Equipment::query()->where(['level_id' => \Auth::guard('admin')->user()->level_id, 'user_id' => 1])->pluck('money');
            }
        }
        $choice_money = Equipment::query()->where($choice_where)->pluck('money');
        // 获取自己下级的级别对应配置的金额
        $agency_money = Defined::query()->where(['user_id' => $id])->pluck('money');
        // 获取配置列表
        $assort = Assort::query()->pluck('assort_name');
        $data = $result = [];

        if (count($own_money->toArray()) == count($choice_money->toArray())) {
            for ($i = 0; $i < count($own_money->toArray()); $i++) {
                $result[] = $agency_money->toArray()[$i] - $own_money->toArray()[$i];
            }
        }
        foreach ($assort as $key => $item) {
            $data[$key]['cost'] = $this->list;
            $data[$key]['assort'] = $assort->toArray();
            $data[$key]['own'] = $own_money->toArray();
            $data[$key]['choice'] = $choice_money->toArray();
            $data[$key]['agency'] = $agency_money->toArray();
            $data[$key]['diff'] = $result;
        }

        // 获取代理人信息
        $info = AdminUserRepository::find($id);
        return view('admin.adminUser.cost', [
            'id' => $id,
            'info' => $info,
            'lists' => $data,
            'prices' => $this->list,
        ]);
    }

    /**
     * @Title: adjust
     * @Description: 调整成本（自定义代理人）
     * @param Request $request
     * @return array
     * @Author: 李军伟
     */
    public function adjust(Request $request)
    {
        try {
            // 如果不是ajax方式，则非法请求
            $this->isAjax($request);
            $parameter = $request->only($this->formNames);
            $parent_id = $this->getParentId(\Auth::guard('admin')->user()->id);
            // 先验证数据的完整性
            if ($parameter['agency'] == "") {
                return [
                    'code' => 1,
                    'msg' => trans('adminUser.define_empty'),
                    'redirect' => false
                ];
            }

            $agency = $parameter['agency'];
            $choice = $parameter['choice'];
            $assort = $parameter['assort'];
            $own = $parameter['own'];
            if (count($agency) < 4) {
                return [
                    'code' => 1,
                    'msg' => trans('adminUser.define_set'),
                    'redirect' => false
                ];
            }
            // 调整的金额是否大于或者等于要调整代理用户下级金额
            // 1、获取要调整用户的下级
            $user_where = ["pid" => $parameter['id']];
            $ids = AdminUserRepository::getIds($user_where);
            foreach ($agency as $key => $v) {
                if (!is_numeric($v)) {
                    return [
                        'code' => 1,
                        'msg' => trans('general.not_cost'),
                        'redirect' => false
                    ];
                }
                // 自定义的金额和最低限度金额进行比较
                if ($v < $choice[$key]) {
                    return [
                        'code' => 1,
                        'msg' => trans('adminUser.define_cost'),
                        'redirect' => false
                    ];
                }
                // 代理成本大于或等于零售价
                if ($v >= $this->list[$key]) {
                    return [
                        'code' => 1,
                        'msg' => trans('equipment.gltPrice'),
                        'redirect' => false
                    ];
                } elseif (bcsub($this->list[$key], $v, 2) < 1) {
                    // 代理成本与零售价的差额不能低于1
                    return [
                        'code' => 1,
                        'msg' => trans('equipment.gltPrice'),
                        'redirect' => false
                    ];
                }
                // 自定义的金额和和自己的差值进行比较（不能低于1）
                if (bcsub($v, $own[$key], 2) < 1) {
                    return [
                        'code' => 1,
                        'msg' => trans('equipment.gltZero'),
                        'redirect' => false
                    ];
                }
                // 验证是否有往下调整空间
                $ass_where = ['assort_name' => $assort[$key]];
                $ass_info = AssortRepository::findByWhere($ass_where);
                // 2、获取要调整用户的下级里面自定义金额最低的人的金额
                $defined_query = Defined::query()->where('assort_id', $ass_info->id)->whereIn('user_id', $ids)->min('money');
                // 如果有下级则验证，否则不验证
                if ($defined_query) {
                    // 3、如果升级金额大于或者等于该用户的下级金额，则报错
                    if ($agency[$key] >= $defined_query || (bcsub($agency[$key], $defined_query, 2) <= 1 && bcsub($agency[$key], $defined_query, 2) >= 0)) {
                        return [
                            'code' => 1,
                            'msg' => trans('equipment.gltLower'),
                            'redirect' => false
                        ];
                    }
                }
                // 验证是否有往上调整空间
                $guodai_where = ['user_id' => $parent_id];
                $res = EquipmentRepository::findByWhere($guodai_where);
                // 获取选择的级别对应配置的金额
                if ($res) {
                    $choice_where = ['level_id' => 8, 'user_id' => $parent_id];
                } else {
                    $choice_where = ['level_id' => 8, 'user_id' => 1];
                }
                $choice_money = Equipment::query()->where($choice_where)->pluck('money');
                if ($agency[$key] < $choice_money[$key]) {
                    return [
                        'code' => 1,
                        'msg' => trans('equipment.gltLower'),
                        'redirect' => false
                    ];
                }
            }

            // 把自定义的配置级别添加到表里面去
            foreach ($assort as $k => $item) {
                $ass_where = ['assort_name' => $item];
                $ass_info = AssortRepository::findByWhere($ass_where);
                $defined = [
                    'assort_id' => $ass_info->id,
                    'money' => $agency[$k],
                    'updated_at' => date("Y-m-d H:i:s", time()),
                ];
                $where = ['user_id' => $parameter['id'], "assort_id" => $ass_info->id];
                Defined::query()->where($where)->update($defined);
            }
            return [
                'code' => 0,
                'msg' => trans('general.createSuccess'),
                'redirect' => true
            ];
        } catch (QueryException $e) {
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }

    /**
     * @Title: change
     * @Description: 待联系代理人状态更新
     * @param $id
     * @return array
     * @Author: 李军伟
     */
    public function change(Request $request, $id)
    {
        try {
            // 如果不是ajax方式，则非法请求
            $this->isAjax($request);
            $where = ['id' => $id];
            $params = ['is_relation' => 2];
            AdminUserRepository::updateByWhere($where, $params);
            return [
                'code' => 0,
                'msg' => trans('general.updateSuccess'),
                'redirect' => true
            ];
        } catch (QueryException $e) {
            return [
                'code' => 1,
                'msg' => $e->getMessage(),
                'redirect' => false
            ];
        }
    }
}
