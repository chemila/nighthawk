<?php
namespace NHK\System;

/**
 * Class Consumer
 *
 * @package NHK\System
 * @author  fuqiang(chemila@me.com)
 */
class Consumer
{
    /**
     * @var array
     */
    public static $exchangeTypes
        = array(
            'topic' => AMQP_EX_TYPE_TOPIC,
            'fanout' => AMQP_EX_TYPE_FANOUT,
            'header' => AMQP_EX_TYPE_HEADER,
            'direct' => AMQP_EX_TYPE_DIRECT,
        );
    /**
     * @var
     */
    protected $connection;
    /**
     * @var array
     */
    protected $config = array();
    /**
     * @var
     */
    protected $channel;
    /**
     * @var
     */
    protected $exchange;
    /**
     * @var
     */
    protected $queue;
    /**
     * @var bool
     */
    protected $prepared = false;

    /**
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        $this->config = $this->checkConfig($config);
        $this->connect();
        $this->createChannel();
    }

    /**
     * @param array $config
     * @return array
     * @throws \Exception
     */
    protected function checkConfig(array $config)
    {
        $required = array(
            'host',
            'port',
            'vhost',
            'exname',
            'extype',
            'login',
            'password',
            'persist',
            'durable',
            'auto_ack',
            'queue',
            'prefetch_count',
        );

        foreach ($required as $key) {
            if (!array_key_exists($key, $config)) {
                throw new \Exception('invalid config at:' . $key);
            }
        }

        return $config;
    }

    /**
     * @return \AMQPConnection
     */
    protected function connect()
    {
        $config = array(
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'vhost' => $this->config['vhost'],
            'login' => $this->config['login'],
            'password' => $this->config['password'],
        );

        $this->connection = new \AMQPConnection($config);

        if (!empty($this->config['persist'])) {
            $this->connection->pconnect();
        } else {
            $this->connection->connect();
        }

        return $this->connection;
    }

    /**
     * @return \AMQPChannel
     */
    protected function createChannel()
    {
        return $this->channel = new \AMQPChannel($this->connection);
    }

    public function __destruct()
    {
        if ($this->connection) {
            $this->disconnect();
        }
    }

    public function disconnect()
    {
        $this->connection->disconnect();
    }

    public function prepare()
    {
        if (!$this->prepared) {
            $this->createExchange();
            $this->declareExchange();
            $this->createQueue();
            $this->declareQueue();
            $this->bindQueue();
            $this->setDurable();
            $this->setPrefetchCount();
            $this->prepared = true;
        }

        return $this;
    }

    /**
     * @return \AMQPExchange
     * @throws \Exception
     */
    protected function createExchange()
    {
        $this->exchange = new \AMQPExchange($this->channel);
        $this->exchange->setName($this->config['exname']);
        $this->setExchangeType($this->config['extype']);

        return $this->exchange;
    }

    /**
     * @param string $type
     * @throws \Exception
     */
    protected function setExchangeType($type = '')
    {
        $type = strtolower($type);

        if (!array_key_exists($type, self::$exchangeTypes)) {
            throw new \Exception('invalid exchange type: ' . $type);
        }

        $this->exchange->setType(self::$exchangeTypes[$type]);
    }

    /**
     * @return mixed
     */
    protected function declareExchange()
    {
        return $this->exchange->declareExchange();
    }

    /**
     * @return \AMQPQueue
     */
    protected function createQueue()
    {
        $this->queue = new \AMQPQueue($this->channel);
        $this->queue->setName($this->config['queue']);

        return $this->queue;
    }

    /**
     * @return mixed
     */
    protected function declareQueue()
    {
        return $this->queue->declareQueue();
    }

    protected function bindQueue()
    {
        return $this->queue->bind($this->config['exname'], $this->config['routing']);
    }

    /**
     * @return bool
     */
    protected function setDurable()
    {
        if (!empty($this->config['duable'])) {
            $this->exchange->setFlags(AMQP_DURABLE);
            $this->queue->setFlags(AMQP_DURABLE);

            return true;
        }
    }

    /**
     * @return mixed
     */
    protected function setPrefetchCount()
    {
        return $this->channel->setPrefetchCount($this->config['prefetch_count']);
    }

    /**
     * @return bool|string
     */
    public function get()
    {
        $messge = $this->queue->get(AMQP_AUTOACK);

        if (is_object($messge)) {
            return $messge->getBody();
        }

        return false;
    }

    protected function reconnect()
    {
        if ($this->isConnected()) {
            return true;
        }

        $this->connection->reconnect();
        $this->createChannel($this->connection);

        return true;
    }

    /**
     * @return bool
     */
    protected function isConnected()
    {
        return $this->connection->isConnected() && $this->channel->isConnected();
    }
}