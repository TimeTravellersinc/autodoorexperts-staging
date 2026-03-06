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

$find_product_id_by_sku = static function (string $sku): int {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
        $sku
    ));
};

$index = ado_qm_get_index(true);
$assert(!empty($index['products']), 'matcher index builds product bank');
$sku_9531_id = $find_product_id_by_sku('9531');
$assert($sku_9531_id > 0 && !empty($index['inactive']['products'][$sku_9531_id]), 'inactive index includes trashed 9531 product');
$assert(ado_qm_is_external_scope_line('1 Card Reader CARD READER BY OTHERS PROX'), 'external scope detection');
$assert(ado_qm_is_external_scope_line('4 Card Reader BY DIV.28 KINGSTON JOB NO. 19011/2001-01 2024-12-19'), 'division scope detection');
$assert(ado_qm_strip_revision_tail('1 Electric Strike 6300-FSE-24V-630 630 ADDED: PC-047') === '1 ELECTRIC STRIKE 6300-FSE-24V-630 630', 'revision tail trimming');
$assert(in_array('CM7536', ado_qm_model_variants('CM-7536/4'), true), 'slash variant base normalization');
$assert(ado_qm_is_finish_token('US32D'), 'US32D finish token detection');
$assert(!in_array('19011/2001-01', ado_qm_extract_fragments_from_text('JOB NO. 19011/2001-01 2024-12-19'), true), 'job number token ignored');
$assert(!in_array('2024-12-19', ado_qm_extract_fragments_from_text('JOB NO. 19011/2001-01 2024-12-19'), true), 'date token ignored');
$assert(!in_array('19011', ado_qm_extract_fragments_from_text('1 POWER SUPPLY TO BE CENTRALIZED KINGSTON JOB NO. 19011/2001-01 2024-12-19'), true), 'job number tail stripped from full raw line');
$assert(!in_array('1-3/4-134', ado_qm_extract_fragments_from_text('1 ELECTRONIC LOCKING DEVICE AD-400-MS-60-MT-SPA-626-J MED-LH-8B-09-663-10-072 1-3/4-134 626 APARTMENT'), true), 'door thickness token ignored');
$assert(count(ado_qm_split_raw_segments('1 Power Supply POWER SUPPLY 1 Auto Opener 9531 628 LH HDR T.B. x CONCEALED IN HEADER ON/OFF/HO SWITCH (PULL SIDE MTG) 628 1 Auto Opener Mounting Plate 9530-18 628 41 1/2" 628')) >= 3, 'merged raw line splitting');
$assert(count(ado_qm_split_raw_segments('6 AUTO DOOR OPERATOR 9531IQ X 41 HEADER LCN ANODIZED RESTROOM CONTROL 630 / US32D / SATIN')) === 1, 'header dimension does not create fake segment');
$assert(ado_qm_is_external_scope_line('1 POWER SUPPLY POWER SUPPLY BY OWNERS'), 'BY OWNERS external scope detection');
$assert(ado_qm_is_external_scope_line('1 POWER SUPPLY BY ELECTRICAL'), 'BY ELECTRICAL external scope detection');
$assert(ado_qm_is_external_scope_line('1 POWER SUPPLY TO BE CENTRALIZED KINGSTON JOB NO. 19011/2001-01 2024-12-19'), 'TO BE CENTRALIZED external scope detection');
$assert(ado_qm_is_external_scope_line('1 NOTE ELECTRIC STRIKE POWERED BY BF OPERATOR'), 'powered by note external scope detection');

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
    'desc' => 'Auto Door Operator 9531IQ x 41" Header LCN Anodized Restroom Control 630 / US32D / Satin',
    'raw' => '1 AUTO DOOR OPERATOR 9531IQ X 41 HEADER LCN ANODIZED RESTROOM CONTROL 630 / US32D / SATIN',
], $index);
$opener_9531_first = is_array($opener_9531[0] ?? null) ? $opener_9531[0] : [];
$print_match('opener_9531', $opener_9531_first);
$assert((int) ($opener_9531_first['product_id'] ?? 0) === 0, '9531IQ does not auto-match silently');
$assert(((string) ($opener_9531_first['reason_code'] ?? '')) === 'USER_REVIEW', '9531IQ becomes review candidate');
$assert(!empty($opener_9531_first['candidate_products']), '9531IQ review candidates exist');

