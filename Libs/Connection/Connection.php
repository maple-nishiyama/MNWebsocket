<?php
namespace MNWebsocket\Libs\Connection;

abstract class Connection {

    const READ_BUF_SIZE = 8192;

    /**
     *
     * @var AbstractIO
     */
    protected $_io;

    /**
     *
     * @var SelectServer
     */
    protected $_server;

    /**
     * 書き込みたいデータ
     *
     * @var string
     */
    protected $_wantToWrite = '';

    public function __construct($io, $server) {
        $this->_server = $server;
        $this->_io = $io;
    }
    public function getSocket() {
        return $this->_io->getSocket();
    }

    public function close() {
        $this->_server->removeConnection($this);
        return $this->_io->close();
    }

    public function getIo() {
        return $this->_io;
    }

    public abstract function onData();

    public abstract function onFinishThisLoop();

    /**
     *  書き込みたいデータをバッファに追加する
     */
    public function addWantToWrite($data) {
        $this->_wantToWrite .= $data;
    }

    /**
     *  書き込みたいデータを持っているか？
     * @return bool
     */
    public function doesWantToWrite() {
        return strlen($this->_wantToWrite) > 0;
    }

    /**
     * 書き込みたいデータを指定の長さだけ取得
     *
     * @param integer $size 取得したい長さ。データがコレより短い時は全部取れる。
     */
    public function getWantToWriteData($size) {
        if ($this->_wantToWrite === '')  {
            return '';
        }
        return substr($this->_wantToWrite, 0, $size);
    }

    /**
     * データをある長さだけ書き込んだときに呼ばれる。
     * 持っていたデータですでに書き込んだ部分を切り詰めるようにしておく。
     *
     * @param 書き込んだ長さ
     * @param 切り詰めた結果、残っているデータ
     */
    public function onWroteData($wrote) {
        if ($this->_wantToWrite === '') {
            return '';
        }
        $this->_wantToWrite = substr($this->_wantToWrite, $wrote);
        if ($this->_wantToWrite === false) {
            $this->_wantToWrite = '';
        }
        return $this->_wantToWrite;
    }

    // サーバー側からプッシュするデータが来たとき
    public abstract function onServerData($message);

}
