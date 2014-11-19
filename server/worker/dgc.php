<?php
namespace NHK\server\worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
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
     * @var Consumer
     */
    private $_consumer;
    /**
     * @var Strategy
     */
    private $_strategy;

    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        $this->_prepareConsumer();
        $this->_strategy = new Strategy($this->_name);
        Task::add('consume', 1, array($this, 'dealBussiness'));
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
        $message = $this->_consumer->get();
        if (empty($message)) {
            Core::alert('no message receive');
        }
        else {
            if ($name = $this->_strategy->parseContent($message)) {
                Core::alert('match strategy: ' . $name);
            }
            else {
                Core::alert('no exception', false);
            }
        }
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