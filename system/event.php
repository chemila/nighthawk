<?php
namespace NHK\system;
defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Event
 *
 * @package NHK\system
 * @author fuqiang(chemila@me.com)
 */
class Event {
    /**
     * @var bool|resource
     */
    private $_base;
    /**
     * @var string
     */
    private $_name;
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
            EV_SIGNAL => 'onSignal',
        );

    public function __construct($name) {
        $this->_name = $name;
        $this->_base = event_base_new();
    }

    /**
     * @param int          $flag
     * @param resource|int $fd
     * @param callback     $func
     * @param array        $args
     * @internal param string $name
     * @return bool
     */
    public function add($flag, $fd, $func, $args = array()) {
        if (!$this->_checkEventFlag($flag)) {

            return false;
        }

        if (!is_callable($func)) {
            Core::alert('invalid event callback');

            return false;
        }

        $key = $this->_genEventName($flag, $fd);
        $this->_entities[$key] = $event = event_new();

        if (!event_set($event, $fd, $flag | EV_PERSIST, $func, $args)) {
            Core::alert('event: set event failed, name: ' . $this->_name);

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
     * @param int $flag
     * @return bool
     */
    public function remove($flag, $fd) {
        if (!$this->_checkEventFlag($flag)) {
            Core::alert('invalid event flag: ' . $flag);

            return false;
        }

        $key = $this->_genEventName($flag, $fd);
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
     * @desc base loop
     */
    public function loop() {
        event_base_loop($this->_base);
    }

    /**
     * @param int $flag
     * @return bool
     */
    private function _checkEventFlag($flag) {
        if (!array_key_exists($flag, self::$_enentMap)) {
            return false;
        }

        return true;
    }

    /**
     * @param int          $flag
     * @param int|resource $fd
     * @internal param string $name
     * @return string
     */
    private function _genEventName($flag, $fd) {
        $key = sprintf('%s:%s:%s', $this->_name, (int)$fd, self::$_enentMap[$flag]);;

        return $key;
    }

    /**
     * @return array
     */
    public function display() {
        return array_keys($this->_entities);
    }
}
