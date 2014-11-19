<?php
namespace NHK\server\worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\system\Strategy;
use NHK\system\Task;
use NHK\System\Consumer;

/**
 * Class DGC
 *
 * @package NHK\server\worker
 */
class DGC extends Worker {
    const DEFAULT_BATCH_COUNT = 100;
    /**
     * @var Consumer
     */
    private $_consumer;
    /**
     * @var Strategy
     */
    private $_strategy;
    private $_index;
    private $_batchCount;

    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        $this->_prepareConsumer();
        $this->_strategy = new Strategy($this->_name);
        $this->_batchCount = Config::getInstance()->get($this->_name . '.batch_count', self::DEFAULT_BATCH_COUNT);
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
                $this->_index = 0;
                break;
            }

            if (empty($message)) {
                continue;
            }

            if ($name = $this->_strategy->validate($message)) {
                Core::alert('match strategy: ' . $name, false);
            }
        }

        return true;
    }

    /**
     * @param string $package
     * @return bool
     */
    public function processRemote($package) {
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