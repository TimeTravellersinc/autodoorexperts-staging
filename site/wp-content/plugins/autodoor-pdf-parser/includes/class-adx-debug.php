<?php
if (!defined('ABSPATH')) { exit; }

class ADX_Debug {

    private $channels = ['extract'=>[], 'split'=>[], 'parse'=>[], 'scope'=>[]];

    public function reset() {
        $this->channels = ['extract'=>[], 'split'=>[], 'parse'=>[], 'scope'=>[]];
    }

    public function log($channel, $msg) {
        $ch = in_array($channel, ['extract','split','parse','scope'], true) ? $channel : 'parse';
        $ts = sprintf('%.3f', microtime(true));
        $mem = (int) round(memory_get_usage(true) / (1024*1024));
        $this->channels[$ch][] = "[$ts][{$mem}MB] " . (string)$msg;
    }

    public function all() {
        return $this->channels;
    }
}
