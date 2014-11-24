<?php
namespace NHK\server\worker;

defined('NHK_PATH_ROOT') or die('No direct script access.');
require_once NHK_PATH_ROOT . 'vendor/phpmailer.php';
require_once NHK_PATH_ROOT . 'vendor/mongate.php';

use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Log;
use NHK\system\Strategy;
use NHK\system\Task;

/**
 * Class Reporter
 *
 * @package NHK\server\worker
 * @author  fuqiang(chemila@me.com)
 */
class Reporter extends Worker {
    const REPORT_INTERVAL = 1;
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
     * @var \Mongate
     */
    private $_sms;

    /**
     * @return mixed
     */
    public function run() {
        $this->_queue = Env::getInstance()->getMsgQueue();
        $this->_batchCount = Config::getInstance()->get($this->_name, '.batch_count', 10);
        $this->_email = new \PHPMailer();
        $emails = Config::getInstance()->get($this->_name . '.email');
        if ($emails['sendmail']) {
            $this->_email->isSendmail();
        }
        $this->_email->setFrom($emails['from'], $emails['name']);
        $this->_sms = new \Mongate(Config::getInstance()->get($this->_name . '.sms'));
        Strategy::loadData();
        Task::add('sendReport', self::REPORT_INTERVAL, array($this, 'sendReport'));
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
            Core::alert('send email or sms for: ' . $id);
            $this->_alertUsers($id, $time, $message);
        }
    }

    /**
     * @param string $id
     * @param int    $alertAt
     * @param string $message
     */
    private function _alertUsers($id, $alertAt, $message) {
        $config = Strategy::getConfigById($id);
        $users = $config['alerts'];
        $smsTo = $mailsTo = array();

        foreach ($users as $user) {
            if (preg_match('/\d{11}/', $user)) {
                $smsTo[] = $user;
            }
            else {
                $this->_email->addAddress($user);
                $mailsTo[] = $user;
            }
        }

        $data = array(
            '{time}' => date('Y-m-d H:i:s', $alertAt),
            '{desc}' => $config['desc'],
            '{id}' => $id,
            '{content}' => substr($message, 0, 50),
        );

        if (!empty($mailsTo)) {
            $this->_email->Subject = $config['desc'];
            $this->_email->Body = $this->_genMailContent($data);

            if ($this->_email->send()) {
                Core::alert('send mail success', false);
            }
            else {
                Log::write('send mail error: ' . $this->_email->ErrorInfo);
            }
        }

        if (!empty($smsTo)) {
            if ($this->_sms->sendSms($smsTo, $this->_genSmsContent($data))) {
                Core::alert('send sms to users: ' . implode(';', $smsTo), false);
            }
            else {
                Log::write('send sms error: ' . $this->_sms->getLastError());
            }
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

    /**
     * @param array $data
     * @return string
     */
    private function _genSmsContent(array $data) {
        $template = Config::getInstance()->get($this->_name . '.sms.template');

        return strtr($template, $data);
    }

    /**
     * @param array $data
     * @return string
     */
    private function _genMailContent(array $data) {
        $template = Config::getInstance()->get($this->_name . '.email.template');

        return strtr($template, $data);
    }

    public function __destruct() {
        msg_remove_queue($this->_queue);
    }
}