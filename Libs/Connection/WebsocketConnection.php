<?php
namespace MNWebsocket\Libs\Connection;

use MNWebsocket\Libs\Exception\MNWebsocketIOException;
use MNWebsocket\Libs\Exception\WebsocketException;

class WebsocketConnection extends Connection {

    const WEBSOCKET_UDID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

    private $__isHandshaked = false;
    private $__closeFrameSent = false;
    private $__requestLine = '';
    private $__requestUrl = '';
    private $__rawHeader = '';
    private $__application = ''; // このコネクションがつないでいるアプリ
    private $__requestHeaders = [];
    private $__data = '';
    private $__frame = null;
    // 断片化されているときにこれまでの断片を入れておくバッファ
    private $__continuedBuffer = '';
    // 断片化されたメッセージのタイプ
    private $__fragmentedMessageType = null;
    // 受信した断片の個数
    private $__numFragments = 0;
    private $__io = null;

    // 前回 ping を送信した時刻
    private $__pingLastSendTime = false;

    // 最後に pong を受信した時刻
    private $__pongLastReceivedTime = false;

    protected $_logger = null;

    public function __construct($io, $server) {
        parent::__construct($io, $server);
        $this->_logger = \MNWebsocket\Libs\Log\MNWebsocketLogger::getInstance();
        $this->__pongLastReceivedTime = time();
    }

    public function onData() {

        try {
            if (!$this->__isHandshaked) {
                $this->__doHandShake();
            } else {
                $this->__handleFrame();
            }
        } catch (MNWebsocketIOException $ioe) {
            $this->__continuedBuffer = '';
            $this->__fragmentedMessageType = null;
            $this->__numFramgents = 0;
            $this->_logger->e($ioe->getMessage());
            $this->closeOnError($ioe->getCode());
        } catch (WebsocketException $e) {
            $this->__continuedBuffer = '';
            $this->__fragmentedMessageType = null;
            $this->__numFramgents = 0;
            $this->_logger->e($e->getMessage());
            $this->closeOnError($e->getCode());
        }
    }

    public function onFinishThisLoop() {
        $this->_carePing();
    }

    // 一定時間（10秒）ごとに ping を送信
    protected function _carePing() {
		return;
        $now = time();
        if ($now - $this->__pongLastReceivedTime > 30) {
            // 30秒間一度も pong を受け取れていなければ強制切断する
            $this->_logger->e('!! Close websocket connection since not responding !!');
            $this->closeOnError(12345);
            return;
        }
        if (!$this->__isHandshaked) {
            return;
        }
        $diff = $now - $this->__pingLastSendTime;
        if ($this->__pingLastSendTime === false || $diff >= 10) {
            $this->_logger->i('PING 送信');
            $ping = WebsocketFrame::buildPingFrame();
            $this->addWantToWrite($ping);
            $this->__pingLastSendTime = $now;
        }
    }

    public function onServerData($message) {
        if ($this->__application) {
            $this->__application->onServerData($this, $message);
        }
    }

    public function sendText($text) {
        $response = WebsocketFrame::buildDataFrame($text);
        $this->addWantToWrite($response);
    }

    public function sendBinary($binary) {
        $response = WebsocketFrame::buildDataFrame($binary, $isBinary = true);
        $this->addWantToWrite($response);
    }

    private function __doHandShake() {
        $requestHeaders = [];
        if (empty($this->__requestLine)) {
            // リクエストラインの取得
            $this->__requestLine = $this->_readRequestLine();
            $this->_logger->n("request line = " . $this->__requestLine);
            // リクエストURLの取得
            $this->__requestUrl = $this->_parseUrl($this->__requestLine);
            $this->_logger->d("request url = " . $this->__requestUrl);
            // アプリケーションが登録されているか？
            $this->__application = $this->__getApplication($this->__requestUrl);
            if ($this->__application === false) {
                throw new WebsocketException('Application Not Found: ' . $this->__requestUrl);
            }
        } elseif (empty($this->__requestHeaders)) {
            // リクエストヘッダーの取得
            $requestHeaders = $this->_readRequestHeaders();
        }

        if (!empty($requestHeaders)) {
            $this->__requestHeaders = $requestHeaders;
            if ($this->__sendHandShakeResponse($requestHeaders)) {
                $this->__isHandshaked = true;
            }
        }
    }

    private function __getApplication($url) {
        if ($url === '') {
            return false;
        }
        $appName = explode('/', $url)[1];
        if ($app = $this->_server->getApplication($appName)) {
            return $app;
        }
        // '/' というルート名前のアプリケーションがあればそこに回す。
        if ($app = $this->_server->getApplication('/')) {
            return $app;
        }
        return false;
    }

