<?php
namespace NHK\server\worker;

use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\system\Email;
use NHK\System\Env;
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
     *
     */
    const SEND_INTERVAL = 60;
    /**
     *
     */
    const SEND_MAX_COUNT = 10;
    /**
     *
     */
    const EXPIRE_TIME = 600;
    /**
     * @var
     */
    private $_queue;
    /**
     * @var
     */
    private $_index;
    /**
     * @var
     */
    private $_batchCount;
    /**
     * @var array
     */
    private $_history = array();
    /**
     * @var Email
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
        $this->_email = new Email();
        $this->_sms = new Sms();
        Strategy::loadData();
        Task::add('sendReport', 1, array($this, 'sendReport'));
    }

    /**
     *
     */
    public function sendReport() {
        $this->_index = 0;

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
            if (time() - $time > self::EXPIRE_TIME) {
                continue;
            }

            if (isset($this->_history[$id])) {
                $lastTime = $this->_history[$id];
                if (time() - $lastTime <= self::SEND_INTERVAL) {
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
        $phones = $emails = array();
        foreach ($users as $user) {
            if (preg_match('/\d{11}/', $user)) {
                $phones[] = $user;
            }
            else {
                $emails[] = $user;
            }
        }

        if (!empty($phones)) {
            $this->_sms->send($config['desc']);
        }

        if (!empty($emails)) {
            $this->_email->send($config['desc']);
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

} 