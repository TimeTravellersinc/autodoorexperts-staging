<?php
// ADO Quote Matcher: brand/model bank + schema-driven scoped line matching.
if (defined('ADO_QUOTE_MATCHER_LOADED')) { return; }
define('ADO_QUOTE_MATCHER_LOADED', true);

function ado_qm_index_option_key(): string {
    return 'ado_quote_matcher_index_v1';
}

function ado_qm_override_option_key(): string {
    return 'ado_quote_matcher_overrides_v1';
}

function ado_qm_rejection_option_key(): string {
    return 'ado_quote_matcher_rejections_v1';
}

function ado_qm_meta_model_fields(): array {
    return ['_manufacturer_part_number', 'manufacturer_part_number', '_ado_model', '_ado_catalog', 'manufacturer_sku', 'alternate_sku', 'mpn'];
}

function ado_qm_text_stop_words(): array {
    return [
        'A', 'AL', 'ALUM', 'AND', 'AT', 'AUTO', 'BE', 'BY', 'CONTROL', 'DOOR', 'DOORS', 'EA', 'EACH',
        'ENTRY', 'EXIT', 'FINISH', 'FOR', 'FRAME', 'FRONT', 'HAND', 'HEADER', 'HDR', 'HO', 'IN', 'INSIDE',
        'JOB', 'KEYPAD', 'LH', 'LONG', 'MTG', 'NO', 'OF', 'ON', 'OFF', 'ONLY', 'OPER', 'OPENER', 'OPERATOR',
        'OTHERS', 'OUTSIDE', 'OWNER', 'PAIR', 'PLATE', 'POWER', 'PROX', 'PULL', 'PUSH', 'QTY', 'RAW', 'READER',
        'REG', 'RH', 'RTE', 'SECTION', 'SET', 'SIDE', 'SIZE', 'SPEC', 'STRIKE', 'SUPPLY', 'SWIPE', 'SWITCH',
        'SYSTEM', 'TB', 'THROUGH', 'TO', 'X',
    ];
}

function ado_qm_context_stop_words(): array {
    return array_merge(ado_qm_text_stop_words(), ['THE', 'WITH', 'WITHOUT', 'SERIES', 'PRODUCTS', 'PACKAGE', 'PACKAGES']);
}

function ado_qm_finish_tokens(): array {
    return ['AL', 'BLK', 'BR', 'BZ', 'CLR', 'DURO', 'US10', 'US10B', 'US26', 'US26D', '630', '626', '628', '689'];
}

function ado_qm_decode_text(string $value): string {
    $value = wp_strip_all_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($value);
}

function ado_qm_normalize_text(string $value): string {
    $value = strtoupper(trim(ado_qm_decode_text($value)));
    if ($value === '') { return ''; }
    $value = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", '–', '—'], '-', $value);
    $value = preg_replace('/["\']+/', '', $value);
    $value = preg_replace('/[\(\)\[\],:;]+/', ' ', (string) $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);
    return trim((string) $value);
}

function ado_qm_compact(string $value): string {
    return preg_replace('/[^A-Z0-9]+/', '', ado_qm_normalize_text($value)) ?: '';
}

function ado_qm_alpha_prefix(string $value): string {
    $norm = ado_qm_normalize_text($value);
    if (preg_match('/^([A-Z]{2,8})(?=[-\/]?\d)/', $norm, $m)) {
        return $m[1];
    }
    return '';
}

function ado_qm_numeric_head(string $value): string {
    if (preg_match('/(\d{3})/', ado_qm_normalize_text($value), $m)) {
        return $m[1];
    }
    return '';
}

function ado_qm_is_finish_token(string $value): bool {
    return in_array(ado_qm_compact($value), array_map('ado_qm_compact', ado_qm_finish_tokens()), true);
}

function ado_qm_is_dimension_fragment(string $value): bool {
    return (bool) preg_match('/\b\d+(?:["”])?\s*X\s*\d+(?:["”])?(?:\s*X\s*\d+(?:["”])?)?\b/', ado_qm_normalize_text($value));
}

function ado_qm_is_model_like_fragment(string $value): bool {
    $norm = ado_qm_normalize_text($value);
    $compact = ado_qm_compact($norm);
    if ($norm === '' || $compact === '' || !preg_match('/\d/', $norm)) { return false; }
    if (ado_qm_is_finish_token($norm) || ado_qm_is_dimension_fragment($norm)) { return false; }
    if (preg_match('/^\d{1,3}$/', $compact)) { return false; }
    if (preg_match('/^(?:19|20)\d{2}$/', $compact)) { return false; }
    if (preg_match('/^(?:19|20)\d{6}$/', $compact)) { return false; }
    if (preg_match('/^\d{5,}$/', $compact) && preg_match('/^\d+$/', $norm)) { return false; }
    if (in_array($compact, array_map('ado_qm_compact', ado_qm_text_stop_words()), true)) { return false; }
    return (bool) preg_match('/(?:[A-Z]|-|\d)/', $norm);
}

