<?php
namespace NHK\Server;

use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Log;
use NHK\System\Process;
use NHK\system\Task;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Master
 *
 * @package NHK\Server
 */
class Master {
    const MAX_CHILDREN = 10;
    const STATE_START = 1;
    const STATE_SHUTDOWN = 2;
    const STATE_RUNNING = 4;
    const STATE_RESTART = 8;
    const KILL_WAIT = 5;
    /**
     * @var array
     */
    private static $_sockets = array();

    /**
     * @var array
     */
    private static $_workers = array();
    private static $_workersMap = array();

    /**
     * @var array
     */
    private static $_sigHandle
        = array(
            SIGINT, // quit
            SIGHUP, // reload
            SIGCHLD, // wait
            SIGUSR1, // customize
        );

    /**
     * @var array
     */
    private static $_sigIgnore
        = array(
            SIGTTIN,
            SIGPIPE,
            SIGTTOU,
            SIGQUIT,
            SIGALRM,
        );
    /**
     * @var string
     */
    private $_IPCKey;

    /**
     * @var resource
     */
    private $_shm;
    /**
     * @var resource
     */
    private $_msg;
    /**
     * @var
     */
    private $_pid;
    /**
     * @var string
     */
    private $_pidFile;
    /**
     * @var int
     */
    private $_runningState = self::STATE_START;

    /**
     * @desc init env
     */
    function __construct() {
        $this->_IPCKey = Env::getInstance()->getIPCKey();
        $this->_shm = Env::getInstance()->getShm();
        $this->_msg = Env::getInstance()->getMsgQueue();
        $this->_pidFile = Env::getInstance()->getPIDFile();
        Process::setProcessTitle(Core::PRODUCT_NAME . ':master');
    }

    /**
     *
     */
    public function run() {
        Core::alert('start to run', false);
        $this->_runningState = self::STATE_START;
        $this->_daemonize();
        $this->_savePid();
        $this->_installSignal();
        $this->_spawnWorkers();
        Task::init();
        $this->_loop();
    }

    /**
     * @desc daemonize master
     */
    private function _daemonize() {
        umask(0);

        if ($oldPid = Process::isMasterRunning()) {
            Core::alert("already running, pid: " . $oldPid, true);
            exit(1);
        }

        $pid = pcntl_fork();
        if (-1 == $pid) {
            Core::alert('fork error: ' . posix_strerror(posix_get_last_error()));
            exit(1);
        }
        elseif ($pid > 0) {
            exit(0);
        }

        if (-1 == posix_setsid()) {
            Core::alert('setsid error: ' . posix_strerror(posix_get_last_error()));
            exit(1);
        }

        $pid2 = pcntl_fork();
        if (-1 == $pid2) {
            Core::alert('fork error: ' . posix_strerror(posix_get_last_error()));
            exit(1);
        }
        elseif (0 !== $pid2) {
            exit(0);
        }

        Process::closeSTD();
    }

    /**
     * @desc save master pid to a file, see Env & config
     */
    private function _savePid() {
        $this->_pid = posix_getpid();

        if (false === @file_put_contents($this->_pidFile, $this->_pid)) {
            Core::alert('cant save pid in file: ' . $this->_pidFile);
            exit(1);
        }

        chmod($this->_pidFile, 0644);
    }

    /**
     * @desc loop signal
     */
    private function _loop() {
        $this->_runningState = self::STATE_RUNNING;
        for (; ;) {
            sleep(1);
            $this->_checkWorkers();
            pcntl_signal_dispatch();
        }
    }

    /**
     * @desc setup signal handler
     */
    private function _installSignal() {
        foreach (self::$_sigIgnore as $signo) {
            pcntl_signal($signo, SIG_IGN);
        }

        foreach (self::$_sigHandle as $signo) {
            if (!pcntl_signal($signo, array($this, 'signalHandler'), false)) {
                Core::alert('install signal failed');
                exit(1);
            }
        }
    }

    /**
     * TODO: setup all signal handlers
     *
     * @param $signo
     * @return bool
     */
    public function signalHandler($signo) {
        switch ($signo) {
            case SIGUSR1:
                break;
            case SIGCHLD:
                break;
            case SIGHUP:
                Log::write('SIGHUP received');
                Config::reload();
                $this->_restartWorkers();
                break;
            case SIGINT:
                $this->_stop();
                break;
            default:
                break;
        }
    }

