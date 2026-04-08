<?php
chdir('/var/www/html');
require_once 'wp-load.php';

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is required for architecture tests.\n");
    exit(1);
}

function ado_brand_arch_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function ado_brand_arch_find_label(array $labels, string $needle): string
{
    $needle = strtoupper($needle);
    foreach ($labels as $label) {
        if (stripos($label, $needle) !== false) {
            return $label;
        }
    }
    return $needle;
}

$index = ado_qm_get_index();
$brand_labels = array_keys((array) ($index['brands'] ?? []));

$canonical_samples = [
    'camden' => 'camden',
    'lcn' => 'lcn',
    'hes' => 'hes',
    'von_duprin' => 'duprin',
    'unknown' => 'unknown',
];

foreach ($canonical_samples as $expected => $needle) {
    $label = ado_brand_arch_find_label($brand_labels, $needle);
    if (ado_brand_canonical_key($label) !== $expected) {
        ado_brand_arch_fail(sprintf('canonical %s should map from %s', $expected, $label));
    }
}

$fixtures = [
    [
        'label' => 'Camden detection',
        'segment' => 'CM-60/2',
        'catalog' => 'Camden Door Controls CX-RIM-SB',
        'desc' => 'Camden (CM) cylinder',
        'expected_primary' => 'camden',
    ],
    [
        'label' => 'LCN detection',
        'segment' => '9500IQ-347',
        'catalog' => 'LCN 9500IQ Neodymium',
        'desc' => 'LCN 9500IQ chassis',
        'expected_primary' => 'lcn',
    ],
    [
        'label' => 'Von Duprin detection',
        'segment' => '6211-630',
        'catalog' => 'Von Duprin 6211',
        'desc' => 'Von Duprin electrified exit device',
        'expected_primary' => 'von_duprin',
    ],
    [
        'label' => 'Ambiguous LCN/Von Duprin',
        'segment' => '9500IQ-6211',
        'catalog' => 'LCN 9500IQ / Von Duprin 6211',
        'desc' => 'Shared strike hardware',
        'expected_primary' => 'lcn',
        'expected_secondary' => 'von_duprin',
    ],
    [
        'label' => 'Unknown fallback',
        'segment' => 'OTHER-5000X',
        'catalog' => 'Other Brand',
        'desc' => 'Unmatched hardware',
        'expected_primary' => 'unknown',
    ],
];

$tested_parity = false;

foreach ($fixtures as $fixture) {
    $item = [
        'catalog' => $fixture['catalog'] ?? '',
        'desc' => $fixture['desc'] ?? '',
        'qty' => 1,
    ];
    $segment = $fixture['segment'];
    $expected_primary = $fixture['expected_primary'];
    $detection = ado_brand_detect_segment([
        'item' => $item,
        'segment' => $segment,
        'index' => $index,
    ]);
    $scored = (array) ($detection['scored_brands'] ?? []);
    if (!$scored) {
        ado_brand_arch_fail($fixture['label'] . ': scored_brands missing');
    }
    $candidates = array_column($scored, 'canonical');
    if (($candidates[0] ?? '') !== $expected_primary) {
        ado_brand_arch_fail(sprintf('%s: expected primary=%s got=%s', $fixture['label'], $expected_primary, ($candidates[0] ?? '')));
    }
    if (!empty($fixture['expected_secondary'])) {
        if (!in_array($fixture['expected_secondary'], $candidates, true)) {
            ado_brand_arch_fail(sprintf('%s: secondary candidate missing %s', $fixture['label'], $fixture['expected_secondary']));
        }
        if (($scored[0]['score'] ?? 0) <= ($scored[1]['score'] ?? 0)) {
            ado_brand_arch_fail($fixture['label'] . ': scoring not ranking primary above secondary');
        }
    }
    if (!isset($detection['candidate_brands']) || $detection['candidate_brands'] !== $candidates) {
        ado_brand_arch_fail($fixture['label'] . ': candidate_brands out of sync with scored_brands');
    }
    if (!isset($detection['trace']) || !is_array($detection['trace'])) {
        ado_brand_arch_fail($fixture['label'] . ': trace missing');
    }
    $trace_blob = implode('|', $detection['trace']);
    if (strpos($trace_blob, 'candidates=') === false) {
        ado_brand_arch_fail($fixture['label'] . ': trace missing candidates info');
    }

    $orchestrator = ADO_Brand_Match_Orchestrator::match_segment($item, $segment, $index);
    $detector_in_orch = (array) ($orchestrator['brand_detector'] ?? []);
    if (($detector_in_orch['primary_brand'] ?? '') !== $expected_primary) {
        ado_brand_arch_fail(sprintf('%s: orchestrator primary=%s', $fixture['label'], ($detector_in_orch['primary_brand'] ?? '')));
    }
    if (($detector_in_orch['candidate_brands'] ?? []) !== $candidates) {
        ado_brand_arch_fail($fixture['label'] . ': orchestrator candidate list mismatch');
    }

    if (!$tested_parity && $expected_primary !== 'unknown') {
        $generic = new ADO_Generic_Brand_Matcher();
        $generic_result = $generic->match_segment($item, $segment, $index);
        $direct_result = ado_qm_match_segment($item, $segment, $index);
        foreach (['product_id', 'match_method', 'reason_code'] as $field) {
            if (($generic_result[$field] ?? null) !== ($direct_result[$field] ?? null)) {
                ado_brand_arch_fail("$field mismatch for $segment");
            }
        }
        $generic_products = array_map('intval', array_column((array) ($generic_result['candidate_products'] ?? []), 'product_id'));
        $direct_products = array_map('intval', array_column((array) ($direct_result['candidate_products'] ?? []), 'product_id'));
        sort($generic_products);
        sort($direct_products);
        if ($generic_products !== $direct_products) {
            ado_brand_arch_fail("candidate_products mismatch for $segment");
        }
        $tested_parity = true;
    }
}

echo 'Brand architecture smoke test passed.' . "\n";