function ado_qm_context_words(string $value): array {
    $words = preg_split('/[^A-Z0-9]+/', ado_qm_normalize_text($value)) ?: [];
    $stop = array_map('ado_qm_compact', ado_qm_context_stop_words());
    $out = [];
    foreach ($words as $word) {
        $word = trim((string) $word);
        if ($word === '' || strlen($word) < 3) { continue; }
        if (in_array($word, $stop, true)) { continue; }
        if (ado_qm_is_finish_token($word)) { continue; }
        $out[$word] = true;
    }
    return array_keys($out);
}

function ado_qm_extract_fragments_from_text(string $value): array {
    $norm = ado_qm_normalize_text($value);
    if ($norm === '') { return []; }
    $matches = [];
    foreach ([
        '/\b\d{3,5}(?:[-\/][A-Z0-9]{1,8})+\b/u',
        '/\b[A-Z0-9]+(?:[-\/][A-Z0-9]+)+\b/u',
        '/\b(?:[A-Z]{1,8}\d[A-Z0-9]*|\d{4,6}[A-Z]{1,8}[A-Z0-9]*)\b/u',
        '/\b\d{4,5}\b/u',
    ] as $pattern) {
        if (preg_match_all($pattern, $norm, $m, PREG_OFFSET_CAPTURE)) {
            foreach ((array) $m[0] as $entry) {
                $fragment = ado_qm_normalize_text((string) ($entry[0] ?? ''));
                if (!ado_qm_is_model_like_fragment($fragment)) { continue; }
                $matches[] = ['fragment' => $fragment, 'offset' => (int) ($entry[1] ?? 0)];
            }
        }
    }
    usort($matches, static fn(array $a, array $b): int => ($a['offset'] <=> $b['offset']) ?: (strlen($b['fragment']) <=> strlen($a['fragment'])));
    $seen = [];
    $out = [];
    foreach ($matches as $match) {
        $compact = ado_qm_compact((string) ($match['fragment'] ?? ''));
        if ($compact === '' || isset($seen[$compact])) { continue; }
        $seen[$compact] = true;
        $out[] = (string) $match['fragment'];
    }
    return $out;
}

function ado_qm_primary_model_from_field(string $value): string {
    $value = trim($value);
    if ($value === '') { return ''; }
    if (ado_qm_is_model_like_fragment($value)) {
        return ado_qm_normalize_text($value);
    }
    $fragments = ado_qm_extract_fragments_from_text($value);
    return $fragments[0] ?? '';
}

function ado_qm_model_variants(string $value): array {
    $norm = ado_qm_normalize_text($value);
    if ($norm === '') { return []; }
    $ordered = [$norm];
    $slash_trim = trim((string) preg_replace('/\/\d+\b/u', '', $norm));
    if ($slash_trim !== '' && $slash_trim !== $norm) {
        $ordered[] = preg_replace('/\s+/', ' ', $slash_trim) ?: $slash_trim;
    }
    $compact = ado_qm_compact($norm);
    if (preg_match('/^(\d{4,5})(?:[A-Z]{1,6})(?:[-\/][A-Z0-9]+)*$/', $compact, $m)) {
        $ordered[] = $m[1];
    }
    if (preg_match('/^([A-Z]+[-\/]?\d{4,5})(?:[-\/][A-Z0-9]+)*$/', $compact, $m)) {
        $ordered[] = $m[1];
    }
    $seen = [];
    $out = [];
    foreach ($ordered as $variant) {
        $compact = ado_qm_compact((string) $variant);
        if ($compact === '' || isset($seen[$compact])) { continue; }
        $seen[$compact] = true;
        $out[] = $compact;
    }
    return $out;
}

function ado_qm_infer_brand_from_title(string $title): string {
    $title = ado_qm_normalize_text($title);
    if ($title === '') { return ''; }
    $words = preg_split('/\s+/', $title) ?: [];
    $brand = [];
    foreach ($words as $word) {
        $word = trim((string) $word, "-/ \t\n\r\0\x0B");
        if ($word === '') { continue; }
        if (preg_match('/\d/', $word)) { break; }
        if (in_array($word, ['SERIES', 'PRODUCTS'], true)) { break; }
        $brand[] = $word;
        if (count($brand) >= 2) { break; }
    }
    return implode(' ', $brand);
}

function ado_qm_model_signature(string $model): string {
    $norm = ado_qm_normalize_text($model);
    if ($norm === '') { return ''; }
    if (!preg_match_all('/[A-Z]+|\d+|[-\/]/', $norm, $m)) {
        return '';
    }
    $parts = [];
    foreach ((array) $m[0] as $part) {
        if ($part === '-' || $part === '/') {
            $parts[] = $part;
        } elseif (ctype_digit($part)) {
            $parts[] = 'D' . strlen($part);
        } else {
            $parts[] = 'A' . strlen($part);
        }
    }
    return implode('', $parts);
}

