<?php
namespace NHK\Server;

use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\system\Event;
use NHK\System\Exception;
use NHK\System\Log;
use NHK\system\Task;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Worker
 *
 * @package NHK\Server
 */
abstract class Worker {
    const EVENT_SIGNAL_PREFIX = 'SIG_';
    const EVENT_SOCKET_TCP = self::SOCKET_PROTOCAL_TCP;
    const SOCKET_PROTOCAL_TCP = 'tcp';
    const EVENT_SOCKET_UDP = 'udp';
    /**
     * @desc package preread length
     */
    const PREREAD_BUFFER_LENGTH = 4;
    /**
     * @desc max package length
     */
    const PACKAGE_MAX_LENGTH = 65507;
    /**
     * @desc buffer size
     */
    const MAX_RECEIVE_BUFF_SIZE = 1024000;
    /**
     * @desc buffer size
     */
    const MAX_SEND_BUFF_SIZE = 2024000;
    const EXIT_WAIT_TIME = 10;
    /**
     * @desc on running state
     */
    const STATE_RUNNING = 2;
    /**
     * @desc shutdonw
     */
    const STATE_SHUTDOWN = 4;
    /**
     * @var string the worker name
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
     * @var Event
     */
    protected $_eventBase;
    /**
     * @var array signals add to libevent
     */
    protected static $_signalEvents
        = array(
            SIGALRM,
            SIGHUP,
            SIGINT,
            SIGUSR1,
            SIGUSR2,
        );
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
     * @var resource current TCP connection
     */
    protected $_currentConnection;
    /**
     * @var string client address
     */
    protected $_currentClient;
    /**
     * @var int WorkerStatus
     */
    protected $_status;
    /**
     * @var int current state
     */
    protected $_runState;

    /**
     * @param string   $name
     * @param resource $socket
     * @throws Exception
     */
    function __construct($name, $socket) {
        $this->_name = $name;
        if (!is_resource($socket) || !($socketStat = socket_get_status($socket))) {
            throw new Exception('invalid socket');
        }
        socket_set_blocking($socket, 0);
        $this->_socket = $socket;
        $this->_protocal = substr($socketStat['stream_type'], 0, 3);
        $this->_isPersist = Config::getInstance()->get($name . '.persistent_connection', false);
        $this->_eventBase = new Event($this->_name);
        $this->_status = new WorkerStatus();
    }

    /**
     * @desc start to deal with worker bussiness
     */
    public function start() {
        $this->_status->startTime = time();
        $this->installSignal();
        $this->installEvent();
        Task::init($this->_eventBase);
        $this->onStart();
        $this->run();
        $this->_eventBase->loop();
        Core::alert('exit loop unexpected', true);
    }

    public function onStart() {
        Core::alert('start to run: ' . $this->_name, false);
    }

