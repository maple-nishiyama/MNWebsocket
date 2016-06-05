<?php
namespace MNWebsocket\Libs\Server;

use MNWebsocket\Libs\Connection\ConnectionFactory;
use MNWebsocket\Libs\Connection\Connection;
use MNWebsocket\Libs\Log\MNWebsocketLogger;
use MNWebsocket\Libs\Exception\MNWebsocketTimeoutException;

error_reporting(E_ALL);
assert_options(ASSERT_BAIL, true);
set_time_limit(0);
ob_implicit_flush();

class SelectServer {

    const WRITE_BUFF_LENGTH = 4096;
    const STREAM_TIMEOUT = 100000; // マイクロ秒

    private $__allConnections = [];
    private $__serverSocket = null;

    protected $_connectionFactory;
    protected $_applications = [];

    protected $_logger;

    protected $_ip;
    protected $_port;
    protected $_isSSL;

    public function __construct(ConnectionFactory $connectionFactory, $ip, $port, $isSSL) {
        $this->_connectionFactory = $connectionFactory;
        $this->_ip = $ip;
        $this->_port = $port;
        $this->_isSSL = $isSSL;
        $this->_logger = MNWebsocketLogger::getInstance();
    }

    /*
     * 内部的なConnection (メッセージキューなど）を追加する
     */
    public function addConnection(Connection $connection) {
        $s = $connection->getSocket();
        $this->__allConnections[(int)$s] = $connection;
    }

    public function registerApplication($name, $application) {
        $this->_applications[$name] = $application;
    }

    /**
     * @return Application
     */
    public function getApplication($name) {
        return isset($this->_applications[$name]) ? $this->_applications[$name] : null;
    }

    /**
     * 全接続にメッセージを送信
     * @param string $message
     */
    public function broadcast($message) {
        $connections = $this->__allConnections;
        foreach ($connections as $c) {
            $c->onServerData($message);
        }

    }

    public function run() {

        $config = $this->_isSSL ? 'tls://' : 'tcp://' . "{$this->_ip}:{$this->_port}";
        $errno = 0;
        $errstr = '';

        $context = stream_context_create();
        if ($this->_isSSL) {
            $this->__applySSLContext($context);
        }

        // サーバーソケットを作成して
        $this->__serverSocket = stream_socket_server($config, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
        if (!$this->__serverSocket) {
            die("$errstr ($errno)");
        }
        // メインループに入る
        $this->_mainLoop();
        socket_close($this->__serverSocket);
    }

    protected function _mainLoop() {

        $this->_logger->i('SelectServer main loop starts.');

        while (true) {
            $changedSockets = $this->__getAllSockets();
            $wantToWrites = $this->__findToWriteSocket();
            if (empty($wantToWrites)) {
                $wantToWrites = NULL;
            }
            $except = NULL;

            // select() する！！
            stream_select($changedSockets, $wantToWrites, $except, 1);

            // 変化のあったソケットを処理する
            foreach ($changedSockets as $socket) {
                $this->_processChangedSocket($socket);
            }

            if (!is_null($wantToWrites)) {
                foreach ($wantToWrites as $socket) {
                    $this->_processWritableSocket($socket);
                }
            }

            foreach($this->_applications as $app) {
                $app->onServerProcedure();
            }

            foreach($this->__allConnections as $c) {
                $c->onFinishThisLoop();
            }
        }
    }


	private function __applySSLContext(&$context)
	{
		$pem_file = './server.pem';
		$pem_passphrase = 'mnwebsocket';

		// Generate PEM file
		if(!file_exists($pem_file))
		{
			$dn = array(
				"countryName" => "JP",
				"stateOrProvinceName" => "none",
				"localityName" => "none",
				"organizationName" => "none",
				"organizationalUnitName" => "none",
				"commonName" => "",
				"emailAddress" => ""
			);
			$privkey = openssl_pkey_new();
			$cert    = openssl_csr_new($dn, $privkey);
			$cert    = openssl_csr_sign($cert, null, $privkey, 3650);
			$pem = array();
			openssl_x509_export($cert, $pem[0]);
			openssl_pkey_export($privkey, $pem[1], $pem_passphrase);
			$pem = implode($pem);
			file_put_contents($pem_file, $pem);
		}

		// apply ssl context:
		stream_context_set_option($context, 'ssl', 'local_cert', $pem_file);
		stream_context_set_option($context, 'ssl', 'passphrase', $pem_passphrase);
		stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
		stream_context_set_option($context, 'ssl', 'verify_peer', false);
	}

    private function __getAllSockets() {
        $sockets = array_map(function(Connection $conn) {
            return $conn->getSocket();
        }, $this->__allConnections);

        $sockets[] = $this->__serverSocket;

        return $sockets;

    }

    /**
     * 書き込みたいデータを持っているソケットを取得
     */
    private function __findToWriteSocket() {
        $connections = array_filter($this->__allConnections, function($conn) {
            return $conn->doesWantToWrite();
        });
        return array_map(function($conn) {
            return $conn->getSocket();
        }, $connections);
    }

    private function __isServerSocket($socket) {
        if (!$socket) {
            return false;
        }
        return $socket === $this->__serverSocket;
    }

    private function __hasValidConnection($socket) {
        if (!$socket) {
            return false;
        }
        return in_array($socket, $this->__allSockets);
    }

    protected function _processChangedSocket($socket) {
        // サーバーソケットの場合は新規接続の開始
        if ($this->__isServerSocket($socket)) {
            $this->_logger->d("new connection comes");
            return $this->__addNewConnection();
        }
        // 既存接続からのメッセージ
        else if ($conn = $this->__getConnectionBySocket($socket)) {
            return $conn->onData();
        }
        else {
            $this->_logger->e("不明な changed socket: " . (int)$socket);
            return false;
        }
    }

    protected function _processWritableSocket($socket) {
        $conn = $this->__getConnectionBySocket($socket);
        try {
            if ($conn) {
                // 書き込む
                $buff = $conn->getWantToWriteData(self::WRITE_BUFF_LENGTH);
                $wrote = $conn->getIo()->write($buff);
                $conn->onWroteData($wrote);
                $this->_logger->d("データを{$wrote}バイト書き込みました。");
            } else {
                $this->_logger->w("不明な writable socket: " . (int) $socket);
                return false;
            }
        } catch (MNWebsocketTimeoutException $e) {
            $this->_logger->e('書き込みソケットがタイムアウトしました：' . $e->getMessage());
            $conn->close();
        }
    }

    private function __getConnectionBySocket($socket) {
        if (!isset($this->__allConnections[(int)$socket])) {
            return null;
        }
        return $this->__allConnections[(int)$socket];
    }

    private function __addNewConnection() {
        $newSock = stream_socket_accept($this->__serverSocket);
        if (!$newSock) {
            return false;
        }
        stream_set_timeout($newSock, 0, self::STREAM_TIMEOUT);
        $this->__allConnections[(int)$newSock] = $this->_connectionFactory->create($this, $newSock);
    }

    public function removeConnection($connection) {
        $sock = $connection->getSocket();
        $key = (int)$sock;
        if (!isset($this->__allConnections[$key])) {
            $this->_logger->critical("removeConnection 失敗($key)");
            return false;
        }
        foreach ($this->_applications as $app) {
            $app->onClose($connection);
        }
        unset($this->__allConnections[$key]);
        $this->_logger->d("removeConnection done. ($key)");
        return true;
    }
}