function ado_qm_signature_to_regex(string $signature): string {
    if ($signature === '') { return ''; }
    $regex = preg_replace_callback('/([AD])(\d+)/', static function (array $m): string {
        return $m[1] === 'A'
            ? '[A-Z]{' . (int) $m[2] . '}'
            : '\d{' . (int) $m[2] . '}';
    }, preg_quote($signature, '/'));
    $regex = str_replace(['\-','\/'], ['-','\/'], (string) $regex);
    return '/^' . $regex . '$/';
}

function ado_qm_product_model_candidates(string $title, string $sku, array $meta_values): array {
    $ordered = [];
    foreach (array_merge([$sku], $meta_values, [$title]) as $source) {
        $primary = ado_qm_primary_model_from_field((string) $source);
        if ($primary !== '') { $ordered[] = $primary; }
        foreach (ado_qm_extract_fragments_from_text((string) $source) as $fragment) {
            $ordered[] = $fragment;
        }
    }
    $seen = [];
    $out = [];
    foreach ($ordered as $model) {
        $compact = ado_qm_compact((string) $model);
        if ($compact === '' || isset($seen[$compact])) { continue; }
        $seen[$compact] = true;
        $out[] = ado_qm_normalize_text((string) $model);
    }
    return $out;
}

function ado_qm_rebuild_index(): array {
    $posts = get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);

    $raw_products = [];
    $anchor_votes = [];
    foreach ((array) $posts as $post_id) {
        $post_id = (int) $post_id;
        $product = wc_get_product($post_id);
        if (!$product) { continue; }
        $title = ado_qm_decode_text((string) get_the_title($post_id));
        $sku = ado_qm_decode_text((string) $product->get_sku());
        $meta_values = [];
        foreach (ado_qm_meta_model_fields() as $meta_key) {
            $meta_value = get_post_meta($post_id, $meta_key, true);
            if (is_scalar($meta_value) && trim((string) $meta_value) !== '') {
                $meta_values[$meta_key] = ado_qm_decode_text((string) $meta_value);
            }
        }
        $models = ado_qm_product_model_candidates($title, $sku, array_values($meta_values));
        $brand = ado_qm_infer_brand_from_title($title);
        $raw_products[$post_id] = [
            'id' => $post_id,
            'title' => $title,
            'sku' => $sku,
            'meta' => $meta_values,
            'models' => $models,
            'brand' => $brand !== '' ? $brand : 'UNKNOWN',
            'context_words' => ado_qm_context_words($title . ' ' . implode(' ', $meta_values)),
        ];
        if ($brand !== '') {
            foreach ($models as $model) {
                $anchor = ado_qm_alpha_prefix($model);
                if ($anchor === '') { continue; }
                if (!isset($anchor_votes[$anchor])) { $anchor_votes[$anchor] = []; }
                $anchor_votes[$anchor][$brand] = ($anchor_votes[$anchor][$brand] ?? 0) + 1;
            }
        }
    }

    $anchor_map = [];
    foreach ($anchor_votes as $anchor => $votes) {
        arsort($votes);
        $brands = array_keys($votes);
        if (count($brands) === 1 || (($votes[$brands[0]] ?? 0) > ($votes[$brands[1]] ?? 0))) {
            $anchor_map[$anchor] = $brands[0];
        }
    }

    $index = [
        'version' => 1,
        'generated_at' => current_time('mysql'),
        'products' => [],
        'brands' => [],
        'anchors' => $anchor_map,
        'global_models' => [],
        'numeric_heads' => [],
    ];

    foreach ($raw_products as $post_id => $product) {
        $brand = (string) ($product['brand'] ?? 'UNKNOWN');
        if ($brand === 'UNKNOWN') {
            foreach ((array) ($product['models'] ?? []) as $model) {
                $anchor = ado_qm_alpha_prefix($model);
                if ($anchor !== '' && isset($anchor_map[$anchor])) {
                    $brand = (string) $anchor_map[$anchor];
                    break;
                }
            }
        }
        $product['brand'] = $brand;
        $product['title_norm'] = ado_qm_normalize_text((string) $product['title']);
        $product['sku_compact'] = ado_qm_compact((string) $product['sku']);
        $product['model_map'] = [];
        foreach ((array) ($product['models'] ?? []) as $model) {
            $normalized = ado_qm_compact($model);
            if ($normalized === '') { continue; }
            $signature = ado_qm_model_signature($model);
            $product['model_map'][$normalized] = ['display' => $model, 'signature' => $signature];
            $index['global_models'][$normalized][] = $post_id;
            if (!isset($index['brands'][$brand])) {
                $index['brands'][$brand] = ['products' => [], 'models' => [], 'families' => [], 'anchors' => []];
            }
            $index['brands'][$brand]['products'][] = $post_id;
            $index['brands'][$brand]['models'][$normalized][] = $post_id;
            if ($signature !== '') {
                if (!isset($index['brands'][$brand]['families'][$signature])) {
                    $index['brands'][$brand]['families'][$signature] = [
                        'regex' => ado_qm_signature_to_regex($signature),
                        'models' => [],
                    ];
                }
                $index['brands'][$brand]['families'][$signature]['models'][$normalized][] = $post_id;
            }
            $anchor = ado_qm_alpha_prefix($model);
            if ($anchor !== '') {
                $index['brands'][$brand]['anchors'][$anchor] = true;
            }
            $head = ado_qm_numeric_head($model);
            if ($head !== '') {
                $index['numeric_heads'][$head][] = $post_id;
            }
        }
        $index['products'][$post_id] = $product;
    }

    foreach ($index['brands'] as &$brand_group) {
        $brand_group['products'] = array_values(array_unique(array_map('intval', (array) $brand_group['products'])));
        foreach ($brand_group['models'] as &$product_ids) {
            $product_ids = array_values(array_unique(array_map('intval', (array) $product_ids)));
        }
        unset($product_ids);
        $brand_group['anchors'] = array_values(array_keys((array) $brand_group['anchors']));
    }
    unset($brand_group);

    foreach ($index['global_models'] as &$product_ids) {
        $product_ids = array_values(array_unique(array_map('intval', (array) $product_ids)));
    }
    unset($product_ids);

    foreach ($index['numeric_heads'] as &$product_ids) {
        $product_ids = array_values(array_unique(array_map('intval', (array) $product_ids)));
    }
    unset($product_ids);

    update_option(ado_qm_index_option_key(), $index, false);
    return $index;
}

