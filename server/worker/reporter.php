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
class Reporter extends Worker
{
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
    private $queue;
    /**
     * @var int
     */
    private $index;
    /**
     * @var int
     */
    private $batchCount;
    /**
     * @var array
     */
    private $history = array();
    /**
     * @var \PHPMailer
     */
    private $email;
    /**
     * @var \Mongate
     */
    private $sms;

    /**
     * @return mixed
     */
    public function run()
    {
        $this->queue = Env::getInstance()->getMsgQueue();
        $this->batchCount = Config::getInstance()->get($this->name, '.batch_count', 10);
        $this->email = new \PHPMailer();
        $emails = Config::getInstance()->get($this->name . '.email');
        if ($emails['sendmail']) {
            $this->email->isSendmail();
        }
        $this->email->setFrom($emails['from'], $emails['name']);
        $this->sms = new \Mongate(Config::getInstance()->get($this->name . '.sms'));
        Strategy::loadData();
        Task::add('sendReport', self::REPORT_INTERVAL, array($this, 'sendReport'));
    }

    /**
     * @desc batch job, get message from queue, send by sms or email
     */
    public function sendReport()
    {
        $this->index = 0;
        $sendInterval = Config::getInstance()->get($this->name . '.send_interval', self::SEND_INTERVAL);
        $expireTime = Config::getInstance()->get($this->name . '.expire_time', self::EXPIRE_TIME);

        while (true) {
            $ret = msg_receive($this->queue, Env::MSG_TYPE_ALERT, $msgtype, 1024, $message, true, 0, $error);
            $this->index++;
            if ($this->index >= $this->batchCount) {
                break;
            }

            if (!$ret) {
                continue;
            }

            $id = $message['id'];
            $time = $message['time'];
            $details = $message['details'];

            if (time() - $time > $expireTime) {
                continue;
            }

            if (isset($this->history[$id])) {
                $lastTime = $this->history[$id];
                if (time() - $lastTime <= $sendInterval) {
                    continue;
                }
            }

            $this->history[$id] = time();
            Core::alert('send email or sms for: ' . $id);
            $this->alertUsers($id, $time, $details);
        }
    }

    /**
     * @param string $id
     * @param int    $alertAt
     * @param string $details
     */
    private function alertUsers($id, $alertAt, $details)
    {
        $config = Strategy::getConfigById($id);
        $users = $config['alerts'];
        $smsTo = $mailsTo = array();

        foreach ($users as $user) {
            if (preg_match('/\d{11}/', $user)) {
                $smsTo[] = $user;
            } else {
                $this->email->addAddress($user);
                $mailsTo[] = $user;
            }
        }

        $data = array(
            '{time}' => date('Y-m-d H:i:s', $alertAt),
            '{desc}' => $config['desc'],
            '{id}' => $id,
            '{content}' => substr($details, 0, 50),
        );
        var_dump($data);

        if (!empty($mailsTo)) {
            $this->email->Subject = $config['desc'];
            $this->email->Body = $this->genMailContent($data);

            if ($this->email->send()) {
                Core::alert('send mail success', false);
            } else {
                Log::write('send mail error: ' . $this->email->ErrorInfo);
            }
        }

        if (!empty($smsTo)) {
            if ($this->sms->sendSms($smsTo, $this->_genSmsContent($data))) {
                Core::alert('send sms to users: ' . implode(';', $smsTo), false);
            } else {
                Log::write('send sms error: ' . $this->sms->getLastError());
            }
        }
    }

    /**
     * @param string $buff
     * @return int|false
     */
    public function parseInput($buff)
    {
        // TODO: Implement parseInput() method.
    }

    /**
     * @param string $package
     * @return bool
     */
    public function serve($package)
    {
        // TODO: Implement processRemote() method.
    }

    /**
     * @param array $data
     * @return string
     */
    private function _genSmsContent(array $data)
    {
        $template = Config::getInstance()->get($this->name . '.sms.template');

        return strtr($template, $data);
    }

    /**
     * @param array $data
     * @return string
     */
    private function genMailContent(array $data)
    {
        $template = Config::getInstance()->get($this->name . '.email.template');

        return strtr($template, $data);
    }

    public function __destruct()
    {
        msg_remove_queue($this->queue);
    }
}