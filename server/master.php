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
 * @author  fuqiang(chemila@me.com)
 */
class Master
{
    const PROTOCAL_UDP = 'udp';
    const REPORT_WORKER_EXIT_UNEXPECTED = 'workerExitUnexpected';
    const REPORT_WORKER_REMOVED = 'workerRemoved';
    const REPORT_WORKER_KILLED = 'workerKilled';
    const REPORT_START_TIME = 'startTime';
    const REPORT_WORKER_TOTAL_CREATED = 'workerTotalCreated';
    const REPORT_SYS_LOAD_AVG = 'sysLoadAvg';
    const REPORT_MEMORY_USAGE = 'memoryUsage';
    const MAX_WORKER_EXCEPTION = 10;
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
    private static $sockets = array();
    /**
     * @var array
     */
    private static $workers = array();
    /**
     * @var array
     */
    private static $workersMap = array();

    /**
     * @var array
     */
    private static $signalHandles
        = array(
            SIGINT, // quit
            SIGHUP, // reload
            SIGCHLD, // wait
            SIGUSR1, // customize
        );

    /**
     * @var array
     */
    private static $ignoredSignals
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
    private $shm;
    /**
     * @var resource
     */
    private $msg;
    /**
     * @var int
     */
    private $pid;
    /**
     * @var string
     */
    private $pidFile;
    /**
     * @var int
     */
    private $runState = self::STATE_START;
    /**
     * @var array
     */
    private $statistics = array();
    /**
     * @var int
     */
    private static $workerExit = 0;

    /**
     * @desc init env
     */
    public function __construct()
    {
        $this->shm = Env::getInstance()->getShm();
        $this->msg = Env::getInstance()->getMsgQueue();
        $this->pidFile = Env::getInstance()->getPIDFile();
        Process::setProcessTitle(Core::PRODUCT_NAME . ':master');
    }

    /**
     * @desc main
     */
    public function run()
    {
        Core::alert('start to run master', false);
        $this->addReport(self::REPORT_START_TIME, time());
        $this->runState = self::STATE_START;
        $this->daemonize();
        $this->savePid();
        $this->installSignal();
        $this->spawnWorkers();
        Task::init();
        $this->loop();
    }

    /**
     * @param     $name
     * @param int $count
     * @return $this
     */
    private function addReport($name, $count = 1)
    {
        if (!array_key_exists($name, $this->statistics)) {
            $this->statistics[$name] = $count;
        } else {
            $this->statistics[$name] += $count;
        }

        $this->statistics[self::REPORT_SYS_LOAD_AVG] = sys_getloadavg();
        $this->statistics[self::REPORT_MEMORY_USAGE] = memory_get_usage();

        return $this;
    }

    /**
     * @desc daemonize master
     */
    private function daemonize()
    {
        umask(0);

        if ($oldPid = Process::isMasterRunning()) {
            Core::alert("already running, pid: " . $oldPid, true);
            exit(1);
        }

        $pid = pcntl_fork();
        if (-1 == $pid) {
            Core::alert('fork error: ' . posix_strerror(posix_get_last_error()));
            exit(1);
        } elseif ($pid > 0) {
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
        } elseif (0 !== $pid2) {
            exit(0);
        }

        Process::closeSTD();
    }

    /**
     * @desc save master pid to a file, see Env & config
     */
    private function savePid()
    {
        $this->pid = posix_getpid();

        if (false === @file_put_contents($this->pidFile, $this->pid)) {
            Core::alert('cant save pid in file: ' . $this->pidFile);
            exit(1);
        }

        chmod($this->pidFile, 0644);
    }

    /**
     * @desc setup signal handler
     */
    private function installSignal()
    {
        foreach (self::$ignoredSignals as $signo) {
            pcntl_signal($signo, SIG_IGN);
        }

        foreach (self::$signalHandles as $signo) {
            if (!pcntl_signal($signo, array($this, 'signalHandler'), false)) {
                Core::alert('install signal failed');
                exit(1);
            }
        }
    }