function ado_qm_get_index(bool $force = false): array {
    static $cache = null;
    if (!$force && is_array($cache)) { return $cache; }
    $index = !$force ? get_option(ado_qm_index_option_key(), null) : null;
    if (!is_array($index) || (int) ($index['version'] ?? 0) !== 1) {
        $index = ado_qm_rebuild_index();
    }
    $cache = is_array($index) ? $index : [];
    return $cache;
}

function ado_qm_get_overrides(): array {
    $overrides = get_option(ado_qm_override_option_key(), []);
    return is_array($overrides) ? $overrides : [];
}

function ado_qm_get_rejections(): array {
    $rejections = get_option(ado_qm_rejection_option_key(), []);
    return is_array($rejections) ? $rejections : [];
}

function ado_qm_override_lookup(string $decision_key): int {
    $overrides = ado_qm_get_overrides();
    $entry = $overrides[$decision_key] ?? null;
    if (!is_array($entry)) { return 0; }
    $product_id = (int) ($entry['product_id'] ?? 0);
    return $product_id > 0 ? $product_id : 0;
}

function ado_qm_save_override_choice(string $decision_key, string $normalized_model, string $brand, int $product_id): void {
    if ($decision_key === '' || $normalized_model === '' || $product_id <= 0) { return; }
    $overrides = ado_qm_get_overrides();
    foreach (array_values(array_unique(array_filter([$decision_key, $brand !== '' ? ($brand . '|' . $normalized_model) : '', '*|' . $normalized_model]))) as $key) {
        $current = $overrides[$key] ?? ['count' => 0];
        $overrides[$key] = [
            'product_id' => $product_id,
            'count' => (int) ($current['count'] ?? 0) + 1,
            'brand' => $brand,
            'normalized_model' => $normalized_model,
            'updated_at' => current_time('mysql'),
        ];
    }
    update_option(ado_qm_override_option_key(), $overrides, false);
}

function ado_qm_save_rejection(string $decision_key, array $product_ids): void {
    if ($decision_key === '' || !$product_ids) { return; }
    $rejections = ado_qm_get_rejections();
    if (!isset($rejections[$decision_key]) || !is_array($rejections[$decision_key])) {
        $rejections[$decision_key] = [];
    }
    foreach (array_values(array_unique(array_map('intval', $product_ids))) as $product_id) {
        if ($product_id <= 0) { continue; }
        $rejections[$decision_key][(string) $product_id] = (int) (($rejections[$decision_key][(string) $product_id] ?? 0) + 1);
    }
    update_option(ado_qm_rejection_option_key(), $rejections, false);
}

function ado_qm_is_external_scope_line(string $raw_line): bool {
    return (bool) preg_match('/\b(?:BY OTHERS|BY OWNER|N\.I\.C\.)\b/', ado_qm_normalize_text($raw_line));
}

function ado_qm_strip_revision_tail(string $raw_line): string {
    return trim((string) preg_replace('/\b(?:ADDED|REVISED|DELETE(?:D)?)\b.*$/', '', ado_qm_normalize_text($raw_line)));
}

function ado_qm_trim_narrative_tail(string $raw_line): string {
    $norm = ado_qm_strip_revision_tail($raw_line);
    foreach ([' - ENTRY AND EXIT THROUGH', ' - ACCESS CONTROL', ' - SUPPLIED BY ACCESS CONTROL', ' 2025-'] as $needle) {
        $pos = strpos($norm, $needle);
        if ($pos !== false) {
            $norm = substr($norm, 0, $pos);
            break;
        }
    }
    return trim($norm);
}

