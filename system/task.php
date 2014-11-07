<?php
namespace NHK\system;

defined('NHK_PATH_ROOT') or die('No direct script access.');
/**
 * Class Task
 *
 * @package NHK\system
 */
class Task {
    /**
     * @var array
     */
    private $_tasks = array();
    /**
     * @var $this
     */
    public static $_instance;

    /**
     * @desc init
     */
    private function __construct() {
        pcntl_alarm(1);
        pcntl_signal(SIGALRM, array($this, 'signalHandle'), false);
    }

    /**
     * @return Task
     */
    public static function getInstance() {
        if (!self::$_instance) {
            return self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * @desc handle signal alarm
     */
    public function signalHandle() {
        if (!empty($this->_tasks)) {
            /** @var TaskEntity $entity */
            foreach ($this->_tasks as $entity) {
                if ($entity->isReady()) {
                    $entity->doIt(array());
                }
            }
        }

        pcntl_alarm(1);
    }

    /**
     * @param      $name
     * @param      $interval
     * @param      $callback
     * @param null $startTime
     * @param bool $persist
     * @return $this
     */
    public function add($name, $interval, $callback, $startTime = null, $persist = true) {
        $taskEntity = new TaskEntity($name, $interval, $callback, $startTime, $persist);
        if (!array_key_exists($name, $this->_tasks)) {
            $this->_tasks[$name] = $taskEntity;

            return true;
        }

        return false;
    }

    /**
     * @desc reset
     */
    public function clear() {
        pcntl_alarm(1);
        $this->_tasks = array();
    }
}

/**
 * Class TaskEntity
 *
 * @package NHK\system
 */
class TaskEntity {
    /**
     * @var string
     */
    public $name;
    /**
     * @var int|null
     */
    public $runTime;
    /**
     * @var bool
     */
    public $persist;
    /**
     * @var callback
     */
    public $callback;
    /**
     * @var int
     */
    public $interval;

    /**
     * @param string   $name
     * @param int      $interval
     * @param callback $callback
     * @param null|int $startTime
     * @param bool     $persist
     * @throws Exception
     */
    public function __construct($name, $interval, $callback, $startTime = null, $persist = true) {
        if (!$startTime) {
            $startTime = time();
        }

        $this->name = $name;
        $this->runTime = $startTime + $interval;
        $this->interval = $interval;

        if (!is_callable($callback)) {
            throw new Exception('invalid task callback parameter');
        }

        $this->callback = $callback;
        $this->persist = (bool)$persist;
    }

    /**
     * @return bool
     */
    public function isReady() {
        return $this->runTime <= time();
    }

    /**
     * @param $args
     */
    public function doIt($args) {
        call_user_func_array($this->callback, $args);
        if (true === $this->persist) {
            $this->runTime = time() + $this->interval;
        }
    }

    public function display() {
        return sprintf(
            "name: %s, runTime: %s, interval: %d", $this->name, date('Y-m-d H:i:s', $this->runTime),
            $this->interval
        );
    }
}