    /**
     * @desc fork all workers
     */
    private function spawnWorkers()
    {
        $config = Config::getInstance()->getAllWorkers();
        $maxChildren = Config::getInstance()->get('master.max_children', self::MAX_CHILDREN);

        foreach ($config as $name => $array) {
            if (isset($array['listen']) && !isset(self::$sockets[$name])) {
                $flags = strtolower(substr($array['listen'], 0, 3)) == self::PROTOCAL_UDP
                    ? STREAM_SERVER_BIND
                    : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

                self::$sockets[$name] = stream_socket_server($array['listen'], $code, $msg, $flags);
                if (!self::$sockets[$name]) {
                    Core::alert(sprintf('socket server listen failed, worker: %s, msg: %s', $name, $msg), true);
                    exit(1);
                }
            }

            if (empty(self::$workers[$name])) {
                self::$workers[$name] = array();
            }

            $children = isset($array['start_workers']) ? (int)$array['start_workers'] : 1;
            while (count(self::$workers[$name]) < min($children, $maxChildren)) {
                if (!$this->forkWorker($name)) {
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
    private function forkWorker($name)
    {
        $pid = pcntl_fork();
        pcntl_signal_dispatch();

        if (-1 === $pid) {
            Core::alert('fork worker error: ' . posix_strerror(posix_get_last_error()));

            return false;
        }

        if ($pid > 0) {
            $this->addWorker($name, $pid);

            return $pid;
        }

        $bindSocket = false;
        foreach (self::$sockets as $key => $socket) {
            if ($name !== $key) {
                unset(self::$sockets[$key]);
            } else {
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
    private function addWorker($name, $pid)
    {
        self::$workersMap[$pid] = $name;
        $this->addReport(self::REPORT_WORKER_TOTAL_CREATED);

        if (!array_key_exists($name, self::$workers)) {
            self::$workers[$name] = array($pid);
        } else {
            self::$workers[$name][$pid] = time();
        }
    }

    /**
     * @desc loop signal
     */
    private function loop()
    {
        $this->runState = self::STATE_RUNNING;
        for (; ;) {
            sleep(1);
            pcntl_signal_dispatch();
            $this->checkWorkers();
            $this->syncReport();
        }
    }

    /**
     * @desc make sure worker is running
     */
    private function checkWorkers()
    {
        $maxExit = Config::getInstance()->get('master.max_worker_exit', self::MAX_WORKER_EXCEPTION);

        while (($pid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED)) != 0) {
            if (0 != $status) {
                Core::alert('worker exit code: ' . $status);
                $this->addReport(self::REPORT_WORKER_EXIT_UNEXPECTED);
                self::$workerExit++;
                if (self::$workerExit >= $maxExit) {
                    $this->stop();
                }
            }

            $this->rmWorker($pid); // remove worker from working list

            if ($this->runState == self::STATE_SHUTDOWN) {
                if (!$this->hasWorker()) {
                    $this->clearWorker($pid);
                    Process::killMaster();
                    Core::alert('master is shutdown');
                }
            } else {
                $this->spawnWorkers();
            }
        }
    }

    /**
     * @param      $pid
     * @param null $name
     * @return bool
     */
    private function rmWorker($pid, $name = null)
    {
        if (!$name && array_key_exists($pid, self::$workersMap)) {
            $name = self::$workersMap[$pid];
        }

        unset(self::$workersMap[$pid]);
        if (!array_key_exists($name, self::$workers)) {
            return false;
        }

        $this->addReport(self::REPORT_WORKER_REMOVED);
        unset(self::$workers[$name][$pid]);

        return true;
    }

    /**
     * @desc check any worker is running or not
     * @return bool
     */
    private function hasWorker()
    {
        if (empty(self::$workers) || empty(self::$workersMap)) {
            return false;
        }

        foreach (self::$workers as $name => $pids) {
            if (!empty($pids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $pid
     */
    private function clearWorker($pid)
    {
        if (is_resource($this->shm)) {
            shm_detach($this->shm);
        }

        if (is_resource($this->msg)) {
            msg_remove_queue($this->msg);
        }

        self::$sockets = array();
        self::$workers = array();
        self::$workersMap = array();
    }

    /**
     * @return bool
     */
    private function syncReport()
    {
        $this->statistics['workers'] = self::$workers;

        return shm_put_var($this->shm, Env::SHM_STATUS, $this->statistics);
    }

    /**
     * TODO: setup all signal handlers
     *
     * @param $signo
     * @return bool
     */
    public function signalHandler($signo)
    {
        switch ($signo) {
            case SIGHUP:
                Log::write('SIGHUP received');
                Config::reload();
                $this->restartWorkers();
                break;
            case SIGINT:
                $this->stop();
                break;
            default:
                break;
        }
    }

    /**
     * @desc restart workers, kill -1|-9
     */
    private function restartWorkers()
    {
        if ($this->runState == self::STATE_SHUTDOWN) {
            return false;
        }

        $this->runState = self::STATE_RESTART;
        if (empty(self::$workers)) {
            return false;
        }

        foreach (self::$workers as $name => $pids) {
            $this->killWorker($name, true);
        }

        $this->runState = self::STATE_RUNNING;

        return true;
    }

    /**
     * @param      $name
     * @param bool $restart
     * @return bool
     * @throws Exception
     */
    private function killWorker($name, $restart = false)
    {
        Core::alert('stopping worker: ' . $name);
        if (!array_key_exists($name, self::$workers)) {
            Core::alert('worker not exist: ' . $name);

            return false;
        }

        $workerPids = self::$workers[$name];
        foreach ($workerPids as $pid => $startTime) {
            posix_kill($pid, $restart ? SIGHUP : SIGINT);
            $this->addReport(self::REPORT_WORKER_KILLED);
        }

        Task::add(
            'kill_worker_' . $name,
            Config::getInstance()->get('master.kill_wait', self::KILL_WAIT),
            array('NHK\\System\\Process', 'forceKill'), null, false,
            array($workerPids)
        );

        return true;
    }

    /**
     * @desc catch signal SIGINT to stop workers and master
     */
    private function stop()
    {
        if (empty(self::$workers)) {
            Core::alert('no worker running, exit now!');
            exit(0);
        }

        $this->runState = self::STATE_SHUTDOWN;

        foreach (self::$workers as $workerName => $pids) {
            $this->killWorker($workerName);
        }

        Core::alert('master is going to shutdown');
    }
}