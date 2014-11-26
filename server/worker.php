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
 * @author  fuqiang(chemila@me.com)
 */
abstract class Worker
{
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
    /**
     * @desc force kill master, wait time(second)
     */
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
    protected $name;
    /**
     * @var resource
     */
    protected $socket;
    /**
     * @var bool|string
     */
    protected $persist = false;
    /**
     * @var Event
     */
    protected $eventBase;
    /**
     * @var array signals add to libevent
     */
    protected static $signalEvents
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
    protected $connections = array();
    /**
     * @var array
     */
    protected $sendBuffer = array();
    /**
     * @var array
     */
    protected $receiveBuffer = array();
    /**
     * @var resource current TCP connection
     */
    protected $currentConn;
    /**
     * @var string client address
     */
    protected $currentClient;
    /**
     * @var int WorkerStatus
     */
    protected $status;
    /**
     * @var int current state
     */
    protected $runState;
    /**
     * @var string
     */
    protected $protocal;

    /**
     * @param string   $name
     * @param resource $socket
     * @throws Exception
     */
    public function __construct($name, $socket)
    {
        $this->name = $name;
        if (!is_resource($socket) || !($socketStat = socket_get_status($socket))) {
            throw new Exception('invalid socket');
        }
        socket_set_blocking($socket, 0);
        $this->socket = $socket;
        $this->protocal = substr($socketStat['stream_type'], 0, 3);
        $this->persist = Config::getInstance()->get($name . '.persist', false);
        $this->eventBase = new Event($this->name);
        $this->status = new WorkerStatus();
    }

    /**
     * @desc start to deal with worker bussiness
     */
    public function start()
    {
        $this->status->startTime = time();
        $this->installSignal();
        $this->installEvent();
        Task::init($this->eventBase);
        $this->onStart();
        $this->run();
        $this->eventBase->loop();
        Core::alert('exit loop unexpected', true);
    }

    public function onStart()
    {
        Core::alert('start to run: ' . $this->name, false);
    }

