<?php
chdir('/var/www/html');
require_once 'wp-load.php';

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce not loaded.\n");
    exit(1);
}

if (!class_exists('ADX_Debug') || !class_exists('ADX_Extractor') || !class_exists('ADX_Parser') || !class_exists('ADX_Scope')) {
    fwrite(STDERR, "Parser classes not loaded.\n");
    exit(1);
}

$pdfs = array_slice($argv, 1);
if (!$pdfs) {
    $pdfs = [
        '/tmp/ado-pdf-audit/Carleton University New Student Residence - Revised Hardware Schedule - January 31, 2025.pdf',
        '/tmp/ado-pdf-audit/Hardware Schedule.pdf',
        '/tmp/ado-pdf-audit/Providence Manor- Hardware Schedule Operators only.pdf',
        '/tmp/ado-pdf-audit/Hardware Schedule for ADO Install (1).pdf',
    ];
}

$reportDir = '/var/www/html/wp-content/themes/ado-modern/tests/output';
if (!is_dir($reportDir)) {
    wp_mkdir_p($reportDir);
}

$debug = new ADX_Debug();
$extractor = new ADX_Extractor('/usr/bin/pdftotext', $debug);
$parser = new ADX_Parser($debug);
$scope = new ADX_Scope($debug);
$index = ado_qm_get_index();
$now = gmdate('Ymd-His');

