<?php
namespace NHK\Server;

use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\system\Event;
use NHK\System\Exception;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Worker
 *
 * @package NHK\Server
 */
abstract class Worker {
    const EVENT_SIGNAL_PREFIX = 'SIG_';
    /**
     *
     */
    const PREREAD_BUFFER_LENGTH = 4;
    /**
     *
     */
    const PACKAGE_MAX_LENGTH = 65507;
    /**
     *
     */
    const MAX_RECEIVE_BUFF_SIZE = 1024000;
    const MAX_SEND_BUFF_SIZE = 2024000;
    const EVENT_NAME_PREFIX = 'conn_';
    /**
     *
     */
    const EXIT_WAIT_TIME = 10;
    /**
     *
     */
    const STATE_RUNNING = 2;
    /**
     *
     */
    const STATE_SHUTDOWN = 4;

    /**
     * @var
     */
    protected $_name;
    /**
     * @var resource
     */
    protected $_socket;
    /**
     * @var bool|string
     */
    protected $_isPersist = false;
    /**
     * @var array
     */
    protected $_signalHandle = array();
    /**
     * @var Event
     */
    protected $_eventBase;
    /**
     * @var array
     */
    protected $_signalIgnore = array();
    /**
     * @var array
     */
    protected $_connections = array();
    /**
     * @var array
     */
    protected $_bufferSend = array();
    /**
     * @var array
     */
    protected $_bufferRecv = array();
    /**
     * @var
     */
    protected $_currentConn;
    /**
     * @var WorkerStatus
     */
    protected $_status;
    /**
     * @var int
     */
    protected static $count = 1;
    /**
     * @var
     */
    protected $_runState;

    /**
     * @param      $name
     * @param null $socket
     * @throws Exception
     */
    function __construct($name, $socket) {
        $this->_name = $name;

        if (!is_resource($socket)) {
            throw new Exception('invalid socket');
        }

        $this->_socket = $socket;
        $socketStat = socket_get_status($socket);
        if (!$socketStat) {
            throw new Exception('invalid socket');
        }
        socket_set_blocking($this->_socket, 0);
        $this->_protocal = substr($socketStat['stream_type'], 0, 3);
        $this->_isPersist = Config::getInstance()->get($name . '.persistent_connection', false);
        $this->_eventBase = new Event();
        $this->_status = new WorkerStatus();
    }

    /**
     *
     */
    public function start() {
        $this->_status->startTime = time();
        $this->installSignal();
        $this->installEvent();

        $this->beforeRun();
        $this->run();
        $this->afterRun();

        Core::alert('start event loop', false);
        $this->_eventBase->loop();
        Core::alert('exit loop unexpect', true);
    }

    /**
     * @return mixed
     */
    abstract public function run();

    /**
     *
     */
    protected function beforeRun() {

    }

