<?php
namespace NHK\Server\Worker;

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

    /**
     * @param $buff
     * @return mixed
     */
    public function dealBussiness($buff) {
        // TODO: Implement dealBussiness() method.
        Core::alert('deal bussiness on: ' . $buff, false);
    }
}