function ado_scope_audit_segment_rows(array $payload, array $index): array {
    $rows = [];
    foreach ((array) ($payload['doors'] ?? []) as $door) {
        if (!is_array($door)) {
            continue;
        }
        $doorId = trim((string) ($door['door_id'] ?? ''));
        foreach ((array) ($door['items'] ?? []) as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = trim((string) ($item['raw'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $segments = ado_qm_split_raw_segments($raw);
            if (!$segments) {
                $segments = [$raw];
            }
            foreach ($segments as $segmentIndex => $segment) {
                $segment = trim((string) $segment);
                if ($segment === '') {
                    continue;
                }
                $segmentItem = [
                    'qty' => (int) ($item['qty'] ?? 1),
                    'catalog' => '',
                    'desc' => '',
                    'raw' => $segment,
                ];
                $match = ado_qm_match_segment($segmentItem, $segment, $index);
                $candidateFragments = [];
                foreach ((array) ($match['candidate_products'] ?? []) as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }
                    $candidateFragments[] = trim((string) ($candidate['sku'] ?? ($candidate['title'] ?? '')));
                }
                $rows[] = [
                    'row_id' => md5(wp_json_encode([$doorId, $itemIndex, $segmentIndex, $segment])),
                    'door_id' => $doorId,
                    'raw_line' => $segment,
                    'source_catalog' => (string) ($item['catalog'] ?? ''),
                    'source_desc' => (string) ($item['desc'] ?? ''),
                    'match' => [
                        'product_id' => (int) ($match['product_id'] ?? 0),
                        'match_method' => (string) ($match['match_method'] ?? ''),
                        'reason_code' => (string) ($match['reason_code'] ?? ''),
                        'normalized_model' => (string) ($match['normalized_model'] ?? ''),
                        'candidate_products' => array_values($candidateFragments),
                    ],
                ];
            }
        }
    }
    return $rows;
}

function ado_scope_audit_key(array $row): string {
    return (string) ($row['door_id'] ?? '') . '|' . (string) ($row['raw_line'] ?? '');
}

function ado_scope_audit_model_hints(array $row): array {
    $hints = [];
    foreach ([(string) ($row['source_catalog'] ?? ''), (string) ($row['raw_line'] ?? '')] as $source) {
        foreach (ado_qm_extract_fragments_from_text($source) as $fragment) {
            $normalized = ado_qm_compact((string) $fragment);
            if ($normalized === '') {
                continue;
            }
            $hints[$normalized] = (string) $fragment;
        }
    }
    return $hints;
}

$report = [
    'generated_at' => gmdate('c'),
    'files' => [],
];

$allGreen = true;

foreach ($pdfs as $pdfPath) {
    $fileReport = [
        'pdf_path' => $pdfPath,
        'label' => basename($pdfPath),
        'status' => 'ok',
        'matched_segments' => [],
        'correctly_dropped_segments' => [],
        'incorrectly_dropped_segments' => [],
        'kept_without_bank_match' => [],
    ];

    if (!is_readable($pdfPath)) {
        $fileReport['status'] = 'missing_pdf';
        $allGreen = false;
        $report['files'][] = $fileReport;
        continue;
    }

    $extract = $extractor->extract_text_pdftotext($pdfPath);
    $text = (string) ($extract['text'] ?? '');
    if ($text === '') {
        $fileReport['status'] = 'extract_failed';
        $allGreen = false;
        $report['files'][] = $fileReport;
        continue;
    }

    $parsed = $parser->adaptive_parse($text);
    $scoped = $scope->apply_operator_scope_filter_to_result($parsed);

    $parsedRows = ado_scope_audit_segment_rows($parsed, $index);
    $scopedRows = ado_scope_audit_segment_rows($scoped, $index);
    $scopedByKey = [];
    foreach ($scopedRows as $row) {
        $scopedByKey[ado_scope_audit_key($row)] = $row;
    }

    foreach ($parsedRows as $row) {
        $key = ado_scope_audit_key($row);
        $inScoped = isset($scopedByKey[$key]);
        $match = (array) ($row['match'] ?? []);
        $hasBankMatch = (int) ($match['product_id'] ?? 0) > 0 || !empty($match['candidate_products']);
        $entry = [
            'door_id' => (string) ($row['door_id'] ?? ''),
            'raw_line' => (string) ($row['raw_line'] ?? ''),
            'source_catalog' => (string) ($row['source_catalog'] ?? ''),
            'source_desc' => (string) ($row['source_desc'] ?? ''),
            'match_method' => (string) ($match['match_method'] ?? ''),
            'reason_code' => (string) ($match['reason_code'] ?? ''),
            'normalized_model' => (string) ($match['normalized_model'] ?? ''),
            'candidate_products' => array_values((array) ($match['candidate_products'] ?? [])),
            'product_id' => (int) ($match['product_id'] ?? 0),
            'model_hints' => ado_scope_audit_model_hints($row),
        ];

        if ($inScoped && $hasBankMatch) {
            $fileReport['matched_segments'][] = $entry;
            continue;
        }
        if (!$inScoped && !$hasBankMatch) {
            $fileReport['correctly_dropped_segments'][] = $entry;
            continue;
        }
        if (!$inScoped && $hasBankMatch) {
            $fileReport['incorrectly_dropped_segments'][] = $entry;
            continue;
        }
        if ($inScoped && !$hasBankMatch) {
            $fileReport['kept_without_bank_match'][] = $entry;
        }
    }

    if (!empty($fileReport['incorrectly_dropped_segments'])) {
        $fileReport['status'] = 'false_drops_found';
        $allGreen = false;
    }

    $fileReport['counts'] = [
        'parsed_segments' => count($parsedRows),
        'scoped_segments' => count($scopedRows),
        'matched_segments' => count($fileReport['matched_segments']),
        'correctly_dropped_segments' => count($fileReport['correctly_dropped_segments']),
        'incorrectly_dropped_segments' => count($fileReport['incorrectly_dropped_segments']),
        'kept_without_bank_match' => count($fileReport['kept_without_bank_match']),
    ];

    $matchedModels = [];
    foreach ((array) $fileReport['matched_segments'] as $row) {
        foreach ((array) ($row['model_hints'] ?? []) as $norm => $label) {
            $matchedModels[(string) $norm] = (string) $label;
        }
    }
    $missingModels = [];
    foreach ((array) $fileReport['correctly_dropped_segments'] as $row) {
        foreach ((array) ($row['model_hints'] ?? []) as $norm => $label) {
            $missingModels[(string) $norm] = (string) $label;
        }
    }
    $fileReport['matched_models'] = $matchedModels;
    $fileReport['missing_models'] = $missingModels;

    $report['files'][] = $fileReport;
}

$report['summary'] = [
    'all_green' => $allGreen,
];

$jsonPath = $reportDir . '/scoped-pdf-audit-' . $now . '.json';
$mdPath = $reportDir . '/scoped-pdf-audit-' . $now . '.md';

file_put_contents($jsonPath, wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$md = [];
$md[] = '# Scoped PDF Audit';
$md[] = '';
$md[] = '- Generated: ' . gmdate('c');
$md[] = '- Overall green: ' . ($allGreen ? 'yes' : 'no');
$md[] = '';
foreach ($report['files'] as $fileReport) {
    $md[] = '## ' . (string) ($fileReport['label'] ?? '');
    $md[] = '';
    $md[] = '- Status: ' . (string) ($fileReport['status'] ?? 'ok');
    $md[] = '- Parsed segments: ' . (string) ($fileReport['counts']['parsed_segments'] ?? 0);
    $md[] = '- Scoped segments: ' . (string) ($fileReport['counts']['scoped_segments'] ?? 0);
    $md[] = '- Matched segments: ' . (string) ($fileReport['counts']['matched_segments'] ?? 0);
    $md[] = '- Correctly dropped: ' . (string) ($fileReport['counts']['correctly_dropped_segments'] ?? 0);
    $md[] = '- Incorrectly dropped: ' . (string) ($fileReport['counts']['incorrectly_dropped_segments'] ?? 0);
    $md[] = '- Kept without bank match: ' . (string) ($fileReport['counts']['kept_without_bank_match'] ?? 0);
    $md[] = '- Matched model hints: ' . implode(', ', array_keys((array) ($fileReport['matched_models'] ?? [])));
    $md[] = '- Dropped model hints with no bank match: ' . implode(', ', array_keys((array) ($fileReport['missing_models'] ?? [])));
    if (!empty($fileReport['incorrectly_dropped_segments'])) {
        $md[] = '- Incorrect drop sample:';
        foreach (array_slice((array) $fileReport['incorrectly_dropped_segments'], 0, 20) as $row) {
            $md[] = '  - `' . ($row['raw_line'] ?? '') . '` -> `' . implode(', ', (array) ($row['candidate_products'] ?? [])) . '`';
        }
    }
    $md[] = '';
}
file_put_contents($mdPath, implode("\n", $md));

echo "JSON report: {$jsonPath}\n";
echo "Markdown report: {$mdPath}\n";
foreach ($report['files'] as $fileReport) {
    $counts = (array) ($fileReport['counts'] ?? []);
    echo sprintf(
        "[%s] %s :: parsed=%d scoped=%d matched=%d correct_drop=%d false_drop=%d kept_unmapped=%d\n",
        empty($fileReport['incorrectly_dropped_segments']) ? 'PASS' : 'FAIL',
        (string) ($fileReport['label'] ?? ''),
        (int) ($counts['parsed_segments'] ?? 0),
        (int) ($counts['scoped_segments'] ?? 0),
        (int) ($counts['matched_segments'] ?? 0),
        (int) ($counts['correctly_dropped_segments'] ?? 0),
        (int) ($counts['incorrectly_dropped_segments'] ?? 0),
        (int) ($counts['kept_without_bank_match'] ?? 0)
    );
}

exit($allGreen ? 0 : 1);
