<?php
// Real-input mapping audit for ADO quote matcher.
chdir('/var/www/html');
require_once 'wp-load.php';

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce not loaded.\n");
    exit(1);
}

$integration = ADO_Quote_Integration::instance();
$uploads = wp_upload_dir();
$index = ado_qm_get_index();
$now = gmdate('Ymd-His');
$report_dir = '/var/www/html/wp-content/themes/ado-modern/tests/output';
if (!is_dir($report_dir)) {
    wp_mkdir_p($report_dir);
}

$pdfs = [
    [
        'label' => 'Providence Manor- Hardware Schedule Operators only.pdf',
        'pdf_path' => 'C:/Users/marcr.TIME_MACHINE/Downloads/Providence Manor- Hardware Schedule Operators only.pdf',
    ],
    [
        'label' => 'Carleton University New Student Residence - Revised Hardware Schedule - January 31, 2025.pdf',
        'pdf_path' => 'C:/Users/marcr.TIME_MACHINE/Downloads/Carleton University New Student Residence - Revised Hardware Schedule - January 31, 2025.pdf',
    ],
    [
        'label' => 'Revised Hardware Schedule (1).pdf',
        'pdf_path' => 'C:/Users/marcr.TIME_MACHINE/Downloads/Revised Hardware Schedule (1).pdf',
    ],
    [
        'label' => 'CN YOW - Prelim SHOPS rev 1 - 07.23.2025 15.pdf',
        'pdf_path' => 'C:/Users/marcr.TIME_MACHINE/Downloads/CN YOW - Prelim SHOPS rev 1 - 07.23.2025 15.pdf',
    ],
    [
        'label' => 'Hardware Schedule for ADO Install (1).pdf',
        'pdf_path' => 'C:/Users/marcr.TIME_MACHINE/Downloads/Hardware Schedule for ADO Install (1).pdf',
    ],
    [
        'label' => 'Hardware Schedule.pdf',
        'pdf_path' => 'C:/Users/marcr.TIME_MACHINE/Downloads/Hardware Schedule.pdf',
    ],
];

function ado_test_slug_variants(string $pdf_path): array {
    $base = pathinfo($pdf_path, PATHINFO_FILENAME);
    $variants = [];
    $variants[] = sanitize_title($base);
    $variants[] = sanitize_title(str_replace(['(', ')'], [' ', ' '], $base));
    $variants[] = sanitize_title(str_replace([' - ', ' '], ['-', '-'], $base));
    $variants[] = sanitize_title(preg_replace('/\s*\(\d+\)$/', '', $base));
    return array_values(array_unique(array_filter($variants, 'strlen')));
}

function ado_test_compact_name(string $value): string {
    return strtolower(preg_replace('/[^a-z0-9]+/', '', $value) ?: '');
}

function ado_test_find_scoped_json(string $pdf_path, string $base_dir): string {
    $variants = ado_test_slug_variants($pdf_path);
    $target_compact = ado_test_compact_name(pathinfo($pdf_path, PATHINFO_FILENAME));
    $candidates = [];
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($dir as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        if (strtolower($file->getExtension()) !== 'json') {
            continue;
        }
        if (stripos($name, 'scoped') === false) {
            continue;
        }
        $name_lc = strtolower($name);
        $name_compact = ado_test_compact_name(preg_replace('/-scoped.*$/i', '', pathinfo($name, PATHINFO_FILENAME)));
        if ($target_compact !== '' && $name_compact === $target_compact) {
            $candidates[] = [
                'path' => $file->getPathname(),
                'mtime' => $file->getMTime(),
                'name' => $name,
                'score' => 100,
            ];
            continue;
        }
        foreach ($variants as $variant) {
            if ($variant !== '' && strpos($name_lc, strtolower($variant)) !== false) {
                $candidates[] = [
                    'path' => $file->getPathname(),
                    'mtime' => $file->getMTime(),
                    'name' => $name,
                    'score' => strlen($variant),
                ];
                break;
            }
        }
    }
    usort($candidates, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: (($b['mtime'] <=> $a['mtime']) ?: strcmp($a['name'], $b['name'])));
    return (string) ($candidates[0]['path'] ?? '');
}