function ado_qm_split_raw_segments(string $raw_line): array {
    $clean = ado_qm_trim_narrative_tail($raw_line);
    if ($clean === '') { return []; }
    $parts = preg_split('/\s+(?=(?:[1-9]|[1-9]\d)\s+[A-Z])/', $clean) ?: [];
    $segments = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') { continue; }
        $segments[] = $part;
    }
    return $segments ?: [$clean];
}

function ado_qm_segment_qty(string $segment, int $fallback): int {
    if (preg_match('/^\s*(\d+)\b/', $segment, $m)) {
        $qty = (int) $m[1];
        return $qty > 0 ? $qty : $fallback;
    }
    return $fallback;
}

function ado_qm_extract_candidates(array $item, string $segment, array $index): array {
    $ordered = [];
    $strong_sources = array_filter([(string) ($item['catalog'] ?? ''), $segment], 'strlen');
    $strong_found = false;
    foreach ($strong_sources as $source) {
        foreach (ado_qm_extract_fragments_from_text((string) $source) as $fragment) {
            $normalized = ado_qm_compact($fragment);
            if ($normalized === '') { continue; }
            $variants = ado_qm_model_variants($fragment);
            $signature = ado_qm_model_signature($fragment);
            $brand_hints = [];
            $anchor = ado_qm_alpha_prefix($fragment);
            if ($anchor !== '' && isset($index['anchors'][$anchor])) {
                $brand_hints[] = (string) $index['anchors'][$anchor];
            }
            $ordered[] = [
                'fragment' => $fragment,
                'normalized' => $normalized,
                'variants' => $variants ?: [$normalized],
                'signature' => $signature,
                'anchor' => $anchor,
                'brand_hints' => array_values(array_unique($brand_hints)),
            ];
            $strong_found = true;
        }
    }
    if (!$strong_found) {
        foreach (array_filter([(string) ($item['desc'] ?? '')], 'strlen') as $source) {
            foreach (ado_qm_extract_fragments_from_text((string) $source) as $fragment) {
                $normalized = ado_qm_compact($fragment);
                if ($normalized === '') { continue; }
                $variants = ado_qm_model_variants($fragment);
                $signature = ado_qm_model_signature($fragment);
                $brand_hints = [];
                $anchor = ado_qm_alpha_prefix($fragment);
                if ($anchor !== '' && isset($index['anchors'][$anchor])) {
                    $brand_hints[] = (string) $index['anchors'][$anchor];
                }
                $ordered[] = [
                    'fragment' => $fragment,
                    'normalized' => $normalized,
                    'variants' => $variants ?: [$normalized],
                    'signature' => $signature,
                    'anchor' => $anchor,
                    'brand_hints' => array_values(array_unique($brand_hints)),
                ];
            }
        }
    }
    $seen = [];
    $out = [];
    foreach ($ordered as $candidate) {
        $normalized = (string) ($candidate['normalized'] ?? '');
        if ($normalized === '' || isset($seen[$normalized])) { continue; }
        $seen[$normalized] = true;
        $brand_hints = (array) ($candidate['brand_hints'] ?? []);
        $decision_keys = [];
        foreach ((array) ($candidate['variants'] ?? [$normalized]) as $variant) {
            $decision_keys[] = '*|' . $variant;
            foreach ($brand_hints as $brand) {
                $decision_keys[] = $brand . '|' . $variant;
            }
        }
        $candidate['decision_keys'] = array_values(array_unique($decision_keys));
        $out[] = $candidate;
    }
    return $out;
}

function ado_qm_is_operator_accessory_segment(string $segment): bool {
    $norm = ado_qm_normalize_text($segment);
    if ($norm === '') { return false; }
    if (strpos($norm, 'MOUNTING PLATE') !== false) { return true; }
    if ((bool) preg_match('/\b(?:TB X|CONCEALED IN HEADER|ON\/OFF\/HO SWITCH|PUSH SIDE MTG|PULL SIDE MTG)\b/', $norm) && strpos($norm, 'AUTO OPENER') === false && strpos($norm, 'OPERATOR') === false) {
        return true;
    }
    return false;
}

function ado_qm_override_is_safe(string $segment, array $candidate, int $override_id, array $index): bool {
    if ($override_id <= 0 || empty($index['products'][$override_id])) {
        return false;
    }
    $product = (array) $index['products'][$override_id];
    $title = strtoupper((string) ($product['title'] ?? ''));
    if ($title === '') {
        return false;
    }
    if (strpos($title, '9500 SERIES') !== false) {
        return false;
    }
    return true;
}

function ado_qm_find_product_by_model_hint(array $index, string $model_hint): int {
    $model_hint = ado_qm_compact($model_hint);
    if ($model_hint === '') { return 0; }
    $ids = array_values(array_unique(array_map('intval', (array) ($index['global_models'][$model_hint] ?? []))));
    return count($ids) === 1 ? (int) $ids[0] : 0;
}

