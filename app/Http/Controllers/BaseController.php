<?php

namespace App\Http\Controllers;

use Validator, Input, Log, Request, Redis;
use App\Model\FXFans;
use App\Model\Wechat;
use App\Model\Wechatuser;
use App\Model\Member;
use App\Services\HttpApi;
use App\Services\RedisKey;
use App\Services\ErrMapping;

class BaseController extends Controller {

    protected $_errCode = 0;
    protected $_msg = '';
    protected $_result;
    protected $accountApi;

    protected $userInfo; //当前登录用户信息
    protected $mallId; // 当前登录账户商场id
    protected $userInfoRy;//当前登录用户信息-大运营
    protected $errorCode = 0;
    /**
     * 校验参数合法性。验证通过返回true，失败返回validator对象
     *
     * @param array $data 数组
     * @param array $rules 规则
     * @return object on fail | bool on success
     */
    protected function _checkParams($data, $rules) {
        $result = true;
        $message = [
            'required' => ' :attribute 不可以为空!',
            'integer' => ' :attribute 参数类型不正确!',
            'max' => ' :attribute 内容最大长度为 :max!',
            'date' => ' :attribute 格式不正确!'
        ];
        $validator = Validator::make($data, $rules, $message);

        if ($validator->fails()) {
            $this->_errCode = ErrMapping::ERR_BAD_PARAMETERS;
            // 将所有错误信息返回
            $this->_msg = $validator->errors()->all();
            $this->_msg = is_array($this->_msg) ? implode(' ', $this->_msg) : $this->_msg;

            $result = $validator;
        }

        return $result;
    }

    /**
     * 将Api的json返回统一输出。
     */
    protected function _jsonOutput($headers = array()) {
        if ($this->_errCode && empty($this->_msg)) {
            $this->_msg = ErrMapping::getMessage($this->_errCode);
        }
        $result = array(
            'meta' => array(
                'errno' => $this->_errCode,
                'status' => $this->_errCode,
                'msg' => $this->_msg
            ),
            'result' => empty($this->_result) ? new \StdClass() : $this->_result
        );
        $response = response()->json($this->_filterReturn($result));
        if ($headers) {
            foreach ($headers as $k => $v) {
                $response->header($k, $v);
            }
        }
        //将日志格式化
        Log::info(sprintf('date[%s] ip[%s] url[%s] 请求参数：[%s] result[%s] 请求http头信息：[%s]', date('Y-m-d H:i:s' , time()), Request::getClientIp(), Request::url(), json_encode(Input::all(), JSON_UNESCAPED_UNICODE), substr(json_encode($result, JSON_UNESCAPED_UNICODE), 0, 300), json_encode($this->getallheaders(), JSON_UNESCAPED_UNICODE)));
        
        return $response;
    }

    /**
     * 跨域问题
     *
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    protected function _jsonOutputWithCrossDomain() {
        return $this->_jsonOutput(array('Access-Control-Allow-Origin' => '*'));
    }

    /**
     * 递归去除null数值。
     *
     * @param array $data
     * @return array
     */
    protected function _filterReturn($data) {
        // 不是数组，直接返回
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => &$val) {
            if (is_array($val)) {
                $val = $this->_filterReturn($val);
            } elseif (is_null($val)) {
                $val = '';
            }
        }

