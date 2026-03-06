<?php
if (!defined('ABSPATH')) {
    exit;
}

$failures = [];

$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};

$print_match = static function (string $label, array $match): void {
    $candidates = array_map(static function (array $row): string {
        return (string) ($row['sku'] ?? ('#' . (int) ($row['product_id'] ?? 0)));
    }, array_values((array) ($match['candidate_products'] ?? [])));
    echo $label . ':product=' . (int) ($match['product_id'] ?? 0)
        . ',method=' . (string) ($match['match_method'] ?? '')
        . ',reason=' . (string) ($match['reason_code'] ?? '')
        . ',model=' . (string) ($match['normalized_model'] ?? '')
        . ',candidates=' . implode('|', $candidates)
        . PHP_EOL;
};

$index = ado_qm_get_index(true);
$assert(!empty($index['products']), 'matcher index builds product bank');
$assert(ado_qm_is_external_scope_line('1 Card Reader CARD READER BY OTHERS PROX'), 'external scope detection');
$assert(ado_qm_strip_revision_tail('1 Electric Strike 6300-FSE-24V-630 630 ADDED: PC-047') === '1 ELECTRIC STRIKE 6300-FSE-24V-630 630', 'revision tail trimming');
$assert(in_array('CM7536', ado_qm_model_variants('CM-7536/4'), true), 'slash variant base normalization');
$assert(count(ado_qm_split_raw_segments('1 Power Supply POWER SUPPLY 1 Auto Opener 9531 628 LH HDR T.B. x CONCEALED IN HEADER ON/OFF/HO SWITCH (PULL SIDE MTG) 628 1 Auto Opener Mounting Plate 9530-18 628 41 1/2" 628')) >= 3, 'merged raw line splitting');

$excluded = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Card Reader',
    'raw' => '1 Card Reader CARD READER BY OTHERS 1/PROX + KEYPAD/SWIPE',
], $index);
$assert(((string) ($excluded[0]['reason_code'] ?? '')) === 'EXTERNAL_SCOPE', 'external scope item excluded');

$cm_variant = ado_qm_match_item_segments([
    'qty' => 2,
    'catalog' => 'CM-7536/4',
    'desc' => 'Column Actuator',
    'raw' => '2 Column Actuator CM-7536/4 ADDED: PC-047',
], $index);
$cm_first = is_array($cm_variant[0] ?? null) ? $cm_variant[0] : [];
$print_match('cm_7536_4', $cm_first);
$assert((int) ($cm_first['product_id'] ?? 0) > 0, 'CM-7536/4 resolves to a product');

$opener_9531 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Auto Opener',
    'raw' => '1 Auto Opener 9531 628 RH HDR T.B. x CONCEALED IN HEADER ON/OFF/HO SWITCH (PULL SIDE MTG) 628',
], $index);
$opener_9531_first = is_array($opener_9531[0] ?? null) ? $opener_9531[0] : [];
$print_match('opener_9531', $opener_9531_first);
$assert((int) ($opener_9531_first['product_id'] ?? 0) === 0, '9531 does not auto-match silently');

$opener_9542 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Auto Opener',
    'raw' => '1 Auto Opener 9542 REGARM 628 RH HDR TB x CONCEALED IN HEADER ON/OFF/HO SWITCH (PUSH SIDE MTG) 628',
], $index);
$opener_9542_first = is_array($opener_9542[0] ?? null) ? $opener_9542[0] : [];
$print_match('opener_9542', $opener_9542_first);
$assert((int) ($opener_9542_first['product_id'] ?? 0) === 0, '9542 does not auto-match silently');

$camden_plate = ado_qm_match_item_segments([
    'qty' => 2,
    'catalog' => '',
    'desc' => 'Miscellaneous Hardware',
    'raw' => '2 Miscellaneous Hardware CM-75SB',
], $index);
$print_match('cm_75sb', is_array($camden_plate[0] ?? null) ? $camden_plate[0] : []);

$mounting_plate = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Mounting Plate',
    'raw' => '1 Mounting Plate 4040XP-18PA 689 689 1 Auto Opener 9542 REGARM 628 LH HDR 38" TB x CONCEALED IN HEADER ON/OFF/HO SWITCH (PUSH SIDE 628',
], $index);
$print_match('4040xp_18pa', is_array($mounting_plate[0] ?? null) ? $mounting_plate[0] : []);

$synthetic = ado_build_cart_lines_from_scope([
    'result' => [
        'doors' => [
            [
                'door_id' => 'T-1',
                'items' => [
                    [
                        'qty' => 1,
                        'catalog' => '',
                        'desc' => 'Card Reader',
                        'raw' => '1 Card Reader CARD READER BY OTHERS PROX',
                    ],
                    [
                        'qty' => 2,
                        'catalog' => 'CM-7536/4',
                        'desc' => 'Column Actuator',
                        'raw' => '2 Column Actuator CM-7536/4 ADDED: PC-047',
                    ],
                ],
            ],
        ],
    ],
]);
$assert(is_array($synthetic) && isset($synthetic['lines'], $synthetic['unmatched'], $synthetic['debug_log']), 'scope build returns mapped arrays');
$assert(count((array) ($synthetic['debug_log'] ?? [])) >= 2, 'scope build logs each segment');

$review_html = ado_render_quote_result_html([
    'id' => 'draft-review-test',
    'items' => [],
    'unmatched' => [[
        'line_key' => 'line-review-test',
        'door_number' => 'R-1',
        'model' => '9531',
        'description' => 'Auto Opener',
        'qty' => 1,
        'raw_line' => '1 Auto Opener 9531',
        'reason_code' => 'MULTIPLE_CANDIDATES',
        'next_action' => 'Select the best product below or mark none of these.',
        'candidate_products' => [[
            'product_id' => 1860,
            'sku' => 'CM-7536',
            'title' => 'Camden CM-7536',
            'score' => 88,
        ]],
    ]],
    'debug_log' => [],
]);
$assert(strpos($review_html, 'ado-match-review-choice') !== false, 'review choice button renders');
$assert(strpos($review_html, 'ado-match-review-reject') !== false, 'review reject button renders');

if ($failures) {
    fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "All matcher assertions passed.\n";
