<?php
if (defined('ADO_CAMDEN_PACK_LOADED')) {
    return;
}
define('ADO_CAMDEN_PACK_LOADED', true);

function ado_camden_get_pack(): array
{
    static $pack = null;
    if (is_array($pack)) {
        return $pack;
    }

    $pack = [
        'brand' => 'camden',
        'official_sources' => ['Camden Controls Model Specifications (internal)'],
        'verified_rules' => [
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
        ],
        'provisional_rules' => [],
        'prefixes' => ['CM', 'CX', 'CV', 'CI'],
        'finish_tokens' => ['US10B', 'US32D', 'US32', 'US3', 'US4', 'US26D', 'US26', 'US28', 'US19', 'US19D', 'AL', 'BK'],
        'series_penalty_tokens' => ['SERIES', 'FAMILY', 'GROUP', 'KIT', 'KITS', 'PACKAGE', 'PACKAGES'],
        'accessory_tokens' => ['FACEPLATE', 'BACKBOX', 'BACK BOX', 'BOX', 'ENCLOSURE', 'HOUSING', 'MOUNT', 'MOUNTING', 'ADAPTER', 'RING'],
        'leaf_preferred' => true,
        'family_aliases' => ['CAMDEN', 'CAMDEN CONTROLS'],
        'noise_patterns' => [
            '/\\bDOOR\\b/i',
            '/\\bBAR\\b/i',
            '/\\bRHR\\b/i',
            '/\\bLHR\\b/i',
            '/\\bELEC\\b/i',
            '/\\bSTRIKE\\b/i',
        ],
        'families' => [
            'cm_40_41_60' => [
                'family_key' => 'cm_40_41_60',
                'prefix' => 'CM',
                'pattern' => '/^(?P<base>CM-(?:40|41|60))(?P<variant>\\/\\d+)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'push_plate_switch',
                'power_supply' => false,
                'variant_type' => 'slash_numeric',
                'confidence' => 'verified',
            ],
            'cm_45_46' => [
                'family_key' => 'cm_45_46',
                'prefix' => 'CM',
                'pattern' => '/^(?P<base>CM-(?:45|46|45CB|46CB))(?P<variant>\\/\\d+)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'push_plate_switch',
                'power_supply' => false,
                'variant_type' => 'slash_numeric',
                'confidence' => 'verified',
            ],
            'cm_2510_2520' => [
                'family_key' => 'cm_2510_2520',
                'prefix' => 'CM',
                'pattern' => '/^(?P<base>CM-(?:2510|2520))(?P<variant>\\/\\d+)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type_map' => [
                    'CM-2510' => 'combo_plate_key_switch',
                    'CM-2520' => 'push_plate_switch',
                ],
                'power_supply' => false,
                'variant_type' => 'slash_numeric',
                'confidence' => 'verified',
            ],
            'cm_30' => [
                'family_key' => 'cm_30',
                'prefix' => 'CM',
                'pattern' => '/^(?P<base>CM-30)(?P<variant>[A-Z]+)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'illuminated_exit_switch',
                'power_supply' => true,
                'variant_type' => 'alpha',
                'variant_pattern' => '/^[A-Z]{1,2}$/',
                'confidence' => 'verified',
            ],
            'cx_33' => [
                'family_key' => 'cx_33',
                'prefix' => 'CX',
                'pattern' => '/^(?P<base>CX-33)(?P<variant>[A-Z0-9]+)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'logic_relay',
                'power_supply' => true,
                'variant_type' => 'alphanum',
                'confidence' => 'verified',
            ],
            'cx_12_plus' => [
                'family_key' => 'cx_12_plus',
                'prefix' => 'CX',
                'pattern' => '/^(?P<base>CX-12)(?:[\\s])?(?P<variant>PLUS)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'door_interface_relay',
                'power_supply' => true,
                'variant_type' => 'keyword',
                'confidence' => 'verified',
            ],
            'cx_wc' => [
                'family_key' => 'cx_wc',
                'prefix' => 'CX',
                'pattern' => '/^(?P<base>CX-WC)(?P<kit>\\d+)(?:[-\\s]*(?P<variant>[A-Z0-9]+))?(?:-(?P<option_group>[A-Z0-9]+(?:-[A-Z0-9]+)*))?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'restroom_control_kit',
                'power_supply' => true,
                'variant_type' => 'alphanum',
                'confidence' => 'verified',
            ],
            'cx_wec' => [
                'family_key' => 'cx_wec',
                'prefix' => 'CX',
                'pattern' => '/^(?P<base>CX-WEC)(?P<kit>\\d+)(?:[-\\s]*(?P<variant>[A-Z0-9]+))?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'emergency_call_kit',
                'power_supply' => true,
                'variant_type' => 'alphanum',
                'confidence' => 'verified',
            ],
            'cv_7600' => [
                'family_key' => 'cv_7600',
                'prefix' => 'CV',
                'pattern' => '/^(?P<base>CV-7600)(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'reader',
                'power_supply' => true,
                'variant_type' => 'none',
                'confidence' => 'verified',
            ],
            'ci_3b' => [
                'family_key' => 'ci_3b',
                'prefix' => 'CI',
                'pattern' => '/^(?P<base>CI-3B)(?P<variant>[A-Z0-9]+)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'industrial_control_station',
                'power_supply' => false,
                'variant_type' => 'alphanum',
                'confidence' => 'verified',
            ],
            'cx_ed1079' => [
                'family_key' => 'cx_ed1079',
                'prefix' => 'CX',
                'pattern' => '/^(?P<base>CX-ED1079)(?P<variant>[A-Z0-9]+)?(?:[\\s]+(?P<suffix>.*))?$/',
                'device_type' => 'electric_strike',
                'power_supply' => false,
                'variant_type' => 'alphanum',
                'variant_pattern' => '/^DL$/',
                'confidence' => 'verified',
            ],
        ],
        'resolver_preferences' => [
            'use_pack_first' => true,
            'min_exact_score' => 70,
            'min_review_score' => 40,
            'base_match_score' => 35,
            'full_match_score' => 55,
            'variant_match_score' => 15,
            'kit_match_score' => 10,
            'variant_missing_penalty' => 15,
            'kit_missing_penalty' => 10,
            'series_penalty' => 25,
            'accessory_penalty' => 40,
            'leaf_bonus' => 10,
        ],
    ];

    return $pack;
}