function ado_test_extract_expected_segments(array $payload, array $index): array {
    $rows = [];
    foreach ((array) ($payload['result']['doors'] ?? []) as $door_index => $door) {
        if (!is_array($door)) {
            continue;
        }
        $door_number = trim((string) ($door['door_id'] ?? $door['door_number'] ?? ''));
        foreach ((array) ($door['items'] ?? []) as $item_index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = (string) ($item['raw'] ?? '');
            $segments = ado_qm_split_raw_segments($raw);
            if (!$segments) {
                $segments = [$raw];
            }
            foreach ($segments as $segment_index => $segment) {
                $segment = trim((string) $segment);
                if ($segment === '') {
                    continue;
                }
                $candidate_sources = [
                    ['catalog' => '', 'desc' => '', 'raw' => $segment],
                    ['catalog' => (string) ($item['catalog'] ?? ''), 'desc' => '', 'raw' => ''],
                    ['catalog' => '', 'desc' => (string) ($item['desc'] ?? ''), 'raw' => ''],
                ];
                $candidates = [];
                foreach ($candidate_sources as $source_item) {
                    $partial = ado_qm_extract_candidates($source_item, (string) ($source_item['raw'] ?? ''), $index);
                    if ($partial) {
                        $candidates = $partial;
                        break;
                    }
                }
                $candidate_models = [];
                foreach ($candidates as $candidate) {
                    $candidate_models[] = [
                        'fragment' => (string) ($candidate['fragment'] ?? ''),
                        'normalized' => (string) ($candidate['normalized'] ?? ''),
                        'variants' => array_values((array) ($candidate['variants'] ?? [])),
                        'anchor' => (string) ($candidate['anchor'] ?? ''),
                        'signature' => (string) ($candidate['signature'] ?? ''),
                        'brand_hints' => array_values((array) ($candidate['brand_hints'] ?? [])),
                    ];
                }
                $rows[] = [
                    'row_id' => md5(wp_json_encode([$door_number, $item_index, $segment_index, $segment])),
                    'door_number' => $door_number,
                    'item_index' => $item_index,
                    'segment_index' => $segment_index,
                    'raw_line' => $segment,
                    'catalog' => (string) ($item['catalog'] ?? ''),
                    'description' => (string) ($item['desc'] ?? ''),
                    'qty' => (int) ($item['qty'] ?? 0),
                    'candidate_models' => $candidate_models,
                ];
            }
        }
    }
    return $rows;
}

function ado_test_product_models(int $product_id, array $index): array {
    $product = (array) ($index['products'][$product_id] ?? []);
    $models = [];
    foreach ((array) ($product['models'] ?? []) as $model) {
        $norm = ado_qm_compact((string) $model);
        if ($norm === '') {
            continue;
        }
        $models[$norm] = [
            'display' => (string) $model,
            'anchor' => ado_qm_alpha_prefix((string) $model),
            'head' => ado_qm_numeric_head((string) $model),
            'signature' => ado_qm_model_signature((string) $model),
        ];
    }
    return $models;
}

function ado_test_expected_model_labels(array $segment): array {
    $candidate = (array) (($segment['candidate_models'][0] ?? []) ?: []);
    $normalized = (string) ($candidate['normalized'] ?? '');
    $fragment = (string) ($candidate['fragment'] ?? '');
    if ($normalized === '' || $fragment === '') {
        return [];
    }
    return [$normalized => $fragment];
}

function ado_test_line_looks_like_operator_body(string $raw_line): bool {
    $raw = strtoupper($raw_line);
    return (bool) preg_match('/\b(?:AUTO\s+OPENER|OPERATOR|AUTO\s+DOOR\s+OPERATOR|SURFACE\s+CLOSER)\b/', $raw);
}

function ado_test_is_accessory_or_fragment(string $raw_line): bool {
    $raw = strtoupper($raw_line);
    if ((bool) preg_match('/\b(?:MOUNT(?:ING)?\s+PLATE|TB\s+X|SWITCH|PLATE|HARNESS|POWER\s+TRANSFER|CONCEALED\s+IN\s+HEADER)\b/', $raw)) {
        return true;
    }
    return false;
}

