<?php
namespace NHK\System;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Env
 *
 * @package NHK\system
 * @author  fuqiang(chemila@me.com)
 */
class Env
{
    /**
     * @desc shared memory default size
     */
    const DEFAULT_SHM_SIZE = 655360;
    /**
     * @desc system version
     */
    const VERSION = '1.0.1';
    /**
     * @desc level warning
     */
    const ERROR_WARNING = 1;
    /**
     * @desc level fatal
     */
    const ERROR_FATAL = 2;

    const MSG_TYPE_STATUS = 1;
    const MSG_TYPE_FILES = 2;
    const MSG_TYPE_TRIGGER = 4;
    const MSG_TYPE_ALERT = 8;

    const SHM_STATUS = 1;
    const SHM_TRIGGER = 2;
    /**
     * @var array
     */
    public static $requiredExtensions
        = array(
            'posix' => true,
            'pcntl' => true,
            'sysvshm' => true,
            'sysvmsg' => true,
            'libevent' => false,
            'proctitle' => false,
            'amqp' => true,
        );
    /**
     * @var array
     */
    public static $requiredFunctions
        = array(
            'exec',
            'stream_socket_server',
            'stream_socket_client',
            'pcntl_signal_dispatch',
            'stream_select',
        );
    /**
     * @var array
     */
    public static $rlimit
        = array(
            'soft openfiles' => 1000,
            'hard openfiles' => 1000,
        );
    /**
     * @var Env
     */
    private static $instance;
    /**
     * @var array
     */
    private $errors = array();

    /**
     * @desc init
     */
    private function __construct()
    {
        chdir(NHK_PATH_ROOT);
    }

    /**
     * @return Env
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return string
     */
    public static function getVersion()
    {
        printf("%s\n", self::VERSION);
    }

    /**
     * @desc main
     */
    public function test()
    {
        $methods = get_class_methods($this);
        foreach ($methods as $name) {
            if (!preg_match('~^check\w*$~', $name)) {
                continue;
            }

            if (!$this->$name()) {
                $this->getResult();
                exit(1);
            }
        }
    }

    /**
     * @desc show errors
     */
    public function getResult()
    {
        if (empty($this->errors)) {
            printf("check env finish, no errors\n");

            return true;
        }

        foreach ($this->errors as $array) {
            $type = $array['type'];
            $msg = $array['msg'];

            if (self::ERROR_FATAL == $type) {
                printf("\033[31;40mFatal: %s\033[0m\n", $msg);
            } else {
                printf("Notice: %s\n", $msg);
            }
        }
    }

    /**
     * @return bool
     */
    public function checkVersion()
    {
        if (version_compare(PHP_VERSION, '5.3.0', '<')) {
            $this->addError('PHP version error, required >=5.3', self::ERROR_FATAL);

            return false;
        }

        return true;
    }

    /**
     * @param     $msg
     * @param int $type
     * @return $this
     */
    private function addError($msg, $type = self::ERROR_WARNING)
    {
        $this->errors[] = array(
            'type' => $type,
            'msg' => $msg
        );

        return $this;
    }

    /**
     * @return bool
     */
    public function checkExtension()
    {
        foreach (self::$requiredExtensions as $name => $required) {
            if (!extension_loaded($name)) {
                if ($required) {
                    $this->addError(sprintf('PHP extension: %s is required', $name), self::ERROR_FATAL);

                    return false;
                } else {
                    $this->addError(sprintf('PHP extension: %s not installed', $name), self::ERROR_WARNING);
                }
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function checkFunctions()
    {
        if ($disabled = ini_get("disable_functions")) {
            $array = array_flip(explode(',', $disabled));
        }

        foreach (self::$requiredFunctions as $func) {
            if (isset($array[$func])) {
                $this->addError(sprintf('function %s is required', $func), self::ERROR_FATAL);

                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function checkRlimit()
    {
        $systemLimits = posix_getrlimit();
        if (empty($systemLimits)) {
            $this->addError('cant get rlimit info', self::ERROR_FATAL);

            return false;
        }

        foreach (self::$rlimit as $name => $limit) {
            if ('unlimited' == $systemLimits[$name]) {
                continue;
            }

            if (!array_key_exists($name, $systemLimits)) {
                $this->addError('invalid rlimit setting:' . $name, self::ERROR_WARNING);
                continue;
            }

            if ($systemLimits[$name] < $limit) {
                $this->addError(
                    sprintf('rlimit %s cant be less than %d, current is %d', $name, $limit, $systemLimits[$name])
                );

                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function checkPidFile()
    {
        $pidFile = $this->getPIDFile();
        $dir = dirname($pidFile);
        if (!is_dir($dir)) {
            $this->addError('nhk.pid directory not exist', self::ERROR_FATAL);

            return false;
        }

        if (!is_writeable($dir)) {
            $this->addError('nhk.pid directory write failed', self::ERROR_FATAL);

            return false;
        }

        $pid = @file_get_contents($pidFile);
        if (!empty($pid)) {
            $this->addError('system is already running, pid is: ' . $pid, self::ERROR_WARNING);
        }

        return true;
    }

    /**
     * @return string
     */
    public function getPIDFile()
    {
        return Config::getInstance()->get('master.pid_file', NHK_PATH_ROOT . 'data/master.pid');
    }

    /**
     * @return bool
     */
    public function checkLogDir()
    {
        $dir = $this->getLogDir();

        if (!is_dir($dir)) {
            $this->addError('log directory not exit:' . $this->getLogDir(), self::ERROR_FATAL);

            return false;
        }

        if (!is_writeable($dir)) {
            $this->addError('log directory isnot writable:' . $dir, self::ERROR_FATAL);

            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    public function getLogDir()
    {
        return config::getInstance()->get('master.log_dir', NHK_PATH_ROOT . 'log' . DIRECTORY_SEPARATOR);
    }

    /**
     * @return bool
     */
    public function checkUser()
    {
        $userInfo = posix_getpwuid(posix_getuid());

        if ($userInfo['name'] !== 'root') {
            $this->addError('please run as user root', self::ERROR_FATAL);

            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    public function getIPCKey()
    {
        return Config::getInstance()->get('master.ipc_key', ftok(NHK_PATH_ROOT, 'N'));
    }

    /**
     * @return string
     */
    public function getSHMSize()
    {
        return Config::getInstance()->get('master.shm_size', self::DEFAULT_SHM_SIZE);
    }

    /**
     * @desc get message queue resource
     * @return resource
     */
    public function getMsgQueue()
    {
        return msg_get_queue($this->getIPCKey());
    }

    /**
     * @desc get shared memory resource
     * @return resource
     */
    public function getShm()
    {
        return shm_attach($this->getIPCKey(), self::DEFAULT_SHM_SIZE);
    }

    /**
     * @return resource
     */
    public function getSem()
    {
        return sem_get($this->getIPCKey(), 1);
    }
}