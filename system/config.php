<?php
namespace NHK\System;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Config
 *
 * @package NHK\System
 * @author  fuqiang(chemila@me.com)
 */
class Config
{
    /**
     * @var array
     */
    private $data = array();
    /**
     * @var array
     */
    private $cache = array();
    /**
     * @var $this
     */
    private static $instance;

    /**
     * @throws \Exception
     */
    private function __construct()
    {
        $fileMaster = NHK_PATH_CONF . 'master.conf';

        if (!file_exists($fileMaster)) {
            throw new \Exception("invalid master config file");
        }

        $this->data['master'] = $this->parseFile($fileMaster);

        foreach (glob(NHK_PATH_CONF . 'workers/*.conf') as $fileWorker) {
            $workerName = basename($fileWorker, '.conf');
            $this->data[$workerName] = $this->parseFile($fileWorker);
        }
    }

    /**
     * @param $file
     * @return array
     * @throws \Exception
     */
    private function parseFile($file)
    {
        $array = parse_ini_file($file, true);
        if (!is_array($array) || empty($array)) {
            throw new \Exception('Invalid configuration format');
        }

        return $array;
    }

    /**
     * @return Config
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            return self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param        $uri
     * @param string $default
     * @return string
     */
    public function get($uri, $default = null)
    {
        if (array_key_exists($uri, $this->cache)) {
            return $this->cache[$uri];
        }

        $node = $this->data;
        $paths = explode('.', $uri);
        while (!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return $default;
            }

            $node = $node[$path];
        }

        return $this->cache[$uri] = $node;
    }

    /**
     * @return array
     */
    public function getAllWorkers()
    {
        $copy = $this->data;
        unset($copy['master']);

        return $copy;
    }

    /**
     * @desc reload
     */
    public static function reload()
    {
        self::$instance = null;
        self::getInstance();
    }
}