function ado_test_match_compatibility(array $segment, array $log_row, array $index): array {
    $product_id = (int) ($log_row['matched_product_id'] ?? 0);
    if ($product_id <= 0) {
        return ['ok' => false, 'reason' => 'UNMATCHED', 'matched_model' => ''];
    }

    $expected = (array) ($segment['candidate_models'] ?? []);
    if (!$expected) {
        return ['ok' => false, 'reason' => 'NO_EXPECTED_MODEL', 'matched_model' => ''];
    }

    $product_models = ado_test_product_models($product_id, $index);
    $product_name = (string) (($index['products'][$product_id]['title'] ?? '') ?: '');
    $matched_model = '';
    $raw_line = strtoupper((string) ($segment['raw_line'] ?? ''));
    $product_name_upper = strtoupper($product_name);

    if (strpos($raw_line, 'PUSH PLATE') !== false && !preg_match('/\b(?:PUSH|PLATE|ACTUATOR|BUTTON)\b/', $product_name_upper)) {
        return ['ok' => false, 'reason' => 'CONTEXT_PRODUCT_MISMATCH', 'matched_model' => ''];
    }

    foreach ($expected as $candidate) {
        $normalized = (string) ($candidate['normalized'] ?? '');
        if ($normalized !== '' && isset($product_models[$normalized])) {
            return ['ok' => true, 'reason' => 'EXACT_PRODUCT_MODEL', 'matched_model' => $normalized];
        }
        foreach ((array) ($candidate['variants'] ?? []) as $variant) {
            if ($variant !== '' && isset($product_models[$variant])) {
                return ['ok' => true, 'reason' => 'EXACT_VARIANT', 'matched_model' => $variant];
            }
        }
    }

    foreach ($expected as $candidate) {
        $head = ado_qm_numeric_head((string) ($candidate['normalized'] ?? ''));
        $anchor = (string) ($candidate['anchor'] ?? '');
        $signature = (string) ($candidate['signature'] ?? '');
        foreach ($product_models as $product_norm => $product_meta) {
            $same_head = $head !== '' && $head === (string) ($product_meta['head'] ?? '');
            $same_anchor = $anchor !== '' && $anchor === (string) ($product_meta['anchor'] ?? '');
            $same_signature = $signature !== '' && $signature === (string) ($product_meta['signature'] ?? '');
            if ($same_head && ($same_anchor || $same_signature)) {
                return ['ok' => true, 'reason' => 'FAMILY_COMPATIBLE', 'matched_model' => $product_norm];
            }
        }
    }

    foreach ($expected as $candidate) {
        $normalized = ado_qm_compact((string) ($candidate['normalized'] ?? ''));
        if ($normalized === '' || !preg_match('/^(95[3456])\d+$/', $normalized, $m)) {
            continue;
        }
        foreach ($product_models as $product_norm => $product_meta) {
            if (str_starts_with($product_norm, $m[1] . '0IQ')) {
                return ['ok' => true, 'reason' => 'IQ_FAMILY_COMPATIBLE', 'matched_model' => $product_norm];
            }
        }
    }

    foreach ($expected as $candidate) {
        $normalized = ado_qm_compact((string) ($candidate['normalized'] ?? ''));
        if ($normalized !== '9500') {
            continue;
        }
        $raw = strtoupper((string) ($segment['raw_line'] ?? ''));
        foreach ($product_models as $product_norm => $product_meta) {
            if (strpos($raw, 'ELECTRIC STRIKE') !== false && $product_norm === 'HES9500SERIES') {
                return ['ok' => true, 'reason' => '9500_STRIKE_FAMILY', 'matched_model' => $product_norm];
            }
            if (strpos($raw, 'MOUNTING PLATE') !== false && $product_norm === '950018') {
                return ['ok' => true, 'reason' => '9500_PLATE_FAMILY', 'matched_model' => $product_norm];
            }
        }
    }

    // Allow generic operator family products only for actual operator bodies.
    if (stripos($product_name, '9500 SERIES') !== false && ado_test_line_looks_like_operator_body((string) ($segment['raw_line'] ?? '')) && !ado_test_is_accessory_or_fragment((string) ($segment['raw_line'] ?? ''))) {
        return ['ok' => true, 'reason' => 'GENERIC_OPERATOR_FAMILY', 'matched_model' => '9500'];
    }

    return ['ok' => false, 'reason' => 'PRODUCT_MODEL_MISMATCH', 'matched_model' => ''];
}

