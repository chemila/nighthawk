<?php


namespace NHK\system;


class Sms {

    public function __construct() {

    }

    public function send($content) {
        Core::alert(__CLASS__ . $content);
    }
} 