    private function __handleFrame() {
        if (empty($this->__frame)) {
            $this->__frame = new WebsocketFrame();
        }
        if (!$this->__frame->readFrame($this->_io)) {
            // フレームを全部読み込みきっていない
            $this->_logger->d('フレーム読み込み継続');
            return;
        }
        // 読み終わったので処理する

        // 制御フレームが断片化していないか？
        $opcode = $this->__frame->getOpcode();
        $fin = $this->__frame->getFin();
        if ($this->__frame->isControlFrame() && $fin === 0) {
            throw new WebsocketException("制御フレームが断片化している");
        }

        switch($opcode) {
            case WebsocketFrame::OPCODE_CONTINUATION_FRAME:
                $this->_logger->d("CONTINUATION フレームを受信 ({$this->__numFragments}))");
                if ($this->__numFragments == 0) {
                    throw new WebsocketException('いきなり CONTINUATION フレームが来た');
                }
                $this->__numFragments++;
                $this->__continuedBuffer .= $this->__frame->getPayload();
                if ($fin) {
                    // 断片化されたメッセージの最後のフレーム
                    if ($this->__fragmentedMessageType === WebsocketFrame::OPCODE_TEXT_FRAME) {

                        // 正しいUTF-8かをチェック
                        if ($this->__fragmentedMessageType == WebsocketFrame::OPCODE_TEXT_FRAME
                                && !mb_check_encoding($this->__continuedBuffer, 'UTF-8')) {
                            throw new WebsocketException('UTF-8 文字列として無効なものがテキストできた。');
                        }

                        $this->__application->onTextData($this, $this->__continuedBuffer);
                    } else if ($this->__fragmentedMessageType === WebsocketFrame::OPCODE_BINARY_FRAME) {
                        $this->__application->onBinaryData($this, $this->__continuedBuffer);
                    } else {
						$this->_logger->e("断片化されたメッセージのタイプ：" . $this->__fragmentedMessageType);
                        throw new WebsocketException('断片化されたメッセージのタイプが不明');
                    }
                    $this->__continuedBuffer = '';
                    $this->__fragmentedMessageType = null;
                    $this->__numFramgents = 0;
                }
                break;
            case WebsocketFrame::OPCODE_TEXT_FRAME:
                $this->_logger->d('TEXT フレームを受信');
                $payload = $this->__frame->getPayload();
                if (!$fin) {
                    $this->_logger->d('断片化されたメッセージの先頭フレーム');
                    $this->__numFragments++;
                    $this->__continuedBuffer = $payload;
                    $this->__fragmentedMessageType = WebsocketFrame::OPCODE_TEXT_FRAME;
                } else {
                    $this->_logger->d('断片化されていないメッセージ');
                    if ($this->__numFragments > 0) {
                        throw new WebsocketException('Fin を受信していないのに新しい断片化していないフレームが来た');
                    }
                    // 正しいUTF-8かをチェック
                    if (!mb_check_encoding($payload, 'UTF-8')) {
                        throw new WebsocketException('UTF-8 文字列として無効なものがテキストできた。');
                    }
                    $this->__application->onTextData($this, $payload);
                    $this->__continuedBuffer = '';
                    $this->__fragmentedMessageType = null;
                }
                break;
            case WebsocketFrame::OPCODE_BINARY_FRAME:
                $payload = $this->__frame->getPayload();
                if (!$fin) {
                    $this->_logger->d('断片化されたメッセージの先頭フレーム');
                    $this->__numFragments++;
                    $this->__continuedBuffer = $payload;
                    $this->__fragmentedMessageType = WebsocketFrame::OPCODE_BINARY_FRAME;
                } else {
                    $this->_logger->d('断片化されていないメッセージ');
                    if ($this->__numFragments > 0) {
                        throw new WebsocketException('Fin を受信していないのに新しい断片化していないフレームが来た');
                    }
                    $this->__application->onBinaryData($this, $payload);
                    $this->__continuedBuffer = '';
                    $this->__fragmentedMessageType = null;
                }
                break;
            case WebsocketFrame::OPCODE_CONNECTION_CLOSE:
                $this->_logger->d('CLOSE フレームを受信');
                $this->__application->onClose($this);
                $this->_onCloseFrame();
                break;
            case WebsocketFrame::OPCODE_PING:
                $this->_logger->d('PING フレームを受信');
                $this->_onPingFrame();
                break;
            case WebsocketFrame::OPCODE_PONG:
                $this->_logger->d('PONG フレームを受信');
                $this->__pongLastReceivedTime = time();
                break;
            case WebsocketFrame::OPCODE_RESERVED_1:
            case WebsocketFrame::OPCODE_RESERVED_2:
            case WebsocketFrame::OPCODE_RESERVED_3:
            case WebsocketFrame::OPCODE_RESERVED_4:
            case WebsocketFrame::OPCODE_RESERVED_5:
            case WebsocketFrame::OPCODE_RESERVED_6:
            case WebsocketFrame::OPCODE_RESERVED_7:
            case WebsocketFrame::OPCODE_RESERVED_8:
            case WebsocketFrame::OPCODE_RESERVED_9:
            case WebsocketFrame::OPCODE_RESERVED_10:
                $this->_logger->e('RESERVED OPCODE: ' . $this->__frame->getOpcode());
                $this->closeOnError(1000);
                break;
        }
        // フレームを使い終わったので捨てる
        $this->__frame = null;
    }

