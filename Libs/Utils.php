<?php
namespace MNWebsocket\Libs;

class Utils {
    public static function arrayGet($array, $key) {
        if (!is_array($array)) {
            return null;
        }
        if (is_null($key)) {
            return null;
        }
        if (!isset($array[$key])) {
            return null;
        }
        return $array[$key];
    }
}
