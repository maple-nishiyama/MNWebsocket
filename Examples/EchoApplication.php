<?php
namespace MNWebsocket\Examples;

use MNWebsocket\Libs\Application;

/**
 * Simple Echo Server.
 * This is also used as the target of Autobahn Websocket Test Suit.
 */
class EchoApplication extends Application {

    protected $_loop = 0;
    protected $_logger = null;
    protected $_message = '';
    protected $_connection = null;

    public function __construct($server) {
        $this->_logger = \MNWebsocket\Libs\Log\MNWebsocketLogger::getInstance();
    }

    public function onTextData($connection, $text) {
        $connection->sendText($text);
    }

    public function onBinaryData($connection, $binary) {
        $connection->sendBinary($binary);
    }

    public function onServerData($connection, $data) {

    }

    public function onClose($connection) {

    }

    public function onServerProcedure() {

    }

}