function ado_test_assertions(array $file_report): array {
    $missing = count((array) ($file_report['missing_models'] ?? [])) === 0;
    $extra = count((array) ($file_report['extra_models'] ?? [])) === 0;
    $wrong = count((array) ($file_report['mismatched_rows'] ?? [])) === 0;
    $stable = !empty($file_report['idempotence']['ok']);
    return [
        'coverage' => $missing,
        'no_extra' => $extra,
        'correct_matches' => $wrong,
        'idempotence' => $stable,
        'all_green' => ($missing && $extra && $wrong && $stable),
    ];
}

function ado_test_segment_is_mappable(array $segment, array $index): bool {
    $raw_line = (string) ($segment['raw_line'] ?? '');
    if (ado_qm_is_external_scope_line($raw_line) || (bool) preg_match('/\bBY\s+OWNERS?\b/i', $raw_line)) {
        return false;
    }
    $context = ado_qm_context_words(((string) ($segment['description'] ?? '')) . ' ' . ((string) ($segment['raw_line'] ?? '')));
    foreach ((array) ($segment['candidate_models'] ?? []) as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        if (!empty(ado_qm_resolve_iq_operator_family($candidate, (string) ($segment['raw_line'] ?? ''), $index))) {
            return true;
        }
        foreach ((array) ($candidate['variants'] ?? []) as $variant) {
            $ids = array_values(array_unique(array_map('intval', (array) ($index['global_models'][$variant] ?? []))));
            foreach ($ids as $id) {
                $compat = ado_test_match_compatibility($segment, ['matched_product_id' => $id], $index);
                if (!empty($compat['ok'])) {
                    return true;
                }
            }
        }
        foreach (ado_qm_fuzzy_product_rows($candidate, $context, $index) as $row) {
            $compat = ado_test_match_compatibility($segment, ['matched_product_id' => (int) ($row['product_id'] ?? 0)], $index);
            if (!empty($compat['ok'])) {
                return true;
            }
        }
    }
    return false;
}

function ado_test_sorted_unique(array $values): array {
    $values = array_values(array_unique(array_filter(array_map('strval', $values), 'strlen')));
    sort($values, SORT_STRING);
    return $values;
}

$report = [
    'generated_at' => gmdate('c'),
    'reports' => [],
    'summary' => [],
];

$admin_user = get_user_by('login', 'marcr');
$user_id = $admin_user ? (int) $admin_user->ID : 1;

