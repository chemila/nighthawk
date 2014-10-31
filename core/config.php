<?php
namespace NHK\Core;

class Config {

    private static $_instance;

    protected function __construct() {
    }

    public static function getInstance() {
        if (!self::$_instance) {
            return self::$_instance = new self();
        }

        return self::$_instance;
    }

    protected function parseFile() {
        $array = parse_ini_file('');
    }
}
