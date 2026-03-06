<?php
if (!defined('ABSPATH')) {
    exit;
}

$failures = [];

$cases = [
    [
        'pdf_name' => 'Providence Manor- Hardware Schedule Operators only.pdf',
        'scoped_prefix' => 'Providence-Manor-Hardware-Schedule-Operators-only-scoped',
    ],
    [
        'pdf_name' => 'Hardware Schedule for ADO Install.pdf',
        'scoped_prefix' => 'Hardware-Schedule-for-ADO-Install-scoped',
    ],
    [
        'pdf_name' => 'Carleton University New Student Residence - Revised Hardware Schedule - January 31, 2025.pdf',
        'scoped_prefix' => 'Carleton-University-New-Student-Residence-Revised-Hardware-Schedule-January-31-2025-scoped',
    ],
    [
        'pdf_name' => 'carleton_tmp.pdf',
        'scoped_prefix' => 'carleton_tmp-scoped',
    ],
    [
        'pdf_name' => 'Hardware Schedule.pdf',
        'scoped_prefix' => 'Hardware-Schedule-scoped',
    ],
    [
        'pdf_name' => 'Revised Hardware Schedule (1).pdf',
        'scoped_prefix' => 'Revised-Hardware-Schedule-1-scoped',
    ],
    [
        'pdf_name' => 'Queen\'s University Leonard Hall Renovations-Hardware Schedule.pdf',
        'scoped_prefix' => 'Queens-University-Leonard-Hall-Renovations-Hardware-Schedule-scoped',
    ],
    [
        'pdf_name' => 'CN YOW - Prelim SHOPS rev 1 - 07.23.2025 15.pdf',
        'scoped_prefix' => 'CN-YOW-Prelim-SHOPS-rev-1-07.23.2025-15-scoped',
    ],
    [
        'pdf_name' => 'Hardware Schedule for ADO Install (1).pdf',
        'scoped_prefix' => 'Hardware-Schedule-for-ADO-Install-1-scoped',
    ],
    [
        'pdf_name' => 'hardware (1).pdf',
        'scoped_prefix' => 'hardware-1-scoped',
    ],
];

$print_counts = static function (array $counts, int $limit = 5): string {
    if (!$counts) {
        return '-';
    }
    $pairs = [];
    foreach (array_slice($counts, 0, $limit, true) as $key => $count) {
        $pairs[] = $key . '=' . (int) $count;
    }
    return implode(', ', $pairs);
};

$find_latest_scoped = static function (string $uploads_dir, string $prefix): string {
    $pattern = trailingslashit($uploads_dir) . $prefix . '*.json';
    $paths = glob($pattern) ?: [];
    $best_path = '';
    $best_rank = -1;
    $best_mtime = -1;
    foreach ($paths as $path) {
        $name = basename($path);
        if (!preg_match('/^' . preg_quote($prefix, '/') . '(?:-(\d+))?\.json$/', $name, $matches)) {
            continue;
        }
        $rank = isset($matches[1]) ? (int) $matches[1] : 0;
        $mtime = (int) (filemtime($path) ?: 0);
        if ($rank > $best_rank || ($rank === $best_rank && $mtime > $best_mtime)) {
            $best_path = $path;
            $best_rank = $rank;
            $best_mtime = $mtime;
        }
    }
    return $best_path;
};

$scope_url_from_path = static function (string $scope_path): string {
    $uploads = wp_upload_dir();
    $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
    $base_url = (string) ($uploads['baseurl'] ?? '');
    $scope_path = wp_normalize_path($scope_path);
    if ($base_dir === '' || $base_url === '' || $scope_path === '') {
        return '';
    }
    if (strpos($scope_path, $base_dir) !== 0) {
        return '';
    }
    $relative = ltrim(substr($scope_path, strlen($base_dir)), '/');
    return trailingslashit($base_url) . str_replace('\\', '/', $relative);
};

