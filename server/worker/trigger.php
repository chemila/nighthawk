<?php
namespace NHK\Server\Worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Exception;
use NHK\system\Strategy;
use NHK\system\Task;

/**
 * Class Trigger
 *
 * @package NHK\Server\Worker
 */
class Trigger extends Worker{
    /**
     * @var Strategy
     */
    private $_strategy;

    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        $this->_strategy = new Strategy('dgc');
        Task::add('triggerException', 1, array($this, 'dealBussiness'));
        Core::alert('test');
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
        $ret = msg_receive($this->_queue, Env::MSG_TYPE_EXCEPTION, $type, 1024, $message, true, MSG_IPC_NOWAIT, $error);
        if (!$ret) {
            Core::alert('no msg in queue');
            return false;
        }

        $config = $this->_strategy->getConfig();
        foreach ($config as $name => $value) {
            $key = $this->_strategy->getQueueKey($name);
            if (!array_key_exists($key, $message)) {
                continue;
            }

            list($limit, $interval) = $value['frequency'];
            $time = $message[$key];
            if (time() - $time > $interval) {
                // Ignored this msg, expired!!!
                continue;
            }

            $this->_checkException($key, $limit);
        }

        return true;
    }

    /**
     * @param     $key
     * @param int $limit
     * @return bool
     * @throws Exception
     */
    private function _checkException($key, $limit = 10) {
        // TODO: get sem lock
        if (shm_has_var($this->_shm, Env::SHM_EXCEPTION)) {
            $string = shm_get_var($this->_shm, Env::SHM_EXCEPTION);
            $array = unserialize($string);
            if (!is_array($array)) {
                throw new Exception('invalid shm exception data');
            }

            if (array_key_exists($key, $array)) {
                $array[$key] += 1;
            }
            else {
                $array[$key] = 1;
            }

            if ($array[$key] >= $limit) {
                $this->alert($key);
                unset($array[$key]); // Clear counter
            }

        }
        else {
            $array = array($key => 1);
        }

        return shm_put_var($this->_shm, Env::SHM_EXCEPTION, serialize($array));
    }

    /**
     * @param $name
     */
    public function alert($name) {
        Core::alert('trigger alert now: ' . $name);
    }
}