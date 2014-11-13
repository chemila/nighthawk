<?php
namespace NHK\System;
defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Log
 *
 * @package NHK\System
 */
class Log {
    /**
     * @param string $msg
     * @param array  $data
     * @return int
     */
    public static function write($msg, $data = array()) {
        $dir = Env::getInstance()->getLogDir() . DIRECTORY_SEPARATOR . date('Y-m-d');
        umask(0);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir . "/server.log";
        if (is_array($data)) {
            $data = json_encode($data);
        }

        $content = sprintf("%s %s %s\n", date('Y-m-d H:i:s'), $msg, $data);

        return file_put_contents($file, $content, FILE_APPEND);
    }
}