        return $data;
    }

    public function errorResponse($e) {
        if ($e instanceof HttpException) {
            // 通过
            $this->_errCode = ErrMapping::ERR_HTTP_PREFIX . $e->getStatusCode();
        } else {
        	if (!empty(ErrMapping::existsMsg($e->getCode()))) {
				$this->_errCode = $e->getCode();
        	}
        	else {
            	$this->_errCode = ErrMapping::ERR_INTERNAL_SERVER_ERR;
        	}
        }

        return $this->_jsonOutput();
    }
    
    /**
     * @name 大运营单点登录
     */
    public function getRyUser() {
        $user = array();
        $bsst = isset($_COOKIE['RYST']) ? $_COOKIE['RYST'] : '';
        if (!empty($bsst)) {
            $apiConfig = config('constants.SSO');
            $data = [
                'ryst' => $bsst,
                'channel' => $apiConfig['yunying_channel']
            ];
            $httpApi = new HttpApi();
            $result = $httpApi->jsonClient($apiConfig['yunying_url'], $data, 10);
            if ($result) {
                if ($result['meta']['errno'] != 0) {
                    Log::warning(sprintf('class[%s] func[%s] errno[%s] msg[%s]', __CLASS__, __FUNCTION__, $result['meta']['errno'], $result['meta']['msg']));
                }
                else {
                    if (!empty($result['result']['data'])) {
                        $user = $result['result']['data']['userInfo'];
                        return $user;
                    }
                    else {
                        Log::warning(sprintf('class[%s] func[%s] msg[call merchant userinfo success, but no data]', __CLASS__, __FUNCTION__));
                    }
                }
            }
        }
        $this->_errCode = ErrMapping::ERR_LOGIN_USER;
        return $user;
    }
    
    public function checkRyUser($emptyException = false) {
        $checkResult = true;
        $this->userInfoRy = $this->getRyUser();
        //mock数据
//         $this->userInfoRy = json_decode('{"id": 1,"username": "superadmin","memo": "1","nickname": "超级管理员","status": 1,"name": "superadmin"}' , true);
        if (empty($this->userInfoRy)) {
            $checkResult = false;
            $this->_errCode = ErrMapping::ERR_LOGIN_USER;
        }
        else {
        }
        if ($this->_errCode && $emptyException) {
            throw new \Exception('', $this->_errCode);
        }
        return $checkResult;
    }

    /**
     * @name 登录
     */
    protected function getUser() {
        $user = array();

        if (isset($_COOKIE['BSST'])) {
            $bsst = $_COOKIE['BSST'];
            if (!empty($bsst)) {
                $apiConfig = config('constants.SSO');
                $data = [
                    'bsst' => $bsst,
                    'channel' => $apiConfig['channel']
                ];

                $httpApi = new HttpApi();
                $result = $httpApi->jsonClient($apiConfig['url'], $data, 10);
                Log::info(sprintf('date[%s] class[%s] func[%s] where[%s] url[%s] params[%s] return[%s]', date('Y-m-d H:i:s' , time()), __CLASS__, __FUNCTION__, json_encode($data, JSON_UNESCAPED_UNICODE), $apiConfig['url'], json_encode($data, JSON_UNESCAPED_UNICODE), json_encode($result, JSON_UNESCAPED_UNICODE)));
                if ($result['meta']['errno'] != 0) {
                    $this->_errCode = $result['meta']['errno'];
                    $this->_msg = $result['meta']['msg'];
                    return $user;
                } else {
                    if (!empty($result['result']['data']['userInfo'])) {
                        $user = $result['result']['data']['userInfo'];
                        Session(['user' => $user]);
                        return $user;
                    }
                }
            }
        }
        $this->_errCode = ErrMapping::ERR_LOGIN_USER;

        return $user;
    }

    function getallheaders() {
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }

    /**
     * @name 二维数组排序
     */
   public function arr_sort($array, $key, $order = "asc") {//asc是升序 desc是降序
        $arr_nums = $arr = array();
        foreach ($array as $k => $v) {
            $arr_nums[$k] = $v[$key];
        }
        if ($order == 'asc') {
            asort($arr_nums);
        } else {
            arsort($arr_nums);
        }
        foreach ($arr_nums as $k => $v) {
            $arr[$k] = $array[$k];
        }
        return $arr;
    }

    /**
     * 检查用户是否登录
     * @param string $emptyException
     * @throws \Exception
     * @return boolean
     */
    public function checkUserLogin($emptyException = false) {
    	$checkResult = true;
    	$this->userInfo = $this->getUser();
    	if (empty($this->userInfo)) {
    		$checkResult = false;
    		if ($emptyException) {
    			throw new \Exception('', ErrMapping::ERR_LOGIN_USER);
    		}
    	}
    	return $checkResult;
    }

    /**
     * 检查用户和商场id是否为空。暂时商场id都存在，所以通过商场id来进行处理
     * @return boolean
     */
	public function checkUserAndMallId($emptyException = false) {
		$checkResult = true;
		$this->userInfo = $this->getUser();
		// mock数据 $this->userInfo = json_decode('{"bindingMid":"51f9d7f731d6559b7d00014d","bindingType":"Mall","mallId":22,"type":0,"mallMid":"51f9d7f731d6559b7d00014d","userNickName":"\u5927\u5b81\u7ba1\u7406","id":296,"identity":1,"isCooperation":1,"bindingId":22,"shopId":0,"createSource":0,"userName":"\u5927\u5b81\u7ba1\u7406","filialeId":0,"userPhone":"13812345678","userAccount":"D_13812345678"}' , true);
		if (empty($this->userInfo)) {
			$checkResult = false;
			$this->_errCode = ErrMapping::ERR_LOGIN_USER;
		}
		else {
			$this->mallId = $this->getMallIdFromUser();
			if (empty($this->mallId)) {
				$checkResult = false;
				// 设置错误码
				$this->_errCode = ErrMapping::ERR_LOGIN_USER;
				$this->_msg = '该角色账户暂不支持，请联系管理员';
			}
			else {
				// do nothing
			}
		}
		if ($this->_errCode && $emptyException) {
			throw new \Exception('', $this->_errCode);
		}
		return $checkResult;
	}

    /**
     * 通过用户获取商场id。
     */
    public function getMallIdFromUser() {
    	$mallId = '';
    	// 微信开始获取的字段来自bindingMid,8-26修改为获取mallMid
		if (!empty($this->userInfo) && !empty($this->userInfo['bindingMid'])) {
			$mallId = $this->userInfo['bindingMid'];
		}
		return $mallId;
    }
    
    /**
     * @name 关系链粉丝保存
     * @param array $data
     */
    public function createFXFans($data) {
        $re = false;
        if (isset($data['mall_id']) && isset($data['openid']) && isset($data['promoterid']) && strlen($data['openid']) < 50) {
            $fxFans = new FXFans();
            $rowFXFans = $fxFans->getFans(['mall_id' => $data['mall_id'], 'openid' => $data['openid']]);
            if (!empty($rowFXFans)) {
                Log::info(sprintf('date[%s] class[%s] func[%s] params[%s] return[%s] msg[分销粉丝关系修改]', date('Y-m-d H:i:s' , time()), __CLASS__, __FUNCTION__, json_encode($data, JSON_UNESCAPED_UNICODE), json_encode($rowFXFans, JSON_UNESCAPED_UNICODE)));
                if (!empty($data['phone']) && !empty($data['uid'])) {
                    if ($rowFXFans['phone'] == '' && $rowFXFans['uid'] == '') {
                        $addFans = array();
                        $addFans['mall_id'] = $data['mall_id'];
                        $addFans['id'] = $rowFXFans['id'];
                        $addFans['phone'] = $data['phone'];
                        $addFans['uid'] = $data['uid'];
                        $fxFans->updateFans($addFans);
                    }
                }
            }
            else {
                $addFans = array();
                $addFans['mall_id'] = $data['mall_id'];
                $addFans['openid'] = $data['openid'];
                $addFans['phone'] = $data['phone'];
                $addFans['uid'] = $data['uid'];
                $addFans['parent_openid'] = $data['promoterid'];
                Log::info(sprintf('date[%s] class[%s] func[%s] params[%s] msg[分销粉丝关系创建]', date('Y-m-d H:i:s' , time()), __CLASS__, __FUNCTION__, json_encode($addFans, JSON_UNESCAPED_UNICODE)));
                $result = $fxFans->insertFans($addFans);
                if ($result) {
                    $re = true;
                }
            }
        }
        return $re;
    }

    /**
     * 系统错误
     */
    protected function systemErr()
    {
        $this->_errCode = ErrMapping::ERR_INTERNAL_SERVER_ERR;
    }

    /**
     * @param $params
     * @param $fieldList
     * @return array
     */
    protected function getParams($params, $fieldList)
    {
        $newParams = [];
        foreach ($fieldList as $fieldName) {
            if (isset($params[$fieldName])) {
                if (is_array($params[$fieldName])) {
                    $jsonFieldName = json_encode($params[$fieldName]);
                    $newParams[$fieldName] = $jsonFieldName;
                } else {
                    $newParams[$fieldName] = $params[$fieldName];
                }
            }
        }
        return $newParams;
    }

    /**
     * 是否是会员
     *
     * @return bool
     */
    protected function isMember()
    {
        $isMember = false;
        if (!empty($this->userInfo['uid'])) {
            $isMember = true;
        }
        return $isMember;
    }

    /**
     * @name 会员注册url
     *
     * @param $mallId
     * @return string
     */
    protected function JumpLogin($mallId)
    {
        return config('constants.DOMAINNAME') . 'manage/front/reg?mall_id='. $mallId .'&returnUrl='.urlencode(Request::fullurl());
    }

    /**
     * @name 错误视图
     *
     * @param array $data
     * @return $this
     */
    protected function errorView($data = [])
    {
        return view("wechat_h5.page.405")->with('data', $data);
    }

    /**
     * 以字面编码多字节 Unicode 字符（默认是编码成 \uXXXX）。 自 PHP 5.4.0 起生效。
     *
     * @param $data
     * @return string
     */
    protected function json_encode($data)
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * log error 记录
     *
     * @param $params
     * @param $result
     * @param $msg
     */
    protected function logError($params, $result, $msg)
    {
        Log::warning(sprintf('class[%s] function[%s] params[%s] result[%s] msg[' . $msg . ']', get_class($this), __FUNCTION__, $this->json_encode($params), $this->json_encode($result)));
    }

    /**
     * @return string
     */
    protected function error405()
    {
        if ($this->errorCode == 405) {
            return $this->errorView();
        }
    }

    /**
     * 网页授权
     * @param $mallId
     * @param $redirecturl
     * @return string
     * @throws \Exception
     */
    protected function wechatFansInfo($mallId,$redirecturl) {
        $params = Input::all();
        try {
            $wechat = new Wechat($mallId);
            $auth = $wechat->oauth;
            $auth->scopes(['snsapi_userinfo']);
        } catch (\Exception $e) {
            Log::warning(sprintf('class[%s] function[%s] message[%s] code[%s] msg[商家没有绑定公众号]',__CLASS__, __FUNCTION__, $e->getMessage(), $e->getCode()));
            throw new \Exception('系统开小差啦，小主请重试~', $e->getCode());
        }
        // 设置code、state参数即可获取数据
        $isJumpUrl = false;
        $wxopenid = '';
        try {
            if (isset($params['code'])) {
                $user = $auth->user();
                $user = $user->toArray();
                $wxopenid = $this->_updateUserInfo($mallId, $user);
            } else {
                $isJumpUrl = true;
            }
        } catch (\Exception $e) {
            Log::warning(sprintf('class[%s] function[%s] message[%s] code[%s] msg[处理微信不是自己code问题]',__CLASS__, __FUNCTION__, $e->getMessage(), $e->getCode()));
            $isJumpUrl = true;
            $url = Request::url();
            $params = array_except($params, ['code', 'state']);
            if (!empty($params)) {
                $param = '';
                foreach($params as $k => $v) {
                    $param .= $k . '=' . $v . '&';
                }
                $param = trim($param, '&');
                $redirecturl = $url.'?'.$param;
            }
        }
        if ($isJumpUrl) {
            echo $auth->redirect($redirecturl)->send();
            exit();
        }
        return $wxopenid;
    }

    /**
     * 更新用户信息
     *
     * @param $mallId
     * @param $user
     * @return string
     */
    protected function _updateUserInfo($mallId, $user)
    {
        $wxopenid = '';
        if (isset($user['id'])) {
            $wxopenid = $user['id'];
            $fansUser = [];
            $headImgUrl = 'http://img0.t.rongyi.com/XWQlTuv3i0BUCCaG.jpg';
            $fansUser['headimgurl'] = !empty($user['original']['headimgurl']) ? str_replace(' ', '', $user['original']['headimgurl']) : $headImgUrl;
            $fansUser['nickname'] = isset($user['original']['nickname']) ? $user['original']['nickname'] : '';
            $fansUser['sex'] = isset($user['original']['sex']) ? $user['original']['sex'] : '';
            $fansUser['city'] = isset($user['original']['city']) ? $user['original']['city'] : '';
            $fansUser['country'] = isset($user['original']['country']) ? $user['original']['country'] : '';
            $fansUser['province'] = isset($user['original']['province']) ? $user['original']['province'] : '';
            $fansUser['language'] = isset($user['original']['language']) ? $user['original']['language'] : '';
            $fansUser['unionid'] = isset($user['original']['unionid']) ? $user['original']['unionid'] : '';
            if (isset($user['subscribe'])) {
                $fansUser['subscribe'] = $user['subscribe'];
            }
            if (!empty($user['subscribe_time'])) {
                $fansUser['subscribe_time'] = $user['subscribe_time'];
            }

            $wechatUser = new Wechatuser();
            $row = $wechatUser->getWechatFansUser(['mallid' => $mallId, 'openid' => $wxopenid]);
            if (!empty($row['id'])) {
                $wechatUser->updateWechatUser(['mallid' => $mallId, 'openid' => $wxopenid, 'id' => $row['id']], $fansUser);
            } else {
                $fansUser['mallid'] = $mallId;
                $fansUser['openid'] = $wxopenid;
                $wechatUser->insertWechatUser($fansUser);
            }
            $fansUser['mallid'] = $mallId;
            $fansUser['openid'] = $wxopenid;
            Redis::set(RedisKey::getKey(RedisKey::WX_MALLID_OPEN_USER, ['mallId' => $mallId, 'openid' => $wxopenid]), json_encode($fansUser));
            Redis::expire(RedisKey::getKey(RedisKey::WX_MALLID_OPEN_USER, ['mallId' => $mallId, 'openid' => $wxopenid]), 1800);
            $this->openid = $wxopenid;
            Session(['openid_' . $mallId => $wxopenid]);
        }
        return $wxopenid;
    }

    /**
     * 分店获取上级的mall_id
     *
     * @return string
     */
    public function getMall()
    {
        $mallId = $this->mallId;
        $isChainShop = $this->checkBranchShop();
        if ($isChainShop == 1) {
            $mallId = isset($this->userInfo['parentShopMid']) ? $this->userInfo['parentShopMid'] : $this->mallId;
        } else if ($isChainShop == 2) {
            $mallId = isset($this->userInfo['mallMid']) ? $this->userInfo['mallMid'] : $this->mallId;
        }

        return $mallId;
    }

    /**
     * 检测分店
     * 0-商城／总部 1-连锁分店 2-商城分店
     *
     * @return int
     */
    public function checkBranchShop()
    {
        $branchShop = 0;
        if (!empty($this->userInfo['identity']) && $this->userInfo['identity'] == 4 && isset($this->userInfo['singleShopAccount']) && !$this->userInfo['singleShopAccount']) {
            if (isset($this->userInfo['chainShop'])) {
                if ($this->userInfo['chainShop']) {
                    $branchShop = 1;
                } else {
                    $branchShop = 2;
                }
            }
        }
        return $branchShop;
    }

    /**
     * @name 获取电子会员多个字段内容
     * @param $field 字段数组[默认：会员uid、手机号码]
     * @param  $userInfo
     * @return string
     */
    protected function getMemberInfo($field = ['uid', 'phone'], $userInfo = [])
    {
        if (!$userInfo && $this->userInfo) {
            $userInfo = $this->userInfo;
        }
        $memberInfo = array_only($userInfo, $field);
        return $memberInfo;
    }

    /**
     * @name 获取电子会员字段内容
     * @param $field 字段[默认手机号码]
     * @return string
     */
    protected function getMemberField($field = 'phone')
    {
        $fieldContent = '';
        if (isset($this->userInfo[$field])) {
            $fieldContent = $this->userInfo[$field];
        }
        return $fieldContent;
    }

    /**
     * 检测是否要授权
     *
     * @param $mallId
     * @param $openid
     */
    protected function checkWechatAuth($mallId, $wxopenid, $snsApi = 'userinfo')
    {
        $falg = false;
        if (!empty($wxopenid) && $snsApi == 'userinfo') {
            $wechatInfoModel = new Wechatuser();
            $wechatInfo = $wechatInfoModel->getWechatFansUser(['mallid' => $mallId, 'openid' => $wxopenid]);
            $falg = !empty($wechatInfo['openid']) ? true : false;
        }
        if (!$falg) {
            if ($snsApi == 'userinfo') {
                $this->wechatFansInfo($mallId, Request::fullurl());
            }
            else {
                $this->getnodeOpenid($mallId, Request::fullurl());
            }
        }
        if (!empty($this->openid)) {
            $member = new Member();
            $results = $member->infos(array('mall_id' => $mallId, 'openid' => $this->openid));
            if (!empty($results['uid'])) {
                $this->userInfo = $results;
            }
        }
    }

    /**
     * @name 网页授权获取openid
     */
    protected function getnodeOpenid($mallId, $redirecturl = '') {
        if ((Session('openid_' . $mallId) && Session('openid_' . $mallId) != '')) {
            return Session('openid_' . $mallId);
        }
        $wechat = new Wechat($mallId);
        $auth = $wechat->oauth;
        if (empty($redirecturl)) {
            $redirecturl = Request::fullUrl();
        }
        $auth->scopes(['snsapi_base']);
        // 设置code、state参数即可获取数据
        $params = Input::all();
        if (isset($params['code'])) {
            try {
                $user = $auth->user();
                if ($user->id) {
                    Session(['openid_' . $mallId => $user->id]);
                    return $user->id;
                }
                else {
                    return FALSE;
                }
            }
            catch (\Exception $e) {
                $url = Request::url();
                $params = Input::all();
                $params = array_except($params, ['code', 'state']);
                if (!empty($params)) {
                    $param = '';
                    foreach($params as $k => $v) {
                        $param .= $k . '=' . $v . '&';
                    }
                    $param = trim($param, '&');
                    $url = $url.'?'.$param;
                }
                echo redirect($url)->send();
                exit();
            }
        }
        else {
            //         	$redirecturl = $redirecturl ? : Request::fullUrl();
            // 由于较多地方是通过方法来调用的，所以无法直接return auth->redirect($redirecturl);
            echo $auth->redirect($redirecturl)->send();
            exit();
        }
    }
    
    /**
     * 金钱格式化
     *
     * @param $money
     * @return float|int
     */
    protected function moneyFormat($money)
    {
        return number_format($this->pointToYuan($money),2,".","");
    }
    
    /**
     * 分转化为元
     *
     * @param $point
     * @return float|int
     */
    protected function pointToYuan($point)
    {
        return $point / 100;
    }
}
