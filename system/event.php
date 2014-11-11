<?php


namespace NHK\system;


/**
 * Class Event
 *
 * @package NHK\system
 */
class Event {
    /**
     * @var bool|resource
     */
    private $_base;
    /**
     * @var array
     */
    private $_entities = array();
    /**
     * @var array
     */
    private static $_enentMap
        = array(
            EV_READ => 'onRead',
            EV_WRITE => 'onWrite',
            EV_SIGNAL => 'onReceiveSignal',
        );

    /**
     *
     */
    public function __construct() {
        $this->_base = event_base_new();
    }

    /**
     * @param       $name
     * @param       $fd
     * @param       $flag
     * @param       $func
     * @param array $args
     * @return bool
     */
    public function add($name, $fd, $flag, $func, $args = array()) {
        Core::alert('event add:'.$name, false);
        if (!$this->_checkEventFlag($flag)) {
            return false;
        }

        if (!is_callable($func)) {
            Core::alert('invalid event callback');

            return false;
        }

        $key = sprintf('%s:%s', $name, self::$_enentMap[$flag]);;
        $flag = $flag | EV_PERSIST;

        if (array_key_exists($key, $this->_entities)) {
            Core::alert(sprintf('event[%s] alread exist', $key));

            return false;
        }

        $this->_entities[$key] = $event = event_new();

        if (!event_set($event, $fd, $flag, $func, $args)) {
            Core::alert('event: set event failed, name: ' . $name);

            return false;
        }

        if (!event_base_set($event, $this->_base)) {
            Core::alert('event: associate event base failed');

            return false;
        }

        if (!event_add($event)) {
            Core::alert('event: add event failed');

            return false;
        }

        return true;
    }

    /**
     * @param $name
     * @param $flag
     * @return bool
     */
    public function remove($name, $flag) {
        if (!$this->_checkEventFlag($flag)) {
            return false;
        }

        if (event_del($name)) {
            unset($this->_entities[$name]);
            return true;
        }

        return false;
    }

    /**
     *
     */
    public function loop() {
        event_base_loop($this->_base);
    }

    /**
     * @param $flag
     * @return bool
     */
    private function _checkEventFlag($flag) {
        if (!array_key_exists($flag, self::$_enentMap)) {
            Core::alert('invalid event flag: ' . $flag);

            return false;
        }

        return true;
    }
}
