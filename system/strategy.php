<?php

namespace NHK\system;

class Strategy {

    private $_type;
    private $_data;
    private $_name;
    private $_frequency;
    private $_users;

    const DEFAULT_INTERVAL = 60;

    public function __construct($type) {
        $this->_type = $type;
    }

    public function parse($content) {
        $data = file($this->_findFile());

        foreach ($data as $line) {
            $line = trim($line);
            $array = explode(' ', $line);
            list($name, $regexp, $frequency, $users) = $array;
            if (preg_match("~$regexp~i", $content)) {
                $this->_name = $name;
                list($count, $interval) = explode('/', $frequency);
                if (!$interval) {
                    $interval = self::DEFAULT_INTERVAL;
                }
                $this->_frequency = array($count, $interval);
                $this->_users = $users;

                return true;
            }
        }

        return false;
    }

    private function _findFile() {
        $path = NHK_PATH_ROOT . 'data/strategy/';
        $file = $path . $this->_type;
        if (!file_exists($file)) {
            throw new Exception('get file error: ' . $this->_type);
        }

        return $file;
    }

    public function getFrequency() {
        return $this->_frequency;
    }

    public function getName() {
        return $this->_name;
    }

    public function getUsers() {
        return $this->_users;
    }
} 