    /**
     * @desc fork all workers
     */
    private function _spawnWorkers() {
        $config = Config::getInstance()->getAllWorkers();
        foreach ($config as $name => $array) {
            if (isset($array['listen']) && !isset(self::$_sockets[$name])) {
                $flags = strtolower(substr($array['listen'], 0, 3)) == 'udp'
                    ?
                    STREAM_SERVER_BIND
                    :
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

                $code = 0;
                $msg = '';
                self::$_sockets[$name] = stream_socket_server($array['listen'], $code, $msg, $flags);
                if (!self::$_sockets[$name]) {
                    Core::alert('socket server listen failed, msg: ' . $msg, true);
                    exit(1);
                }
            }

            if (empty(self::$_workers[$name])) {
                self::$_workers[$name] = array();
            }

            $children = isset($array['start_workers']) ? (int)$array['start_workers'] : 1;
            while (count(self::$_workers[$name]) < min($children, self::MAX_CHILDREN)) {
                if (!$this->_forkWorker($name)) {
                    Core::alert('worker exit');
                    Log::write(sprintf('worker %s exit loop', $name));
                    exit(1);
                }
                else {
                    Core::alert('fork worker: ' . $name, false);
                }
            }
        }
    }

    /**
     * @param $name
     * @return bool
     */
    private function _forkWorker($name) {
        $pid = pcntl_fork();
        pcntl_signal_dispatch();

        if (-1 === $pid) {
            Core::alert('fork worker error: ' . posix_strerror(posix_get_last_error()));

            return false;
        }

        if ($pid > 0) {
            $this->_addWorker($name, $pid);

            return $pid;
        }

        $bindSocket = false;
        foreach (self::$_sockets as $key => $socket) {
            if ($name !== $key) {
                unset(self::$_sockets[$key]);
            }
            else {
                $bindSocket = $socket;
            }
        }

        $workerClass = Core::NHK_NAMESPACE . '\\Server\\Worker\\' . $name;
        if (!class_exists($workerClass)) {
            Core::alert('worker file not found:' . $workerClass, true);

            return false;
        }

        /** @var Worker $worker */
        $worker = new $workerClass($name, $bindSocket);
        Process::setProcessTitle(Core::PRODUCT_NAME . '_' . $worker->getName());
        Process::closeSTD();
        $worker->start();

        return false;
    }

    /**
     * @desc catch signal SIGINT to stop workers and master
     */
    private function _stop() {
        if (empty(self::$_workers)) {
            Core::alert('no worker running, exit now!', false);
            exit(0);
        }

        $this->_runningState = self::STATE_SHUTDOWN;

        foreach (self::$_workers as $workerName => $pids) {
            $this->_killWorker($workerName);
        }

        Core::alert('master exit now');
        exit(0);
    }

    /**
     * @param string $name
     * @param bool   $restart
     * @return bool
     */
    private function _killWorker($name, $restart = false) {
        if (!array_key_exists($name, self::$_workers)) {
            Core::alert('worker not exist: ' . $name);

            return false;
        }

        $workerPids = self::$_workers[$name];
        foreach ($workerPids as $pid) {
            if (posix_kill($pid, $restart ? SIGHUP : SIGINT)) {
                $this->_rmWorker($pid, $name);
            }
        }

        Task::add(
            'kill_worker_' . $name,
            self::KILL_WAIT,
            array('NHK\\System\\Process', 'forceKill'), null, false,
            array($workerPids)
        );

        return true;
    }

    public function test() {
        Core::alert('test', false);
    }

    private function _restartWorkers() {
        if ($this->_runningState == self::STATE_SHUTDOWN) {
            return false;
        }

        $this->_runningState = self::STATE_RESTART;
        if (empty(self::$_workers)) {
            return false;
        }

        foreach (self::$_workers as $name => $pids) {
            $this->_killWorker($name, true);
        }

        $this->_runningState = self::STATE_RUNNING;
    }

    private function _checkWorkers() {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED)) != 0) {
            if (0 != $status) {
                Core::alert('worker exit code: ' . $status);
            }

            $this->_clearWorker($pid);

            if ($this->_runningState == self::STATE_SHUTDOWN) {
                Core::alert('master is shutdown');
                exit(0);
            }

            $this->_spawnWorkers();
        }
    }

    private function _clearWorker($pid) {
        $this->_rmWorker($pid);
    }

    private function _addWorker($name, $pid) {
        self::$_workersMap[$pid] = $name;

        if (!array_key_exists($name, self::$_workers)) {
            self::$_workers[$name] = array($pid);
        }
        else {
            self::$_workers[$name][$pid] =  $pid;
        }
    }

    private function _rmWorker($pid, $name = null) {
        if (!$name && array_key_exists($pid, self::$_workersMap)) {
            $name = self::$_workersMap[$pid];
        }
        unset(self::$_workersMap[$pid]);
        if (!array_key_exists($name, self::$_workers)) {
            return false;
        }

        unset(self::$_workers[$name][$pid]);

        return true;
    }
}