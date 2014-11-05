<?php
namespace NHK\System;
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
     * @param      $message
     * @param bool $andExit
     */
    public static function alert($message, $andExit = true) {
        if (true === $andExit) {
            printf("\033[31;40m%s\033[0m\n", $message);
            exit(1);
        }
        else {
            printf("\033[32;49m%s\033[0m\n", $message);
        }
    }

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
     * @return bool
     */
    public static function kill() {
        $pid = self::checkProcess();
        if ($pid <= 0) {
            return false;
        }

        if (!posix_kill($pid, SIGKILL)) {
            return false;
        }

        return unlink(Env::getInstance()->getPIDFile());
    }

    /**
     * @return bool
     */
    public static function reload() {
        $pid = self::checkProcess();
        if ($pid <= 0) {
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
            return false;
        }

        posix_kill($pid, SIGINT);
        $startTime = time();

        while (is_file(Env::getInstance()->getPIDFile())) {
            clearstatcache();
            usleep(1000);
            if (time() - $startTime >= $waitTime) {
                self::kill();
                usleep(500000);
                break;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function status() {
        $clientAddress = Config::getInstance()->get('monitor.listen');
        $sock = @stream_socket_client($clientAddress);
        if (!$sock) {
            self::alert("socket client address not exist");
        }
        fwrite($sock, 'status');
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
}