$uploads = wp_upload_dir();
$uploads_dir = (string) ($uploads['basedir'] ?? '');

echo 'Quote PDF regression set: ' . count($cases) . ' scoped inputs' . PHP_EOL;

foreach ($cases as $case) {
    $pdf_name = (string) ($case['pdf_name'] ?? '');
    $prefix = (string) ($case['scoped_prefix'] ?? '');
    $scope_path = $find_latest_scoped($uploads_dir, $prefix);
    if ($scope_path === '' || !file_exists($scope_path)) {
        $failures[] = 'missing scoped json for ' . $pdf_name;
        echo 'FAIL ' . $pdf_name . ' missing scoped json for prefix ' . $prefix . PHP_EOL;
        continue;
    }

    $payload = ado_quote_load_scope_payload_from_path($scope_path);
    if (!is_array($payload) || empty($payload['result']['doors'])) {
        $failures[] = 'invalid scoped payload for ' . $pdf_name;
        echo 'FAIL ' . $pdf_name . ' invalid scoped payload' . PHP_EOL;
        continue;
    }

    $mapped = ado_build_cart_lines_from_scope($payload);
    $matched_lines = array_values((array) ($mapped['lines'] ?? []));
    $matched_qty = 0;
    foreach ($matched_lines as $line) {
        if (!is_array($line)) { continue; }
        $matched_qty += max(0, (int) ($line['qty'] ?? 0));
    }

    $draft = [
        'id' => 'regression-' . sanitize_title_with_dashes(pathinfo($pdf_name, PATHINFO_FILENAME)),
        'name' => $pdf_name,
        'created_at' => wp_date('Y-m-d H:i'),
        'updated_at' => wp_date('Y-m-d H:i'),
        'scope_path' => $scope_path,
        'scope_url' => $scope_url_from_path($scope_path),
        'items' => $matched_lines,
        'total_items' => $matched_qty,
        'unmatched' => array_values((array) ($mapped['unmatched'] ?? [])),
        'unmatched_count' => count((array) ($mapped['unmatched'] ?? [])),
        'debug_log' => array_values((array) ($mapped['debug_log'] ?? [])),
    ];
    $draft = array_merge($draft, ado_quote_write_debug_log_file($draft));
    $debug_path = (string) ($draft['debug_log_file_path'] ?? '');
    if ($debug_path === '' || !file_exists($debug_path)) {
        $failures[] = 'missing debug export for ' . $pdf_name;
        echo 'FAIL ' . $pdf_name . ' debug export missing' . PHP_EOL;
        continue;
    }

    $debug_payload = json_decode((string) file_get_contents($debug_path), true);
    if (!is_array($debug_payload)) {
        $failures[] = 'invalid debug export for ' . $pdf_name;
        echo 'FAIL ' . $pdf_name . ' debug export invalid json' . PHP_EOL;
        continue;
    }

    $summary = (array) ($debug_payload['summary'] ?? []);
    echo 'PASS ' . $pdf_name . PHP_EOL;
    echo '  scoped=' . basename($scope_path) . PHP_EOL;
    echo '  matched_lines=' . count($matched_lines) . ' matched_qty=' . $matched_qty . ' unmatched=' . (int) ($debug_payload['unmatched_count'] ?? 0) . PHP_EOL;
    echo '  reasons=' . $print_counts((array) ($summary['reason_counts'] ?? [])) . PHP_EOL;
    echo '  normalized=' . $print_counts((array) ($summary['normalized_model_counts'] ?? [])) . PHP_EOL;
    echo '  tokens=' . $print_counts((array) ($summary['token_counts'] ?? [])) . PHP_EOL;
    echo '  candidate_skus=' . $print_counts((array) ($summary['candidate_sku_counts'] ?? [])) . PHP_EOL;
    echo '  debug=' . $debug_path . PHP_EOL;
}

if ($failures) {
    fwrite(STDERR, 'Failures: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo "Quote PDF regression completed.\n";