    /**
     * @desc ignore these signals
     */
    public function installSignal()
    {
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_IGN);
    }

    /**
     * @desc init event handlers
     */
    public function installEvent()
    {
        if ($this->protocal == self::SOCKET_PROTOCAL_TCP) {
            $this->eventBase->add(EV_READ, $this->socket, array($this, 'accept'));
        } else {
            $this->eventBase->add(EV_READ, $this->socket, array($this, 'recvUdp'));
        }

        $this->addSignalEvent();
    }

    /**
     * @throws Exception
     */
    protected function addSignalEvent()
    {
        foreach (self::$signalEvents as $signo) {
            $res = $this->eventBase->add(EV_SIGNAL, $signo, array($this, 'signalHandler'), array($signo));

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
    public function accept()
    {
        $address = null;
        $conn = stream_socket_accept($this->socket, 0, $address);
        if (!$conn) {
            throw new Exception('socket connect failed');
        }

        $current = intval($conn);
        stream_set_blocking($conn, 0);
        $this->connections[$current] = $conn;
        $this->onAccept();

        if (!$this->persist) {
            $preLength = self::PACKAGE_MAX_LENGTH;
        } else {
            $preLength = Config::getInstance()->get($this->name . '.preread_length', 10);
        }

        $this->receiveBuffer[$current] = new Buffer($preLength);
        Core::alert('accept address:' . $address, false);
        $this->status->incre('accepted');

        if (!$this->eventBase->add(EV_READ, $conn, array($this, 'processTcpInput'), array($address))) {
            throw new Exception('event add failed');
        }

        return true;
    }

    /**
     * @desc a hook on tcp accept, after connection init
     */
    public function onAccept()
    {
    }

    /**
     * @param $connection
     * @param $events
     * @param $args
     * @return bool
     */
    public function processTcpInput($connection, $events, $args)
    {
        $this->status->incre('processRequest');

        if ($this->runState == self::STATE_SHUTDOWN) {
            $this->stop();
            pcntl_alarm(self::EXIT_WAIT_TIME); //TODO: deal SIGALARM
            return false;
        }

        $current = intval($connection);
        $this->currentConn = $connection;

        /** @var Buffer $receiveBuffer */
        $receiveBuffer = $this->receiveBuffer[$current];
        $content = stream_socket_recvfrom($connection, $receiveBuffer->getRemainLength());
        if ('' == $content) {
            if (!feof($connection)) {
                //NOTE: not closed by client yet, try again
                Core::alert('connection is open, try again', false);

                return false;
            }

            $this->status->incre('clientClosed');

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
        $this->receiveBuffer[$current] = $receiveBuffer;

        if ($receiveBuffer->isDone()) {
            Core::alert('receive buffer done', false);
            try {
                $this->serve($receiveBuffer->content);
                $this->status->incre('bussinessDone');
            } catch (\Exception $e) {
                $this->status->incre('bussinessException');
            }

            if ($this->persist) {
                $receiveBuffer->reset();
                $this->receiveBuffer[$current] = $receiveBuffer;

                return true;
            }

            if (isset($this->sendBuffer[$current])) {
                /** @var Buffer $sendBuffer */
                $sendBuffer = $this->sendBuffer[$current];
                if ($sendBuffer->isEmpty()) {
                    $this->closeConnection($current);
                }
            } else {
                $this->closeConnection($current);
            }
        }

        return true;
    }

    /**
     * @desc stop process and exit
     */
    public function stop()
    {
        $this->onStop();

        if ($this->runState != self::STATE_SHUTDOWN) {
            $this->eventBase->remove(EV_READ, $this->socket);
            fclose($this->socket);
            $this->runState = self::STATE_SHUTDOWN;
        }

        if ($this->checkConnection()) {
            exit(0);
        }
    }

    /**
     * @desc a hook on stop worker
     */
    public function onStop()
    {
    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
        return empty($this->connections) || ($this->persist && $this->emptyReceiver());
    }

    /**
     * @return bool
     */
    public function emptyReceiver()
    {
        if (empty($this->receiveBuffer)) {
            return true;
        }

        foreach ($this->receiveBuffer as $buff) {
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
    protected function closeConnection($connection)
    {
        $connection = intval($connection);

        if ($this->protocal != self::EVENT_SOCKET_UDP && isset($this->connections[$connection])) {
            $this->eventBase->remove(EV_READ, $connection);
            $this->eventBase->remove(EV_WRITE, $connection);
            fclose($this->connections[$connection]);
        }

        unset($this->connections[$connection], $this->receiveBuffer[$connection], $this->sendBuffer[$connection]);

        return true;
    }

    /**
     * @param string $buff
     * @return int|bool
     */
    abstract public function parseInput($buff);

    /**
     * @param string $package
     * @return bool
     */
    abstract public function serve($package);

    /**
     * @desc on udp connection
     */
    public function recvUdp()
    {
        $address = null;
        $buff = stream_socket_recvfrom($this->socket, 65536, 0, $address);
        if (false === $buff || empty($address)) {
            return false;
        }

        $this->currentClient = $address;
        $this->serve($buff);

        return true;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $null
     * @param $nullr
     * @param $args
     */
    public function signalHandler($null, $nullr, $args)
    {
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
                $this->status->consumeQueue();
                break;
            default:
                break;
        }
    }

    /**
     * @desc put worker status in msg-queue
     */
    protected function syncStatus()
    {
        Core::alert('sync worker status', false);
        $this->status->enQueue();
    }

    /**
     * @desc reload
     */
    public function reload()
    {
        Core::alert('reload worker now', false);
        $this->onReload();
    }

    /**
     * @desc a hook on reload process
     */
    public function onReload()
    {
    }

    /**
     * @param  string $content
     * @return bool
     */
    public function sendToClient($content)
    {
        if (self::SOCKET_PROTOCAL_TCP !== $this->protocal) { // send UDP packages
            stream_socket_sendto($this->socket, $content, 0, $this->currentClient);

            return true;
        }

        $current = intval($this->currentConn);
        $sentLen = fwrite($this->connections[$current], $content);
        if ($sentLen === strlen($content)) {
            return true;
        }

        $subContent = substr($content, $sentLen);
        if (empty($this->sendBuffer[$current])) { // Not connected
            $this->sendBuffer[$current] = new Buffer(strlen($subContent));
        }

        /** @var Buffer $buffer */
        $buffer = $this->sendBuffer[$current];
        $buffer->push($subContent); //TODO: limit output buffer max length
        $this->sendBuffer[$current] = $buffer;
        $this->eventBase->add(EV_WRITE, $this->currentConn, array($this, 'processTcpOutput'));

        return true;
    }

    /**
     * @param $connection
     * @param $events
     * @param $args
     * @return bool
     * @throws Exception
     */
    public function processTcpOutput($connection, $events, $args)
    {
        $current = intval($connection);
        if (!array_key_exists($current, $this->sendBuffer)) {
            return false;
        }

        /** @var Buffer $sendBuffer */
        $sendBuffer = $this->sendBuffer[$current];
        if (feof($connection)) {
            $this->status->incre('clientClosed');
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

        $this->sendBuffer[$current] = $sendBuffer;

        return true;
    }
}

/**
 * Class Buffer
 * TODO: collect buffer as array
 *
 * @package NHK\Server
 */
class Buffer
{
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
    public function __construct($initLength)
    {
        $this->remainLength = $this->prereadLength = $initLength;
    }

    /**
     * @param string $stream
     * @param int    $remain
     */
    public function push($stream, $remain = 0)
    {
        $this->content .= $stream;
        $this->length += strlen($stream);
        $this->remainLength = (int)$remain;
    }

    /**
     * @param $length
     * @return bool
     */
    public function pop($length)
    {
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
    public function getRemainLength()
    {
        return $this->remainLength;
    }

    /**
     * @desc init status
     */
    public function reset()
    {
        $this->content = '';
        $this->length = 0;
        $this->remainLength = $this->prereadLength;
    }

    /**
     * @return bool
     */
    public function isDone()
    {
        return $this->remainLength === 0;
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->content);
    }
}

/**
 * Class WorkerStatus
 *
 * @package NHK\Server
 */
class WorkerStatus
{
    /**
     * @var array
     */
    public $data = array();

    /**
     * @throws Exception
     */
    public function enQueue()
    {
        $queue = Env::getInstance()->getMsgQueue();
        $errCode = null;
        if (!msg_send($queue, Env::MSG_TYPE_STATUS, $this->data, true, false, $errCode)) {
            throw new Exception('send worker status to queue failed, try again');
        }
    }

    /**
     * @return null
     */
    public function consumeQueue()
    {
        $queue = Env::getInstance()->getMsgQueue();
        $msgType = $data = null;
        msg_receive($queue, Env::MSG_TYPE_STATUS, $msgType, 65535, $data, true);

        return $data;
    }

    /**
     * @param $name
     * @return null
     */
    public function __get($name)
    {
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
    public function __set($name, $value)
    {
        return $this->data[$name] = $value;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        return array_key_exists($name, $this->data);
    }

    /**
     * @param     $name
     * @param int $count
     * @return $this
     */
    public function incre($name, $count = 1)
    {
        if (array_key_exists($name, $this->data)) {
            $this->data[$name] += $count;
        } else {
            $this->data[$name] = $count;
        }

        return $this;
    }

    /**
     * @return array
     */
    public function display()
    {
        return json_encode($this->data);
    }

}