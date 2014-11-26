<?php
namespace NHK\System;

defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Exception
 *
 * @package NHK\System
 * @author  fuqiang(chemila@me.com)
 */
class Exception extends \Exception
{
    /**
     * @param string            $message
     * @param int               $code
     * @param \Exception|string $filename
     * @param int               $lineno
     */
    public function __construct($message = "", $code = 0, $filename = __FILE__, $lineno = __LINE__)
    {
        $this->message = $message;
        $this->code = $code;
        $this->file = $filename;
        $this->line = $lineno;
    }
}