<?php
if (!defined('ABSPATH')) { exit; }

class ADX_Extractor {

    private $homePdftotext;
    private $dbg;

    public function __construct($homePdftotext, ADX_Debug $dbg) {
        $this->homePdftotext = $homePdftotext;
        $this->dbg = $dbg;
    }

    private function is_function_enabled($fn) {
        if (!function_exists($fn)) return false;
        $disabled = ini_get('disable_functions');
        if (!$disabled) return true;
        $disabled = array_map('trim', explode(',', $disabled));
        return !in_array($fn, $disabled, true);
    }

    private function normalize_text($t) {
        $t = str_replace("\r\n", "\n", (string)$t);
        $t = str_replace("\r", "\n", $t);
        $t = str_replace("\t", " ", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        $t = str_replace(["–","—","“","”","’"], ["-","-","\"","\"","'"], $t);
        return $t ?? '';
    }

    public function extract_text_pdftotext($pdf) {
        $this->dbg->log('extract', "--- EXTRACT START ---");

        $tmp = wp_tempnam($pdf);
        if (!$tmp) {
            $this->dbg->log('extract', "wp_tempnam failed");
            return ['text'=>''];
        }

        if (!$this->is_function_enabled('exec')) {
            $this->dbg->log('extract', "exec() disabled => cannot run pdftotext");
            @unlink($tmp);
            return ['text'=>''];
        }

        $pdf_arg = escapeshellarg($pdf);
        $tmp_arg = escapeshellarg($tmp);

        $candidates = [];
        $candidates[] = $this->homePdftotext;

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') $candidates[] = rtrim($home,'/') . '/bin/pdftotext';

        $candidates[] = '/home/u236173098/bin/xpdf-tools-linux-4.06/bin64/pdftotext';
        $candidates[] = '/home/u236173098/bin/xpdf-tools-linux-4.06/bin32/pdftotext';
        $candidates[] = '/usr/bin/pdftotext';
        $candidates[] = '/bin/pdftotext';
        $candidates[] = '/usr/local/bin/pdftotext';

        $candidates = array_values(array_unique(array_filter($candidates)));

        $this->dbg->log('extract', "pdftotext candidates: " . implode(", ", $candidates));

        foreach ($candidates as $bin) {
            $this->dbg->log('extract', "Try bin: $bin");

            if (!file_exists($bin)) {
                $this->dbg->log('extract', " - does not exist");
                continue;
            }
            if (function_exists('is_executable') && !is_executable($bin)) {
                $this->dbg->log('extract', " - not executable");
                continue;
            }

            $cmd = escapeshellcmd($bin) . " -layout -enc UTF-8 $pdf_arg $tmp_arg";
            $this->dbg->log('extract', "CMD: $cmd");

            $out = [];
            $rc = null;
            @exec($cmd . " 2>&1", $out, $rc);

            $this->dbg->log('extract', "RC: " . (is_int($rc) ? $rc : 'null'));
            if (!empty($out)) {
                $this->dbg->log('extract', "OUT (first 150 lines):\n" . implode("\n", array_slice($out, 0, 150)));
            }

            $txt = '';
            if (file_exists($tmp)) {
                $txt = @file_get_contents($tmp);
                $txt = is_string($txt) ? trim($this->normalize_text($txt)) : '';
                $this->dbg->log('extract', "Output bytes: " . strlen($txt));
            } else {
                $this->dbg->log('extract', "Output file not created: $tmp");
            }

            if ($txt !== '') {
                @unlink($tmp);
                $this->dbg->log('extract', "--- EXTRACT SUCCESS ---");
                return ['text'=>$txt];
            }
        }

        @unlink($tmp);
        $this->dbg->log('extract', "--- EXTRACT FAILED ---");
        return ['text'=>''];
    }
}