$opener_9542 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Auto Opener',
    'raw' => '1 Auto Opener 9542 REGARM 628 RH HDR TB x CONCEALED IN HEADER ON/OFF/HO SWITCH (PUSH SIDE MTG) 628',
], $index);
$opener_9542_first = is_array($opener_9542[0] ?? null) ? $opener_9542[0] : [];
$print_match('opener_9542', $opener_9542_first);
$assert((int) ($opener_9542_first['product_id'] ?? 0) === 0, '9542 does not auto-match silently');
$assert(((string) ($opener_9542_first['reason_code'] ?? '')) === 'USER_REVIEW', '9542 becomes a family review candidate');

$power_supply_contaminated_desc = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'BY OTHERS 9531 628 RH HDR T.B. x CONCEALED IN HEADER ON/OFF/HO SWITCH (PULL SIDE MTG) 9530-18 628 41 1/2"',
    'raw' => '1 POWER SUPPLY POWER SUPPLY',
], $index);
$power_supply_contaminated_desc_first = is_array($power_supply_contaminated_desc[0] ?? null) ? $power_supply_contaminated_desc[0] : [];
$print_match('power_supply_contaminated_desc', $power_supply_contaminated_desc_first);
$assert(((string) ($power_supply_contaminated_desc_first['reason_code'] ?? '')) === 'NO_CANDIDATES', 'contaminated description does not turn generic power supply into opener hardware');
$assert(empty($power_supply_contaminated_desc_first['candidate_products']), 'generic power supply stays unresolved when raw line has no model');

$opener_9542_contaminated_desc = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => '4040XP-18PA 689 9542 REGARM 628 LH HDR 38" TB x CONCEALED IN HEADER ON/OFF/HO SWITCH (PUSH SID',
    'raw' => '1 AUTO OPENER 9542 REGARM 628 LH HDR 38 TB X CONCEALED IN HEADER ON/OFF/HO SWITCH PUSH SIDE 628',
], $index);
$opener_9542_contaminated_desc_first = is_array($opener_9542_contaminated_desc[0] ?? null) ? $opener_9542_contaminated_desc[0] : [];
$print_match('opener_9542_contaminated_desc', $opener_9542_contaminated_desc_first);
$assert(((string) ($opener_9542_contaminated_desc_first['reason_code'] ?? '')) === 'USER_REVIEW', 'contaminated description does not hijack 9542 opener review flow');
$assert(((string) ($opener_9542_contaminated_desc_first['candidate_products'][0]['sku'] ?? '')) === '9540IQ', '9542 opener still reviews against 9540IQ family');
$assert(((string) ($opener_9542_contaminated_desc_first['normalized_model'] ?? '')) === '9542', '9542 opener keeps the raw-line model as the normalized model');