function ado_qm_resolve_iq_operator_family(array $candidate, string $segment, array $index): array {
    $normalized = (string) ($candidate['normalized'] ?? '');
    if ($normalized === '') {
        return [];
    }
    $compact = ado_qm_compact($normalized);
    if (!preg_match('/^(95[3456])\d{1,3}$/', $compact, $m)) {
        return [];
    }

    $family_map = [
        '953' => '9530IQ',
        '954' => '9540IQ',
        '955' => '9550IQ',
        '956' => '9560IQ',
    ];
    $family_model = (string) ($family_map[$m[1]] ?? '');
    if ($family_model === '') {
        return [];
    }

    $segment_norm = ado_qm_normalize_text($segment);
    $plate = strpos($segment_norm, 'MOUNTING PLATE') !== false || str_ends_with($compact, '18');
    $target_model = $plate ? ($family_model . '-18') : $family_model;
    $product_id = ado_qm_find_product_by_model_hint($index, $target_model);
    if ($product_id <= 0) {
        return [];
    }

    return [
        'product_id' => $product_id,
        'match_method' => $plate ? 'family_plate_model' : 'family_operator_model',
        'normalized_model' => ado_qm_compact($family_model),
    ];
}

function ado_qm_resolve_9500_family(array $candidate, string $segment, array $index): array {
    $normalized = ado_qm_compact((string) ($candidate['normalized'] ?? ''));
    if ($normalized !== '9500') {
        return [];
    }
    $segment_norm = ado_qm_normalize_text($segment);
    $target_model = '';
    $method = '';
    if (strpos($segment_norm, 'MOUNTING PLATE') !== false) {
        $target_model = '9500-18';
        $method = 'family_plate_model';
    } elseif (strpos($segment_norm, 'ELECTRIC STRIKE') !== false) {
        $target_model = 'HES-9500-SERIES';
        $method = 'family_strike_model';
    } elseif (strpos($segment_norm, 'AUTO OPENER') !== false || strpos($segment_norm, 'OPERATOR') !== false) {
        $target_model = '9500';
        $method = 'family_operator_model';
    }
    if ($target_model === '') {
        return [];
    }
    $product_id = ado_qm_find_product_by_model_hint($index, $target_model);
    if ($product_id <= 0) {
        return [];
    }
    return [
        'product_id' => $product_id,
        'match_method' => $method,
        'normalized_model' => ado_qm_compact($target_model),
    ];
}

function ado_qm_exact_product_rows(array $product_ids, array $index, string $method, int $score): array {
    $rows = [];
    foreach (array_values(array_unique(array_map('intval', $product_ids))) as $product_id) {
        if ($product_id <= 0 || empty($index['products'][$product_id])) { continue; }
        $product = $index['products'][$product_id];
        $rows[] = [
            'product_id' => $product_id,
            'score' => $score,
            'method' => $method,
            'sku' => (string) ($product['sku'] ?? ''),
            'title' => (string) ($product['title'] ?? ''),
            'brand' => (string) ($product['brand'] ?? 'UNKNOWN'),
        ];
    }
    return $rows;
}

function ado_qm_context_overlap_score(array $context_words, array $product): int {
    $overlap = array_intersect($context_words, (array) ($product['context_words'] ?? []));
    return min(18, count($overlap) * 6);
}

