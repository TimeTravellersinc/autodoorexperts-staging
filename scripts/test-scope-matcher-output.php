<?php
define('WP_USE_THEMES', false);
require '/var/www/html/wp-load.php';
require_once '/var/www/html/wp-content/plugins/autodoor-pdf-parser/autodoor-pdf-parser.php';
require_once '/var/www/html/wp-content/themes/ado-modern/inc/ado-portal/ado-quote-matcher.php';

function scope_match_case_paths(): array {
    return [
        'providence'      => '/var/www/html/wp-content/uploads/2026/03/Providence-Manor-Hardware-Schedule-Operators-only.pdf',
        'ado_install'     => '/var/www/html/wp-content/uploads/2026/03/Hardware-Schedule-for-ADO-Install-1.pdf',
        'carleton'        => '/var/www/html/wp-content/uploads/2026/02/Carleton-University-New-Student-Residence-Revised-Hardware-Schedule-January-31-2025.pdf',
        'laurier'         => '/tmp/234-Laurier-Hardware-schedule-2024.07.23.pdf',
        'resubmit_23162'  => '/tmp/23-162-Hardware-SD_MCR-resubmit.pdf',
        'hardware_1'      => '/var/www/html/wp-content/uploads/2026/02/Hardware-Schedule-1.pdf',
    ];
}

function scope_match_assert(bool $condition, string $message, array &$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
}

function scope_match_parse_and_scope(string $pdf): array {
    $dbg = new ADX_Debug();
    $extractor = new ADX_Extractor('/usr/bin/pdftotext', $dbg);
    $parser = new ADX_Parser($dbg);
    $scope = new ADX_Scope($dbg);
    $text = $extractor->extract_text_pdftotext($pdf)['text'] ?? '';
    $parsed = $parser->adaptive_parse($text);
    $scoped = $scope->apply_operator_scope_filter_to_result($parsed);

    return [
        'parsed' => $parsed,
        'scoped' => $scoped,
    ];
}

function scope_match_find_door(array $result, string $doorId): ?array {
    foreach (($result['doors'] ?? []) as $door) {
        if ((string) ($door['door_id'] ?? '') === $doorId) {
            return $door;
        }
    }
    return null;
}

function scope_match_find_item(array $door, string $needle): ?array {
    foreach (($door['items'] ?? []) as $item) {
        if (strpos((string) ($item['raw'] ?? ''), $needle) !== false) {
            return $item;
        }
    }
    return null;
}

function scope_match_item_results(array $item, ?array $index = null): array {
    $index = is_array($index) ? $index : ado_qm_get_index();
    return ado_qm_match_item_segments($item, $index);
}

