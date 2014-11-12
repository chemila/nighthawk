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
        Core::alert('add event: ' . $name, false);
        if (!$this->_checkEventFlag($flag)) {
            Core::alert('invalid evnet flag');

            return false;
        }

        if (!is_callable($func)) {
            Core::alert('invalid event callback');

            return false;
        }

        $key = $this->_genEventName($name, $flag);
        if (array_key_exists($key, $this->_entities)) {
            Core::alert(sprintf('event[%s] alread exist', $key));

            return false;
        }

        $this->_entities[$key] = $event = event_new();

        if (!event_set($event, $fd, $flag | EV_PERSIST, $func, $args)) {
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
            Core::alert('invalid event flag: ' . $flag);

            return false;
        }

        $key = $this->_genEventName($name, $flag);
        if (!isset($this->_entities[$key])) {
            return false;
        }

        if (event_del($this->_entities[$key])) {
            unset($this->_entities[$key]);

            return true;
        }
        else {
            Core::alert('event del failed');

            return false;
        }
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

    /**
     * @param $name
     * @param $flag
     * @return string
     */
    private function _genEventName($name, $flag) {
        $key = sprintf('%s:%s', $name, self::$_enentMap[$flag]);;

        return $key;
    }

    public function display() {
        return $this->_entities;
    }
}
