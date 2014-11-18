<?php
namespace NHK\Server;

use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Exception;
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
    const PROTOCAL_UDP = 'udp';
    /**
     * @desc limit max workers children
     */
    const MAX_CHILDREN = 10;
    /**
     * @desc init state
     */
    const STATE_START = 1;
    /**
     * @desc stop
     */
    const STATE_SHUTDOWN = 2;
    /**
     * @desc master is in loop
     */
    const STATE_RUNNING = 4;
    /**
     * @decs restart workers
     */
    const STATE_RESTART = 8;
    /**
     * @desc seconds wait to force kill worker
     */
    const KILL_WAIT = 5;
    /**
     * @var array
     */
    private static $_sockets = array();
    /**
     * @var array
     */
    private static $_workers = array();
    /**
     * @var array
     */
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
     * @var resource
     */
    private $_shm;
    /**
     * @var resource
     */
    private $_msg;
    /**
     * @var int
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
     * @var array
     */
    private $_report = array();

    /**
     * @desc init env
     */
    function __construct() {
        $this->_shm = Env::getInstance()->getShm();
        $this->_msg = Env::getInstance()->getMsgQueue();
        $this->_pidFile = Env::getInstance()->getPIDFile();
        Process::setProcessTitle(Core::PRODUCT_NAME . ':master');
    }

    /**
     * @desc main
     */
    public function run() {
        Core::alert('start to run', false);
        $this->_addReport('startTime', time());
        $this->_runningState = self::STATE_START;
        $this->_daemonize();
        $this->_savePid();
        $this->_installSignal();
        $this->_spawnWorkers();
        Task::init();
        $this->_loop();
    }

    /**
     * @param     $name
     * @param int $count
     * @return $this
     */
    private function _addReport($name, $count = 1) {
        if (!array_key_exists($name, $this->_report)) {
            $this->_report[$name] = $count;
        }
        else {
            $this->_report[$name] += $count;
        }

        $this->_report['sysLoadAvg'] = sys_getloadavg();
        $this->_report['memoryUsage'] = memory_get_usage();

        return $this;
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
     * @desc fork all workers
     */
    private function _spawnWorkers() {
        $config = Config::getInstance()->getAllWorkers();
        foreach ($config as $name => $array) {
            if (isset($array['listen']) && !isset(self::$_sockets[$name])) {
                $flags = strtolower(substr($array['listen'], 0, 3)) == self::PROTOCAL_UDP
                    ? STREAM_SERVER_BIND
                    : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

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
     * @param $name
     * @param $pid
     */
    private function _addWorker($name, $pid) {
        self::$_workersMap[$pid] = $name;
        $this->_addReport('workerTotalCreated');

        if (!array_key_exists($name, self::$_workers)) {
            self::$_workers[$name] = array($pid);
        }
        else {
            self::$_workers[$name][$pid] = time();
        }
    }

    /**
     * @desc loop signal
     */
    private function _loop() {
        $this->_runningState = self::STATE_RUNNING;
        for (; ;) {
            sleep(1);
            $this->_checkWorkers();
            $this->_syncReport();
            pcntl_signal_dispatch();
        }
    }

    /**
     * @desc make sure worker is running
     */
    private function _checkWorkers() {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED)) != 0) {
            if (0 != $status) {
                Core::alert('worker exit code: ' . $status);
            }

            $this->_addReport('workerExitUnexpected');
            $this->_rmWorker($pid); // remove worker from working list

            if ($this->_runningState == self::STATE_SHUTDOWN) {
                if (!$this->_hasWorker()) {
                    $this->_clearWorker($pid);
                    Core::alert('master is shutdown');
                    exit(0);
                }
            }
            else {
                $this->_spawnWorkers();
            }
        }
    }

    /**
     * @param      $pid
     * @param null $name
     * @return bool
     */
    private function _rmWorker($pid, $name = null) {
        if (!$name && array_key_exists($pid, self::$_workersMap)) {
            $name = self::$_workersMap[$pid];
        }

        unset(self::$_workersMap[$pid]);
        if (!array_key_exists($name, self::$_workers)) {
            return false;
        }

        $this->_addReport('workerRemoved');
        unset(self::$_workers[$name][$pid]);

        return true;
    }

    /**
     * @desc check any worker is running or not
     * @return bool
     */
    private function _hasWorker() {
        if (empty(self::$_workers) || empty(self::$_workersMap)) {
            return false;
        }

        foreach (self::$_workers as $name => $pids) {
            if (!empty($pids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $pid
     */
    private function _clearWorker($pid) {
        if (is_resource($this->_shm)) {
            shm_remove($this->_shm);
        }

        if (is_resource($this->_msg)) {
            msg_remove_queue($this->_msg);
        }
    }

    /**
     * @return bool
     */
    private function _syncReport() {
        $this->_report['workers'] = self::$_workers;

        return shm_put_var($this->_shm, Env::SHM_REPORT, $this->_report);
    }

    /**
     * TODO: setup all signal handlers
     *
     * @param $signo
     * @return bool
     */
    public function signalHandler($signo) {
        switch ($signo) {
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
     * @desc restart workers, kill -1|-9
     */
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

        return true;
    }

    /**
     * @param      $name
     * @param bool $restart
     * @return bool
     * @throws Exception
     */
    private function _killWorker($name, $restart = false) {
        Core::alert('stopping worker: ' . $name);
        if (!array_key_exists($name, self::$_workers)) {
            Core::alert('worker not exist: ' . $name);

            return false;
        }

        $workerPids = self::$_workers[$name];
        foreach ($workerPids as $pid => $startTime) {
            if (!posix_kill($pid, $restart ? SIGHUP : SIGINT)) {
                throw new Exception('worker pid is invalid');
            }
            $this->_addReport('workerKilled');
        }

        Task::add(
            'kill_worker_' . $name,
            self::KILL_WAIT,
            array('NHK\\System\\Process', 'forceKill'), null, false,
            array($workerPids)
        );

        return true;
    }

    /**
     * @desc catch signal SIGINT to stop workers and master
     */
    private function _stop() {
        if (empty(self::$_workers)) {
            Core::alert('no worker running, exit now!');
            exit(0);
        }

        $this->_runningState = self::STATE_SHUTDOWN;

        foreach (self::$_workers as $workerName => $pids) {
            $this->_killWorker($workerName);
        }

        Core::alert('master is going to shutdown');
    }
}