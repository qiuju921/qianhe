<?php

namespace App\Services;
use Log;

class ErrMapping {
    // http 错误前缀
    const ERR_HTTP_PREFIX = 1500;
    const ERR_BAD_PARAMETERS = 1510400;
    const ERR_UNAUTHORIZED = 1510401;
    const ERR_ACCESS_DENIED = 1510403;
    const ERR_INTERFACE_SERVER = 1510404; // 接口异常，如404
    const ERR_METHOD_NOT_ALLOW = 1510405;

    static $msg = array(
        self::ERR_INTERNAL_SERVER_ERR => '系统错误',
        self::ERR_BAD_PARAMETERS => '参数错误',
        self::ERR_UNAUTHORIZED => '未认证',
        self::ERR_ACCESS_DENIED => '访问被拒绝',
        self::ERR_METHOD_NOT_ALLOW => '请求方法不支持',
    );

    public static function existsMsg($code) {
        return isset(self::$msg[$code]);
    }

    public static function getMessage($code, $params = array()) {
        if (isset(self::$msg[$code])) {
            $result = self::$msg[$code];
            $result = self::_replacePlaceholder($result, $params);
        } else {
            $result = '';
        }

        return $result;
    }

    private static function _replacePlaceholder($msg, $params) {
        foreach ($params as $placeholder => $val) {
            $msg = str_replace('{' . $placeholder . '}', $val, $msg);
        }

        return $msg;
    }

    /**
     * @name 日志打印
     * @param string $className 类名称
     * @param string $functionName 方法名称
     * @param string $params 参数
     * @param string $data 返回值
     * @param string $apiName 接口名称路径
     * @param string $errInfo 错误信息
     */
    public static function ErrCode($className, $functionName, $params, $data, $apiName, $errInfo = '') {
        // 根据不同的错误码写日志
        $code = intval($data['status']);
        if ($code == self::ERR_INTERFACE_SERVER) {
            // 系统错误
            $httpCode = isset($data['msg']) ? $data['msg'] : '';
            Log::info('code : ' . $httpCode . ',' . $apiName . ':' . $errInfo);
            Log::info(sprintf('date[%s] class[%s] func[%s] api[%s] params[%s] return[%s] msg[%s]', date('Y-m-d H:i:s' , time()), $className, $functionName, $apiName, json_encode($params), json_encode($data), $errInfo));
        } else {
            Log::info($apiName .  json_encode($data));
        }
    }
}
