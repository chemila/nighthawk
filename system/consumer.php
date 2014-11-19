<?php
namespace NHK\System;

/**
 * Class Consumer
 *
 * @package NHK\System
 */
class Consumer {
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
    protected $_connection;
    /**
     * @var array
     */
    protected $_config = array();
    /**
     * @var
     */
    protected $_channel;
    /**
     * @var
     */
    protected $_exchange;
    /**
     * @var
     */
    protected $_queue;
    /**
     * @var bool
     */
    protected $_isPrepared = false;

    /**
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config) {
        $this->_config = $this->_checkConfig($config);
        $this->_connect();
        $this->_createChannel();
    }

    /**
     * @param array $config
     * @return array
     * @throws \Exception
     */
    protected function _checkConfig(array $config) {
        $required = array(
            'host',
            'port',
            'vhost',
            'exname',
            'extype',
            'login',
            'password',
            'persist',
            'duable',
            'autoack',
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
    protected function _connect() {
        $config = array(
            'host' => $this->_config['host'],
            'port' => $this->_config['port'],
            'vhost' => $this->_config['vhost'],
            'login' => $this->_config['login'],
            'password' => $this->_config['password'],
        );

        $this->_connection = new \AMQPConnection($config);

        if (!empty($this->_config['persist'])) {
            $this->_connection->pconnect();
        }
        else {
            $this->_connection->connect();
        }

        return $this->_connection;
    }

    /**
     * @return \AMQPChannel
     */
    protected function _createChannel() {
        return $this->_channel = new \AMQPChannel($this->_connection);
    }

    public function __destruct() {
        if ($this->_connection) {
            $this->disconnect();
        }
    }

    public function disconnect() {
        $this->_connection->disconnect();
    }

    public function prepare() {
        if (!$this->_isPrepared) {
            $this->_createExchange();
            $this->_declareExchange();
            $this->_createQueue();
            $this->_declareQueue();
            $this->_bindQueue();
            $this->_setDurable();
            $this->_setPrefetchCount();
            $this->_isPrepared = true;
        }

        return $this;
    }

    /**
     * @return \AMQPExchange
     * @throws \Exception
     */
    protected function _createExchange() {
        $this->_exchange = new \AMQPExchange($this->_channel);
        $this->_exchange->setName($this->_config['exname']);
        $this->_setExchangeType($this->_config['extype']);

        return $this->_exchange;
    }

    /**
     * @param string $type
     * @throws \Exception
     */
    protected function _setExchangeType($type = '') {
        $type = strtolower($type);

        if (!array_key_exists($type, self::$exchangeTypes)) {
            throw new \Exception('invalid exchange type: ' . $type);
        }

        $this->_exchange->setType(self::$exchangeTypes[$type]);
    }

    /**
     * @return mixed
     */
    protected function _declareExchange() {
        return $this->_exchange->declareExchange();
    }

    /**
     * @return \AMQPQueue
     */
    protected function _createQueue() {
        $this->_queue = new \AMQPQueue($this->_channel);
        $this->_queue->setName($this->_config['queue']);

        return $this->_queue;
    }

    /**
     * @return mixed
     */
    protected function _declareQueue() {
        return $this->_queue->declareQueue();
    }

    protected function _bindQueue() {
        return $this->_queue->bind($this->_config['exname'], $this->_config['routing']);
    }

    /**
     * @return bool
     */
    protected function _setDurable() {
        if (!empty($this->_config['duable'])) {
            $this->_exchange->setFlags(AMQP_DURABLE);
            $this->_queue->setFlags(AMQP_DURABLE);

            return true;
        }
    }

    /**
     * @return mixed
     */
    protected function _setPrefetchCount() {
        return $this->_channel->setPrefetchCount($this->_config['prefetch_count']);
    }

    /**
     * @return bool|string
     */
    public function get() {
        $messge = $this->_queue->get(AMQP_AUTOACK);

        if (is_object($messge)) {
            return $messge->getBody();
        }

        return false;
    }

    protected function _reconnect() {
        if ($this->_isConnected()) {
            return true;
        }

        $this->_connection->reconnect();
        $this->_createChannel($this->_connection);

        return true;
    }

    /**
     * @return bool
     */
    protected function _isConnected() {
        return $this->_connection->isConnected() && $this->_channel->isConnected();
    }
}