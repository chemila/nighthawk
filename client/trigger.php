<?php
namespace NHK\Client;
/**
 * Class Trigger
 *
 * @package NHK\Client
 */
class Trigger {
    const HEADER_LEN = 7;
    /**
     * @var string
     */
    private $_address;

    /**
     * @param $host
     * @param $port
     */
    public function __construct($host, $port) {
        $this->_address = sprintf('udp://%s:%d', $host, $port);
    }

    /**
     * @param $name
     * @param $key
     * @param $msg
     * @return bool|string
     */
    public function report($name, $key, $msg) {
        $buffer = self::encode($name, $key, $msg);

        return $this->_send($buffer);
    }

    /**
     * @param $buffer
     * @return bool
     */
    private function _send($buffer) {
        $socket = stream_socket_client($this->_address);
        if (!$socket) {
            return false;
        }

        if (stream_socket_sendto($socket, $buffer) == strlen($buffer)) {
            return stream_socket_recvfrom($socket, 1024);
        }
    }

    /**
     * @param $name
     * @param $key
     * @param $msg
     * @return string
     */
    public static function encode($name, $key, $msg) {
        $header = pack('CCNC', strlen($name), strlen($key), time(), strlen($msg));

        return $header . $name . $key . $msg;
    }
}
