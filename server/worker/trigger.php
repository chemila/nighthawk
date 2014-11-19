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
    /**
     * @desc run in batch model
     */
    const DEFAULT_BATCH_COUNT = 10;
    /**
     * @var Strategy
     */
    private $_strategy;
    /**
     * @var int
     */
    private $_index;
    /**
     * @var int
     */
    private $_batchCount;
    /**
     * @var resource
     */
    private $_shm;
    /**
     * @var resource
     */
    private $_queue;
    /**
     * @var array
     */
    private $_statistics = array();

    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        $this->_batchCount = Config::getInstance()->get($this->_name . '.batch_count', self::DEFAULT_BATCH_COUNT);
        $this->_shm = Env::getInstance()->getShm();
        $this->_queue = Env::getInstance()->getMsgQueue();
        Strategy::loadData();
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

    /**
     * @return bool
     */
    public function handleException() {
        $this->_index = 0;
        $start = time();
        while (true) {
            $ret = msg_receive(
                $this->_queue, Env::MSG_TYPE_EXCEPTION, $name, 1024, $message, true, MSG_IPC_NOWAIT, $error
            );

            $this->_index++;
            if ($this->_index >= $this->_batchCount) {
                $this->_index = 0;
                break;
            }

            if (!$ret) {
                continue;
            }

            $this->_addStatistics('msgReceived');

            list($id, $time) = each($message);
            list($name, $key) = Strategy::getSectionName($id);
            $config = Strategy::getConfig($name, $key);
            list($limit, $interval) = $config['frequency'];

            if (time() - $time > $interval) {
                // Ignored this msg, expired!!!
                $this->_addStatistics('messageExpired');
                continue;
            }

            $this->_checkException($id, $limit);
        }

        $this->_addStatistics('runTime', time() - $start);

        return true;
    }

    /**
     * @param string $id
     * @param int    $limit
     * @return bool
     * @throws Exception
     */
    private function _checkException($id, $limit = 10) {
        if (shm_has_var($this->_shm, Env::SHM_EXCEPTION)) {
            $string = shm_get_var($this->_shm, Env::SHM_EXCEPTION);
            $array = unserialize($string);
            if (array_key_exists($id, $array)) {
                $array[$id] += 1;
            }
            else {
                $array[$id] = 1;
            }

            if ($array[$id] >= $limit) {
                $this->alert($id);
                unset($array[$id]); // Clear counter
            }
        }
        else {
            $this->_addStatistics('shmNoVar');
            $array = array($id => 1);
        }

        $this->_addStatistics('shmPutVar');

        return shm_put_var($this->_shm, Env::SHM_EXCEPTION, serialize($array));
    }

    /**
     * @param string $id
     */
    public function alert($id) {
        $this->_addStatistics('totalAlerts');
        list($name, $key) = Strategy::getSectionName($id);
        $config = Strategy::getConfig($name, $key);
        $alerts = $config['alerts'];
        //TODO: send email|sms
        Core::alert('trigger alert now, to: ' . json_encode($alerts));
    }

    /**
     * @param string $package
     * @return bool
     */
    public function processRemote($package) {
        $package = trim($package);
        switch ($package) {
            case 'stat':
                $state = msg_stat_queue($this->_queue);
                $this->sendToClient(json_encode($state) . "\n");
                break;
            case 'statistics':
                $this->sendToClient(json_encode($this->_statistics) . "\n");
                break;
            case 'quit':
                $this->closeConnection($this->_currentConnection);
                break;
            case 'help':
            default:
                $this->sendToClient("input: stat|statistics|quit\n");
                break;
        }
    }

    /**
     * @param     $name
     * @param int $count
     */
    private function _addStatistics($name, $count = 1) {
        if (isset($this->_statistics[$name])) {
            $this->_statistics[$name] += $count;
        }
        else {
            $this->_statistics[$name] = $count;
        }
    }
}