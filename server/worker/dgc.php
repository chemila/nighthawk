<?php
namespace NHK\server\worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Exception;
use NHK\system\Strategy;
use NHK\system\Task;
use NHK\System\Consumer;

/**
 * Class DGC
 *
 * @package NHK\server\worker
 * @author fuqiang(chemila@me.com)
 */
class DGC extends Worker {
    /**
     * @desc default batch model count
     */
    const DEFAULT_BATCH_COUNT = 100;
    /**
     * @var Consumer
     */
    private $_consumer;
    /**
     * @var
     */
    private $_index;
    /**
     * @var
     */
    private $_batchCount;
    /**
     * @var
     */
    private $_queue;

    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        $this->_prepareConsumer();
        $this->_queue = Env::getInstance()->getMsgQueue();
        $this->_batchCount = Config::getInstance()->get($this->_name . '.batch_count', self::DEFAULT_BATCH_COUNT);
        Strategy::loadData($this->_name);
        Task::add('consumeLog', 1, array($this, 'consumeLog'));
    }

    /**
     * @param string $buff
     * @return int|false
     */
    public function parseInput($buff) {
        // TODO: Implement parseInput() method.
    }

    /**
     * @return bool
     */
    public function consumeLog() {
        $this->_index = 0;

        while (true) {
            // TODO: Implement dealBussiness() method.
            $message = $this->_consumer->get();
            $this->_index++;
            if ($this->_index >= $this->_batchCount) {
                break;
            }

            if (empty($message)) {
                continue;
            }

            if ($key = Strategy::validate($this->_name, $message)) {
                $this->_collect($key, $message);
            }
        }

        return true;
    }

    /**
     * @param string $key
     * @param array  $details
     * @return bool
     * @throws Exception
     */
    private function _collect($key, $details) {
        // TODO: save current error details in redis|database
        $id = Strategy::getQueueId($this->_name, $key);
        $data = array(
            $id => time(),
        );

        $res = msg_send($this->_queue, Env::MSG_TYPE_TRIGGER, $data, true, false, $error);
        if (!$res) {
            throw new Exception('send msg queue failed: ' . $error);
        }

        return true;
    }

    /**
     * @param string $package
     * @return bool
     */
    public function serve($package) {
        // TODO: Implement processRemote() method.
    }

    /**
     * init consumer connection
     */
    private function _prepareConsumer() {
        $config = Config::getInstance()->get($this->_name . '.amqp');

        try {
            $this->_consumer = new Consumer($config);
            $this->_consumer->prepare();
        }
        catch (\Exception $e) {
            Core::alert($e->getMessage());
            exit(1);
        }
    }
}