function ado_qm_fuzzy_product_rows(array $candidate, array $context_words, array $index): array {
    $normalized = (string) ($candidate['normalized'] ?? '');
    if ($normalized === '') { return []; }
    $signature = (string) ($candidate['signature'] ?? '');
    $head = ado_qm_numeric_head($normalized);
    $pool = [];
    foreach ((array) ($candidate['brand_hints'] ?? []) as $brand) {
        $pool = array_merge($pool, (array) ($index['brands'][$brand]['products'] ?? []));
        if ($signature !== '') {
            foreach ((array) ($index['brands'][$brand]['families'][$signature]['models'] ?? []) as $product_ids) {
                $pool = array_merge($pool, (array) $product_ids);
            }
        }
    }
    if (!$pool && $head !== '') {
        $pool = array_merge($pool, (array) ($index['numeric_heads'][$head] ?? []));
    }
    if (!$pool) { return []; }

    $rows = [];
    foreach (array_values(array_unique(array_map('intval', $pool))) as $product_id) {
        if (empty($index['products'][$product_id])) { continue; }
        $product = $index['products'][$product_id];
        $best_score = 0;
        foreach ((array) ($product['model_map'] ?? []) as $product_model => $meta) {
            $score = 0;
            if ($signature !== '' && $signature === (string) ($meta['signature'] ?? '')) { $score += 18; }
            if (!empty($candidate['anchor']) && $candidate['anchor'] === ado_qm_alpha_prefix((string) ($meta['display'] ?? ''))) { $score += 16; }
            if ($head !== '' && $head === ado_qm_numeric_head($product_model)) { $score += 12; }
            similar_text($normalized, (string) $product_model, $pct);
            $score += (int) round($pct / 3);
            $score += ado_qm_context_overlap_score($context_words, $product);
            if ($score > $best_score) { $best_score = $score; }
        }
        if ($best_score < 68) { continue; }
        $rows[] = [
            'product_id' => $product_id,
            'score' => min(95, $best_score),
            'method' => 'schema_review',
            'sku' => (string) ($product['sku'] ?? ''),
            'title' => (string) ($product['title'] ?? ''),
            'brand' => (string) ($product['brand'] ?? 'UNKNOWN'),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => ((int) $b['score'] <=> (int) $a['score']) ?: ((int) $a['product_id'] <=> (int) $b['product_id']));
    return array_slice($rows, 0, 5);
}

function ado_qm_filter_rejected_rows(array $rows, array $decision_keys): array {
    if (!$rows || !$decision_keys) { return $rows; }
    $rejections = ado_qm_get_rejections();
    $rejected_ids = [];
    foreach ($decision_keys as $decision_key) {
        foreach ((array) ($rejections[$decision_key] ?? []) as $product_id => $count) {
            if ((int) $count > 0) { $rejected_ids[(int) $product_id] = true; }
        }
    }
    if (!$rejected_ids) { return $rows; }
    return array_values(array_filter($rows, static fn(array $row): bool => empty($rejected_ids[(int) ($row['product_id'] ?? 0)])));
}

function ado_qm_match_segment(array $item, string $segment, array $index): array {
    $clean_segment = trim($segment);
    $qty = ado_qm_segment_qty($clean_segment, isset($item['qty']) ? (int) $item['qty'] : 1);
    $context_words = ado_qm_context_words(((string) ($item['desc'] ?? '')) . ' ' . $clean_segment);
    $trace = ['segment=' . $clean_segment];
    if (ado_qm_is_external_scope_line($clean_segment)) {
        return [
            'product_id' => 0,
            'qty' => $qty,
            'raw_line' => $clean_segment,
            'source_model' => (string) ($item['catalog'] ?? ''),
            'source_desc' => (string) ($item['desc'] ?? ''),
            'match_method' => 'excluded',
            'confidence' => 0,
            'reason_code' => 'EXTERNAL_SCOPE',
            'candidate_products' => [],
            'decision_key' => '',
            'normalized_model' => '',
            'trace' => $trace,
        ];
    }

    $candidates = ado_qm_extract_candidates($item, $clean_segment, $index);
    $trace[] = 'candidates=' . implode(', ', array_map(static fn(array $row): string => (string) ($row['fragment'] ?? ''), $candidates));
    if (!$candidates) {
        return [
            'product_id' => 0,
            'qty' => $qty,
            'raw_line' => $clean_segment,
            'source_model' => (string) ($item['catalog'] ?? ''),
            'source_desc' => (string) ($item['desc'] ?? ''),
            'match_method' => 'none',
            'confidence' => 0,
            'reason_code' => 'NO_CANDIDATES',
            'candidate_products' => [],
            'decision_key' => '',
            'normalized_model' => '',
            'trace' => $trace,
        ];
    }

    $review_rows = [];
    $review_key = '';
    $review_model = '';
    $best_review_score = -1;
    foreach ($candidates as $candidate) {
        $normalized = (string) ($candidate['normalized'] ?? '');
        if ($normalized === '') { continue; }
        $family_match = ado_qm_resolve_iq_operator_family($candidate, $clean_segment, $index);
        if (!empty($family_match['product_id'])) {
            return [
                'product_id' => (int) $family_match['product_id'],
                'qty' => $qty,
                'raw_line' => $clean_segment,
                'source_model' => (string) ($item['catalog'] ?? ''),
                'source_desc' => (string) ($item['desc'] ?? ''),
                'match_method' => (string) ($family_match['match_method'] ?? 'family_operator_model'),
                'confidence' => 96,
                'reason_code' => '',
                'candidate_products' => [],
                'decision_key' => '*|' . (string) ($family_match['normalized_model'] ?? $normalized),
                'normalized_model' => (string) ($family_match['normalized_model'] ?? $normalized),
                'trace' => array_merge($trace, ['family_resolve=' . $normalized . '->' . (int) $family_match['product_id']]),
            ];
        }
        $family_match = ado_qm_resolve_9500_family($candidate, $clean_segment, $index);
        if (!empty($family_match['product_id'])) {
            return [
                'product_id' => (int) $family_match['product_id'],
                'qty' => $qty,
                'raw_line' => $clean_segment,
                'source_model' => (string) ($item['catalog'] ?? ''),
                'source_desc' => (string) ($item['desc'] ?? ''),
                'match_method' => (string) ($family_match['match_method'] ?? 'family_operator_model'),
                'confidence' => 96,
                'reason_code' => '',
                'candidate_products' => [],
                'decision_key' => '*|' . (string) ($family_match['normalized_model'] ?? $normalized),
                'normalized_model' => (string) ($family_match['normalized_model'] ?? $normalized),
                'trace' => array_merge($trace, ['family_resolve=' . $normalized . '->' . (int) $family_match['product_id']]),
            ];
        }
        foreach ((array) ($candidate['decision_keys'] ?? []) as $decision_key) {
            $override_id = ado_qm_override_lookup((string) $decision_key);
            if ($override_id > 0 && ado_qm_override_is_safe($clean_segment, $candidate, $override_id, $index)) {
                return [
                    'product_id' => $override_id,
                    'qty' => $qty,
                    'raw_line' => $clean_segment,
                    'source_model' => (string) ($item['catalog'] ?? ''),
                    'source_desc' => (string) ($item['desc'] ?? ''),
                    'match_method' => 'user_override',
                    'confidence' => 100,
                    'reason_code' => '',
                    'candidate_products' => [],
                    'decision_key' => (string) $decision_key,
                    'normalized_model' => $normalized,
                    'trace' => array_merge($trace, ['override=' . $decision_key . '->' . $override_id]),
                ];
            } elseif ($override_id > 0) {
                $trace[] = 'override_blocked=' . $decision_key . '->' . $override_id;
            }
        }

        $exact_rows = [];
        $exact_variant = '';
        foreach ((array) ($candidate['variants'] ?? [$normalized]) as $variant) {
            $exact_ids = array_values(array_unique(array_map('intval', (array) ($index['global_models'][$variant] ?? []))));
            if (count($exact_ids) === 1) {
                return [
                    'product_id' => (int) $exact_ids[0],
                    'qty' => $qty,
                    'raw_line' => $clean_segment,
                    'source_model' => (string) ($item['catalog'] ?? ''),
                    'source_desc' => (string) ($item['desc'] ?? ''),
                    'match_method' => $variant === $normalized ? 'exact_model' : 'exact_variant',
                    'confidence' => 100,
                    'reason_code' => '',
                    'candidate_products' => [],
                    'decision_key' => '*|' . $variant,
                    'normalized_model' => $variant,
                    'trace' => array_merge($trace, ['exact=' . $variant]),
                ];
            }
            if ($exact_ids) {
                $exact_variant = $exact_variant ?: $variant;
                $exact_rows = array_merge($exact_rows, ado_qm_exact_product_rows($exact_ids, $index, 'exact_duplicate', 100));
            }
        }
        $candidate_rows = [];
        if ($exact_rows) {
            $candidate_rows = $exact_rows;
        } else {
            $candidate_rows = ado_qm_fuzzy_product_rows($candidate, $context_words, $index);
        }
        $candidate_rows = ado_qm_filter_rejected_rows($candidate_rows, (array) ($candidate['decision_keys'] ?? []));
        if (!$candidate_rows) { continue; }
        $top_score = (int) ($candidate_rows[0]['score'] ?? 0);
        if ($top_score > $best_review_score) {
            $best_review_score = $top_score;
            $review_rows = $candidate_rows;
            $review_key = $exact_variant !== '' ? ('*|' . $exact_variant) : (string) (($candidate['decision_keys'][0] ?? '*|' . $normalized));
            $review_model = $exact_variant !== '' ? $exact_variant : $normalized;
        }
    }

    if ($review_rows) {
        $reason = count($review_rows) > 1 ? 'MULTIPLE_CANDIDATES' : 'USER_REVIEW';
        return [
            'product_id' => 0,
            'qty' => $qty,
            'raw_line' => $clean_segment,
            'source_model' => (string) ($item['catalog'] ?? ''),
            'source_desc' => (string) ($item['desc'] ?? ''),
            'match_method' => 'review',
            'confidence' => (int) ($review_rows[0]['score'] ?? 0),
            'reason_code' => $reason,
            'candidate_products' => $review_rows,
            'decision_key' => $review_key,
            'normalized_model' => $review_model,
            'trace' => array_merge($trace, ['review=' . $review_key]),
        ];
    }

    return [
        'product_id' => 0,
        'qty' => $qty,
        'raw_line' => $clean_segment,
        'source_model' => (string) ($item['catalog'] ?? ''),
        'source_desc' => (string) ($item['desc'] ?? ''),
        'match_method' => 'none',
        'confidence' => 0,
        'reason_code' => 'NO_CANDIDATES',
        'candidate_products' => [],
        'decision_key' => '',
        'normalized_model' => '',
        'trace' => $trace,
    ];
}

function ado_qm_match_item_segments(array $item, ?array $index = null): array {
    $index = is_array($index) ? $index : ado_qm_get_index();
    $raw_line = (string) ($item['raw'] ?? '');
    $segments = ado_qm_split_raw_segments($raw_line);
    if (!$segments) { $segments = [$raw_line]; }
    $results = [];
    foreach ($segments as $segment) {
        $results[] = ado_qm_match_segment($item, $segment, $index);
    }
    return $results;
}

add_action('save_post_product', static function (int $post_id, WP_Post $post, bool $update): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (wp_is_post_revision($post_id)) { return; }
    ado_qm_rebuild_index();
}, 30, 3);

add_action('trashed_post', static function (int $post_id): void {
    if (get_post_type($post_id) === 'product') { ado_qm_rebuild_index(); }
});

add_action('untrashed_post', static function (int $post_id): void {
    if (get_post_type($post_id) === 'product') { ado_qm_rebuild_index(); }
});

add_action('deleted_post', static function (int $post_id): void {
    if (get_post_type($post_id) === 'product') { ado_qm_rebuild_index(); }
});
