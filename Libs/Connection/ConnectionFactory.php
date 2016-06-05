<?php
namespace MNWebsocket\Libs\Connection;

use MNWebsocket\Libs\IO\StreamIO;

class ConnectionFactory {
    private $__connectionClassName = '';

    protected $_logger;

    public function __construct($connectionClassName) {
        $this->__connectionClassName = $connectionClassName;
        $this->_logger = \MNWebsocket\Libs\Log\MNWebsocketLogger::getInstance();
    }

    public function create($server, $socket) {
        $this->_logger->i("connection class name = " . $this->__connectionClassName);
        $io = new StreamIO($socket);
        $className =__NAMESPACE__ . '\\' . $this->__connectionClassName;
        return new $className($io, $server);
    }
}