$opener_9531_plain = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Auto Opener',
    'raw' => '1 AUTO OPENER 9531 628 RH HDR T.B. X CONCEALED IN HEADER ON/OFF/HO SWITCH PULL SIDE MTG 628',
], $index);
$opener_9531_plain_first = is_array($opener_9531_plain[0] ?? null) ? $opener_9531_plain[0] : [];
$print_match('opener_9531_plain', $opener_9531_plain_first);
$assert(((string) ($opener_9531_plain_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', '9531 inactive product is surfaced explicitly');
$assert(((string) ($opener_9531_plain_first['candidate_products'][0]['sku'] ?? '')) === '9531', '9531 inactive candidate is exposed');

$mount_plate_9530_18 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Auto Opener Mounting Plate',
    'raw' => '1 AUTO OPENER MOUNTING PLATE 9530-18 628 41 1/2 628',
], $index);
$mount_plate_9530_18_first = is_array($mount_plate_9530_18[0] ?? null) ? $mount_plate_9530_18[0] : [];
$print_match('mount_plate_9530_18', $mount_plate_9530_18_first);
$assert(((string) ($mount_plate_9530_18_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', '9530-18 inactive product is surfaced explicitly');
$assert(((string) ($mount_plate_9530_18_first['candidate_products'][0]['sku'] ?? '')) === '9530-18', '9530-18 inactive candidate is exposed');

$door_contact_ge947w = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Door Contact',
    'raw' => '1 DOOR CONTACT GE947W DOOR CONTACT',
], $index);
$door_contact_ge947w_first = is_array($door_contact_ge947w[0] ?? null) ? $door_contact_ge947w[0] : [];
$print_match('door_contact_ge947w', $door_contact_ge947w_first);
$assert(((string) ($door_contact_ge947w_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', 'GE947W inactive product is surfaced explicitly');
$assert(((string) ($door_contact_ge947w_first['candidate_products'][0]['sku'] ?? '')) === 'GE947W', 'GE947W inactive candidate is exposed');

$strike_1006 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Electric Strike',
    'raw' => '1 ELECTRIC STRIKE 1006-FSE-24V-630 KM-630 630',
], $index);
$strike_1006_first = is_array($strike_1006[0] ?? null) ? $strike_1006[0] : [];
$print_match('strike_1006', $strike_1006_first);
$assert((int) ($strike_1006_first['product_id'] ?? 0) > 0, '1006/KM-630 line matches active 1006 series');
$assert(((string) ($strike_1006_first['match_method'] ?? '')) === 'exact_model', '1006/KM-630 line uses exact model family match');

$strike_1006cs = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Electric Strike',
    'raw' => '1 ELECTRIC STRIKE 1006CS-630 630',
], $index);
$strike_1006cs_first = is_array($strike_1006cs[0] ?? null) ? $strike_1006cs[0] : [];
$print_match('strike_1006cs', $strike_1006cs_first);
$assert(((string) ($strike_1006cs_first['reason_code'] ?? '')) === 'USER_REVIEW', '1006CS-630 becomes review candidate');
$assert(((string) ($strike_1006cs_first['candidate_products'][0]['sku'] ?? '')) === 'HES-1006-SERIES', '1006CS-630 review candidate is exposed');

$strike_6300fse = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Electric Strike',
    'raw' => '1 ELECTRIC STRIKE 6300-FSE-24V-630 630',
], $index);
$strike_6300fse_first = is_array($strike_6300fse[0] ?? null) ? $strike_6300fse[0] : [];
$print_match('strike_6300fse', $strike_6300fse_first);
$assert(((string) ($strike_6300fse_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', '6300-FSE-24V-630 is surfaced as inactive family product');
$assert(((string) ($strike_6300fse_first['candidate_products'][0]['sku'] ?? '')) === 'FSE-24V-630', '6300-FSE-24V-630 inactive family candidate is exposed');

$hes_1500c = ado_qm_match_item_segments([
    'qty' => 6,
    'catalog' => '',
    'desc' => 'Electric Strike 1500C HES',
    'raw' => '6 ELECTRIC STRIKE 1500C HES 626 / US26D / SATIN',
], $index);
$hes_1500c_first = is_array($hes_1500c[0] ?? null) ? $hes_1500c[0] : [];
$print_match('hes_1500c', $hes_1500c_first);
$assert(((string) ($hes_1500c_first['reason_code'] ?? '')) === 'USER_REVIEW', '1500C becomes review candidate');
$assert(((string) ($hes_1500c_first['candidate_products'][0]['sku'] ?? '')) === 'HES-1500-SERIES', '1500C review candidate is exposed');

$cx_wc11ef = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Push to Lock Kit',
    'raw' => '1 PUSH TO LOCK KIT CX-WC11E/F',
], $index);
$cx_wc11ef_first = is_array($cx_wc11ef[0] ?? null) ? $cx_wc11ef[0] : [];
$print_match('cx_wc11ef', $cx_wc11ef_first);
$assert(((string) ($cx_wc11ef_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', 'CX-WC11E/F is surfaced as inactive family product');
$assert(((string) ($cx_wc11ef_first['candidate_products'][0]['sku'] ?? '')) === 'CX-WC11', 'CX-WC11E/F inactive family candidate is exposed');

$cm_46_4 = ado_qm_match_item_segments([
    'qty' => 2,
    'catalog' => '',
    'desc' => 'Actuator',
    'raw' => '2 ACTUATOR CM-46/4/GRF/SFE1 630',
], $index);
$cm_46_4_first = is_array($cm_46_4[0] ?? null) ? $cm_46_4[0] : [];
$print_match('cm_46_4', $cm_46_4_first);
$assert(((string) ($cm_46_4_first['reason_code'] ?? '')) === 'MULTIPLE_CANDIDATES', 'CM-46/4/GRF/SFE1 becomes review candidates');
$assert(count((array) ($cm_46_4_first['candidate_products'] ?? [])) >= 2, 'CM-46/4/GRF/SFE1 exposes multiple active candidates');

$cm_46_8 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Actuator',
    'raw' => '1 ACTUATOR CM-46/8/GRF/SFE1 630',
], $index);
$cm_46_8_first = is_array($cm_46_8[0] ?? null) ? $cm_46_8[0] : [];
$print_match('cm_46_8', $cm_46_8_first);
$assert(((string) ($cm_46_8_first['reason_code'] ?? '')) === 'MULTIPLE_CANDIDATES', 'CM-46/8/GRF/SFE1 becomes review candidates');
$assert(count((array) ($cm_46_8_first['candidate_products'] ?? [])) >= 2, 'CM-46/8/GRF/SFE1 exposes multiple active candidates');

$opener_9542_plain = ado_qm_match_item_segments([
    'qty' => 5,
    'catalog' => '',
    'desc' => 'Operator',
    'raw' => '5 OPERATOR 9542 REGARM 628 LH HDR 44 628',
], $index);
$opener_9542_plain_first = is_array($opener_9542_plain[0] ?? null) ? $opener_9542_plain[0] : [];
$print_match('opener_9542_plain', $opener_9542_plain_first);
$assert(((string) ($opener_9542_plain_first['reason_code'] ?? '')) === 'USER_REVIEW', '9542 plain becomes review candidate');
$assert(((string) ($opener_9542_plain_first['candidate_products'][0]['sku'] ?? '')) === '9540IQ', '9542 plain review candidate is exposed');

$opener_9563_plain = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Operator',
    'raw' => '1 OPERATOR 9563 REGARM2 628 HDR2 72 628',
], $index);
$opener_9563_plain_first = is_array($opener_9563_plain[0] ?? null) ? $opener_9563_plain[0] : [];
$print_match('opener_9563_plain', $opener_9563_plain_first);
$assert(((string) ($opener_9563_plain_first['reason_code'] ?? '')) === 'USER_REVIEW', '9563 plain becomes review candidate');
$assert(((string) ($opener_9563_plain_first['candidate_products'][0]['sku'] ?? '')) === '9560IQ', '9563 plain review candidate is exposed');

