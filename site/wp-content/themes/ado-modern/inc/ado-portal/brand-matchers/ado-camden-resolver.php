<?php
if (defined('ADO_CAMDEN_RESOLVER_LOADED')) {
    return;
}
define('ADO_CAMDEN_RESOLVER_LOADED', true);

function ado_camden_resolver_default_preferences(array $pack): array
{
    $defaults = [
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
    ];
    return array_merge($defaults, (array) ($pack['resolver_preferences'] ?? []));
}

function ado_camden_resolver_brand_key(array $index, array $pack): string
{
    $targets = array_map('ado_qm_compact', array_filter(array_merge([(string) ($pack['brand'] ?? '')], (array) ($pack['family_aliases'] ?? [])), 'strlen'));
    foreach ((array) ($index['brands'] ?? []) as $brand_key => $group) {
        $compact = ado_qm_compact((string) $brand_key);
        if ($compact !== '' && in_array($compact, $targets, true)) {
            return (string) $brand_key;
        }
    }
    return '';
}

function ado_camden_resolver_collect_candidates(array $parsed, array $index, array $pack): array
{
    $brand_key = ado_camden_resolver_brand_key($index, $pack);
    $pool = [];
    if ($brand_key !== '') {
        $pool = (array) ($index['brands'][$brand_key]['products'] ?? []);
    } else {
        $aliases = array_map('ado_qm_normalize_text', (array) ($pack['family_aliases'] ?? []));
        foreach ((array) ($index['products'] ?? []) as $product_id => $product) {
            $title_norm = (string) ($product['title_norm'] ?? ado_qm_normalize_text((string) ($product['title'] ?? '')));
            foreach ($aliases as $alias) {
                if ($alias !== '' && $title_norm !== '' && strpos($title_norm, $alias) !== false) {
                    $pool[] = (int) $product_id;
                    break;
                }
            }
        }
    }
    $pool = array_values(array_unique(array_map('intval', $pool)));
    $candidates = [];
    foreach ($pool as $product_id) {
        if ($product_id <= 0 || empty($index['products'][$product_id])) {
            continue;
        }
        $candidates[] = (array) $index['products'][$product_id];
    }
    return $candidates;
}

function ado_camden_resolver_score_candidate(array $product, array $parsed, array $pack, array $prefs): array
{
    $title = (string) ($product['title'] ?? '');
    $title_norm = (string) ($product['title_norm'] ?? ado_qm_normalize_text($title));
    $model_map = (array) ($product['model_map'] ?? []);
    $base_model = (string) ($parsed['base_model'] ?? '');
    $prefix = (string) ($parsed['prefix'] ?? '');
    $variant = (string) ($parsed['variant_code'] ?? '');
    $kit_code = (string) ($parsed['kit_code'] ?? '');
    $full_model = $base_model . $variant;

    $base_norm = ado_qm_normalize_text($base_model);
    $full_norm = ado_qm_normalize_text($full_model);
    $base_compact = ado_qm_compact($base_model);
    $full_compact = ado_qm_compact($full_model);

    $has_base = ($base_norm !== '' && strpos($title_norm, $base_norm) !== false) || ($base_compact !== '' && isset($model_map[$base_compact]));
    $has_full = ($full_norm !== '' && strpos($title_norm, $full_norm) !== false) || ($full_compact !== '' && isset($model_map[$full_compact]));
    $base_digits = preg_replace('/\\D+/', '', $base_model);
    $has_family_hint = $prefix !== '' && $base_digits !== '' && preg_match('/\\b' . preg_quote($prefix, '/') . '\\b/', $title_norm) && preg_match('/\\b' . preg_quote($base_digits, '/') . '\\b/', $title_norm);
    $variant_present = $variant !== '';
    $has_variant = $has_full || ($variant_present && $has_base && $variant !== '' && strpos($title_norm, $variant) !== false);
    $kit_present = $kit_code !== '';
    $has_kit = $kit_present && preg_match('/\b' . preg_quote($kit_code, '/') . '\b/', $title_norm);

    $is_series = false;
    foreach ((array) ($pack['series_penalty_tokens'] ?? []) as $token) {
        $token = strtoupper(trim((string) $token));
        if ($token !== '' && preg_match('/\\b' . preg_quote($token, '/') . '\\b/', $title_norm)) {
            $is_series = true;
            break;
        }
    }

    $is_accessory = false;
    foreach ((array) ($pack['accessory_tokens'] ?? []) as $token) {
        $token = strtoupper(trim((string) $token));
        if ($token !== '' && preg_match('/\\b' . preg_quote($token, '/') . '\\b/', $title_norm)) {
            $is_accessory = true;
            break;
        }
    }

    $score = 0;
    $reasons = [];
    if ($has_base) {
        $score += (int) $prefs['base_match_score'];
        $reasons[] = 'base_match';
    }
    if ($has_full) {
        $score += (int) $prefs['full_match_score'];
        $reasons[] = 'full_match';
    }
    if ($variant_present) {
        if ($has_variant) {
            $score += (int) $prefs['variant_match_score'];
            $reasons[] = 'variant_match';
        } else {
            $score -= (int) $prefs['variant_missing_penalty'];
            $reasons[] = 'variant_missing';
        }
    }
    if ($kit_present) {
        if ($has_kit) {
            $score += (int) $prefs['kit_match_score'];
            $reasons[] = 'kit_match';
        } else {
            $score -= (int) $prefs['kit_missing_penalty'];
            $reasons[] = 'kit_missing';
        }
    }
    if (!empty($pack['leaf_preferred']) && $has_base && !$is_series) {
        $score += (int) $prefs['leaf_bonus'];
        $reasons[] = 'leaf_bonus';
    }
    if ($is_series) {
        $score -= (int) $prefs['series_penalty'];
        $reasons[] = 'series_penalty';
    }
    if ($is_accessory && empty($parsed['accessory'])) {
        $score -= (int) $prefs['accessory_penalty'];
        $reasons[] = 'accessory_penalty';
    }

    return [
        'id' => (int) ($product['id'] ?? 0),
        'title' => $title,
        'score' => $score,
        'is_series' => $is_series,
        'is_accessory' => $is_accessory,
        'has_base' => $has_base,
        'has_full' => $has_full,
        'has_family_hint' => $has_family_hint,
        'has_variant' => $has_variant,
        'has_kit' => $has_kit,
        'reasons' => $reasons,
    ];
}