    /**
     *
     */
    public function installSignal() {
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_IGN);
    }

    /**
     *
     */
    public function installEvent() {
        if ($this->_protocal == 'tcp') {
            $this->_eventBase->add('tcp', $this->_socket, EV_READ, array($this, 'accept'));
        }
        else {
            $this->_eventBase->add('udp', $this->_socket, EV_READ, array($this, 'recvUdp'));
        }

        $this->_eventBase->add(
            self::EVENT_SIGNAL_PREFIX . SIGUSR1, SIGUSR1, EV_SIGNAL, array($this, 'signalHandler'), array(SIGUSR1)
        );
        $this->_eventBase->add(
            self::EVENT_SIGNAL_PREFIX . SIGUSR2, SIGUSR2, EV_SIGNAL, array($this, 'signalHandler'), array(SIGUSR2)
        );
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function accept() {
        $address = null;
        $conn = stream_socket_accept($this->_socket, 0, $address);
        if (!$conn) {
            throw new Exception('socket connect failed');
        }

        $current = intval($conn);
        stream_set_blocking($conn, 0);
        $this->_connections[$current] = $conn;
        if (!$this->_isPersist) {
            $preLength = self::PACKAGE_MAX_LENGTH;
        }
        else {
            $preLength = Config::getInstance()->get($this->_name . '.preread_length', 10);
        }

        $this->_bufferRecv[$current] = new Buffer($preLength);
        Core::alert('accept address:' . $address, false);
        $this->_status->incre('acceptCount');

        if (!$this->_eventBase->add(
            self::EVENT_NAME_PREFIX . $current, $conn, EV_READ, array($this, 'processTcpInput'),
            array($address)
        )
        ) {
            throw new Exception('event add failed');
        }

        return true;
    }

    /**
     * @param $connection
     * @param $events
     * @param $args
     */
    public function processTcpInput($connection, $events, $args) {
        Core::alert('process on: ' . $args[0], false);
        $this->_status->incre('processRequest');

        if ($this->_runState == self::STATE_SHUTDOWN) {
            $this->stop();
            pcntl_alarm(self::EXIT_WAIT_TIME); //TODO: deal SIGALARM
            return false;
        }

        $current = intval($connection);

        /** @var Buffer $receiveBuffer */
        $receiveBuffer = $this->_bufferRecv[$current];
        $content = stream_socket_recvfrom($connection, $receiveBuffer->getRemainLength());
        $receiveBuffer->content .= $content;
        if ('' === $content || '' === fread($connection, $receiveBuffer->getRemainLength())) {
            if (!feof($connection)) {
                //NOTE: not closed by client yet, try again
                return false;
            }

            $this->_status->incre('clientCloseCnt');

            if (!$receiveBuffer->isEmpty()) {
                Core::alert('no data received, and closed by client');
            }

            Core::alert('close connection now', false);
            $this->closeConnection($current);
        }
        else {
            $result = $this->parseInput($content);
            Core::alert('parse input result:' . $result, false);

            if (false === $result || $result < 0) {
                Core::alert('parse input failed');

                return false;
            }

            $receiveBuffer->receive($content, $result);
            $this->_bufferRecv[$current] = $receiveBuffer;

            if ($receiveBuffer->isDone()) {
                Core::alert('receive buffer done', false);
                try {
                    $this->dealBussiness($receiveBuffer->content);
                }
                catch (\Exception $e) {
                    $this->_status->incre('businessException');
                }

                if ($this->_isPersist) {
                    Core::alert('persist connection reset', false);
                    $receiveBuffer->reset();
                    $this->_bufferRecv[$current] = $receiveBuffer;

                    return true;
                }

                if (isset($this->_bufferSend[$current])) {
                    /** @var Buffer $sendBuffer */
                    $sendBuffer = $this->_bufferSend[$current];
                    if ($sendBuffer->isEmpty()) {
                        $this->closeConnection($current);
                    }
                }
                else {
                    $this->closeConnection($current);
                }
            }
        }
    }

    /**
     * @param string $buff
     * @return int|false
     */
    abstract public function parseInput($buff);

    /**
     * @param string $package
     * @return mixed
     */
    abstract public function dealBussiness($package);

    /**
     *
     */
    public function recvUdp() {
        $address = null;
        $buff = stream_socket_recvfrom($this->_socket, 65536, 0, $address);
        if (false === $buff || empty($address)) {

            return false;
        }

    }

    /**
     *
     */
    protected function afterRun() {

    }

    /**
     * @return mixed
     */
    public function getName() {
        return $this->_name;
    }

    /**
     * @param $null
     * @param $null
     * @param $args
     */
    public function signalHandler($null, $null, $args) {
        $signo = $args[0];
        Core::alert('catch signal: ' . $signo, false);
        switch ($signo) {
            case SIGUSR1:
                $this->syncStatus();
                break;
            case SIGUSR2:
                $this->_status->consumeQueue();
                break;
            default:
                break;
        }
    }

    /**
     *
     */
    public function stop() {

    }

    /**
     *
     */
    public function reload() {

    }

    /**
     *
     */
    public function onReload() {

    }

    /**
     *
     */
    protected function syncStatus() {
        Core::alert('sync worker status', false);
        var_dump($this->_status->data);
        $this->_status->pushQueue();
    }

    /**
     *
     */
    protected function syncFiles() {
        $errorCode = 0;
        $requiredFiles = array_flip(get_included_files());
    }

    /**
     *
     */
    public function test() {
        fwrite($this->_socket, 'hello');
    }

    /**
     * @param $connection
     * @return bool
     */
    protected function closeConnection($connection) {
        $connection = intval($connection);

        if ($this->_protocal != 'udp' && isset($this->_connections[$connection])) {
            $this->_eventBase->remove(self::EVENT_NAME_PREFIX . $connection, EV_READ);
            $this->_eventBase->remove(self::EVENT_NAME_PREFIX . $connection, EV_WRITE);
            fclose($this->_connections[$connection]);
        }

        unset($this->_connections[$connection], $this->_bufferRecv[$connection], $this->_bufferSend[$connection]);

        return true;
    }
}

/**
 * Class Buffer
 *
 * @package NHK\Server
 */
class Buffer {
    /**
     * @var int
     */
    public $remainLength = 0;
    /**
     * @var int
     */
    public $length = 0;
    /**
     * @var string
     */
    public $content = '';
    /**
     * @var int
     */
    public $prereadLength = 4;
    /**
     *
     */
    const MAX_LENGTH = 100000000;

    /**
     * @param $initLength
     */
    public function __construct($initLength) {
        $this->remainLength = $this->prereadLength = $initLength;
    }

    /**
     * @param $stream
     * @param $remain
     */
    public function receive($stream, $remain) {
        $this->content .= $stream;
        $this->length += strlen($stream);
        $this->remainLength = (int)$remain;
    }

    /**
     * @return int
     */
    public function getRemainLength() {
        return $this->remainLength;
    }

    /**
     *
     */
    public function reset() {
        $this->content = '';
        $this->length = 0;
        $this->remainLength = $this->prereadLength;
    }

    /**
     * @return bool
     */
    public function isDone() {
        return $this->remainLength === 0;
    }

    /**
     * @return bool
     */
    public function isEmpty() {
        return empty($this->content);
    }
}

/**
 * Class WorkerStatus
 *
 * @package NHK\Server
 */
class WorkerStatus {
    /**
     * @var array
     */
    public $data = array();

    /**
     * @throws Exception
     */
    public function pushQueue() {
        $queue = Env::getInstance()->getMsgQueue();
        $errCode = null;
        if (!msg_send($queue, Env::MSG_TYPE_STATUS, $this->data, true, false, $errCode)) {
            throw new Exception('send worker status to queue failed, try again');
        }
    }

    /**
     * @return null
     */
    public function consumeQueue() {
        $queue = Env::getInstance()->getMsgQueue();
        $msgType = $data = null;
        msg_receive($queue, Env::MSG_TYPE_STATUS, $msgType, 65535, $data, true);

        return $data;
    }

    /**
     * @param $name
     * @param $value
     * @return mixed
     */
    public function __set($name, $value) {
        return $this->data[$name] = $value;
    }

    /**
     * @param $name
     * @return null
     */
    public function __get($name) {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        return null;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name) {
        return array_key_exists($name, $this->data);
    }

    /**
     * @param     $name
     * @param int $count
     * @return $this
     */
    public function incre($name, $count = 1) {
        if (array_key_exists($name, $this->data)) {
            $this->data[$name] += $count;
        }
        else {
            $this->data[$name] = $count;
        }

        return $this;
    }

    /**
     * @return array
     */
    public function display() {
        return $this->data;
    }
}