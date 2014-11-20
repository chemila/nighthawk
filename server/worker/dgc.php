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
                $this->_collect($key);
            }
        }

        return true;
    }

    /**
     * @param string $key
     * @return bool
     * @throws Exception
     */
    private function _collect($key) {
        $id = Strategy::getQueueId($this->_name, $key);
        //Core::alert('match strategy: ' . $id);

        $res = msg_send($this->_queue, Env::MSG_TYPE_TRIGGER, array($id => time()), true, false, $error);
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
        $config = Config::getInstance()->get($this->_name);
        $consumerConfig = array(
            'host' => $config['amqp.host'],
            'port' => $config['amqp.port'],
            'login' => $config['amqp.login'],
            'password' => $config['amqp.password'],
            'vhost' => $config['amqp.vhost'],
            'prefetch_count' => $config['amqp.prefetch_count'],
            'exname' => $config['amqp.exname'],
            'extype' => $config['amqp.extype'],
            'persist' => $config['amqp.persist'],
            'duable' => $config['amqp.duable'],
            'routing' => $config['amqp.routing'],
            'queue' => $config['amqp.queue'],
            'autoack' => $config['amqp.auto_ack'],
        );

        try {
            $this->_consumer = new Consumer($consumerConfig);
            $this->_consumer->prepare();
        }
        catch (\Exception $e) {
            Core::alert($e->getMessage());
            exit(1);
        }
    }
}