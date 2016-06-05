<?php

namespace MNWebsocket\Libs\Log;

class MNWebsocketLogger implements LoggerInterface {

    const COLOR_RED = "\e[31m";
    const COLOR_YELLOW = "\e[33m";
    const COLOR_CYAN = "\e[36m";
    const COLOR_GREEN = "\e[32m";

    const COLOR_OFF = "\e[m";

    protected static $_defaultLevels = [
        'emergency' => LOG_EMERG,
        'alert' => LOG_ALERT,
        'critical' => LOG_CRIT,
        'error' => LOG_ERR,
        'warning' => LOG_WARNING,
        'notice' => LOG_NOTICE,
        'info' => LOG_INFO,
        'debug' => LOG_DEBUG,
    ];

    protected static $_colorMap = [
        'emergency' => self::COLOR_RED,
        'alert' => self::COLOR_RED,
        'critical' => self::COLOR_RED,
        'error' => self::COLOR_RED,
        'warning' => self::COLOR_YELLOW,
        'notice' => self::COLOR_CYAN,
        'info' => self::COLOR_GREEN,
    ];

    private static $__instance = null;

	/**
	 * 出力先 file or STDOUT
	 */
	protected $_out = 'stdout';

    /**
     * 出力先ディレクトリ
     * @var string
     */
    protected $_dir;

    /**
     * 現在のログレベル
     * @return type
     */
    protected $_currentLogLevel = LOG_DEBUG;

    public static function getInstance() {
        if (is_null(self::$__instance)) {
            self::$__instance = new MNWebsocketLogger();
        }
        return self::$__instance;
    }

    private function __construct() {
    }

    public function config($config) {
		$out = $config['out'];
		$this->_out = in_array($out, ['file', 'stdout']) ? $out : 'stdout';
        $this->_dir = $config['directory'];
        $logLevel = $config['log_level'];
        $this->_currentLogLevel = in_array($logLevel, self::$_defaultLevels) ? $logLevel : LOG_DEBUG;
    }

    public function emargency($message) {
        return $this->_write($message, 'emergency');
    }

    public function alert($message) {
        return $this->_write($message, 'alert');
    }

    public function critical($message) {
        return $this->_write($message, 'critical');
    }

    public function e($message) {
        return $this->_write($message, 'error');
    }

    public function w($message) {
        return $this->_write($message, 'warning');
    }

    public function n($message) {
        return $this->_write($message, 'notice');
    }
    public function i($message) {
        return $this->_write($message, 'info');
    }

    public function d($message) {
        return $this->_write($message, 'debug');
    }

    protected function _write($message, $level) {
        $lv = isset(self::$_defaultLevels[$level]) ? self::$_defaultLevels[$level] : PHP_INT_MAX;
        if ($lv > $this->_currentLogLevel) {
            return;
        }
        $backtrace = debug_backtrace();
        $file = $backtrace[1]['file'];
        $line = $backtrace[1]['line'];
        $func = $backtrace[2]['function'];
        $date = date('Y-m-d H:i:s');
        $data = [
            sprintf("%-11s", "[$level]"),
            $date,
            $message,
            "at $file ($line): in function \"$func()\"",
        ];
        $colorBegin = isset(self::$_colorMap[$level]) ? self::$_colorMap[$level] : '';
        $colorEnd = ($colorBegin === '') ? '' : self::COLOR_OFF;
        $log = $colorBegin . implode("\t", $data) . $colorEnd . "\n";
        if ($this->_out === 'file') {
            $filepath = $this->_dir . DIRECTORY_SEPARATOR . "WNWebsocket-" . date('Y-m-d') . ".log";
            file_put_contents($filepath, $log, FILE_APPEND | LOCK_EX);
        } else if ($this->_out === 'stdout') {
            echo $log;
        }
    }

}
