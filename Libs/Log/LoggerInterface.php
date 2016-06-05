<?php

namespace MNWebsocket\Libs\Log;

interface LoggerInterface {

    public function emargency($message);

    public function alert($message);

    public function critical($message);

    // error
    public function e($message);

    // warning
    public function w($message);

    // info
    public function i($message);

    // notice
    public function n($message);

    // debug
    public function d($message);
}
