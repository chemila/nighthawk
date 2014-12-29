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
 * @author  fuqiang(chemila@me.com)
 */
class DGC extends Worker
{
    /**
     * @desc default batch model count
     */
    const DEFAULT_BATCH_COUNT = 100;
    /**
     * @var Consumer
     */
    private $consumer;
    /**
     * @var
     */
    private $index;
    /**
     * @var
     */
    private $batchCount;
    /**
     * @var
     */
    private $queue;

    /**
     * @return mixed
     */
    public function run()
    {
        // TODO: Implement run() method.
        $this->prepareConsumer();
        $this->queue = Env::getInstance()->getMsgQueue();
        $this->batchCount = Config::getInstance()->get($this->name . '.batch_count', self::DEFAULT_BATCH_COUNT);
        Strategy::loadData($this->name);
        Task::add('consumeLog', 1, array($this, 'consumeLog'));
    }

    /**
     * @param string $buff
     * @return int|false
     */
    public function parseInput($buff)
    {
        // TODO: Implement parseInput() method.
    }

    /**
     * @return bool
     */
    public function consumeLog()
    {
        $this->index = 0;

        while (true) {
            // TODO: Implement dealBussiness() method.
            $message = $this->consumer->get();
            $this->index++;
            if ($this->index >= $this->batchCount) {
                break;
            }

            if (empty($message)) {
                continue;
            }

            if ($key = Strategy::validate($this->name, $message)) {
                $this->collect($key, $message);
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
    private function collect($key, $details)
    {
        // TODO: save current error details in redis|database
        $id = Strategy::getQueueId($this->name, $key);
        $data = array(
            'id' => $id,
            'time' => time(),
            'details' => $details,
        );

        $res = msg_send($this->queue, Env::MSG_TYPE_TRIGGER, $data, true, false, $error);
        if (!$res) {
            throw new Exception('send msg queue failed: ' . $error);
        }

        return true;
    }

    /**
     * @param string $package
     * @return bool
     */
    public function serve($package)
    {
        // TODO: Implement processRemote() method.
    }

    /**
     * init consumer connection
     */
    private function prepareConsumer()
    {
        $config = Config::getInstance()->get($this->name . '.amqp_dev');

        try {
            $this->consumer = new Consumer($config);
            $this->consumer->prepare();
        } catch (\Exception $e) {
            Core::alert($e->getMessage());
            exit(1);
        }
    }
}