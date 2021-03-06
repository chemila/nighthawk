<?php
namespace NHK\system;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Task
 *
 * @package NHK\system
 * @author  fuqiang(chemila@me.com)
 */
class Task
{
    /**
     * @var array
     */
    private static $tasks = array();

    /**
     * @param Event $eventBase
     * @return bool
     */
    public static function init($eventBase = null)
    {
        pcntl_alarm(1);
        if ($eventBase instanceof Event) {
            return $eventBase->add(EV_SIGNAL, SIGALRM, array(__CLASS__, 'signalHandle'));
        } else {
            return pcntl_signal(SIGALRM, array(__CLASS__, 'signalHandle'), false);
        }
    }

    /**
     * @desc handle signal alarm
     */
    public static function signalHandle()
    {
        if (!empty(self::$tasks)) {
            /** @var TaskEntity $entity */
            foreach (self::$tasks as $entity) {
                if ($entity->isReady()) {
                    $entity->doIt();
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
     * @return bool
     */
    public static function add($name, $interval, $callback, $startTime = null, $persist = true, $args = array())
    {
        $taskEntity = new TaskEntity($name, $interval, $callback, $startTime, $persist, $args);
        if (!array_key_exists($name, self::$tasks)) {
            self::$tasks[$name] = $taskEntity;

            return true;
        }

        return false;
    }

    /**
     * @desc reset
     */
    public static function clear()
    {
        pcntl_alarm(1);
        self::$tasks = array();
    }

    /**
     * @return array
     */
    public static function display()
    {
        return array_keys(self::$tasks);
    }
}

/**
 * Class TaskEntity
 *
 * @package NHK\system
 */
class TaskEntity
{
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
     * @var array
     */
    public $callbackArgs = array();
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
     * @param array    $args
     * @throws Exception
     */
    public function __construct($name, $interval, $callback, $startTime = null, $persist = true, $args = array())
    {
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
        $this->callbackArgs = $args;
        $this->persist = (bool)$persist;
    }

    /**
     * @return bool
     */
    public function isReady()
    {
        return $this->runTime <= time();
    }

    /**
     * @desc call task callback
     */
    public function doIt()
    {
        call_user_func_array($this->callback, $this->callbackArgs);
        if (true === $this->persist) {
            $this->runTime = time() + $this->interval;
        }
    }

    /**
     * @return string
     */
    public function display()
    {
        return sprintf(
            "name: %s, runTime: %s, interval: %d", $this->name, date('Y-m-d H:i:s', $this->runTime),
            $this->interval
        );
    }
}