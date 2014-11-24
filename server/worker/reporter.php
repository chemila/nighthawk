<?php
namespace NHK\server\worker;
require_once NHK_PATH_ROOT . 'vendor/phpmailer.php';

use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Log;
use NHK\system\Sms;
use NHK\system\Strategy;
use NHK\system\Task;

/**
 * Class Reporter
 *
 * @package NHK\server\worker
 */
class Reporter extends Worker {
    /**
     * @desc seconds, send interval
     */
    const SEND_INTERVAL = 60;
    /**
     * @desc expired trigger exception, seconds
     */
    const EXPIRE_TIME = 600;
    /**
     * @var resource
     */
    private $_queue;
    /**
     * @var int
     */
    private $_index;
    /**
     * @var int
     */
    private $_batchCount;
    /**
     * @var array
     */
    private $_history = array();
    /**
     * @var \PHPMailer
     */
    private $_email;
    /**
     * @var Sms
     */
    private $_sms;

    /**
     * @return mixed
     */
    public function run() {
        $this->_queue = Env::getInstance()->getMsgQueue();
        $this->_batchCount = Config::getInstance()->get($this->_name, '.batch_count', 10);
        $this->_email = new \PHPMailer();
        $this->_email->isSendmail();
        $this->_email->setFrom('chemia@me.com', 'fuqiang');
        $this->_sms = new Sms();
        Strategy::loadData();
        Task::add('sendReport', 1, array($this, 'sendReport'));
    }

    /**
     * @desc batch job, get message from queue, send by sms or email
     */
    public function sendReport() {
        $this->_index = 0;
        $sendInterval = Config::getInstance()->get($this->_name . '.send_interval', self::SEND_INTERVAL);
        $expireTime = Config::getInstance()->get($this->_name . '.expire_time', self::EXPIRE_TIME);

        while (true) {
            $ret = msg_receive($this->_queue, Env::MSG_TYPE_ALERT, $msgtype, 1024, $message, true, 0, $error);
            $this->_index++;

            if ($this->_index >= $this->_batchCount) {
                break;
            }

            if (!$ret) {
                continue;
            }

            list($id, $time) = each($message);
            if (time() - $time > $expireTime) {
                continue;
            }

            if (isset($this->_history[$id])) {
                $lastTime = $this->_history[$id];
                if (time() - $lastTime <= $sendInterval) {
                    continue;
                }
            }

            $this->_history[$id] = time();
            //TODO: invoke email|sms sender
            Core::alert('send email|sms for: ' . $id);
            $this->_alertUsers($id);
        }
    }

    /**
     * @param $id
     */
    private function _alertUsers($id) {
        $config = Strategy::getConfigById($id);
        $users = $config['alerts'];
        $this->_email->Subject = $config['desc'];
        $this->_email->Body = sprintf("This is a alert test, id: " . $id);

        foreach ($users as $user) {
            if (preg_match('/\d{11}/', $user)) {
                $this->_sms->send($config['desc']);
            }
            else {
                $this->_email->addAddress($user);
            }
        }

        if (!$this->_email->send()) {
            Log::write('send mail error: ' . $this->_email->ErrorInfo);
        }
        else {
            Core::alert('send mail success', false);
        }
    }

    /**
     * @param string $buff
     * @return int|false
     */
    public function parseInput($buff) {
        // TODO: Implement parseInput() method.
    }

    /**
     * @param string $package
     * @return bool
     */
    public function serve($package) {
        // TODO: Implement processRemote() method.
    }

    private function _sendMail($to, $title, $content) {

    }
}