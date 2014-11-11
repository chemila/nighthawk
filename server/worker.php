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
    const PREREAD_BUFFER_LENGTH = 4;
    const PACKAGE_MAX_LENGTH = 65535;
    const STREAM_MAX_LENGTH = 1024000;
    /**
     *
     */
    const EXIT_WAIT_TIME = 10;
    /**
     *
     */
    const MSGTYPE_STATUS = 1;

    /**
     * @var
     */
    protected $_name;
    /**
     * @var resource
     */
    protected $_socket;
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
    protected $_bufferSend = array();
    protected $_bufferRecv = array();
    protected $_currentConn;

    /**
     * @param      $name
     * @param null $socket
     * @throws Exception
     */
    function __construct($name, $socket = null) {
        $this->_name = $name;
        if (is_resource($socket)) {
            $this->_socket = $socket;
        }

        $socketStat = socket_get_status($socket);
        if (!$socketStat) {
            throw new Exception('invalid socket');
        }

        socket_set_blocking($this->_socket, 0);
        $this->_protocal = substr($socketStat['stream_type'], 0, 3);
        $this->_eventBase = new Event();
        $this->_isPersist = Config::getInstance()->get($name . 'persistent_connection', false);
    }

    /**
     *
     */
    public function start() {
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
        $this->_bufferRecv[$current] = new Buffer();
        Core::alert('accept address:' . $address, false);

        if (!$this->_eventBase->add($address, $conn, EV_READ, array($this, 'processTcpInput'), array($address))) {
            throw new Exception('event add failed');
        }

        return true;
    }

    /**
     * @param $fd
     * @param $events
     * @param $args
     */
    public function processTcpInput($fd, $events, $args) {
        Core::alert('process on: ' . $args[0], false);
        $current = intval($fd);

        /** @var Buffer $receiveBuffer */
        $receiveBuffer = $this->_bufferRecv[$current];
        /** @var Buffer $sendBuffer */
        $sendBuffer = $this->_bufferSend[$current];

        $content = stream_socket_recvfrom($fd, $receiveBuffer->getRemainLength());
        if ('' === $content || '' === fread($fd, $receiveBuffer->getRemainLength())) {
            if (!$receiveBuffer->isEmpty()) {
                Core::alert('send data failed');
            }

            Core::alert('client closed', false);
            $this->closeClient($current);
        }
        else {
            $remainLen = $this->parseInput($content);
            $receiveBuffer->receive($content, $remainLen);

            if ($receiveBuffer->isDone()) {
                if ($this->_isPersist) {
                    $receiveBuffer->reset();
                }
                else {
                    if ($sendBuffer->isEmpty()) {
                        $this->closeClient($current);
                    }
                }
            }
            elseif ($remainLen > 0) {
                $receiveBuffer->remainLength = $remainLen;
            }
            else {
                Core::alert('input parse error');
                $this->closeClient($fd);
            }

            fwrite($fd, 'received:' . $receiveBuffer->content);
        }
    }

    public function parseInput($buff) {
        return '';
    }

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

    protected function closeClient($fd) {
        $fd = intval($fd);

        if ($this->_protocal != 'udp' && isset($this->_connections[$fd])) {
            $this->_eventBase->remove($this->_connections[$fd], EV_READ);
            $this->_eventBase->remove($this->_connections[$fd], EV_WRITE);
            fclose($this->_connections[$fd]);
        }

        unset($this->_connections[$fd], $this->_bufferRecv[$fd], $this->_bufferSend[$fd]);

        return true;
    }
}

class Buffer {
    public $remainLength = 0;
    public $length = 0;
    public $content = '';
    public $isInit = false;
    const MAX_LENGTH = 100000000;
    const PREREAD_LENGTH = 4;

    public function __construct() {
        $this->isInit = true;
    }

    public function receive($stream, $remain) {
        $this->content .= $stream;
        $this->length += strlen($stream);
        $this->remainLength = (int)$remain;
    }

    public function getRemainLength() {
        if ($this->isInit) {
            return self::PREREAD_LENGTH;
        }

        return $this->remainLength;
    }

    public function reset() {
        $this->content = '';
        $this->length = 0;
        $this->remainLength = self::PREREAD_LENGTH;
    }

    public function isDone() {
        return $this->remainLength === 0;
    }

    public function isEmpty() {
        return empty($this->content);
    }
}
