<?php
/*
Plugin Name: AutoDoor PDF Parser DEBUG (Adaptive Parser)
Description: Upload PDF, extract text via pdftotext, detect format, split into sections, infer schema, parse into structured JSON. Debug build with 4 debug windows (extract/split/parse/scope).
Version: 2.7.4-q1
Author: Marc Touma
*/

if (!defined('ABSPATH')) { exit; }

define('ADX_PARSER_VERSION', '2.7.4-q1');
define('ADX_PARSER_BUILD', 'DEBUG-HOSTINGER-2.7.4-QUOTE');
define('ADX_PARSER_PATH', plugin_dir_path(__FILE__));
define('ADX_PARSER_URL', plugin_dir_url(__FILE__));

require_once ADX_PARSER_PATH . 'includes/class-adx-debug.php';
require_once ADX_PARSER_PATH . 'includes/class-adx-extract.php';
require_once ADX_PARSER_PATH . 'includes/class-adx-parse.php';
require_once ADX_PARSER_PATH . 'includes/class-adx-scope.php';
require_once ADX_PARSER_PATH . 'includes/class-autodoor-pdf-debug-hostinger.php';

new AutoDoorPDFDebug_Hostinger();
