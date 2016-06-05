<?php
namespace MNWebsocket\Libs\IO;

abstract class AbstractIO
{
    const READ_BUF_SIZE = 8192;

    /**
     * @param $n
     * @return mixed
     */
    abstract public function read($n);

    abstract public function readLine();

    /**
     * @param $data
     * @return mixed
     */
    abstract public function write($data);

    /**
     * @return mixed
     */
    abstract public function close();

    /**
     * @param $sec
     * @param $usec
     * @return mixed
     */
    abstract public function select($sec, $usec);

    /**
     * @return mixed
     */
    // abstract public function connect();

    /**
     * @return mixed
     */
    // abstract public function reconnect();

    /**
     * @return mixed
     */
    abstract public function getSocket();
}
