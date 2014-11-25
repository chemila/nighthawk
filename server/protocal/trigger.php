<?php
namespace NHK\server\protocal;

/**
 * Class TriggerProtocal
 *
 * @package NHK\Client
 */
class Trigger {
    const HEADER_LEN = 7;

    /**
     * @param $binary
     * @return array
     */
    public static function decode($binary) {
        $array = unpack('CnameLen/CkeyLen/Ntime/CmsgLen', $binary);
        $array['name'] = substr($binary, self::HEADER_LEN, $array['nameLen']);
        $array['key'] = substr($binary, self::HEADER_LEN + $array['nameLen'], $array['keyLen']);
        $array['msg'] = substr($binary, (int)$array['msgLen'] * -1);

        return $array;
    }
}