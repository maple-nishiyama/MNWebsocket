<?php
namespace MNWebsocket\Libs\Connection;

use MNWebsocket\Libs\Exception\WebsocketException;

class WebsocketFrame {

    // オペコード一覧
    const OPCODE_CONTINUATION_FRAME = 0x00;
    const OPCODE_TEXT_FRAME         = 0x01;
    const OPCODE_BINARY_FRAME       = 0x02;
    const OPCODE_RESERVED_1         = 0x03;
    const OPCODE_RESERVED_2         = 0x04;
    const OPCODE_RESERVED_3         = 0x05;
    const OPCODE_RESERVED_4         = 0x06;
    const OPCODE_RESERVED_5         = 0x07;
    const OPCODE_CONNECTION_CLOSE   = 0x08;
    const OPCODE_PING               = 0x09;
    const OPCODE_PONG               = 0x0A;
    const OPCODE_RESERVED_6         = 0x0B;
    const OPCODE_RESERVED_7         = 0x0C;
    const OPCODE_RESERVED_8         = 0x0D;
    const OPCODE_RESERVED_9         = 0x0E;
    const OPCODE_RESERVED_10        = 0x0F;

    private $__buffer = '';
    private $__parsed = false;
    private $__FIN = '';
    private $__RSV1 = '';
    private $__RSV2 = '';
    private $__RSV3 = '';
    private $__opcode = '';
    private $__MASK = '';
    private $__payloadLength = -1;
    private $__maskingKey = '';
    private $__payloadOffset = -1; // masking-key + ペイロード部分のオフセット
    private $__payload = '';

    protected $_logger = null;

    public function __construct() {
        $this->_logger = \MNWebsocket\Libs\Log\MNWebsocketLogger::getInstance();
    }

    public function getPayload() {
        return $this->__payload;
    }

    public function getOpcode() {
        return $this->__opcode;
    }

    public function getFin() {
        return $this->__FIN;
    }

    public function isControlFrame() {
        // OPCODE の最上位ビットが1なら制御フレーム
        return (bool)($this->__opcode >> 3 & 1);
    }

    public function readFrame($io) {
        if ($this->__parsed) {
            // 解析済み
            //assert(false, 'すでに解析済みのフレームです');
            $this->_logger->e('すでに解析済みのフレームです');
            return;
        }
        if ($this->__payloadLength < 0) {
            // フレームのヘッダ部分が未解析なら解析する（ペイロード長が分かるまで。）
            $this->_logger->d('__parseFrameHeader()');
            $this->__parseFrameHeader($io);
        }
        // MASK を読む
        if ($this->__MASK) {
            $maskingKey = $io->read(4);
            $this->__buffer .= $maskingKey;
            $this->__maskingKey = $maskingKey;
        }
        // ペイロード部分を読んでいく
        if (!$this->__isFullyRead()) {
            $toRead = $this->__payloadLength - strlen($this->__payload);
            if ($toRead > 0) {
                try {
                    $read = $io->read($toRead);
                    $this->__buffer .= $read;
                } catch (MNWebsocketIOException $e) {
                    $this->_logger->d($e->getMessage() . "\n" . "ペイロード読んでいる途中");
                }
            }
            //assert(strlen($this->__payload) <= $this->__payloadLength, "ストリームを読みすぎている");
            if ($this->__isFullyRead()) {
                $payload = substr($this->__buffer, $this->__payloadOffset + 4, $this->__payloadLength);
                // unamsk する
                for ($i = 0; $i < strlen($payload); $i++) {
                    $this->__payload .= $payload[$i] ^ $this->__maskingKey[$i % 4];
                }
                // パース完了
                $this->__parsed = true;
            }
        }
        // TODO: __isFullyRead はいらない？ __parsed のみで済みそう。
        return $this->__isFullyRead();

    }

    public function debugDump() {
        $dump = sprintf("FIN = %d\nMASK = %d\nRSV1 = %d\nopcode = %d\npayloadLength=%d\npayload = %s",
                $this->__FIN,
                $this->__MASK,
                $this->__RSV1,
                $this->__opcode,
                $this->__payloadLength,
                $this->__payload
                );
        $this->_logger->d($dump);
    }

    private function __isFullyRead() {
        $expected = $this->__payloadOffset + 4 + $this->__payloadLength;
        // ↑ +4 はマスキングキーの寄与
        return $expected <= strlen($this->__buffer);
    }

    public function isParsingFinished() {
        return $this->__parsed;
    }

