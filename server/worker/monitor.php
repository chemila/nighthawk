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

    public function run() {
        $task = Task::getInstance();
        $task->add('heatBeat', self::INTERVAL_MASTER_HEATBEAT, array($this, 'checkHeatBeat'));
    }

    public function checkHeatBeat() {
        if ($pid = Process::isMasterRunning()) {
            Log::write('master is running, pid is: ' . $pid);
        }
        else {
            Log::write('master is dead');
        }
    }

    /**
     * @param $buff
     * @return int|false
     */
    public function parseInput($buff) {
        // TODO: Implement parseInput() method.
        return 0;
    }

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
            default:
                $this->sendToClient(sprintf("hey u, got it: %s\n", $content));
                break;
        }
    }
}