$opener_9553_plain = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Operator',
    'raw' => '1 OPERATOR 9553 REGARM2 628 HDR2 84 628',
], $index);
$opener_9553_plain_first = is_array($opener_9553_plain[0] ?? null) ? $opener_9553_plain[0] : [];
$print_match('opener_9553_plain', $opener_9553_plain_first);
$assert(((string) ($opener_9553_plain_first['reason_code'] ?? '')) === 'USER_REVIEW', '9553 plain becomes review candidate');
$assert(((string) ($opener_9553_plain_first['candidate_products'][0]['sku'] ?? '')) === '9550IQ', '9553 plain review candidate is exposed');

$opener_9131_plain = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Operator',
    'raw' => '1 OPERATOR 9131 628 628',
], $index);
$opener_9131_plain_first = is_array($opener_9131_plain[0] ?? null) ? $opener_9131_plain[0] : [];
$print_match('opener_9131_plain', $opener_9131_plain_first);
$assert(((string) ($opener_9131_plain_first['reason_code'] ?? '')) === 'USER_REVIEW', '9131 plain becomes review candidate');
$assert(((string) ($opener_9131_plain_first['candidate_products'][0]['sku'] ?? '')) === '9130', '9131 plain review candidate is exposed');

$opener_9142_plain = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Operator',
    'raw' => '1 OPERATOR 9142 REGARM 628 628',
], $index);
$opener_9142_plain_first = is_array($opener_9142_plain[0] ?? null) ? $opener_9142_plain[0] : [];
$print_match('opener_9142_plain', $opener_9142_plain_first);
$assert(((string) ($opener_9142_plain_first['reason_code'] ?? '')) === 'USER_REVIEW', '9142 plain becomes review candidate');
$assert(((string) ($opener_9142_plain_first['candidate_products'][0]['sku'] ?? '')) === '9140', '9142 plain review candidate is exposed');