foreach ($pdfs as $entry) {
    $pdf_path = (string) $entry['pdf_path'];
    $label = (string) $entry['label'];
    $scope_path = ado_test_find_scoped_json($pdf_path, (string) $uploads['basedir']);
    $file_report = [
        'label' => $label,
        'pdf_path' => $pdf_path,
        'scope_path' => $scope_path,
        'status' => 'ok',
    ];

    if ($scope_path === '' || !file_exists($scope_path)) {
        $file_report['status'] = 'missing_scope_json';
        $file_report['assertions'] = ['all_green' => false];
        $report['reports'][] = $file_report;
        continue;
    }

    $payload = json_decode((string) file_get_contents($scope_path), true);
    if (!is_array($payload)) {
        $file_report['status'] = 'invalid_scope_json';
        $file_report['assertions'] = ['all_green' => false];
        $report['reports'][] = $file_report;
        continue;
    }

    $expected_segments = ado_test_extract_expected_segments($payload, $index);
    $expected_rows_with_models = array_values(array_filter($expected_segments, static fn(array $row): bool => !empty($row['candidate_models'])));
    $mappable_segments = [];
    $catalog_gap_models = [];
    foreach ($expected_rows_with_models as $segment) {
        if (ado_test_segment_is_mappable($segment, $index)) {
            $mappable_segments[] = $segment;
            continue;
        }
        foreach (ado_test_expected_model_labels($segment) as $norm => $label_text) {
            $catalog_gap_models[$norm] = $label_text;
        }
    }

    $quote_name = 'Audit ' . $label . ' ' . $now;
    $create = $integration->create_quote_from_payload($user_id, $payload, ['name' => $quote_name, 'scope_path' => $scope_path, 'debug' => true]);
    $file_report['create_result'] = $create;
    if (empty($create['ok']) || empty($create['quote_id'])) {
        $file_report['status'] = 'quote_create_failed';
        $file_report['expected_model_count'] = count($expected_rows_with_models);
        $file_report['assertions'] = ['all_green' => false];
        $report['reports'][] = $file_report;
        continue;
    }

    $quote_id = (int) $create['quote_id'];
    $snapshot = get_post_meta($quote_id, '_adq_cart_snapshot', true);
    $snapshot = is_array($snapshot) ? $snapshot : [];
    $match_log = get_post_meta($quote_id, '_adq_match_log', true);
    $match_log = is_array($match_log) ? $match_log : [];
    $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $unmatched = is_array($unmatched) ? $unmatched : [];
    $excluded = get_post_meta($quote_id, '_adq_excluded_items', true);
    $excluded = is_array($excluded) ? $excluded : [];

    $log_by_raw = [];
    foreach ($match_log as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row['door_number'] ?? '')) . '|' . trim((string) ($row['raw_line'] ?? ''));
        $log_by_raw[$key][] = $row;
    }

    $matched_models = [];
    $missing_models = [];
    $extra_models = [];
    $mismatched_rows = [];
    $matched_rows = [];
    $expected_model_labels = [];

    foreach ($mappable_segments as $segment) {
        $expected_labels = ado_test_expected_model_labels($segment);
        foreach ($expected_labels as $norm => $label_text) {
            $expected_model_labels[$norm] = $label_text;
        }

        $segment_raw = trim((string) ($segment['raw_line'] ?? ''));
        $log_key = trim((string) ($segment['door_number'] ?? '')) . '|' . $segment_raw;
        $candidate_logs = (array) ($log_by_raw[$log_key] ?? []);
        $best = null;
        foreach ($candidate_logs as $row) {
            if (!is_array($row)) {
                continue;
            }
            $compat = ado_test_match_compatibility($segment, $row, $index);
            $row['_compat'] = $compat;
            if ($best === null) {
                $best = $row;
                continue;
            }
            $best_ok = !empty($best['_compat']['ok']);
            $row_ok = !empty($compat['ok']);
            if ($row_ok && !$best_ok) {
                $best = $row;
                continue;
            }
            if ($row_ok === $best_ok && (int) ($row['confidence'] ?? 0) > (int) ($best['confidence'] ?? 0)) {
                $best = $row;
            }
        }

        if ($best === null) {
            foreach ($expected_labels as $norm => $label_text) {
                $missing_models[$norm] = $label_text;
            }
            continue;
        }

        $compat = (array) ($best['_compat'] ?? []);
        if (!empty($compat['ok'])) {
            foreach ($expected_labels as $norm => $label_text) {
                $matched_models[$norm] = $label_text;
            }
            $matched_rows[] = [
                'door_number' => (string) ($best['door_number'] ?? ''),
                'raw_line' => $segment_raw,
                'expected_models' => array_keys($expected_labels),
                'matched_product_id' => (int) ($best['matched_product_id'] ?? 0),
                'matched_product_name' => (string) (($index['products'][(int) ($best['matched_product_id'] ?? 0)]['title'] ?? '')),
                'match_method' => (string) ($best['matched_by'] ?? ''),
                'compatibility' => $compat,
            ];
        } else {
            foreach ($expected_labels as $norm => $label_text) {
                $missing_models[$norm] = $label_text;
            }
            if ((int) ($best['matched_product_id'] ?? 0) > 0 && !empty($best['_compat']['matched_model'])) {
                $extra_models[(string) $best['_compat']['matched_model']] = (string) $best['_compat']['matched_model'];
            }
            $mismatched_rows[] = [
                'door_number' => (string) ($best['door_number'] ?? ''),
                'raw_line' => $segment_raw,
                'expected_models' => array_keys($expected_labels),
                'matched_product_id' => (int) ($best['matched_product_id'] ?? 0),
                'matched_product_name' => (string) (($index['products'][(int) ($best['matched_product_id'] ?? 0)]['title'] ?? '')),
                'match_method' => (string) ($best['matched_by'] ?? ''),
                'reason' => (string) ($compat['reason'] ?? 'UNKNOWN'),
                'trace' => array_values((array) ($best['attempts'] ?? [])),
            ];
        }
    }

    $rerun = $integration->rerun_matching($quote_id, true);
    $rerun_snapshot = get_post_meta($quote_id, '_adq_cart_snapshot', true);
    $rerun_snapshot = is_array($rerun_snapshot) ? $rerun_snapshot : [];
    $idempotence_a = array_map(static fn(array $row): string => md5(wp_json_encode($row)), array_values($snapshot));
    $idempotence_b = array_map(static fn(array $row): string => md5(wp_json_encode($row)), array_values($rerun_snapshot));
    sort($idempotence_a, SORT_STRING);
    sort($idempotence_b, SORT_STRING);

    $suspicious_rows = [];
    foreach ($match_log as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = strtoupper(trim((string) ($row['raw_line'] ?? '')));
        $method = (string) ($row['matched_by'] ?? '');
        $pid = (int) ($row['matched_product_id'] ?? 0);
        $title = (string) (($index['products'][$pid]['title'] ?? ''));
        if ($method === 'user_override' && strpos($title, '9500 Series') !== false && ado_test_is_accessory_or_fragment($raw)) {
            $suspicious_rows[] = [
                'raw_line' => (string) ($row['raw_line'] ?? ''),
                'matched_product_id' => $pid,
                'matched_product_name' => $title,
                'reason' => 'override_operator_family_on_accessory_or_fragment',
            ];
        }
        if ($pid > 0 && trim((string) ($row['model'] ?? '')) === '' && trim((string) ($row['description'] ?? '')) === '') {
            $suspicious_rows[] = [
                'raw_line' => (string) ($row['raw_line'] ?? ''),
                'matched_product_id' => $pid,
                'matched_product_name' => $title,
                'reason' => 'matched_with_blank_source_fields',
            ];
        }
    }

    $file_report['quote_id'] = $quote_id;
    $file_report['expected_model_count'] = count($expected_model_labels);
    $file_report['matched_model_count'] = count($matched_models);
    $file_report['missing_models'] = ado_test_sorted_unique(array_keys($missing_models));
    $file_report['extra_models'] = ado_test_sorted_unique(array_keys($extra_models));
    $file_report['matched_models'] = ado_test_sorted_unique(array_keys($matched_models));
    $file_report['catalog_gap_models'] = ado_test_sorted_unique(array_keys($catalog_gap_models));
    $file_report['mismatched_rows'] = $mismatched_rows;
    $file_report['suspicious_rows'] = $suspicious_rows;
    $file_report['unmatched_count'] = count($unmatched);
    $file_report['excluded_count'] = count($excluded);
    $file_report['snapshot_count'] = count($snapshot);
    $file_report['idempotence'] = [
        'ok' => !empty($rerun['ok']) && $idempotence_a === $idempotence_b,
        'rerun_result' => $rerun,
        'before_hashes' => $idempotence_a,
        'after_hashes' => $idempotence_b,
    ];
    $file_report['matched_rows_sample'] = array_slice($matched_rows, 0, 25);
    $file_report['assertions'] = ado_test_assertions($file_report);

    $report['reports'][] = $file_report;
}

