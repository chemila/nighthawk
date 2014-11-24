<?php
namespace NHK\System;

use NHK\System\Core;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Process
 *
 * @package NHK\System
 */
class Process {
    /**
     *
     */
    const PROCESS_PERMISSION_ERROR = -1;
    /**
     *
     */
    const PROCESS_PID_NOT_EXIST = -2;
    /**
     *
     */
    const PROCESS_NOT_RUNNING = -3;

    /**
     * @return int
     */
    public static function checkProcess() {
        if (!posix_access(Env::getInstance()->getPIDFile(), POSIX_W_OK | POSIX_R_OK)) {
            return self::PROCESS_PERMISSION_ERROR;
        }

        $pid = @file_get_contents(Env::getInstance()->getPIDFile());
        if (empty($pid)) {
            return self::PROCESS_PID_NOT_EXIST;
        }

        if (!posix_kill($pid, 0)) {
            return self::PROCESS_NOT_RUNNING;
        }

        return $pid;
    }

    /**
     * @return bool|int
     */
    public static function isMasterRunning() {
        $pidFile = Env::getInstance()->getPIDFile();
        $oldPid = file_get_contents($pidFile);
        if ($oldPid !== false && posix_kill(trim($oldPid), 0)) {
            return $oldPid;
        }

        return false;
    }

    /**
     * @return bool
     */
    public static function killMaster() {
        $pid = self::checkProcess();
        if ($pid <= 0) {
            Core::alert('master pid file not exist');
            return false;
        }

        if (!posix_kill($pid, SIGKILL)) {
            Core::alert('kill master failed');

            return false;
        }
        else {
            Core::alert('force to kill master');
        }

        return unlink(Env::getInstance()->getPIDFile());
    }

    /**
     * @param int|array $pids
     */
    public static function forceKill($pids) {
        if (!is_array($pids)) {
            $pids = array($pids);
        }

        foreach ($pids as $pid) {
            if (!posix_kill($pid, 0)) {
                continue;
            }

            Log::write('force to kill pid: ' . $pid);
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * @return bool
     */
    public static function reload() {
        $pid = self::checkProcess();
        if ($pid <= 0) {
            Core::alert('master is not running');

            return false;
        }

        if (!posix_kill($pid, SIGHUP)) {
            return false;
        }

        return true;
    }

    /**
     * @param int $waitTime
     * @return bool
     */
    public static function stop($waitTime = 10) {
        $pid = self::checkProcess();
        if ($pid <= 0) {
            Core::alert('master is not running');
            return false;
        }

        posix_kill($pid, SIGINT);
        $startTime = time();

        while (is_file(Env::getInstance()->getPIDFile())) {
            clearstatcache();
            usleep(1000);
            if (time() - $startTime >= $waitTime) {
                self::killMaster();
                break;
            }
        }

        Core::alert('master is stopped', false);

        return true;
    }

    /**
     * @return bool
     */
    public static function status() {
        $clientAddress = Config::getInstance()->get('monitor.listen');
        $sock = @stream_socket_client($clientAddress);
        if (!$sock) {
            Core::alert("socket client address not exist");
        }
        fwrite($sock, 'report');
        $reads = array($sock);
        $writes = $exceptions = array();

        while ($ret = stream_select($reads, $writes, $exceptions, 1)) {
            if (!$ret) {
                break;
            }
            foreach ($reads as $fd) {
                if ($response = fread($fd, 8192)) {
                    echo $response;
                }
                else {
                    return true;
                }
            }
        }
    }

    /**
     * @return bool
     */
    public static function closeSTD() {
        return;
        global $STDERR, $STDOUT;

        if (Core::getDebugMode() && posix_ttyname(STDOUT)) {
            return true;
        }

        @fclose(STDOUT);
        @fclose(STDERR);

        $STDOUT = fopen('/dev/null', "rw+");
        $STDERR = fopen('/dev/null', "rw+");

        return true;
    }

    /**
     * @param $title
     * @return bool
     */
    public static function setProcessTitle($title) {
        if (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);

            return true;
        }

        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);

            return true;
        }

        return false;
    }
}