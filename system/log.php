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
     * @param $msg
     */
    public static function write($msg) {
        $dir = Env::getInstance()->getLogDir() . DIRECTORY_SEPARATOR . date('Y-m-d');
        umask(0);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir . "/server.log";
        file_put_contents($file, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
    }
}