    protected function _onPingFrame() {
        $this->_logger->d('PONG 返信');
        $pong = WebsocketFrame::buildPongFrame($this->__frame->getPayload());
        $this->_io->write($pong);
        $this->__frame = null; // 処理し終わったフレームを捨てる
    }

    protected function _sendCloseFrame($reason = '') {
        $closeFrame = WebsocketFrame::buildCloseFrame($reason);
        if ($this->_io->write($closeFrame) > 0) {
            $this->__closeFrameSent = true;
        } else {
            $this->_logger->e('CLOSE フレーム送信失敗');
        }
    }

    protected function _onCloseFrame() {
        if ($this->__closeFrameSent) {
            $this->_logger->d('こちらから送った CLOSE フレームに返信の CLOSE フレームが来た');
            $this->close();
            $this->_logger->i('ソケットを閉じました');
        } else {
            $this->_logger->i('CLOSE フレームを受信したのでこちらも CLOSE フレームを返してソケットを閉じる');
            $this->_sendCloseFrame();
            $this->close();
        }
    }

    public function closeOnError($errorCode) {
        //$this->_sendCloseFrame();
        $ret = $this->close();
        $this->_logger->e("connection error($errorCode). connection closed");
        return $ret;
    }

    protected function _readRequestLine() {
        $requestLine = $this->_io->readLine();
        // 改行で終わっていないならエラー
        if (substr($requestLine, -2) !== "\r\n") {
            $this->closeOnError(400);
            return false;
        }
        $this->_logger->d('read reaquest line done.');
        return $requestLine;
    }

    protected function _parseUrl($requestLine) {
        $parts = explode(' ', $requestLine);
        if (!isset($parts[1])) {
            throw new WebsocketException('Invalid Request Line');
        }
        return $parts[1];
    }

    protected function _readRequestHeaders() {
        // まだ読んでいる途中なら再度読み込む
        if (!$this->__isFinishedReadingHeader()) {
            $this->__rawHeader .= $this->_io->readLine();
        }
        // （再度読み込んだ結果）読み込み完了していれば値を返す。
        if (!$this->__isFinishedReadingHeader()) {
            return null;
        }

        $this->_logger->i('read Headers done: ' . $this->__rawHeader);
        return $this->_httpParseHeaders($this->__rawHeader);
    }

    private function __sendHandShakeResponse($headers) {
        $response = $this->__buildHandShakeResponse($headers);
        $log = "\n************** handshake response ********************\n";
        $log .= $response;
        $log .= "\n******************************************************\n";
        $this->_logger->i($log);
        $this->addWantToWrite($response);
        return true;
    }

    private function __buildHandShakeResponse($headers) {
        $key = trim($headers['Sec-WebSocket-Key']);
        $accept_key = $this->__createAcceptKey($key);

        $this->_logger->d("key = $key");
        $this->_logger->d("accept_key = $accept_key");

        $msg = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: $accept_key\r\n";

        // サブプロトコルの設定
        $subprotocols = isset($headers['Sec-WebSocket-Protocol']) ? explode(',', $headers['Sec-WebSocket-Protocol']) : [];
        $subprotocols = array_map(function($p) { return trim($p);}, $subprotocols);
        if (in_array('chat', $subprotocols)) {
            $msg .= "Sec-WebSocket-Protocol: chat\r\n";
        } elseif (empty($subprotocols)) {
            // なにもなし
        } else {
            throw new WebsocketException("Subprotocol unmatch");
            return;
        }

        $msg .= "\r\n";

        return $msg;
    }

    private function __isFinishedReadingHeader() {
        // 末尾が \r\n\r\n になっていれば終わりまで読んでいる
        return substr($this->__rawHeader, -4) === "\r\n\r\n";
    }

    private function __createAcceptKey($clientKey) {
        return base64_encode(sha1($clientKey . self::WEBSOCKET_UDID, true));
    }

    private function __clearRequest() {
        $this->__isHandshaked = false;
        $this->__requestLine = false;
        $this->__request = '';
        $this->__data = '';
    }

    protected function _httpParseHeaders($raw_headers) {
        $headers = [];

        foreach (explode("\r\n", $raw_headers) as $i => $h) {
            $h = explode(':', $h, 2);

            if (isset($h[1])) {
                $headers[$h[0]] = trim($h[1]);
            }
        }

        return $headers;
    }

}
