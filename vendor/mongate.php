<?php
require_once('nusoap.php');

/**
 * Class Mongate
 */
class Mongate {
    /**
     * @var
     */
    private $_gateway;
    /**
     * @var
     */
    private $_username;
    /**
     * @var
     */
    private $_password;
    /**
     * @var nusoap_client
     */
    private $_soap;
    /**
     * @var string
     */
    private $_port;
    /**
     * @var string
     */
    private $_lastError;
    /**
     * @var array
     */
    public static $error
        = [
            '-1' => '参数为空',
            '-2' => '电话号码个数超过100',
            '-10' => '申请缓存空间失败',
            '-11' => '电话号码中有非数字字符',
            '-12' => '有异常电话号码',
            '-13' => '电话号码个数与实际个数不相等',
            '-14' => '实际号码个数超过100',
            '-101' => '发送消息等待超时',
            '-102' => '发送或接收消息失败',
            '-103' => '接收消息超时',
            '-200' => '其他错误',
            '-999' => '服务器内部错误',
            '-10001' => '用户登陆不成功(帐号不存在/停用/密码错误)',
            '-10002' => '提交格式不正确',
            '-10003' => '用户余额不足',
            '-10004' => '手机号码不正确',
            '-10005' => '计费用户帐号错误',
            '-10006' => '计费用户密码错',
            '-10007' => '账号已经被停用',
            '-10008' => '账号类型不支持该功能',
            '-10009' => '其它错误',
            '-10010' => '企业代码不正确',
            '-10011' => '信息内容超长',
            '-10012' => '不能发送联通号码',
            '-10013' => '操作员权限不够',
            '-10014' => '费率代码不正确',
            '-10015' => '服务器繁忙',
            '-10016' => '企业权限不够',
            '-10017' => '此时间段不允许发送',
            '-10018' => '经销商用户名或密码错',
            '-10019' => '手机列表或规则错误',
            '-10021' => '没有开停户权限',
            '-10022' => '没有转换用户类型的权限',
            '-10023' => '没有修改用户所属经销商的权限',
            '-10024' => '经销商用户名或密码错',
            '-10025' => '操作员登陆名或密码错误',
            '-10026' => '操作员所充值的用户不存在',
            '-10027' => '操作员没有充值商务版的权限',
            '-10028' => '该用户没有转正不能充值',
            '-10029' => '此用户没有权限从此通道发送信息(用户没有绑定该性质的通道，比如：用户发了小灵通的号码)',
            '-10030' => '不能发送移动号码',
            '-10031' => '手机号码(段)非法',
            '-10032' => '用户使用的费率代码错误',
            '-10033' => '非法关键词',
        ];

    /**
     * @param array $config
     */
    public function __construct($config) {
        $this->_gateway = $config['gateway'];
        $this->_username = $config['username'];
        $this->_password = $config['password'];
        $this->_port = $config['port'];
        $this->_soap = new nusoap_client($this->_gateway, false);
    }

    /**
     * @param array  $mobiles
     * @param string $content
     * @return mixed
     */
    public function sendSms(array $mobiles, $content) {
        $params = array();
        $total = count($mobiles);
        $array = implode(",", $mobiles);
        $params['userId'] = $this->_username;
        $params['password'] = $this->_password;
        $params['pszSubPort'] = $this->_port;
        $params['pszMobis'] = $array;
        $params['pszMsg'] = $content;
        $params['iMobiCount'] = $total;

        $res = $this->_soap->call('MongateCsSpSendSmsNew', $params);
        if (in_array($res, self::$error)) {
            $this->_lastError = self::$error[$res];

            return false;
        }
        else {
            return true;
        }
    }

    /**
     * @return string
     */
    public function getLastError() {
        return $this->_lastError;
    }
}