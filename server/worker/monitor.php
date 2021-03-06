<?php
namespace NHK\Server\Worker;

defined('NHK_PATH_ROOT') or die('No direct script access.');

require_once NHK_PATH_ROOT . 'vendor/mongate.php';

use NHK\Server\Master;
use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Log;
use NHK\System\Process;
use NHK\system\Task;

/**
 * Class Monitor
 *
 * @package NHK\Server\Worker
 * @author  fuqiang(chemila@me.com)
 */
class Monitor extends Worker
{
    /**
     * @desc check worker status, time interval
     */
    const INTERVAL_STATUS = 20;
    /**
     * @desc send alert sms interval, 60s as default
     */
    const ALERT_INTERVAL = 60;
    /**
     * @desc default check interval
     */
    const INTERVAL_MASTER_HEATBEAT = self::ALERT_INTERVAL;
    /**
     * @desc default exit count
     */
    const DEFAULT_MAX_EXIT = 10;
    /**
     * @var resource
     */
    private $shm;
    /**
     * @var \Mongate
     */
    private $sms;
    /**
     * @var int
     */
    private $lastAlert = false;

    /**
     * @desc main job goes here
     */
    public function run()
    {
        $this->shm = Env::getInstance()->getShm();
        $this->sms = new \Mongate(Config::getInstance()->get($this->name . '.sms'));
        Task::add('checkMaster', self::INTERVAL_MASTER_HEATBEAT, array($this, 'checkMaster'));
        Task::add('checkWorker', self::INTERVAL_STATUS, array($this, 'checkWorker'));
    }

    /**
     * @desc check master heat beat
     */
    public function checkMaster()
    {
        if ($pid = Process::isMasterRunning()) {
            Core::alert('master is running', false);
        } else {
            Core::alert('master is dead');
            $this->alertAdmin('master is dead');
        }
    }

    /**
     * @desc check worker status
     */
    public function checkWorker()
    {
        if (!$report = $this->getReport()) {
            return false;
        }

        $max = Config::getInstance()->get($this->name . '.workers_max_exit', self::DEFAULT_MAX_EXIT);
        if (is_array($report) && isset($report[Master::REPORT_WORKER_EXIT_UNEXPECTED])) {
            $count = intval($report[Master::REPORT_WORKER_EXIT_UNEXPECTED]);
            if ($count >= $max) {
                Core::alert('too many worker exit');
                $this->alertAdmin('too many worker exit');
            }
        }
    }

    /**
     * @return bool|mixed
     */
    public function getReport()
    {
        if (shm_has_var($this->shm, Env::SHM_STATUS)) {
            return shm_get_var($this->shm, Env::SHM_STATUS);
        }

        return false;
    }

    /**
     * @param string $buff
     * @return int|bool
     */
    public function parseInput($buff)
    {
        return 0;
    }

    /**
     * @param string $package
     * @return bool
     */
    public function serve($package)
    {
        $content = trim($package);

        switch ($content) {
            case 'status':
                $this->sendToClient($this->status->display() . "\n");
                break;
            case 'quit':
                $this->closeConnection($this->currentConn);
                break;
            case 'report':
                $report = $this->getReport();
                if ($report) {
                    $this->sendToClient(json_encode($report) . "\n");
                } else {
                    $this->sendToClient("get report failed\n");
                }
                break;
            case 'help':
            default:
                $this->sendToClient("input: status|quit|report\n");
                break;
        }

        return true;
    }

    /**
     * @param $message
     */
    private function alertAdmin($message)
    {
        if (empty($this->lastAlert) || time() - $this->lastAlert > self::ALERT_INTERVAL) {
            $mobile = Config::getInstance()->get('master.admin.mobile');
            if ($mobile) {
                $this->sms->sendSms($mobile, $message);
            }
        }

        $this->lastAlert = time();
    }
}