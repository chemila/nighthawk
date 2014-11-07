<?php
namespace NHK\Server;

use NHK\System\Core;
use NHK\System\Log;

defined('NHK_PATH_ROOT') or die('No direct script access.');

abstract class Worker {
    protected $_name;

    function __construct($name) {
        $this->_name = $name;
    }

    public function start() {
        Core::alert('start worker: ' . $this->getName(), false);
        Log::write('start worker:' . $this->getName());
    }

    public function getName() {
        return $this->_name;
    }
}