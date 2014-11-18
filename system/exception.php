<?php
namespace NHK\System;

defined('NHK_PATH_ROOT') or die('No direct script access.');

class Exception extends \Exception {
    public function __construct($message = "", $code = 0, $filename = __FILE__, $lineno = __LINE__) {
        $this->message = $message;
        $this->code = $code;
        $this->file = $filename;
        $this->line = $lineno;
    }
}