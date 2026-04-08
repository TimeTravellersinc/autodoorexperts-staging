<?php
chdir('/var/www/html');
require_once 'wp-load.php';

function camden_assert(string $message, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$pack = ado_camden_get_pack();
$families = (array) ($pack['families'] ?? []);
$expected_families = [
    'cm_40_41_60',
    'cm_45_46',
    'cm_2510_2520',
    'cm_30',
    'cx_33',
    'cx_12_plus',
    'cx_wc',
    'cx_wec',
    'cv_7600',
    'ci_3b',
    'cx_ed1079',
];

foreach ($expected_families as $family_key) {
    camden_assert("pack missing family $family_key", isset($families[$family_key]));
}

$positive_cases = [
    [
        'segment' => 'CM-60/2',
        'expect' => [
            'brand' => 'camden',
            'prefix' => 'CM',
            'family_key' => 'cm_40_41_60',
            'base_model' => 'CM-60',
            'variant_code' => '/2',
            'device_type' => 'push_plate_switch',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CM-60/2 US10B',
        'expect' => [
            'family_key' => 'cm_40_41_60',
            'base_model' => 'CM-60',
            'variant_code' => '/2',
            'finish' => 'US10B',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'cm 41 / 3 wr us32d',
        'expect' => [
            'brand' => 'camden',
            'prefix' => 'CM',
            'family_key' => 'cm_40_41_60',
            'base_model' => 'CM-41',
            'variant_code' => '/3',
            'device_type' => 'push_plate_switch',
            'finish' => 'US32D',
            'options' => ['WR'],
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CM-45/4',
        'expect' => [
            'family_key' => 'cm_45_46',
            'base_model' => 'CM-45',
            'variant_code' => '/4',
            'device_type' => 'push_plate_switch',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CM-46CB/4',
        'expect' => [
            'family_key' => 'cm_45_46',
            'base_model' => 'CM-46CB',
            'variant_code' => '/4',
            'device_type' => 'push_plate_switch',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CM-2510/8',
        'expect' => [
            'family_key' => 'cm_2510_2520',
            'base_model' => 'CM-2510',
            'variant_code' => '/8',
            'device_type' => 'combo_plate_key_switch',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CM-2520/48',
        'expect' => [
            'family_key' => 'cm_2510_2520',
            'base_model' => 'CM-2520',
            'variant_code' => '/48',
            'device_type' => 'push_plate_switch',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CM-30EE',
        'expect' => [
            'family_key' => 'cm_30',
            'base_model' => 'CM-30',
            'variant_code' => 'EE',
            'device_type' => 'illuminated_exit_switch',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CX-33PS',
        'expect' => [
            'family_key' => 'cx_33',
            'base_model' => 'CX-33',
            'variant_code' => 'PS',
            'device_type' => 'logic_relay',
            'power_supply' => true,
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CX-12 Plus',
        'expect' => [
            'family_key' => 'cx_12_plus',
            'base_model' => 'CX-12',
            'variant_code' => 'PLUS',
            'device_type' => 'door_interface_relay',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CX-WC13AXFM-PSFE',
        'expect' => [
            'family_key' => 'cx_wc',
            'base_model' => 'CX-WC',
            'variant_code' => 'AXFM',
            'kit_code' => '13',
            'options' => ['PS', 'FE'],
            'power_supply' => true,
            'device_type' => 'restroom_control_kit',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CX-WEC13-TS',
        'expect' => [
            'family_key' => 'cx_wec',
            'base_model' => 'CX-WEC',
            'variant_code' => 'TS',
            'kit_code' => '13',
            'device_type' => 'emergency_call_kit',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CV-7600',
        'expect' => [
            'family_key' => 'cv_7600',
            'base_model' => 'CV-7600',
            'device_type' => 'reader',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CI-3BXL',
        'expect' => [
            'family_key' => 'ci_3b',
            'base_model' => 'CI-3B',
            'variant_code' => 'XL',
            'device_type' => 'industrial_control_station',
            'parse_status' => 'parsed',
        ],
    ],
    [
        'segment' => 'CX-ED1079DL',
        'expect' => [
            'family_key' => 'cx_ed1079',
            'base_model' => 'CX-ED1079',
            'variant_code' => 'DL',
            'device_type' => 'electric_strike',
            'parse_status' => 'parsed',
        ],
    ],
];

foreach ($positive_cases as $case) {
    $result = ado_camden_parse_segment($case['segment']);
    foreach ((array) ($case['expect'] ?? []) as $field => $expected) {
        camden_assert("{$case['segment']} missing {$field}", isset($result[$field]));
        if ($field === 'options' && is_array($expected)) {
            foreach ($expected as $token) {
                camden_assert("{$case['segment']} options missing {$token}", in_array($token, (array) ($result['options'] ?? []), true));
            }
            continue;
        }
        camden_assert("{$case['segment']} {$field} mismatch", ($result[$field] ?? null) === $expected);
    }
}

$negative_cases = [
    'x 2200 Door 4\' Bar RHR',
    '6211-630',
    'CM-30XYZ',
    'CX-ED1079ZZ',
];
foreach ($negative_cases as $segment) {
    $result = ado_camden_parse_segment($segment);
    camden_assert("$segment should not parse", $result['parse_status'] === 'no_match');
}

$duplicates = ado_camden_extract_model_candidates('CM-60/2 CM-60/2');
camden_assert('duplicate extraction should dedupe', count($duplicates) === 1);

$first = ado_camden_parse_segment('CM-41/3 WR US32D');
$second = ado_camden_parse_segment('CM-41/3 WR US32D');
camden_assert('parser should be idempotent', json_encode($first) === json_encode($second));

$normalized_variants = [
    ado_camden_parse_segment('CM-60/2'),
    ado_camden_parse_segment('cm-60/2'),
    ado_camden_parse_segment('CM 60 / 2'),
    ado_camden_parse_segment('CM60/2'),
];
$reference = [
    'family_key' => $normalized_variants[0]['family_key'],
    'base_model' => $normalized_variants[0]['base_model'],
    'variant_code' => $normalized_variants[0]['variant_code'],
];
foreach ($normalized_variants as $variant) {
    camden_assert('normalized variants must match family', $variant['family_key'] === $reference['family_key']);
    camden_assert('normalized variants must match base_model', $variant['base_model'] === $reference['base_model']);
    camden_assert('normalized variants must match variant', $variant['variant_code'] === $reference['variant_code']);
}

$finish_check = ado_camden_parse_segment('CM-60/2 US10B');
camden_assert('finish should be US10B', ($finish_check['finish'] ?? null) === 'US10B');
camden_assert('finish should not remain in options', !in_array('US10B', (array) ($finish_check['options'] ?? []), true));

$wec_reference = ado_camden_parse_segment('CX-WEC13-TS');
$wec_variant = ado_camden_parse_segment('CX WEC13-TS');
$wec_variant_spaced = ado_camden_parse_segment('CX-WEC 13 TS');
camden_assert('WEC variants must match family', $wec_reference['family_key'] === $wec_variant['family_key']);
camden_assert('WEC variants must match base_model', $wec_reference['base_model'] === $wec_variant['base_model']);
camden_assert('WEC variants must match kit', $wec_reference['kit_code'] === $wec_variant['kit_code']);
camden_assert('WEC variants must match variant', $wec_reference['variant_code'] === $wec_variant['variant_code']);
camden_assert('WEC spaced variants must match family', $wec_reference['family_key'] === $wec_variant_spaced['family_key']);
camden_assert('WEC spaced variants must match base_model', $wec_reference['base_model'] === $wec_variant_spaced['base_model']);
camden_assert('WEC spaced variants must match kit', $wec_reference['kit_code'] === $wec_variant_spaced['kit_code']);
camden_assert('WEC spaced variants must match variant', $wec_reference['variant_code'] === $wec_variant_spaced['variant_code']);

$wc_reference = ado_camden_parse_segment('CX-WC13AXFM-PSFE');
$wc_variant = ado_camden_parse_segment('CX WC13AXFM-PSFE');
camden_assert('WC variants must match family', $wc_reference['family_key'] === $wc_variant['family_key']);
camden_assert('WC variants must match base_model', $wc_reference['base_model'] === $wc_variant['base_model']);
camden_assert('WC variants must match kit', $wc_reference['kit_code'] === $wc_variant['kit_code']);
camden_assert('WC variants must match variant', $wc_reference['variant_code'] === $wc_variant['variant_code']);
camden_assert('WC variants must include PS', in_array('PS', (array) ($wc_variant['options'] ?? []), true));
camden_assert('WC variants must include FE', in_array('FE', (array) ($wc_variant['options'] ?? []), true));

echo "Camden parser smoke test passed.\n";
