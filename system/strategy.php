<?php
namespace NHK\system;

/**
 * Class Strategy
 *
 * @package NHK\system
 * @author fuqiang(chemila@me.com)
 */
class Strategy {
    /**
     * @desc queue key separator
     */
    const KEY_SEPARATOR = '.';
    /**
     * @var array
     */
    private static $_data = array();

    private function __construct() {
    }

    /**
     * @param bool $name
     * @throws Exception
     * @return array
     */
    public static function loadData($name = false) {
        if ($name) {
            if (!isset(self::$_data[$name])) {
                self::$_data[$name] = self::parseFile($name);
            }

            return self::$_data[$name];
        }

        foreach (glob(NHK_PATH_ROOT . 'data/strategy/*.php') as $data) {
            $name = basename($data, '.php');
            self::$_data[$name] = self::parseFile($name);
        }

        return self::$_data;
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public static function parseFile($name) {
        $array = include(self::findFile($name));
        if (empty($array)) {
            throw new Exception('parse strategy file error');
        }

        return $array;
    }

    /**
     * @param $name
     * @return string
     * @throws Exception
     */
    public static function findFile($name) {
        $path = NHK_PATH_ROOT . 'data/strategy/';
        $file = $path . $name . '.php';
        if (!file_exists($file)) {
            throw new Exception('file not found: ' . $name);
        }

        return $file;
    }

    /**
     * @param null $key
     * @return array
     */
    public static function getConfig($name, $key = null) {
        return empty($key)
            ? self::$_data[$name]
            : self::$_data[$name][$key];
    }

    /**
     * @param $name
     * @param $content
     * @return bool|string
     * @throws Exception
     */
    public static function validate($name, $content) {
        $data = self::$_data[$name];
        foreach ($data as $key => $value) {
            if (empty($value['pattern'])) {
                continue;
            }

            $pattern = $value['pattern'];
            // TODO: match multiple strategy, trigger all not just first one
            if (preg_match($pattern, $content)) {
                return $key;
            }
        }

        return false;
    }

    /**
     * @param $name
     * @param $key
     * @return string
     */
    public static function getQueueId($name, $key) {
        return $name . self::KEY_SEPARATOR . $key;
    }

    /**
     * @param $id
     * @return mixed
     */
    public static function getSectionName($id) {
        return explode(self::KEY_SEPARATOR, $id);
    }

    /**
     * @param $id
     * @return array
     */
    public static function getConfigById($id) {
        list($name, $key) = self::getSectionName($id);
        if (!isset(self::$_data[$name])) {
            self::loadData($name);
        }

        return self::getConfig($name, $key);
    }
}