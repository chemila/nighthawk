<?php
namespace NHK\server\worker;
defined('NHK_PATH_ROOT') or die('No direct script access.');

use NHK\Server\Worker;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Exception;
use NHK\system\Task;
use NHK\Vendor\rabbitmq\Consumer;

class DGC extends Worker {
    /**
     * @var Consumer
     */
    private $_consumer;
    /**
     * @return mixed
     */
    public function run() {
        // TODO: Implement run() method.
        $this->_prepareConsumer();
        Task::add('consume', 10, array($this, 'dealBussiness'));
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
        Core::alert('test');
    }

    private function _prepareConsumer() {
        $allConfig = Config::getInstance()->get($this->_name);
        $consumerConfig = array(
            'host' => $allConfig['amqp.host'],
            'port' => $allConfig['amqp.port'],
            'login' => $allConfig['amqp.login'],
            'password' => $allConfig['amqp.password'],
            'vhost' => $allConfig['amqp.vhost'],
            'prefetch_count' => $allConfig['amqp.prefetch_count'],
            'exname' => $allConfig['amqp.exname'],
            'extype' => $allConfig['amqp.extype'],
            'persist' => $allConfig['amqp.persist'],
            'duable' => $allConfig['amqp.duable'],
            'routing' => $allConfig['amqp.routing'],
            'queue' => $allConfig['amqp.queue'],
            'autoack' => $allConfig['amqp.auto_ack'],
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