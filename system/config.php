<?php
namespace NHK\System;
defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Config
 *
 * @package NHK\System
 */
class Config {
    /**
     * @var array
     */
    private $_data = array();
    /**
     * @var array
     */
    private $_cache = array();
    /**
     * @var $this
     */
    private static $_instance;

    /**
     * @throws \Exception
     */
    private function __construct() {
        $fileMaster = NHK_PATH_CONF . 'master.conf';

        if (!file_exists($fileMaster)) {
            throw new \Exception("invalid master config file");
        }

        $this->_data['master'] = $this->_parseFile($fileMaster);

        foreach (glob(NHK_PATH_CONF . 'workers/*.conf') as $fileWorker) {
            $workerName = basename($fileWorker, '.conf');
            $this->_data[$workerName] = $this->_parseFile($fileWorker);
        }
    }

    /**
     * @param $file
     * @return array
     * @throws \Exception
     */
    private function _parseFile($file) {
        $array = parse_ini_file($file, true);
        if (!is_array($array) || empty($array)) {
            throw new \Exception('Invalid configuration format');
        }

        return $array;
    }

    /**
     * @return Config
     */
    public static function getInstance() {
        if (!self::$_instance) {
            return self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * @param        $uri
     * @param string $default
     * @return string
     */
    public function get($uri, $default = null) {
        if (array_key_exists($uri, $this->_cache)) {
            return $this->_cache[$uri];
        }

        $node = $this->_data;
        $paths = explode('.', $uri);
        while (!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return $default;
            }

            $node = $node[$path];
        }

        return $this->_cache[$uri] = $node;
    }

    /**
     * @return array
     */
    public function getAllWorkers() {
        $copy = $this->_data;
        unset($copy['master']);

        return $copy;
    }

    /**
     * @desc reload
     */
    public static function reload() {
        self::$_instance = null;
        self::getInstance();
    }
}