    /**
     * @desc ignore these signals
     */
    public function installSignal() {
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_IGN);
    }

    /**
     * @desc init event handlers
     */
    public function installEvent() {
        if ($this->_protocal == self::SOCKET_PROTOCAL_TCP) {
            $this->_eventBase->add(EV_READ, $this->_socket, array($this, 'accept'));
        }
        else {
            $this->_eventBase->add(EV_READ, $this->_socket, array($this, 'recvUdp'));
        }

        $this->_addSignalEvent();
    }

    /**
     * @throws Exception
     */
    protected function _addSignalEvent() {
        foreach (self::$_signalEvents as $signo) {
            $res = $this->_eventBase->add(EV_SIGNAL, $signo, array($this, 'signalHandler'), array($signo));

            if (!$res) {
                throw new Exception('add signal event failed, signal: ' . $signo);
            }
        }
    }

    /**
     * @return mixed
     */
    abstract public function run();

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
        $this->onAccept();

        if (!$this->_isPersist) {
            $preLength = self::PACKAGE_MAX_LENGTH;
        }
        else {
            $preLength = Config::getInstance()->get($this->_name . '.preread_length', 10);
        }

        $this->_bufferRecv[$current] = new Buffer($preLength);
        Core::alert('accept address:' . $address, false);
        $this->_status->incre('accepted');

        if (!$this->_eventBase->add(EV_READ, $conn, array($this, 'processTcpInput'), array($address))) {
            throw new Exception('event add failed');
        }

        return true;
    }

    /**
     * @desc a hook on tcp accept, after connection init
     */
    public function onAccept() {
    }

    /**
     * @return bool|mixed
     */
    public function getReport() {
        $shmId = Env::getInstance()->getShm();

        if (shm_has_var($shmId, Env::SHM_REPORT)) {
            return shm_get_var($shmId, Env::SHM_REPORT);
        }

        return false;
    }

    /**
     * @param $connection
     * @param $events
     * @param $args
     * @return bool
     */
    public function processTcpInput($connection, $events, $args) {
        $this->_status->incre('processRequest');

        if ($this->_runState == self::STATE_SHUTDOWN) {
            $this->stop();
            pcntl_alarm(self::EXIT_WAIT_TIME); //TODO: deal SIGALARM
            return false;
        }

        $current = intval($connection);
        $this->_currentConnection = $connection;

        /** @var Buffer $receiveBuffer */
        $receiveBuffer = $this->_bufferRecv[$current];
        $content = stream_socket_recvfrom($connection, $receiveBuffer->getRemainLength());
        if ('' == $content) {
            if (!feof($connection)) {
                //NOTE: not closed by client yet, try again
                Core::alert('connection is open, try again', false);

                return false;
            }

            $this->_status->incre('clientClosed');

            if (!$receiveBuffer->isEmpty()) {
                Core::alert('no data received, and closed by client');
            }

            Core::alert('close connection now', false);
            $this->closeConnection($current);

            return true;
        }

        Core::alert('receive message: ' . substr(trim($content), 0, 10), false);
        $result = $this->parseInput($content);

        if (false === $result || $result < 0) {
            Core::alert('parse input failed');

            return false;
        }

        $receiveBuffer->push($content, $result);
        $this->_bufferRecv[$current] = $receiveBuffer;

        if ($receiveBuffer->isDone()) {
            Core::alert('receive buffer done', false);
            try {
                $this->dealBussiness($receiveBuffer->content);
                $this->_status->incre('bussinessDone');
            }
            catch (\Exception $e) {
                $this->_status->incre('bussinessException');
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

        return true;
    }

    /**
     * @desc stop process and exit
     */
    public function stop() {
        $this->onStop();

        if ($this->_runState != self::STATE_SHUTDOWN) {
            $this->_eventBase->remove(EV_READ, $this->_socket);
            fclose($this->_socket);
            $this->_runState = self::STATE_SHUTDOWN;
        }

        if ($this->checkConnection()) {
            exit(0);
        }
    }

    /**
     * @desc a hook on stop worker
     */
    public function onStop() {
    }

    /**
     * @return bool
     */
    public function checkConnection() {
        return empty($this->_connections) || ($this->_isPersist && $this->emptyReceiver());
    }

    /**
     * @return bool
     */
    public function emptyReceiver() {
        if (empty($this->_bufferRecv)) {
            return true;
        }

        foreach ($this->_bufferRecv as $buff) {
            /** @var Buffer $buff */
            if (!$buff->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $connection
     * @return bool
     */
    protected function closeConnection($connection) {
        $connection = intval($connection);

        if ($this->_protocal != self::EVENT_SOCKET_UDP && isset($this->_connections[$connection])) {
            $this->_eventBase->remove(EV_READ, $connection);
            $this->_eventBase->remove(EV_WRITE, $connection);
            fclose($this->_connections[$connection]);
        }

        unset($this->_connections[$connection], $this->_bufferRecv[$connection], $this->_bufferSend[$connection]);

        return true;
    }

    /**
     * @param string $buff
     * @return int|false
     */
    abstract public function parseInput($buff);

    /**
     * @param string $package
     * @return bool
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

        $this->_currentClient = $address;

        return true;
    }

    /**
     * @return mixed
     */
    public function getName() {
        return $this->_name;
    }

    /**
     * @param $null
     * @param $nullr
     * @param $args
     */
    public function signalHandler($null, $nullr, $args) {
        $signo = $args[0];
        switch ($signo) {
            case SIGINT:
                $this->stop();
                break;
            case SIGHUP:
                $this->reload();
                $this->stop();
                break;
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
     * @desc put worker status in msg-queue
     */
    protected function syncStatus() {
        Core::alert('sync worker status', false);
        $this->_status->enQueue();
    }

    /**
     * @desc reload
     */
    public function reload() {
        Core::alert('reload worker now', false);
        $this->onReload();
    }

    /**
     * @desc a hook on reload process
     */
    public function onReload() {
    }

    /**
     * @param  string $content
     * @return bool
     */
    public function sendToClient($content) {
        if (self::SOCKET_PROTOCAL_TCP !== $this->_protocal) { // send UDP packages
            stream_socket_sendto($this->_socket, $content, 0, $this->_currentClient);

            return true;
        }

        $current = intval($this->_currentConnection);
        $sentLen = fwrite($this->_connections[$current], $content);
        if ($sentLen === strlen($content)) {
            return true;
        }

        $subContent = substr($content, $sentLen);
        if (empty($this->_bufferSend[$current])) { // Not connected
            $this->_bufferSend[$current] = new Buffer(strlen($subContent));
        }

        /** @var Buffer $buffer */
        $buffer = $this->_bufferSend[$current];
        $buffer->push($subContent); //TODO: limit output buffer max length
        $this->_bufferSend[$current] = $buffer;
        $this->_eventBase->add(EV_WRITE, $this->_currentConnection, array($this, 'processTcpOutput'));

        return true;
    }

    /**
     * @param $connection
     * @param $events
     * @param $args
     * @return bool
     * @throws Exception
     */
    public function processTcpOutput($connection, $events, $args) {
        $current = intval($connection);
        if (!array_key_exists($current, $this->_bufferSend)) {
            return false;
        }

        /** @var Buffer $sendBuffer */
        $sendBuffer = $this->_bufferSend[$current];
        if (feof($connection)) {
            $this->_status->incre('clientClosed');
            Core::alert('tcp connection closed by client', false);

            return false;
        }

        if ($sendBuffer->isEmpty()) {
            Core::alert('no buffer to send', false);

            return false;
        }

        $length = fwrite($connection, $sendBuffer->content);
        if ($length === $sendBuffer->length) {
            Core::alert('buffer send success', false);

            return true;
        }

        if (!$sendBuffer->pop($length)) {
            throw new Exception('invalid content length');
        }

        $this->_bufferSend[$current] = $sendBuffer;

        return true;
    }
}

/**
 * Class Buffer
 * TODO: collect buffer as array
 *
 * @package NHK\Server
 */
class Buffer {
    /**
     * @desc max string length
     */
    const MAX_LENGTH = 100000000;
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
     * @param $initLength
     */
    public function __construct($initLength) {
        $this->remainLength = $this->prereadLength = $initLength;
    }

    /**
     * @param string $stream
     * @param int    $remain
     */
    public function push($stream, $remain = 0) {
        $this->content .= $stream;
        $this->length += strlen($stream);
        $this->remainLength = (int)$remain;
    }

    /**
     * @param $length
     * @return bool
     */
    public function pop($length) {
        if ($length > $this->length) {
            return false;
        }

        $this->length -= $length;
        $this->remainLength = $this->length - $length;
        $this->content = substr($this->content, $length);

        return true;
    }

    /**
     * @return int
     */
    public function getRemainLength() {
        return $this->remainLength;
    }

    /**
     * @desc init status
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
    public function enQueue() {
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
     * @param $value
     * @return mixed
     */
    public function __set($name, $value) {
        return $this->data[$name] = $value;
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
        return json_encode($this->data);
    }

}