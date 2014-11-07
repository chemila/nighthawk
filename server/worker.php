<?php
namespace NHK\Server;

use NHK\System\Core;
use NHK\System\Env;
use NHK\system\Event;
use NHK\System\Exception;
use NHK\System\Log;

defined('NHK_PATH_ROOT') or die('No direct script access.');

abstract class Worker {
    const EXIT_WAIT_TIME = 10;
    const MSGTYPE_STATUS = 1;

    protected $_name;
    protected $_socket;
    protected $_signalHandle = array();
    protected $_eventBase;
    protected $_signalIgnore = array();
    protected $_connections = array();

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
    }

    public function start() {
        $this->installSignal();
        $this->installEvent();

        $this->beforeRun();
        $this->run();
        $this->afterRun();

        Core::alert('start event loop');
        $this->_eventBase->loop();
        Core::alert('exit loop unexpect', true);
    }

    abstract public function run();

    protected function beforeRun() {

    }

    public function installSignal() {
        $this->_eventBase->add('test', SIGALRM, EV_SIGNAL, array($this, 'signalHandler'));

        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_IGN);
    }

    public function installEvent() {
        if ($this->_protocal == 'tcp') {
            $this->_eventBase->add('tcp', $this->_socket, EV_READ, array($this, 'accept'));
        }
        else {
            $this->_eventBase->add('udp', $this->_socket, EV_READ, array($this, 'recvUdp'));
        }
    }

    public function accept() {
        $address = null;
        $conn = stream_socket_accept($this->_socket, 0, $address);
        if (!$conn) {
            throw new Exception('socket connect failed');
        }

        $this->_connections[$address] = $conn;
        Core::alert('accept address:' . $address, false);

        $this->_eventBase->add($address, $conn, EV_READ, array($this, 'process'), array($conn, $address));
    }

    public function process($conn, $address) {
        Core::alert('process on' . $address);
        $buff = stream_socket_recvfrom($conn, 65535);
        fwrite($conn, 'received:' . $buff);
        fclose($conn);
    }

    public function recvUdp() {
        Core::alert('recvudp');
    }

    protected function afterRun() {

    }

    public function getName() {
        return $this->_name;
    }

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

    public function stop() {

    }

    public function reload() {

    }

    public function onReload() {

    }

    protected function syncStatus() {
        $shm = Env::getInstance()->getShm();
        $errorCode = 0;
        $status = array(
            'name' => 'test',
        );

        msg_send($shm, self::MSGTYPE_STATUS, $status, true, false, $errorCode);
    }

    protected function syncFiles() {
        $errorCode = 0;
        $requiredFiles = array_flip(get_included_files());
    }

    public function test() {
        fwrite($this->_socket, 'hello');
    }
}