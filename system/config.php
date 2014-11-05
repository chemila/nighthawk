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
     * @var string
     */
    public static $fileMaster;
    /**
     * @var array
     */
    public static $config = array();
    /**
     * @var
     */
    private static $_instance;

    /**
     * @throws \Exception
     */
    private function __construct() {
        $fileMaster = NHK_PATH_CONF . 'master.conf';
        if (!file_exists($fileMaster)) {
            throw new \Exception("invalid master conf file");
        }
        self::$config['master'] = self::parseFile($fileMaster);
        self::$fileMaster = realpath($fileMaster);
        foreach (glob(NHK_PATH_CONF . 'workers/*.conf') as $fileWorker) {
            $workerName = basename($fileWorker, '.conf');
            self::$config[$workerName] = self::parseFile($fileWorker);
        }
    }

    /**
     * @param $file
     * @return array
     * @throws \Exception
     */
    protected function parseFile($file) {
        $array = parse_ini_file($file, true);
        if (!is_array($array) || empty($array)) {
            var_dump($file);
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
        $node = self::$config;
        $paths = explode('.', $uri);
        while (!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return $default;
            }

            $node = $node[$path];
        }

        return $node;
    }

    /**
     * @return array
     */
    public function getAllWorkers() {
        $copy = self::$config;
        unset($copy['master']);

        return $copy;
    }

    /**
     * @desc reload
     */
    public function reload() {
        self::$_instance = null;
        self::getInstance();
    }
}
