<?php
namespace NHK\system;

/**
 * Class Strategy
 *
 * @package NHK\system
 */
class Strategy {
    /**
     * @var
     */
    protected $_type;
    /**
     * @var array
     */
    protected $_data = array();
    /**
     * @var array
     */
    protected $_result = array();

    /**
     * @param $type
     * @throws Exception
     */
    public function __construct($type) {
        $this->_type = $type;
        $this->parseFile();
    }

    /**
     * @param null $name
     * @return array
     */
    public function getConfig($name = null) {
        return !empty($name) && array_key_exists($name, $this->_data)
            ? $this->_data[$name]
            : $this->_data;
    }

    /**
     * @return mixed
     * @throws Exception
     */
    protected function parseFile() {
        $array = include($this->_findFile());
        if (empty($array)) {
            throw new Exception('parse strategy file error');
        }

        return $this->_data = $array;
    }

    /**
     * @param $content
     * @return bool|int|string
     * @throws Exception
     */
    public function parseContent($content) {
        foreach ($this->_data as $name => $data) {
            if (empty($data['pattern'])) {
                continue;
            }

            $pattern = $data['pattern'];
            if (preg_match($pattern, $content)) {
                $this->_collect($name);

                return $name;
            }
        }

        return false;
    }

    /**
     * @return string
     * @throws Exception
     */
    private function _findFile() {
        $path = NHK_PATH_ROOT . 'data/strategy/';
        $file = $path . $this->_type . '.php';
        if (!file_exists($file)) {
            throw new Exception('file not found: ' . $this->_type);
        }

        return $file;
    }

    /**
     * @param $name
     * @throws Exception
     */
    protected function _collect($name) {
        $queue = Env::getInstance()->getMsgQueue();
        $key = $this->getQueueKey($name);
        $res = msg_send($queue, Env::MSG_TYPE_EXCEPTION, array($key => time()), true, false, $error);

        if (!$res) {
            throw new Exception('msg send failed: ' . $error);
        }
    }


    public function getQueueKey($name) {
        return crc32($this->_type.$name);
    }
}