$qel_9827 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Exit Device',
    'raw' => '1 EXIT DEVICE QEL-9827-L-NL- CON-626-1067 X 2134 DOOR 44-RHR-996L-NL-V/626-- 24VDC 626/626',
], $index);
$qel_9827_first = is_array($qel_9827[0] ?? null) ? $qel_9827[0] : [];
$print_match('qel_9827', $qel_9827_first);
$assert(((string) ($qel_9827_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', 'QEL-9827 family is surfaced as inactive product');
$assert(((string) ($qel_9827_first['candidate_products'][0]['sku'] ?? '')) === 'QEL-9827EO', 'QEL-9827 family candidate is exposed');

$ad400_ms_60 = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => 'Electronic Locking Device',
    'raw' => '1 ELECTRONIC LOCKING DEVICE AD-400-MS-60-MT-SPA-626-J MED-LH-8B-09-663-10-072 1-3/4-134 626 APARTMENT',
], $index);
$ad400_ms_60_first = is_array($ad400_ms_60[0] ?? null) ? $ad400_ms_60[0] : [];
$print_match('ad400_ms_60', $ad400_ms_60_first);
$assert(((string) ($ad400_ms_60_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', 'AD-400 MS-60 family is surfaced as inactive product');
$assert(((string) ($ad400_ms_60_first['candidate_products'][0]['sku'] ?? '')) === 'AD-400-MS-70-MT-SPA-626-J', 'AD-400 MS-60 family candidate is exposed');

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

$mounting_plate_contaminated_desc = ado_qm_match_item_segments([
    'qty' => 1,
    'catalog' => '',
    'desc' => '4040XP-18PA 689 9542 REGARM 628 LH HDR 38" TB x CONCEALED IN HEADER ON/OFF/HO SWITCH (PUSH SID',
    'raw' => '1 MOUNTING PLATE 4040XP-18PA 689 689',
], $index);
$mounting_plate_contaminated_desc_first = is_array($mounting_plate_contaminated_desc[0] ?? null) ? $mounting_plate_contaminated_desc[0] : [];
$print_match('mounting_plate_contaminated_desc', $mounting_plate_contaminated_desc_first);
$assert(((string) ($mounting_plate_contaminated_desc_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', 'mounting plate still resolves from raw line when description is contaminated');
$assert(((string) ($mounting_plate_contaminated_desc_first['candidate_products'][0]['sku'] ?? '')) === '4040XP-18PA', 'mounting plate keeps the 4040XP-18PA inactive candidate');

$wec_kit = ado_qm_match_item_segments([
    'qty' => 6,
    'catalog' => '',
    'desc' => 'Emergency Call Kit CX-WEC10K2 Camden',
    'raw' => '6 EMERGENCY CALL KIT CX-WEC10K2 CAMDEN',
], $index);
$wec_kit_first = is_array($wec_kit[0] ?? null) ? $wec_kit[0] : [];
$print_match('cx_wec10k2', $wec_kit_first);
$assert((int) ($wec_kit_first['product_id'] ?? 0) === 0, 'CX-WEC10K2 does not auto-match silently');
$assert(((string) ($wec_kit_first['reason_code'] ?? '')) === 'INACTIVE_PRODUCT', 'CX-WEC10K2 exact inactive product is surfaced');
$assert(!empty($wec_kit_first['candidate_products']), 'CX-WEC10K2 review candidates exist');

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

$synthetic_contaminated = ado_build_cart_lines_from_scope([
    'result' => [
        'doors' => [
            [
                'door_id' => 'T-2',
                'items' => [
                    [
                        'qty' => 1,
                        'catalog' => '',
                        'desc' => 'BY OTHERS 9531 628 RH HDR T.B. x CONCEALED IN HEADER ON/OFF/HO SWITCH (PULL SIDE MTG) 9530-18 628 41 1/2"',
                        'raw' => '1 POWER SUPPLY POWER SUPPLY',
                    ],
                    [
                        'qty' => 1,
                        'catalog' => '',
                        'desc' => '4040XP-18PA 689 9542 REGARM 628 LH HDR 38" TB x CONCEALED IN HEADER ON/OFF/HO SWITCH (PUSH SID',
                        'raw' => '1 AUTO OPENER 9542 REGARM 628 LH HDR 38 TB X CONCEALED IN HEADER ON/OFF/HO SWITCH PUSH SIDE 628',
                    ],
                ],
            ],
        ],
    ],
]);
$power_supply_debug = null;
$opener_9542_debug = null;
foreach ((array) ($synthetic_contaminated['debug_log'] ?? []) as $row) {
    if (!is_array($row)) { continue; }
    if (($row['raw_line'] ?? '') === '1 POWER SUPPLY POWER SUPPLY') {
        $power_supply_debug = $row;
    }
    if (($row['raw_line'] ?? '') === '1 AUTO OPENER 9542 REGARM 628 LH HDR 38 TB X CONCEALED IN HEADER ON/OFF/HO SWITCH PUSH SIDE 628') {
        $opener_9542_debug = $row;
    }
}
$assert(is_array($power_supply_debug), 'contaminated power supply debug row is present');
$assert(is_array($opener_9542_debug), 'contaminated 9542 opener debug row is present');
$assert(empty((array) ($power_supply_debug['tokens'] ?? [])), 'debug tokens ignore contaminated description for generic power supply');
$assert(!in_array('4040XP-18PA', (array) ($opener_9542_debug['tokens'] ?? []), true), 'debug tokens do not inherit mounting plate model from contaminated description');
$assert(((array) ($opener_9542_debug['tokens'] ?? [])) === ['9542'], 'debug tokens for contaminated 9542 opener come only from the raw segment');

$review_draft = [
    'id' => 'draft-review-test',
    'name' => 'Draft Review Test',
    'created_at' => wp_date('Y-m-d H:i'),
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
    'debug_log' => [[
        'line_key' => 'line-review-test',
        'door_number' => 'R-1',
        'raw_line' => '1 Auto Opener 9531',
        'matched_product_id' => 0,
        'matched_by' => 'review',
        'confidence' => 88,
        'reason_code' => 'MULTIPLE_CANDIDATES',
    ]],
];
$review_draft = array_merge($review_draft, ado_quote_write_debug_log_file($review_draft));
$debug_file_path = (string) ($review_draft['debug_log_file_path'] ?? '');
$assert($debug_file_path !== '' && file_exists($debug_file_path), 'debug log file is written');
$export_payload = $debug_file_path !== '' ? json_decode((string) file_get_contents($debug_file_path), true) : null;
$assert(is_array($export_payload), 'debug log file contains valid json');
$assert((int) ($export_payload['unmatched_count'] ?? 0) === 1, 'debug log file contains unmatched count');
$assert((int) (($export_payload['summary']['unmatched_line_count'] ?? 0)) === 1, 'debug log file summary contains unmatched line count');
$assert((int) (($export_payload['summary']['reason_counts']['MULTIPLE_CANDIDATES'] ?? 0)) === 1, 'debug log file summary contains reason counts');
$assert(count((array) ($export_payload['unmatched_debug'] ?? [])) === 1, 'debug log file contains filtered unmatched debug rows');
$review_html = ado_render_quote_result_html($review_draft);
$assert(strpos($review_html, 'ado-match-review-choice') !== false, 'review choice button renders');
$assert(strpos($review_html, 'ado-match-review-reject') !== false, 'review reject button renders');
$assert(strpos($review_html, 'Unmatched Debug Data') !== false, 'combined unmatched debug block renders');
$assert(strpos($review_html, 'Output Window') === false, 'legacy per-line output window removed');

if ($failures) {
    fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'debug_export_path=' . $debug_file_path . PHP_EOL;
echo "All matcher assertions passed.\n";
