<?php
namespace MNWebsocket\Libs\IO;

use MNWebsocket\Libs\Exception\MNWebsocketIOException;
use MNWebsocket\Libs\Exception\MNWebsocketRuntimeException;
use MNWebsocket\Libs\Exception\MNWebsocketTimeoutException;

class StreamIO extends AbstractIO {

    public function __construct($socket) {
        $this->__sock = $socket;
    }

    /**
     * @param $n
     * @throws \MNWebsocket\Exception\MNWebsocketIOException
     * @return mixed|string
     */
    public function read($n) {
        $res = '';
        $read = 0;

        while ($read < $n && !feof($this->__sock) && ($buf = fread($this->__sock, $n - $read)) !== false) {
            if ($buf === '') {
                continue;
            }
            $read += strlen($buf);
            $res .= $buf;
            $this->__lastRead = microtime(true);
        }

        if (strlen($res) != $n) {
            throw new MNWebsocketIOException(sprintf(
                'Error reading data. Received %s instead of expected %s bytes',
                strlen($res),
                $n
            ));
        }

        return $res;
    }

    public function readLine() {
        return fgets($this->__sock, self::READ_BUF_SIZE);
    }

    /**
     * @param $data
     * @return mixed|void
     * @throws \MNWebsocket\Exception\MNWebsocketRuntimeException
     * @throws \MNWebsocket\Exception\MNWebsocketTimeoutException
     */
    public function write($data) {

        $written = 0;
        $len = strlen($data);
        while (true) {
            if (is_null($this->__sock)) {
                throw new MNWebsocketRuntimeException('Broken pipe or closed connection');
            }

            if (($w = @fwrite($this->__sock, $data)) === false) {
                throw new MNWebsocketRuntimeException('Error sending data');
            }
            $written += $w;

            if ($this->timedOut()) {
                throw new MNWebsocketTimeoutException('Error sending data. Socket connection timed out');
            }

            $len = $len - $w;
            if ($len > 0) {
                $data = substr($data, 0 - $len, 0 - $len);
            } else {
                $this->__lastWrite = microtime(true);
                break;
            }
        }
        return $written;
    }

    public function close() {
        if (is_resource($this->__sock)) {
            fclose($this->__sock);
        }
        $this->__sock = null;
    }

    public function getSocket() {
        return $this->__sock;
    }

    public function select($sec, $usec) {
        $read = array($this->__sock);
        $write = null;
        $except = null;
        return stream_select($read, $write, $except, $sec, $usec);
    }

    public function timedOut() {
        $info = stream_get_meta_data($this->__sock);
        return $info['timed_out'];
    }
}