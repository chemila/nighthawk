#!/usr/bin/env php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'on');
ini_set('limit_memory', '512M');
ini_set('opcache.enable', false);
date_default_timezone_set('Asia/Shanghai');

if (empty($argv[1])) {
    echo "Usage: __FILE__ {start|stop|restart|reload|kill|status}" . PHP_EOL;
    exit;
}

$cmd = $argv[1];

define('APP_ROOT', realpath(__DIR__ . DIRECTORY_SEPARATOR));
define('APP_CONF', APP_ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
define('APP_CORE', APP_ROOT . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR);

chdir(APP_ROOT);

if (version_compare(PHP_VERSION, '5.3.0', '<')) {
    printf("PHP version required >= 5.3.0 \n");
    exit(1);
}

require_once APP_ROOT . 'server/master.php';
require_once APP_CORE . 'config.php';

NHK\Core\Config::getInstance();

if (!($pid_file = Man\Core\Lib\Config::get('workerman.pid_file'))) {
    $pid_file = '/var/run/workerman.pid'; //NOTE: PID save path
}
define('WORKERMAN_PID_FILE', $pid_file);

if (!($log_dir = Man\Core\Lib\Config::get('workerman.log_dir'))) { //NOTE: log dir
    $log_dir = APP_ROOT . 'logs/';
}
define('WORKERMAN_LOG_DIR', $log_dir . '/');

if (!($ipc_key = Man\Core\Lib\Config::get('workerman.ipc_key'))) { //NOTE: ipc key use ftok instead
    $ipc_key = fileinode(APP_ROOT);
}
define('IPC_KEY', $ipc_key);

if (!($shm_size = Man\Core\Lib\Config::get('workerman.shm_size'))) { //NOTE: shm size
    $shm_size = 393216;
}
define('DEFAULT_SHM_SIZE', $shm_size);

if ($cmd != 'status' && is_file(WORKERMAN_PID_FILE)) {
    if (!posix_access(WORKERMAN_PID_FILE, POSIX_W_OK)) { //NOTE: check pid file writable
        if ($stat = stat(WORKERMAN_PID_FILE)) { //NOTE: set by other user
            if (($start_pwuid = posix_getpwuid($stat['uid']))
                && ($current_pwuid = posix_getpwuid(posix_getuid()))
            ) {
                exit("\n\033[31;40mWorkerman is started by user {$start_pwuid['name']}, {$current_pwuid['name']} can not $cmd Workerman, Permission denied\033[0m\n\n\033[31;40mWorkerman $cmd failed\033[0m\n\n");
            }
        }
        exit("\033[31;40mCan not $cmd Workerman, Permission denied\033[0m\n");
    }

    if ($pid = @file_get_contents(WORKERMAN_PID_FILE)) {
        if (false === posix_kill($pid, 0)) { //NOTE: 文件存在，进程已退出，删除文件
            if (!unlink(WORKERMAN_PID_FILE)) {
                exit("\033[31;40mCan not $cmd Workerman\033[0m\n\n");
            }
        }
    }
}

if ($user_info = posix_getpwuid(posix_getuid())) { //NOTE: why root is required? kill?
    if ($user_info['name'] !== 'root') {
        exit("\033[31;40mYou should ran Workerman as root , Permission denied\033[0m\n");
    }
}

switch ($cmd) {
    case 'start':
        Man\Core\Master::run();
        break;
    case 'stop':
        $pid = @file_get_contents(WORKERMAN_PID_FILE);
        if (empty($pid)) {
            exit("\033[33;40mWorkerman not running?\033[0m\n");
        }
        stop_and_wait();
        break;
    case 'restart':
        stop_and_wait();
        Man\Core\Master::run();
        break;
    case 'reload':
        $pid = @file_get_contents(WORKERMAN_PID_FILE);
        if (empty($pid)) {
            exit("\033[33;40mWorkerman not running?\033[0m\n");
        }
        posix_kill($pid, SIGHUP);
        echo "reload Workerman\n";
        break;
    case 'kill':
        force_kill();
        force_kill();
        break;
    case 'status':
        $address = Man\Core\Lib\Config::get('Monitor.listen');
        $sock = @stream_socket_client($address);
        if (!$sock) {
            exit("\n\033[31;40mcan not connect to $address \033[0m\n\n\033[31;40mWorkerman not running\033[0m\n\n");
        }
        fwrite($sock, 'status');
        $read_fds = array($sock);
        $write_fds = $except_fds = array();
        while ($ret = stream_select($read_fds, $write_fds, $except_fds, 1)) {
            if (!$ret) {
                break;
            }
            foreach ($read_fds as $fd) {
                if ($ret_str = fread($fd, 8192)) {
                    echo $ret_str;
                }
                else {
                    exit;
                }
            }
        }
        break;
    default:
        echo "Usage: workermand {start|stop|restart|reload|kill|status}\n";
        exit;

}

function force_kill() {
    $ret = $match = array();
    exec("ps aux | grep -E '" . Man\Core\Master::NAME . ":|workermand' | grep -v grep", $ret);
    $this_pid = posix_getpid();
    $this_ppid = posix_getppid();
    foreach ($ret as $line) {
        if (preg_match("/^[\S]+\s+(\d+)\s+/", $line, $match)) {
            $tmp_pid = $match[1];
            if ($this_pid != $tmp_pid && $this_ppid != $tmp_pid) {
                posix_kill($tmp_pid, SIGKILL);
            }
        }
    }
}

function stop_and_wait($wait_time = 6) {
    $pid = @file_get_contents(WORKERMAN_PID_FILE);
    if (empty($pid)) {
        //exit("server not running?\n");
    }
    else {
        $start_time = time();
        posix_kill($pid, SIGINT);
        while (is_file(WORKERMAN_PID_FILE)) {
            clearstatcache();
            usleep(1000);
            if (time() - $start_time >= $wait_time) {
                force_kill();
                force_kill();
                unlink(WORKERMAN_PID_FILE);
                usleep(500000);
                break;
            }
        }
        echo "Workerman stoped\n";
    }
}
