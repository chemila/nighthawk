<?php


namespace NHK\System;


class Exception extends \Exception {
    public function __construct($message = "", $code = 0, $severity = 1, $filename = __FILE__, $lineno = __LINE__) {
    }
}