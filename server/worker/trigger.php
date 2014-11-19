<?php
namespace NHK\Server\Worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Config;
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
class Trigger extends Worker {
    const DEFAULT_BATCH_COUNT = 10;
    /**
     * @var Strategy
     */
    private $_strategy;
    private $_index;
    private $_batchCount;
    private $_shm;
    private $_queue;

    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        $this->_strategy = new Strategy('dgc');
        $this->_batchCount = Config::getInstance()->get($this->_name . '.batch_count', self::DEFAULT_BATCH_COUNT);
        $this->_shm = Env::getInstance()->getShm();
        $this->_queue = Env::getInstance()->getMsgQueue();
        Task::add('handleException', 1, array($this, 'handleException'));
    }

    /**
     * @param string $buff
     * @return int|false
     */
    public function parseInput($buff) {
        // TODO: Implement parseInput() method.
        return 0;
    }

    public function handleException() {
        $this->_index = 0;
        while (true) {
            $ret = msg_receive(
                $this->_queue, Env::MSG_TYPE_EXCEPTION, $type, 1024, $message, true, MSG_IPC_NOWAIT, $error
            );

            $this->_index++;
            if ($this->_index >= $this->_batchCount) {
                $this->_index = 0;
                break;
            }

            if (!$ret) {
                continue;
            }

            $config = $this->_strategy->getConfig();
            foreach ($config as $name => $value) {
                $key = $this->_strategy->getQueueKey($name);

                if (!array_key_exists($key, $message)) {
                    Core::alert('invalid queue key name');
                    continue;
                }

                list($limit, $interval) = $value['frequency'];
                $time = $message[$key];
                if (time() - $time > $interval) {
                    // Ignored this msg, expired!!!
                    Core::alert('ignore this message, interval:' . $interval, false);
                    continue;
                }

                $this->_checkException($name, $key, $limit);
            }
        }

        return true;
    }

    /**
     * @param string $package
     * @return bool
     */
    public function processRemote($package) {
        switch ($package) {
            case 'stat':
                $state = msg_stat_queue($this->_queue);
                $this->sendToClient(json_encode($state) . "\n");
                break;
            case 'help':
                $this->sendToClient("stat\n");
                break;
            default:
                $this->sendToClient("see help\n");
                break;
        }
    }

    /**
     * @param string $name
     * @param string $key
     * @param int    $limit
     * @return bool
     * @throws Exception
     */
    private function _checkException($name, $key, $limit = 10) {
        if (shm_has_var($this->_shm, Env::SHM_EXCEPTION)) {
            $string = shm_get_var($this->_shm, Env::SHM_EXCEPTION);
            $array = unserialize($string);
            if (array_key_exists($key, $array)) {
                $array[$key] += 1;
            }
            else {
                $array[$key] = 1;
            }

            if ($array[$key] >= $limit) {
                $this->alert($name);
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
        $config = $this->_strategy->getConfig($name);
        $alerts = $config['alerts'];
        Core::alert('trigger alert now, to: ' . json_encode($alerts));
    }
}