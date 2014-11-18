<?php
namespace NHK\Server\Worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Core;
use NHK\System\Log;
use NHK\System\Process;
use NHK\system\Task;

class Monitor extends Worker {
    const INTERVAL_MASTER_HEATBEAT = 60;

    /**
     * @desc main job goes here
     */
    public function run() {
        Core::alert('start to run: ' . $this->_name, false);
        Task::add('heatBeat', self::INTERVAL_MASTER_HEATBEAT, array($this, 'checkHeatBeat'));
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
    public function dealBussiness($package) {
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
            default:
                $this->sendToClient(sprintf("hey u, got it: %s\n", $content));
                break;
        }

        return true;
    }
}