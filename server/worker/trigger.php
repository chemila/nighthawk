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
 * @author  fuqiang(chemila@me.com)
 */
class Trigger extends Worker
{
    /**
     * @desc run in batch model
     */
    const DEFAULT_BATCH_COUNT = 10;
    /**
     * @var int
     */
    private $index;
    /**
     * @var int
     */
    private $batchCount;
    /**
     * @var resource
     */
    private $shm;
    /**
     * @var resource
     */
    private $sem;
    /**
     * @var resource
     */
    private $queue;
    /**
     * @var array
     */
    private $statistics = array();

    /**
     * @return mixed
     */
    public function run()
    {
        // TODO: Implement run() method.
        $this->batchCount = Config::getInstance()->get($this->name . '.batch_count', self::DEFAULT_BATCH_COUNT);
        $this->shm = Env::getInstance()->getShm();
        $this->sem = Env::getInstance()->getSem();
        shm_remove($this->shm);
        $this->queue = Env::getInstance()->getMsgQueue();
        Strategy::loadData();
        Task::add('handleException', 1, array($this, 'handleException'));
    }

    /**
     * @param string $buff
     * @return int|false
     */
    public function parseInput($buff)
    {
        // TODO: Implement parseInput() method.
        return 0;
    }

    /**
     * @return bool
     */
    public function handleException()
    {
        $this->index = 0;
        $start = time();
        while (true) {
            $ret = msg_receive(
                $this->queue, Env::MSG_TYPE_TRIGGER, $name, 1024, $message, true, MSG_IPC_NOWAIT, $error
            );

            $this->index++;
            if ($this->index >= $this->batchCount) {
                break;
            }

            if (!$ret) {
                continue;
            }

            $this->_addStatistics('msgReceived');

            $id = $message['id'];
            $time = $message['time'];
            $details = $message['details'];

            list($name, $key) = Strategy::getSectionName($id);
            $config = Strategy::getConfig($name, $key);
            list($limit, $interval) = $config['frequency'];

            if (time() - $time > $interval) {
                // Ignored this msg, expired!!!
                $this->_addStatistics('messageExpired');
                continue;
            }
            $this->checkException($id, $limit, $details);
        }

        $this->_addStatistics('batchRunTime', time() - $start);

        return true;
    }

    /**
     * @param     $name
     * @param int $count
     */
    private function _addStatistics($name, $count = 1)
    {
        if (isset($this->statistics[$name])) {
            $this->statistics[$name] += $count;
        } else {
            $this->statistics[$name] = $count;
        }
    }

    /**
     * @param string $id
     * @param int    $limit
     * @param string $details
     * @throws Exception
     */
    private function checkException($id, $limit = 10, $details = '')
    {
        sem_acquire($this->sem);
        if (shm_has_var($this->shm, Env::SHM_TRIGGER)) {
            $string = shm_get_var($this->shm, Env::SHM_TRIGGER);
            $array = unserialize($string);
            if (array_key_exists($id, $array)) {
                $array[$id] += 1;
            } else {
                $array[$id] = 1;
            }

            if ($array[$id] >= $limit) {
                $this->alert($id, $details);
                unset($array[$id]); // Clear counter
            }
        } else {
            $array = array($id => 1);
        }

        $this->_addStatistics('shmPutVar');

        shm_put_var($this->shm, Env::SHM_TRIGGER, serialize($array));
        sem_release($this->sem);
    }

    /**
     * @param string $id
     * @param string $details
     * @return bool
     */
    public function alert($id, $details = null)
    {
        $this->_addStatistics('totalAlerts');
        $message = array(
            'id' => $id,
            'time' => time(),
            'details' => $details,
        );
        Core::alert('trigger alert: ' . $id);

        return msg_send($this->queue, Env::MSG_TYPE_ALERT, $message, true, false, $error);
    }

    /**
     * @param string $package
     * @return bool
     */
    public function serve($package)
    {
        $data = \NHK\server\protocal\Trigger::decode($package);
        $id = Strategy::getQueueId($data['name'], $data['key']);
        $this->sendToClient(sprintf("trigger alert id: %d", $id));

        return $this->alert($id, $data['msg']);
    }

    /**
     * @param string $package
     * @return bool
     */
    public function serveCmd($package)
    {
        $package = trim($package);
        switch ($package) {
            case 'msg_stat':
                $state = msg_stat_queue($this->queue);
                $this->sendToClient(json_encode($state) . "\n");
                break;
            case 'summary':
                $this->sendToClient(json_encode($this->statistics) . "\n");
                break;
            case 'quit':
                $this->closeConnection($this->currentConn);
                break;
            case 'help':
            default:
                $this->sendToClient("input: msg_stat|summary|quit\n");
                break;
        }
    }
}