    private function __parseFrameHeader($io) {
        // 最低2バイトまず読む
        $head2 = $io->read(2);
        $this->__buffer .= $head2;
        $this->_logger->d("head2 = " . bin2hex($head2));

        // 1バイト目
        $first = unpack('C', substr($head2, 0, 1))[1];
        $this->_logger->d("first = " . bin2hex($first));
        $this->__FIN = $first >> 7 & 0b00000001;
        $this->_logger->d("FIN = $this->__FIN");
        $this->__RSV1 = $first >> 6 & 0b00000001;
        $this->__RSV2 = $first >> 5 & 0b00000001;
        $this->__RSV3 = $first >> 4 & 0b00000001;
        $this->__opcode = $first & 0b00001111;

        $this->_logger->d("OPCODE = " . $this->__opcode);

        if ($this->__RSV1 + $this->__RSV2 + $this->__RSV3 !== 0) {
            throw new WebsocketException('Non zero RSV value!');
        }

        // 2バイト目
        $second = unpack('C', substr($head2, 1, 1))[1];
        $this->__MASK = $second >> 7 & 0b00000001;
        // クライアント → サーバーは必ずマスクされているはず
        // assert($this->__MASK === 1, 'クライアントからのデータがマスクされていない： MASK = ' . $this->__MASK);
        if ($this->__MASK !== 1) {
            throw new Exception('クライアントからのデータがマスクされていない： MASK = ' . $this->__MASK . "\n");
        }
        $payload_length = $second & 0b01111111;

        if ($payload_length < 126) {
            // ヘッダのパース完了
            $this->__payloadOffset = 2;
        } else if ($payload_length == 126) {
            // extended payload length を 16ビット( = unsigned short in big endian) 読まなければならない
            // 追加で2バイト読む
            $extendedPayloadLength2 = $io->read(2);
            $this->__buffer .= $extendedPayloadLength2;
            $payload_length = unpack('n', $extendedPayloadLength2)[1];
            $this->__payloadOffset = 4;
        } else if ($payload_length == 127) {
            // extended payload length を 64ビット( = unsigned long long in big endian) 読まなければならない
            // unsigned long long in beg endian を意味するフォーマット文字 'J' が使えるのは PHP 5.6 以上なので、今回は使わない。
            // 32 ビット(= N)を2つ読んでビットシフトで対応する。
            // 追加で8バイト読む
            $extendedPayloadLength8 = $io->read(8);
            $this->__buffer .= $extendedPayloadLength8;
            $long1 = unpack('N', substr($extendedPayloadLength8, 0, 4))[1];
            $long2 = unpack('N', substr($extendedPayloadLength8, 4, 4))[1];
            $payload_length = ($long1 << 32) + $long2;
            $this->__payloadOffset = 10;
        } else {
            assert(false, "不明なpayload_length");
        }
        $this->__payloadLength = $payload_length;
        $this->_logger->d("payloadlength = " . $this->__payloadLength);
        return true;
    }


    public static function buildDataFrame($message, $isBinary = false) {
        // 1バイト目
        $FIN = 0b10000000;
        $RSV = 0b00000000;
        $opcode = $isBinary ? 0b0000010 : 0b00000001;
        $first = $FIN + $RSV + $opcode;

        // 2バイト目
        $MASK = 0;
        $payloadLength = strlen($message);
        // assert($payload_length < 126, 'サーバーからのメッセージが長過ぎます');
        if ($payloadLength < 126) {
            $second = $MASK + $payloadLength;
            $headers = pack('C2', $first, $second);
        } elseif ( 126 <= $payloadLength && $payloadLength < 65536) { // 65536 = 2^16
            // 16ビットに収まる場合
            $second = $MASK + 126;
            $headers = pack('C2n', $first, $second, $payloadLength);
        } elseif (65536 <= $payloadLength) {
            // 64ビット必要な場合
            $second = $MASK + 127;
            $headers = pack('C2N2', $first, $second, $payloadLength / 4294967296, $payloadLength % 4294967296);
        } else {
            assert(false, "不明なペイロード長さです");
        }

        $payload_data = $message;

        return $headers . $payload_data;
    }

    public static function buildPingFrame($payload = '') {
        $headers = static::_buildControllFrameHeaders(self::OPCODE_PING, strlen($payload));
        return $headers . $payload;
    }

    public static function buildPongFrame($payload = '') {
        $headers = static::_buildControllFrameHeaders(self::OPCODE_PONG, strlen($payload));
        return $headers . $payload;
    }

    public static function buildCloseFrame($reason = '') {
        $headers = static::_buildControllFrameHeaders(self::OPCODE_CONNECTION_CLOSE, strlen($reason));
        return $headers . $reason;
    }

    private static function _buildControllFrameHeaders($opcode, $payloadLength) {
        // 制御フレームのペイロードは126バイト未満でなければならない
        //assert($payloadLength < 126);
        if ($payloadLength >= 126) {
            throw new WebsocketException("制御フレームのペイロードが長すぎ($payloadLength バイト)");
        }
        // 1バイト目
        $FIN = 0b10000000;
        $RSV = 0b00000000;
        $first = $FIN + $RSV + $opcode;

        // 2バイト目
        $MASK = 0;
        $second = $MASK + $payloadLength;
        $headers = pack('C2', $first, $second);

        return $headers;
    }
}
