<?php


namespace NHK\system;


class Event {
    private $_base;
    private $_entities = array();
    private static $_enentMap
        = array(
            EV_READ => 'onRead',
            EV_WRITE => 'onWrite',
            EV_SIGNAL => 'onReceiveSignal',
        );

    public function __construct() {
        $this->_base = event_base_new();
    }

    public function add($name, $fd, $flag, $func, $args = array()) {
        ;
        if (!$this->_checkEventFlag($flag)) {
            return false;
        }

        if (!is_callable($func)) {
            Core::alert('invalid event callback');

            return false;
        }
        $flag = $flag | EV_PERSIST;

        $key = sprintf('%s:%s', $name, self::$_enentMap[$flag]);;

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

    public function remove($name, $flag) {
        if (!$this->_checkEventFlag($flag)) {
            return false;
        }

        event_del($name);
        unset($this->_entities[$name]);

        return true;
    }

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
