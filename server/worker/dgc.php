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
use NHK\Vendor\rabbitmq\Consumer;

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
        Task::add('consume', 3, array($this, 'dealBussiness'));
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
            Core::alert('got: ' . $message, false);
            if ($this->_strategy->parse($message)) {
                $this->_incException($this->_strategy->getName(), $this->_strategy->getFrequency());
            }
        }
    }

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

    private function _incException($count = 1) {
        $shm = Env::getInstance()->getShm();
        $data = array();
        $name = $this->_strategy->getName();
        if (shm_has_var($shm, Env::SHM_EXCEPTION_DGC)) {
            $data = shm_get_var($shm, Env::SHM_EXCEPTION_DGC);
            if (array_key_exists($name, $data)) {
                $data[$name][0] += $count;
            }
            else {
                $data[$name][0] = $count;
                $data[$name][1] = time();
            }
        }
        else {
            $data[$name][0] = $count;
            $data[$name][1] = time();
        }

        $data[$name][2] = $this->_strategy->getFrequency();
        $data[$name][3] = $this->_strategy->getUsers();
        return shm_put_var($shm, Env::SHM_EXCEPTION_DGC, $data);
    }
}