function ado_camden_resolve_parsed_model(array $parsed, array $index): array
{
    $pack = ado_camden_get_pack();
    $prefs = ado_camden_resolver_default_preferences($pack);
    $result = [
        'resolution_status' => 'unresolved',
        'matched_product_id' => 0,
        'matched_product_name' => '',
        'candidate_products' => [],
        'reason_code' => 'CAMDEN_UNRESOLVED',
        'confidence' => 'low',
        'trace' => [],
    ];

    if (($parsed['parse_status'] ?? '') !== 'parsed') {
        $result['trace'][] = 'parse_status=invalid';
        return $result;
    }

    $candidates = ado_camden_resolver_collect_candidates($parsed, $index, $pack);
    if (!$candidates) {
        $result['trace'][] = 'candidate_count=0';
        return $result;
    }

    $scored = [];
    foreach ($candidates as $product) {
        $scored[] = ado_camden_resolver_score_candidate($product, $parsed, $pack, $prefs);
    }

    usort($scored, function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return $a['id'] <=> $b['id'];
        }
        return $b['score'] <=> $a['score'];
    });

    $result['candidate_products'] = $scored;
    $result['trace'][] = 'candidate_count=' . count($scored);

    $top = $scored[0] ?? null;
    if (!$top || (int) ($top['id'] ?? 0) <= 0) {
        $result['trace'][] = 'top_candidate=missing';
        return $result;
    }

    $top_score = (int) ($top['score'] ?? 0);
    $result['trace'][] = 'top_score=' . $top_score;
    $result['trace'][] = 'top_reasons=' . implode(',', (array) ($top['reasons'] ?? []));

    if ($top_score >= (int) $prefs['min_exact_score'] && empty($top['is_series']) && empty($top['is_accessory'])) {
        $result['resolution_status'] = 'exact';
        $result['matched_product_id'] = (int) $top['id'];
        $result['matched_product_name'] = (string) ($top['title'] ?? '');
        $result['reason_code'] = 'CAMDEN_EXACT';
        $result['confidence'] = 'high';
        return $result;
    }

    if (!empty($top['is_series']) && (!empty($top['has_base']) || !empty($top['has_full']) || !empty($top['has_family_hint']))) {
        $result['resolution_status'] = 'review';
        $result['matched_product_id'] = (int) $top['id'];
        $result['matched_product_name'] = (string) ($top['title'] ?? '');
        $result['reason_code'] = 'CAMDEN_SERIES_REVIEW';
        $result['confidence'] = 'low';
        return $result;
    }

    if ($top_score >= (int) $prefs['min_review_score']) {
        $result['resolution_status'] = 'review';
        $result['matched_product_id'] = (int) $top['id'];
        $result['matched_product_name'] = (string) ($top['title'] ?? '');
        $result['reason_code'] = !empty($top['is_series']) ? 'CAMDEN_SERIES_REVIEW' : 'CAMDEN_REVIEW';
        $result['confidence'] = 'medium';
        return $result;
    }

    $result['trace'][] = 'top_below_threshold';
    return $result;
}
