<?php
namespace NHK\Server;

use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\system\Event;
use NHK\System\Exception;
use NHK\System\Log;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Worker
 *
 * @package NHK\Server
 */
abstract class Worker {
    /**
     *
     */
    const PREREAD_BUFFER_LENGTH = 4;
    /**
     *
     */
    const PACKAGE_MAX_LENGTH = 65535;
    /**
     *
     */
    const STREAM_MAX_LENGTH = 1024000;
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
        $this->_isPersist = Config::getInstance()->get($name . 'persistent_connection', false);
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
        $this->_connections[$current] = $conn;
        if ($this->_isPersist) {
            $prereadLen = 65535;
        }
        else {
            $prereadLen = Config::getInstance()->get($this->_name . '.preread_lentgh', 4);
        }

        $this->_bufferRecv[$current] = new Buffer($prereadLen);
        Core::alert('accept address:' . $address, false);

        if (!$this->_eventBase->add($address, $conn, EV_READ, array($this, 'processTcpInput'), array($address))) {
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

        if ($this->_runState == self::STATE_SHUTDOWN) {
            $this->stop();
            pcntl_alarm(self::EXIT_WAIT_TIME); //TODO: deal SIGALARM
            return false;
        }

        $current = intval($connection);

        /** @var Buffer $receiveBuffer */
        $receiveBuffer = $this->_bufferRecv[$current];
        /** @var Buffer $sendBuffer */
        $sendBuffer = $this->_bufferSend[$current];

        $content = stream_socket_recvfrom($connection, $receiveBuffer->getRemainLength());
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
            if (false === $result || $result < 0) {
                Core::alert('parse input failed');
                return false;
            }

            $receiveBuffer->receive($content, $result);

            if ($receiveBuffer->isDone()) {
                Core::alert('receive buffer done', false);
                $this->dealBussiness($receiveBuffer->content);

                if ($this->_isPersist) {
                    $receiveBuffer->reset();
                }
                else {
                    if ($sendBuffer->isEmpty()) {
                        $this->closeConnection($current);
                    }
                }
            }
        }
    }

    /**
     * @param $buff
     * @return int|false
     */
    abstract public function parseInput($buff);

    /**
     * @param $buff
     * @return mixed
     */
    abstract public function dealBussiness($buff);

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
     * @param $signo
     */
    public function signalHandler($signo) {
        switch ($signo) {
            case SIGALRM:
                Core::alert('test');
                break;
            case SIGUSR1:
                $this->syncStatus();
                break;
            case SIGUSR2:
                $this->syncFiles();
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
        $shm = Env::getInstance()->getShm();
        $errorCode = 0;
        $status = array(
            'name' => 'test',
        );

        msg_send($shm, self::MSGTYPE_STATUS, $status, true, false, $errorCode);
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
            $this->_eventBase->remove($this->_connections[$connection], EV_READ);
            $this->_eventBase->remove($this->_connections[$connection], EV_WRITE);
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
    public $status = array();

    /**
     * @throws Exception
     */
    public function save() {
        $queue = Env::getInstance()->getMsgQueue();
        $errCode = null;
        if(!msg_send($queue, Env::MSG_TYPE_STATUS, $this->status, true, false, $errCode)) {
            throw new Exception('send worker status to queue failed, try again');
        }
    }

    /**
     * @param $name
     * @param $value
     * @return mixed
     */
    public function __set($name, $value) {
        return $this->status[$name] = $value;
    }

    /**
     * @param $name
     * @return null
     */
    public function __get($name) {
        if (array_key_exists($name, $this->status)) {
            return $this->status[$name];
        }

        return null;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name) {
        return array_key_exists($name, $this->status);
    }

    /**
     * @param     $name
     * @param int $count
     * @return $this
     */
    public function incre($name, $count = 1) {
        if (array_key_exists($name, $this->status)) {
            $this->status[$name] += $count;
        }
        else {
            $this->status[$name] = $count;
        }

        return $this;
    }
}