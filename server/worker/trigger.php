<?php
namespace NHK\Server\Worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Core;
use NHK\System\Env;
use NHK\system\Task;

class Trigger extends Worker{
    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        Task::add('getException', 1, array($this, 'dealBussiness'));
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
    public function dealBussiness($package) {
        // TODO: Implement dealBussiness() method.
        $this->_getException();
    }

    private function _getException() {
        $shm = Env::getInstance()->getShm();
        $array = shm_get_var($shm, Env::SHM_EXCEPTION_DGC);
        if (empty($array)) {
            return false;
        }
        foreach ($array as $name => $value) {
            var_dump($value);
            $count = $value[0];
            $time = $value[1];
            $freq = $value[2];
            $users = $value[3];

            if (time() - $time >= $freq[1] && $count > $freq[0]) {
                Core::alert('trigger exception now');
                shm_remove_var($shm, Env::SHM_EXCEPTION_DGC);
            }
            else {
                Core::alert('no trigger');
            }
        }
    }
} 