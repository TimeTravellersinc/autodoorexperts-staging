#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This is a CLI-only helper; run via php from a terminal.\n");
    exit(1);
}

define('WP_USE_THEMES', false);

$wp_load = __DIR__ . '/../site/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "Unable to locate WordPress loader at {$wp_load}.\n");
    exit(1);
}
require $wp_load;

$plugin_file = ABSPATH . 'wp-content/plugins/autodoor-pdf-parser/autodoor-pdf-parser.php';
if (!file_exists($plugin_file)) {
    fwrite(STDERR, "AutoDoor parser plugin is missing at {$plugin_file}.\n");
    exit(1);
}
require_once $plugin_file;

$theme_file = ABSPATH . 'wp-content/themes/ado-modern/inc/ado-portal/ado-quote-carts.php';
if (!file_exists($theme_file)) {
    fwrite(STDERR, "Client quote toolbox is missing at {$theme_file}.\n");
    exit(1);
}
require_once $theme_file;

function usage(): void
{
    $message = <<<USAGE
Usage:
  php scripts/cli-upload-quote.php --pdf=/path/to/schedule.pdf [--pdf=/another.pdf] [--name="Project Name"] [--user=3] [--debug=1]

Options:
  --pdf        Required; can appear multiple times.
  --name       Optional prefix for generated quote names.
  --user       Optional WordPress user ID (defaults to 3).
  --debug      Enable verbose quote-debug/log output (0 or 1).
  --pdftotext  Force a pdftotext binary path.
  --store-scope Control writing scoped JSON (1|0). Default is 1.
USAGE;
    fwrite(STDERR, $message . "\n");
}

$options = getopt('', ['pdf:', 'name::', 'user::', 'debug::', 'pdftotext::', 'store-scope::']);
if (empty($options['pdf'])) {
    usage();
    exit(1);
}

$pdfEntries = is_array($options['pdf']) ? $options['pdf'] : [$options['pdf']];
$userId = isset($options['user']) && (int) $options['user'] > 0 ? (int) $options['user'] : 3;
$userNameFlag = trim((string) ($options['name'] ?? ''));
$debugMode = filter_var($options['debug'] ?? '0', FILTER_VALIDATE_BOOLEAN);
$storeScope = filter_var($options['store-scope'] ?? '1', FILTER_VALIDATE_BOOLEAN);
$forcedPdftotext = isset($options['pdftotext']) ? trim((string) $options['pdftotext']) : '';

wp_set_current_user($userId);

$pdftotext = '';
foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
    if (empty($pdftotext) && is_executable($candidate)) {
        $pdftotext = $candidate;
    }
}
if ($forcedPdftotext !== '') {
    $pdftotext = $forcedPdftotext;
}
$which = trim((string) shell_exec('which pdftotext 2>/dev/null'));
if ($pdftotext === '' && $which !== '' && is_executable($which)) {
    $pdftotext = $which;
}
if ($pdftotext === '') {
    fwrite(STDERR, "pdftotext binary not found; install Poppler or pass --pdftotext.\n");
    exit(1);
}

$debugger = new ADX_Debug();
$extractor = new ADX_Extractor($pdftotext, $debugger);
$parser = new ADX_Parser($debugger);
$scope = new ADX_Scope($debugger);

$uploads = wp_upload_dir();
if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
    fwrite(STDERR, "Uploads directory unavailable for storing scoped JSON.\n");
    exit(1);
}

$results = [];
foreach ($pdfEntries as $index => $pdfPathRaw) {
    $pdfPath = (string) trim($pdfPathRaw);
    $result = [
        'pdf' => $pdfPath,
        'quote_name' => '',
        'quote_id' => null,
        'scope_url' => '',
        'scope_path' => '',
        'status' => 'pending',
        'message' => '',
        'matched' => [],
        'unmatched_count' => 0,
        'debug_log' => [],
        'parser_log' => [],
    ];

    if ($pdfPath === '' || !is_file($pdfPath)) {
        $result['status'] = 'error';
        $result['message'] = 'PDF missing or unreadable.';
        $results[] = $result;
        continue;
    }

    try {
        $debugger->reset();
        $extracted = $extractor->extract_text_pdftotext($pdfPath);
        $text = trim((string) ($extracted['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('Parsed text is empty.');
        }
        $parsed = $parser->adaptive_parse($text);
        $scoped = $scope->apply_operator_scope_filter_to_result($parsed);
    } catch (Throwable $e) {
        $result['status'] = 'error';
        $result['message'] = 'Parsing failed: ' . $e->getMessage();
        $result['parser_log'] = $debugger->all();
        $results[] = $result;
        continue;
    }

    $baseName = sanitize_file_name(pathinfo($pdfPath, PATHINFO_FILENAME));
    if ($baseName === '') {
        $baseName = 'cli-quote';
    }

    $quoteName = $userNameFlag !== '' ? "{$userNameFlag} - {$baseName}" : 'CLI Quote - ' . $baseName;
    $result['quote_name'] = $quoteName;

    $payload = [
        'meta' => [
            'source_pdf_name' => basename($pdfPath),
            'source_pdf_path' => $pdfPath,
            'generated_at' => current_time('c'),
            'parser_build' => defined('ADX_PARSER_BUILD') ? ADX_PARSER_BUILD : 'cli',
        ],
        'result' => $scoped,
    ];

    $scopeUrl = '';
    $scopePath = '';
    if ($storeScope) {
        $scopeFile = wp_unique_filename($uploads['basedir'], $baseName . '-cli-scoped.json');
        $scopePath = trailingslashit($uploads['basedir']) . $scopeFile;
        $scopeData = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($scopeData) || $scopeData === '') {
            $result['status'] = 'error';
            $result['message'] = 'Failed to encode scoped JSON.';
            $results[] = $result;
            continue;
        }
        if (@file_put_contents($scopePath, $scopeData) === false) {
            $result['status'] = 'error';
            $result['message'] = 'Failed to write scoped JSON to uploads directory.';
            $results[] = $result;
            continue;
        }
        $scopeUrl = trailingslashit($uploads['baseurl']) . $scopeFile;
        $result['scope_url'] = $scopeUrl;
        $result['scope_path'] = $scopePath;
    }

    $quoteResult = ado_quote_integration()->create_quote_from_payload($userId, $payload, [
        'name' => $quoteName,
        'scope_url' => $scopeUrl,
        'scope_path' => $scopePath,
        'debug' => $debugMode,
    ]);

    $result['parser_log'] = $debugger->all();
    $result['debug_log'] = is_array($quoteResult['debug_log'] ?? null) ? array_values($quoteResult['debug_log']) : [];
    $result['status'] = empty($quoteResult['ok']) ? 'error' : 'created';
    $result['message'] = $quoteResult['message'] ?? ($result['status'] === 'created' ? 'Quote ready.' : 'Quote creation failed.');
    $result['quote_id'] = isset($quoteResult['quote_id']) ? (int) $quoteResult['quote_id'] : null;
    $result['unmatched_count'] = $quoteResult['unmatched_count'] ?? 0;
    $result['matched'] = isset($quoteResult['matched']) ? $quoteResult['matched'] : [];
    if ($result['quote_id'] > 0) {
        $result['quote_url'] = ado_quote_url($result['quote_id']);
    }

    if (isset($quoteResult['unmatched']) && is_array($quoteResult['unmatched'])) {
        $result['unmatched'] = array_values($quoteResult['unmatched']);
    }

    $results[] = $result;
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
