<?php
namespace MNWebsocket\Libs;

abstract class Application {

    abstract public function onTextData($connection, $text);
    abstract public function onBinaryData($connection, $binary);
    abstract public function onServerData($connection, $data);
    abstract public function onClose($connection);
    abstract public function onServerProcedure();
}