function scope_match_item_is_matched(array $item, ?array $index = null): bool {
    foreach (scope_match_item_results($item, $index) as $match) {
        if ((int) ($match['product_id'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

function scope_match_item_has_reason(array $item, string $reasonCode, ?array $index = null): bool {
    foreach (scope_match_item_results($item, $index) as $match) {
        if ((string) ($match['reason_code'] ?? '') === $reasonCode) {
            return true;
        }
    }
    return false;
}

function scope_match_direct_item(string $raw, string $desc = '', string $catalog = '', int $qty = 1): array {
    return [
        'raw' => $raw,
        'desc' => $desc,
        'catalog' => $catalog,
        'qty' => $qty,
    ];
}

function scope_match_first_result(array $item, ?array $index = null): array {
    $results = scope_match_item_results($item, $index);
    return $results[0] ?? [];
}

function scope_match_has_positive_product(array $item, ?array $index = null): bool {
    $result = scope_match_first_result($item, $index);
    return (int) ($result['product_id'] ?? 0) > 0;
}

$paths = scope_match_case_paths();
$index = ado_qm_get_index();
$failures = [];
$results = [];

foreach ($paths as $name => $path) {
    $run = scope_match_parse_and_scope($path);
    $parsed = $run['parsed'];
    $scoped = $run['scoped'];

    $results[$name] = [
        'pdf' => $path,
        'parsed_doors' => count($parsed['doors'] ?? []),
        'scoped_doors' => count($scoped['doors'] ?? []),
        'first_scoped_doors' => array_map(static function (array $door): array {
            return [
                'door_id' => (string) ($door['door_id'] ?? ''),
                'item_count' => count((array) ($door['items'] ?? [])),
                'items' => array_slice(array_map(static function (array $item): array {
                    return [
                        'raw' => (string) ($item['raw'] ?? ''),
                        'scope_reason' => (string) ($item['_scope_reason'] ?? ''),
                    ];
                }, (array) ($door['items'] ?? [])), 0, 8),
            ];
        }, array_slice((array) ($scoped['doors'] ?? []), 0, 3)),
    ];
}

// Operator doors should survive.
$providence = scope_match_find_door($resultsData = scope_match_parse_and_scope($paths['providence'])['scoped'], 'D-C.0.008.2');
scope_match_assert($providence !== null, 'providence: operator door D-C.0.008.2 was dropped from scope', $failures);
if ($providence) {
    foreach ([
        '1 Operator 9563 REGARM2 628 HDR2 72" 628',
        '1 Plate 9560-18 628 628',
        '2 Actuator 8310-813',
        '1 Electric Strike 6223- CON 12VDC-630 630',
    ] as $needle) {
        scope_match_assert(scope_match_find_item($providence, $needle) !== null, "providence: missing scoped row containing {$needle}", $failures);
    }

    $cardReader = scope_match_find_item($providence, 'Card Reader BY DIV.28');
    if ($cardReader) {
        scope_match_assert(!scope_match_item_is_matched($cardReader, $index), 'providence: BY DIV.28 card reader was treated as matched hardware', $failures);
    }
}

$adoInstall = scope_match_find_door(scope_match_parse_and_scope($paths['ado_install'])['scoped'], 'P2-01.1');
scope_match_assert($adoInstall !== null, 'ado_install: operator door P2-01.1 was dropped from scope', $failures);
if ($adoInstall) {
    foreach ([
        '1 BF Operator HA8 628 (Pull Side) RH 628',
        '2 Actuator CM45/2 x CM43CBL C32D C32D',
        '1 Electric Strike 1600-CLB 24VDC 630 630',
    ] as $needle) {
        scope_match_assert(scope_match_find_item($adoInstall, $needle) !== null, "ado_install: missing scoped row containing {$needle}", $failures);
    }
}

$hardware1 = scope_match_find_door(scope_match_parse_and_scope($paths['hardware_1'])['scoped'], 'D0-001');
scope_match_assert($hardware1 !== null, 'hardware_1: operator door D0-001 was dropped from scope', $failures);
if ($hardware1) {
    scope_match_assert(scope_match_find_item($hardware1, '1 Auto Operator SW-800 x 74" Header (Pull)') !== null, 'hardware_1: missing SW-800 operator row', $failures);
}

$carletonScoped = scope_match_parse_and_scope($paths['carleton'])['scoped'];
scope_match_assert(scope_match_find_door($carletonScoped, '101') !== null, 'carleton: operator door 101 was dropped from scope', $failures);
foreach (['102', '102B'] as $doorId) {
    scope_match_assert(scope_match_find_door($carletonScoped, $doorId) === null, "carleton: non-operator door {$doorId} incorrectly survived scope", $failures);
}

$laurierScoped = scope_match_parse_and_scope($paths['laurier'])['scoped'];
foreach (['D1514', 'D1614', 'D1714'] as $doorId) {
    scope_match_assert(scope_match_find_door($laurierScoped, $doorId) === null, "laurier: non-operator door {$doorId} incorrectly survived scope", $failures);
}

$resubmitScoped = scope_match_parse_and_scope($paths['resubmit_23162'])['scoped'];
foreach (['STCD100', 'STCD101', 'D103'] as $doorId) {
    scope_match_assert(scope_match_find_door($resubmitScoped, $doorId) === null, "23-162: non-operator door {$doorId} incorrectly survived scope", $failures);
}

// Matcher normalization / gap behavior.
$cxwc = scope_match_first_result(scope_match_direct_item('1 Push Button CX-WC11E', 'Push Button', 'CX-WC11E'), $index);
scope_match_assert((int) ($cxwc['product_id'] ?? 0) > 0, 'matcher: CX-WC11E did not normalize to a Woo-backed product', $failures);

$cxwec = scope_match_first_result(scope_match_direct_item('1 Emergency Call Kit CX-WEC10E', 'Emergency Call Kit', 'CX-WEC10E'), $index);
scope_match_assert((int) ($cxwec['product_id'] ?? 0) > 0, 'matcher: CX-WEC10E did not normalize to a Woo-backed product', $failures);

$ha8 = scope_match_first_result(scope_match_direct_item('1 BF Operator HA-8', 'BF Operator', 'HA-8'), $index);
scope_match_assert((int) ($ha8['product_id'] ?? 0) > 0, 'matcher: HA-8 did not resolve to a Woo-backed product', $failures);

$hes1006fse = scope_match_first_result(scope_match_direct_item(
    '1 Electric Strike 1006-FSE-24V-630',
    'Electric Strike',
    '1006-FSE-24V-630'
), $index);
scope_match_assert(
    (int) ($hes1006fse['product_id'] ?? 0) > 0,
    'matcher: 1006-FSE-24V-630 did not resolve to its Woo-backed family product',
    $failures
);

$sw800 = scope_match_first_result(scope_match_direct_item(
    '1 Auto Operator SW-800 x 74" Header (Pull)',
    'Auto Operator',
    'SW-800'
), $index);
scope_match_assert(
    (int) ($sw800['product_id'] ?? 0) > 0 || (string) ($sw800['reason_code'] ?? '') === 'MULTIPLE_CANDIDATES',
    'matcher: SW-800 did not produce a valid Woo-backed match or review path',
    $failures
);

$gap4040 = scope_match_first_result(scope_match_direct_item('1 Surface Closer 4040XP', 'Surface Closer', '4040XP'), $index);
scope_match_assert((int) ($gap4040['product_id'] ?? 0) === 0, 'matcher: 4040XP was incorrectly auto-matched', $failures);

$gap8310 = scope_match_first_result(scope_match_direct_item('1 Touchless Actuator 8310-810DA', 'Touchless Actuator', '8310-810DA'), $index);
scope_match_assert((int) ($gap8310['product_id'] ?? 0) === 0, 'matcher: 8310-810DA was incorrectly auto-matched', $failures);

$match9563 = scope_match_first_result(scope_match_direct_item('1 Operator 9563 REGARM2', 'Operator', '9563'), $index);
scope_match_assert((int) ($match9563['product_id'] ?? 0) > 0, 'matcher: 9563 did not resolve through its Woo-backed family-series product', $failures);

$match956018 = scope_match_first_result(scope_match_direct_item('1 Plate 9560-18 628', 'Plate', '9560-18'), $index);

$match6223 = scope_match_first_result(scope_match_direct_item('1 Electric Strike 6223-CON', 'Electric Strike', '6223-CON'), $index);
scope_match_assert((int) ($match6223['product_id'] ?? 0) > 0, 'matcher: 6223-CON did not resolve through its Woo-backed family-series product', $failures);

$partial = scope_match_first_result(scope_match_direct_item('1 Electric Strike 1006-630 E630', 'Electric Strike', 'E630'), $index);
scope_match_assert((int) ($partial['product_id'] ?? 0) === 0, 'matcher: junk fragment E630 was incorrectly auto-matched', $failures);

// Context rows must not become matched quote hardware.
$carleton101 = scope_match_find_door($carletonScoped, '101');
if ($carleton101) {
    $cardReader = scope_match_find_item($carleton101, '1 Card Reader CARD READER BY OTHERS');
    $powerSupply = scope_match_find_item($carleton101, '1 Power Supply POWER SUPPLY BY OTHERS');
    if ($cardReader) {
        scope_match_assert(!scope_match_item_is_matched($cardReader, $index), 'carleton: BY OTHERS card reader was treated as matched hardware', $failures);
    }
    if ($powerSupply) {
        scope_match_assert(!scope_match_item_is_matched($powerSupply, $index), 'carleton: BY OTHERS power supply was treated as matched hardware', $failures);
    }
}

$payload = [
    'ok' => count($failures) === 0,
    'failures' => $failures,
    'results' => $results,
    'normalization_checks' => [
        'cx_wc11e' => $cxwc,
        'cx_wec10e' => $cxwec,
        'ha_8' => $ha8,
        'hes_1006_fse_24v_630' => $hes1006fse,
        'sw_800' => $sw800,
        'gap_4040xp' => $gap4040,
        'gap_8310_810da' => $gap8310,
        'match_or_review_9563' => $match9563,
        'match_or_review_9560_18' => $match956018,
        'match_or_review_6223_con' => $match6223,
        'junk_e630' => $partial,
    ],
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['ok'] ? 0 : 1);
