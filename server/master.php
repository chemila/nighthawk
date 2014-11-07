<?php
namespace NHK\Server;

use NHK\Server\Worker\Monitor;
use NHK\System\Config;
use NHK\System\Core;
use NHK\System\Env;
use NHK\System\Log;
use NHK\System\Process;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Master
 *
 * @package NHK\Server
 */
class Master {
    const MAX_CHILDREN = 10;
    const ERROR_FORK = 32;

    /**
     *
     */
    const ERROR_DAEMONIZE = 1;
    /**
     *
     */
    const ERROR_SAVE_PID = 2;
    /**
     *
     */
    const ERROR_INSTALL_SIGNAL = 4;
    /**
     *
     */
    const ERROR_ALREADY_RUNNING = 8;
    const ERROR_SOCKET_LISTEN = 16;

    private static $_sockets = array();

    /**
     * @var array
     */
    private static $_workers
        = array(
            'ctime' => 0,
            'workers' => array(),
        );
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
     *
     */
    function __construct() {
        $this->_IPCKey = Env::getInstance()->getIPCKey();
        $this->_shm = shm_attach($this->_IPCKey, Env::getInstance()->getSHMSize());
        $this->_msg = msg_get_queue($this->_IPCKey);
        $this->_pidFile = Env::getInstance()->getPIDFile();
        Process::setProcessTitle(Core::PRODUCT_NAME . ':master');
    }

    /**
     *
     */
    public function run() {
        Core::alert('start to run', false);
        $this->_daemonize();
        $this->_savePid();
        $this->_installSignal();
        $this->_spawnWorkers();
        $this->_loop();
    }

    /**
     *
     */
    private function _daemonize() {
        umask(0);

        if ($oldPid = Process::isMasterRunning()) {
            Core::alert("already running, pid: " . $oldPid, true);
            exit(self::ERROR_ALREADY_RUNNING);
        }

        $pid = pcntl_fork();
        if (-1 == $pid) {
            Core::alert('fork error: ' . posix_strerror(posix_get_last_error()));
            exit(self::ERROR_DAEMONIZE);
        }
        elseif ($pid > 0) {
            exit(0);
        }

        if (-1 == posix_setsid()) {
            Core::alert('setsid error: ' . posix_strerror(posix_get_last_error()));
            exit(self::ERROR_DAEMONIZE);
        }

        $pid2 = pcntl_fork();
        if (-1 == $pid2) {
            Core::alert('fork error: ' . posix_strerror(posix_get_last_error()));
            exit(self::ERROR_DAEMONIZE);
        }
        elseif (0 !== $pid2) {
            exit(0);
        }

        Process::closeSTD();
    }

    /**
     *
     */
    private function _savePid() {
        $this->_pid = posix_getpid();

        if (false === @file_put_contents($this->_pidFile, $this->_pid)) {
            Core::alert('cant save pid in file: ' . $this->_pidFile);
            exit(self::ERROR_SAVE_PID);
        }

        chmod($this->_pidFile, 0644);
    }

    /**
     *
     */
    private function _loop() {
        for (; ;) {
            sleep(1);
            pcntl_signal_dispatch();
        }
    }

    /**
     *
     */
    private function _installSignal() {
        foreach (self::$_sigIgnore as $signo) {
            pcntl_signal($signo, SIG_IGN);
        }

        foreach (self::$_sigHandle as $signo) {
            if (!pcntl_signal($signo, array($this, 'signalHandler'), false)) {
                Core::alert('install signal failed');
                exit(self::ERROR_INSTALL_SIGNAL);
            }
        }
    }

    /**
     * @param $signo
     * @return bool
     */
    public function signalHandler($signo) {
        switch ($signo) {
            //用户自定义信号
            case SIGUSR1: //busy
                break;
            //子进程结束信号
            case SIGCHLD:
                break;
            //中断进程
            case SIGTERM:
                Log::write('SIGTERM received');
                break;
            case SIGHUP:
                Log::write('SIGHUP received');
                break;
            case SIGQUIT:
                break;
            default:
                return false;
        }

    }

    private function _spawnWorkers() {
        $config = Config::getInstance()->getAllWorkers();

        foreach ($config as $name => $array) {
            if (isset($array['listen'])) {
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
                    exit(self::ERROR_SOCKET_LISTEN);
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
                    exit(self::ERROR_FORK);
                }
                else {
                    Core::alert('fork worker: ' . $name, false);
                }
            }
        }
    }

    private function _forkWorker($name) {
        $pid = pcntl_fork();
        pcntl_signal_dispatch();

        if (-1 === $pid) {
            Core::alert('fork worker error: ' . posix_strerror(posix_get_last_error()));

            return false;
        }

        if ($pid > 0) {
            self::$_workers[$name][$pid] = $pid;

            return $pid;
        }

        foreach (self::$_sockets as $key => $socket) {
            if ($name !== $key) {
                unset(self::$_sockets[$key]);
            }
        }

        $workerClass = Core::NHK_NAMESPACE . '\\Server\\Worker\\' . $name;
        if (!class_exists($workerClass)) {
            Core::alert('worker file not found:' . $workerClass, true);

            return false;
        }
        /** @var Worker $worker */
        $worker = new $workerClass($name);
        $worker->start();

        Process::setProcessTitle(Core::PRODUCT_NAME . '_' . $worker->getName());
        Process::closeSTD();

        return false;
    }

    private function _saveWorkers() {

    }
}