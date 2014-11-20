<?php


namespace NHK\system;


class Email {

    public function send($content) {
        Core::alert(__CLASS__ . $content);
    }
} 