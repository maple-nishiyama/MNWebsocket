<?php
require_once __DIR__.'/../vendor/autoload.php';

use MNWebsocket\Libs\Connection\ConnectionFactory;
use MNWebsocket\Libs\Server\SelectServer;
use MNWebsocket\Libs\Log\MNWebsocketLogger;

$logger = MNWebsocketLogger::getInstance();
$logger->config([
    'out' => 'stdout',
    'directory' => __DIR__ . '/log',
    'log_level' => LOG_DEBUG,
]);
$factory = new ConnectionFactory('WebsocketConnection');
$ip = '0.0.0.0';
$port = 12345;
$isSSL = false;
$server = new SelectServer($factory, $ip, $port, $isSSL);

$echoApp = new MNWebsocket\Examples\EchoApplication($server);
$server->registerApplication('echo', $echoApp);

$server->run();
