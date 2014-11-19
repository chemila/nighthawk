<?php
namespace NHK\Server\Worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Master;
use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Log;
use NHK\System\Process;
use NHK\system\Task;

class Monitor extends Worker {
    const INTERVAL_MASTER_HEATBEAT = 60;
    const DEFAULT_MAX_EXIT = 100;
    /**
     * @var
     */
    private $_shm;

    /**
     * @desc main job goes here
     */
    public function run() {
        $this->_shm = Env::getInstance()->getShm();
        Task::add('checkHeatBeat', self::INTERVAL_MASTER_HEATBEAT, array($this, 'checkHeatBeat'));
        Task::add('checkWorker', 10, array($this, 'checkWorker'));
    }

    /**
     * @desc check master heat beat
     */
    public function checkHeatBeat() {
        if ($pid = Process::isMasterRunning()) {
            Core::alert('master is running', false);
            Log::write('master is running, pid is: ' . $pid);
        }
        else {
            Core::alert('master is dead');
            Log::write('master is dead');
        }
    }

    public function checkWorker() {
        if (!$report = $this->getReport()) {
            return false;
        }

        $max = Config::getInstance()->get($this->_name . '.workers_max_exit', self::DEFAULT_MAX_EXIT);
        if (is_array($report) && isset($report[Master::REPORT_WORKER_EXIT_UNEXPECTED])) {
            $count = intval($report[Master::REPORT_WORKER_EXIT_UNEXPECTED]);
            if ($count >= $max) {
                Core::alert('too many worker exit');
            }
        }
    }

    /**
     * @return bool|mixed
     */
    public function getReport() {
        if (shm_has_var($this->_shm, Env::SHM_REPORT)) {
            return shm_get_var($this->_shm, Env::SHM_REPORT);
        }

        return false;
    }

    /**
     * @param string $buff
     * @return int|false
     */
    public function parseInput($buff) {
        // TODO: Implement parseInput() method.
        return 0;
    }

    /**
     * @param string $package
     * @return bool
     */
    public function processRemote($package) {
        // TODO: Implement dealBussiness() method.
        $content = trim($package);

        switch ($content) {
            case 'status':
                $this->sendToClient($this->_status->display() . "\n");
                break;
            case 'quit':
                $this->closeConnection($this->_currentConnection);
                break;
            case 'report':
                $report = $this->getReport();
                if ($report) {
                    $this->sendToClient(json_encode($report) . "\n");
                }
                else {
                    $this->sendToClient("get report failed\n");
                }
                break;
            case 'help':
                $this->sendToClient("put: status|quit|report|help\n");
                break;
            default:
                $this->sendToClient("see help\n");
                break;
        }

        return true;
    }
}