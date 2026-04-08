<?php
if (defined('ADO_BRAND_MATCHERS_LOADED')) {
    return;
}
define('ADO_BRAND_MATCHERS_LOADED', true);

foreach ([
    'ado-brand-matcher-contract.php',
    'ado-brand-registry.php',
    'ado-brand-detector.php',
    'ado-camden-pack.php',
    'ado-camden-parser.php',
    'ado-camden-resolver.php',
    'ado-camden-matcher.php',
    'ado-generic-brand-matcher.php',
    'ado-brand-match-orchestrator.php',
] as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

/**
 * Brand-aware matcher layer bootstrapping.
 * Current orchestrator routes everything through the generic matcher adapter by default.
 * Future brand matchers can register themselves via ado_register_brand_matcher().
 */