$all_green = true;
$summary_lines = [];
foreach ($report['reports'] as $file_report) {
    $assertions = (array) ($file_report['assertions'] ?? []);
    if (empty($assertions['all_green'])) {
        $all_green = false;
    }
    $summary_lines[] = [
        'label' => (string) ($file_report['label'] ?? ''),
        'status' => (string) ($file_report['status'] ?? 'ok'),
        'expected_models' => (int) ($file_report['expected_model_count'] ?? 0),
        'matched_models' => (int) ($file_report['matched_model_count'] ?? 0),
        'missing_models' => count((array) ($file_report['missing_models'] ?? [])),
        'extra_models' => count((array) ($file_report['extra_models'] ?? [])),
        'mismatched_rows' => count((array) ($file_report['mismatched_rows'] ?? [])),
        'suspicious_rows' => count((array) ($file_report['suspicious_rows'] ?? [])),
        'idempotence_ok' => !empty($file_report['idempotence']['ok']),
        'all_green' => !empty($assertions['all_green']),
    ];
}
$report['summary'] = [
    'all_green' => $all_green,
    'files' => $summary_lines,
];

$json_path = $report_dir . '/model-mapping-audit-' . $now . '.json';
$md_path = $report_dir . '/model-mapping-audit-' . $now . '.md';
file_put_contents($json_path, wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$md = [];
$md[] = '# Model Mapping Audit';
$md[] = '';
$md[] = '- Generated: ' . gmdate('c');
$md[] = '- Overall green: ' . ($all_green ? 'yes' : 'no');
$md[] = '';
foreach ($report['reports'] as $file_report) {
    $md[] = '## ' . ($file_report['label'] ?? 'Unknown');
    $md[] = '';
    $md[] = '- Status: ' . ($file_report['status'] ?? 'ok');
    $md[] = '- Scope JSON: `' . ($file_report['scope_path'] ?? '') . '`';
    $md[] = '- Quote ID: ' . (string) ($file_report['quote_id'] ?? 0);
    $md[] = '- Expected models: ' . (string) ($file_report['expected_model_count'] ?? 0);
    $md[] = '- Matched models: ' . (string) ($file_report['matched_model_count'] ?? 0);
    $md[] = '- Missing models: ' . count((array) ($file_report['missing_models'] ?? []));
    $md[] = '- Extra models: ' . count((array) ($file_report['extra_models'] ?? []));
    $md[] = '- Mismatched rows: ' . count((array) ($file_report['mismatched_rows'] ?? []));
    $md[] = '- Suspicious rows: ' . count((array) ($file_report['suspicious_rows'] ?? []));
    $md[] = '- Idempotence: ' . (!empty($file_report['idempotence']['ok']) ? 'pass' : 'fail');
    if (!empty($file_report['missing_models'])) {
        $md[] = '- Missing model list: ' . implode(', ', array_slice((array) $file_report['missing_models'], 0, 80));
    }
    if (!empty($file_report['extra_models'])) {
        $md[] = '- Extra model list: ' . implode(', ', array_slice((array) $file_report['extra_models'], 0, 80));
    }
    if (!empty($file_report['mismatched_rows'])) {
        $md[] = '- Mismatch sample:';
        foreach (array_slice((array) $file_report['mismatched_rows'], 0, 10) as $row) {
            $md[] = '  - `' . ($row['raw_line'] ?? '') . '` => `' . ($row['matched_product_name'] ?? '') . '` (' . ($row['reason'] ?? '') . ')';
        }
    }
    $md[] = '';
}
file_put_contents($md_path, implode("\n", $md));

echo "JSON report: {$json_path}\n";
echo "Markdown report: {$md_path}\n";
foreach ($summary_lines as $line) {
    echo sprintf(
        "[%s] %s :: expected=%d matched=%d missing=%d extra=%d mismatched=%d suspicious=%d idempotence=%s\n",
        !empty($line['all_green']) ? 'PASS' : 'FAIL',
        $line['label'],
        $line['expected_models'],
        $line['matched_models'],
        $line['missing_models'],
        $line['extra_models'],
        $line['mismatched_rows'],
        $line['suspicious_rows'],
        !empty($line['idempotence_ok']) ? 'pass' : 'fail'
    );
}

exit($all_green ? 0 : 1);
