<?php
// ADO Quote Carts: parser scoped json -> Woo cart -> saved quote drafts
if (defined('ADO_QUOTE_CARTS_LOADED')) { return; }
define('ADO_QUOTE_CARTS_LOADED', true);

function ado_get_quote_drafts(int $user_id): array {
    $drafts = get_user_meta($user_id, '_ado_quote_drafts', true);
    return is_array($drafts) ? array_values($drafts) : [];
}

function ado_save_quote_drafts(int $user_id, array $drafts): void {
    update_user_meta($user_id, '_ado_quote_drafts', array_values($drafts));
}

function ado_scope_url_to_path(string $scope_url): string {
    $uploads = wp_upload_dir();
    if (strpos($scope_url, (string) $uploads['baseurl']) !== 0) { return ''; }
    $rel = ltrim((string) substr($scope_url, strlen((string) $uploads['baseurl'])), '/');
    return trailingslashit((string) $uploads['basedir']) . $rel;
}

function ado_quote_generated_alias_option_key(): string {
    return 'ado_quote_generated_aliases';
}

function ado_quote_manual_alias_map(): array {
    $aliases = get_option('ado_quote_match_aliases', []);
    return is_array($aliases) ? $aliases : [];
}

function ado_quote_generated_alias_map(): array {
    $aliases = get_option(ado_quote_generated_alias_option_key(), []);
    return is_array($aliases) ? $aliases : [];
}

function ado_quote_alias_map(): array {
    $generated = ado_quote_generated_alias_map();
    $manual = ado_quote_manual_alias_map();
    return array_replace($generated, $manual);
}

function ado_quote_normalize_text(string $value): string {
    $value = strtoupper(trim($value));
    if ($value === '') { return ''; }
    $value = str_replace(
        ["\xE2\x80\x93", "\xE2\x80\x94", "\u{00E2}\u{20AC}\u{201C}", "\u{00E2}\u{20AC}\u{201D}"],
        '-',
        $value
    );
    $value = preg_replace('/["\']+/', '', $value);
    $value = preg_replace('/[\(\)\[\],:;]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);
    return trim((string) $value);
}

function ado_quote_compact_token(string $value): string {
    return preg_replace('/[^A-Z0-9]+/', '', ado_quote_normalize_text($value)) ?: '';
}

function ado_quote_stop_tokens(): array {
    return ['AL','ALUM','BY','DIV','DIV28','DOOR','DOORS','EA','EACH','FINISH','FRAME','HAND','HEADER','HDR','JOB','KINGSTON','LH','LONG','NO','OF','OPER','OPERATOR','OTHERS','PAIR','PULL','PUSH','QTY','RAW','REG','RH','SET','SIZE','SPEC'];
}

function ado_quote_title_model_tokens(string $title): array {
    $title = ado_quote_normalize_text($title);
    if ($title === '') { return []; }
    $ordered = [];
    if (preg_match_all('/\b[A-Z0-9]{2,}(?:\s*[-\/]\s*[A-Z0-9]{1,})+\b/', $title, $m)) {
        foreach ((array) $m[0] as $match) { $ordered[] = ado_quote_normalize_text((string) $match); }
    }
    if (preg_match_all('/\b(?:[A-Z]{0,5}\d{3,5}[A-Z0-9]{0,12}|\d{3,5}[A-Z0-9]{0,12})\b/', $title, $m)) {
        foreach ((array) $m[0] as $match) { $ordered[] = ado_quote_normalize_text((string) $match); }
    }
    if (preg_match_all('/\b\d{4}\b/', $title, $m)) {
        foreach ((array) $m[0] as $match) { $ordered[] = ado_quote_normalize_text((string) $match); }
    }
    $tokens = [];
    $stop = ado_quote_stop_tokens();
    foreach ($ordered as $token) {
        $compact = ado_quote_compact_token($token);
        if ($compact === '' || in_array($compact, $stop, true)) { continue; }
        $tokens[] = $token;
        $tokens[] = $compact;
        if (preg_match('/^(9\d)\d\d[A-Z0-9]*$/', $compact, $fam)) {
            $tokens[] = $fam[1] . '00';
        }
    }
    return array_values(array_unique(array_filter($tokens)));
}

function ado_quote_register_generated_alias(array &$aliases, string $alias, int $product_id): void {
    $key = ado_quote_compact_token($alias);
    if ($key === '') { return; }
    if (!isset($aliases[$key])) {
        $aliases[$key] = [$product_id];
        return;
    }
    if (!in_array($product_id, (array) $aliases[$key], true)) {
        $aliases[$key][] = $product_id;
    }
}

function ado_quote_product_alias_tokens(int $post_id, WC_Product $product): array {
    $title = (string) get_the_title($post_id);
    $sku = (string) $product->get_sku();
    $meta_bits = [];
    foreach (['_manufacturer_part_number', 'manufacturer_part_number', '_ado_model', '_ado_catalog', 'manufacturer_sku', 'alternate_sku', 'mpn'] as $meta_key) {
        $meta_value = get_post_meta($post_id, $meta_key, true);
        if (is_scalar($meta_value) && (string) $meta_value !== '') { $meta_bits[] = (string) $meta_value; }
    }
    $tokens = ado_quote_title_model_tokens($title);
    $tokens = array_merge($tokens, ado_quote_extract_model_tokens([
        'catalog' => $title,
        'model' => $sku,
        'desc' => implode(' ', $meta_bits),
        'description' => '',
        'raw' => '',
    ]));
    if (preg_match('/\bLCN\b/', ado_quote_normalize_text($title))) {
        $tokens = array_merge($tokens, ado_quote_lcn_family_tokens(['raw' => $title], $tokens));
    }
    return array_values(array_unique(array_filter($tokens)));
}

function ado_quote_rebuild_generated_aliases(): array {
    $posts = get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);
    $aliases = [];
    foreach ((array) $posts as $post_id) {
        $post_id = (int) $post_id;
        $product = wc_get_product($post_id);
        if (!$product) { continue; }
        foreach (ado_quote_product_alias_tokens($post_id, $product) as $token) {
            ado_quote_register_generated_alias($aliases, (string) $token, $post_id);
        }
    }
    $final = [];
    foreach ($aliases as $alias => $product_ids) {
        $product_ids = array_values(array_unique(array_map('intval', (array) $product_ids)));
        if (!$product_ids) { continue; }
        $final[$alias] = count($product_ids) === 1 ? $product_ids[0] : $product_ids;
    }
    update_option(ado_quote_generated_alias_option_key(), $final, false);
    return $final;
}

function ado_quote_maybe_rebuild_generated_aliases(): void {
    if (!is_array(get_option(ado_quote_generated_alias_option_key(), null))) {
        ado_quote_rebuild_generated_aliases();
    }
}

function ado_quote_extract_model_tokens(array $item): array {
    $ordered = [];
    foreach (['catalog', 'model', 'desc', 'description', 'raw'] as $field) {
        $source = ado_quote_normalize_text((string) ($item[$field] ?? ''));
        if ($source === '') { continue; }
        if (preg_match_all('/\b[A-Z0-9]{2,}(?:\s*[-\/]\s*[A-Z0-9]{1,})+\b/', $source, $m)) {
            foreach ((array) $m[0] as $match) { $ordered[] = ado_quote_normalize_text((string) $match); }
        }
        if (preg_match_all('/\b(?:[A-Z]{0,4}\d{3,5}[A-Z0-9]{0,8}|\d{3,5}[A-Z0-9]{0,8})\b/', $source, $m)) {
            foreach ((array) $m[0] as $match) { $ordered[] = ado_quote_normalize_text((string) $match); }
        }
    }
    $stop = ado_quote_stop_tokens();
    $tokens = [];
    foreach ($ordered as $token) {
        $compact = ado_quote_compact_token($token);
        if ($compact === '' || in_array($compact, $stop, true)) { continue; }
        if (strlen($compact) < 4 && !preg_match('/^\d{4,}$/', $compact)) { continue; }
        $tokens[] = $token;
    }
    return array_values(array_unique($tokens));
}

function ado_quote_lcn_family_tokens(array $item, array $tokens): array {
    $context = ado_quote_normalize_text((string) ($item['raw'] ?? '') . ' ' . (string) ($item['catalog'] ?? '') . ' ' . (string) ($item['desc'] ?? ''));
    if (strpos($context, 'LCN') === false && strpos($context, 'OPERATOR') === false) { return []; }
    $out = [];
    foreach ($tokens as $token) {
        $compact = ado_quote_compact_token($token);
        if (preg_match('/^(9\d)\d\d[A-Z0-9]*$/', $compact, $m)) { $out[] = $m[1] . '00'; }
    }
    return array_values(array_unique($out));
}

function ado_quote_item_qty(array $item): int {
    $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 1;
    return $qty > 0 ? $qty : 1;
}

function ado_quote_product_index(): array {
    static $cache = null;
    if (is_array($cache)) { return $cache; }
    ado_quote_maybe_rebuild_generated_aliases();
    $posts = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true]);
    $products = [];
    $by_sku = [];
    $by_alias = [];
    foreach (ado_quote_alias_map() as $alias => $target) {
        $key = ado_quote_compact_token((string) $alias);
        if ($key === '') { continue; }
        if (is_array($target)) {
            $by_alias[$key] = array_values(array_unique(array_map('intval', $target)));
        } else {
            $by_alias[$key] = is_numeric($target) ? (int) $target : ado_quote_compact_token((string) $target);
        }
    }
    foreach ((array) $posts as $post_id) {
        $post_id = (int) $post_id;
        $product = wc_get_product($post_id);
        if (!$product) { continue; }
        $title = (string) get_the_title($post_id);
        $sku = (string) $product->get_sku();
        $meta_fields = [];
        foreach (['_manufacturer_part_number', 'manufacturer_part_number', '_ado_model', '_ado_catalog', 'manufacturer_sku', 'alternate_sku', 'mpn'] as $meta_key) {
            $meta_value = get_post_meta($post_id, $meta_key, true);
            if (is_scalar($meta_value) && (string) $meta_value !== '') { $meta_fields[$meta_key] = (string) $meta_value; }
        }
        $haystack = ado_quote_normalize_text(implode(' ', array_filter([
            $title,
            $sku,
            (string) get_post_field('post_excerpt', $post_id),
            (string) get_post_field('post_content', $post_id),
            implode(' ', array_values($meta_fields)),
        ], 'strlen')));
        $sku_compact = ado_quote_compact_token($sku);
        if ($sku_compact !== '') { $by_sku[$sku_compact] = $post_id; }
        $products[$post_id] = ['id' => $post_id, 'title' => $title, 'title_norm' => ado_quote_normalize_text($title), 'sku' => $sku, 'sku_compact' => $sku_compact, 'meta' => $meta_fields, 'haystack' => $haystack, 'haystack_compact' => ado_quote_compact_token($haystack)];
    }
    $cache = ['products' => $products, 'by_sku' => $by_sku, 'by_alias' => $by_alias];
    return $cache;
}

function ado_quote_pick_reason(array $trace, array $candidates, array $tokens): string {
    if (in_array('normalization-empty', $trace, true)) { return 'NORMALIZATION_FAILED'; }
    if (!$candidates) { return 'NO_CANDIDATES'; }
    if (count($candidates) > 1) { return 'MULTIPLE_CANDIDATES'; }
    return $tokens ? 'NO_CANDIDATES' : 'NORMALIZATION_FAILED';
}

function ado_quote_match_item(array $item): array {
    $index = ado_quote_product_index();
    $raw_line = (string) ($item['raw'] ?? '');
    $raw_norm = ado_quote_normalize_text($raw_line);
    $raw_compact = ado_quote_compact_token($raw_line);
    $tokens = ado_quote_extract_model_tokens($item);
    $tokens = array_values(array_unique(array_merge($tokens, ado_quote_lcn_family_tokens($item, $tokens))));
    $trace = ['raw_norm=' . $raw_norm, 'tokens=' . implode(', ', $tokens)];
    $alias_candidate_ids = [];
    if ($raw_compact === '' && !$tokens) {
        $trace[] = 'normalization-empty';
        return ['product_id' => 0, 'match_method' => 'none', 'confidence' => 0, 'reason_code' => 'NORMALIZATION_FAILED', 'tokens' => $tokens, 'trace' => $trace, 'candidate_scores' => []];
    }
    foreach ($tokens as $token) {
        $compact = ado_quote_compact_token($token);
        if ($compact === '') { continue; }
        if (isset($index['by_alias'][$compact])) {
            $target = $index['by_alias'][$compact];
            if (is_int($target) && isset($index['products'][$target])) { return ['product_id' => $target, 'match_method' => 'alias', 'confidence' => 100, 'reason_code' => '', 'tokens' => $tokens, 'trace' => array_merge($trace, ['alias:' . $compact]), 'candidate_scores' => [['product_id' => $target, 'score' => 100, 'method' => 'alias']]]; }
            if (is_string($target) && isset($index['by_sku'][$target])) {
                $pid = (int) $index['by_sku'][$target];
                return ['product_id' => $pid, 'match_method' => 'alias', 'confidence' => 100, 'reason_code' => '', 'tokens' => $tokens, 'trace' => array_merge($trace, ['alias:' . $compact]), 'candidate_scores' => [['product_id' => $pid, 'score' => 100, 'method' => 'alias']]];
            }
            if (is_array($target)) {
                $trace[] = 'alias_pool:' . $compact . '=' . implode(',', $target);
                $alias_candidate_ids = array_values(array_unique(array_merge($alias_candidate_ids, array_map('intval', $target))));
            }
        }
        if (isset($index['by_sku'][$compact])) {
            $pid = (int) $index['by_sku'][$compact];
            return ['product_id' => $pid, 'match_method' => 'sku_exact', 'confidence' => 96, 'reason_code' => '', 'tokens' => $tokens, 'trace' => array_merge($trace, ['sku_exact:' . $compact]), 'candidate_scores' => [['product_id' => $pid, 'score' => 96, 'method' => 'sku_exact']]];
        }
    }
    $scores = [];
    $candidate_products = $index['products'];
    if ($alias_candidate_ids) {
        $candidate_products = array_intersect_key($index['products'], array_flip($alias_candidate_ids));
    }
    foreach ($candidate_products as $product_id => $product) {
        $score = 0;
        $method = '';
        if ($alias_candidate_ids && in_array((int) $product_id, $alias_candidate_ids, true)) {
            $score = max($score, 88);
            $method = 'alias_pool';
        }
        foreach ($tokens as $token) {
            $compact = ado_quote_compact_token($token);
            if ($compact === '') { continue; }
            if ($product['sku_compact'] !== '' && strpos($product['sku_compact'], $compact) !== false) { $score = max($score, 92); $method = $method ?: 'sku_contains'; }
            foreach ($product['meta'] as $meta_value) {
                $meta_compact = ado_quote_compact_token((string) $meta_value);
                if ($meta_compact === '') { continue; }
                if ($meta_compact === $compact) { $score = max($score, 91); $method = 'meta_exact'; }
                elseif (strpos($meta_compact, $compact) !== false || strpos($compact, $meta_compact) !== false) { $score = max($score, 84); $method = $method ?: 'meta_contains'; }
            }
            if ($product['title_norm'] !== '' && strpos($product['title_norm'], ado_quote_normalize_text($token)) !== false) { $score = max($score, 82); $method = $method ?: 'title_contains'; }
            if ($product['haystack_compact'] !== '' && strpos($product['haystack_compact'], $compact) !== false) { $score = max($score, 78); $method = $method ?: 'searchable_contains'; }
        }
        if ($raw_compact !== '' && $product['haystack_compact'] !== '') {
            similar_text($raw_compact, $product['haystack_compact'], $pct);
            if ($pct >= 62) {
                $fuzzy = (int) round(min(79, 45 + ($pct / 2)));
                if ($fuzzy > $score) { $score = $fuzzy; $method = 'raw_fuzzy'; }
            }
        }
        if ($score > 0) { $scores[] = ['product_id' => $product_id, 'score' => $score, 'method' => $method ?: 'candidate', 'sku' => $product['sku'], 'title' => $product['title']]; }
    }
    usort($scores, static function (array $a, array $b): int { return ((int) $b['score'] <=> (int) $a['score']) ?: ((int) $a['product_id'] <=> (int) $b['product_id']); });
    $top = array_slice($scores, 0, 5);
    $trace[] = 'top=' . wp_json_encode(array_map(static function (array $row): array { return ['product_id' => (int) $row['product_id'], 'score' => (int) $row['score'], 'method' => (string) $row['method'], 'sku' => (string) $row['sku']]; }, $top));
    if (!$top) { return ['product_id' => 0, 'match_method' => 'none', 'confidence' => 0, 'reason_code' => ado_quote_pick_reason($trace, $top, $tokens), 'tokens' => $tokens, 'trace' => $trace, 'candidate_scores' => $top]; }
    $best = $top[0];
    $second = $top[1] ?? null;
    $best_score = (int) $best['score'];
    $second_score = $second ? (int) ($second['score'] ?? 0) : 0;
    if ($best_score < 72) { return ['product_id' => 0, 'match_method' => 'none', 'confidence' => $best_score, 'reason_code' => 'NO_CANDIDATES', 'tokens' => $tokens, 'trace' => $trace, 'candidate_scores' => $top]; }
    if ($second && ($best_score - $second_score) < 4 && $best_score < 95) { return ['product_id' => 0, 'match_method' => 'none', 'confidence' => $best_score, 'reason_code' => 'MULTIPLE_CANDIDATES', 'tokens' => $tokens, 'trace' => $trace, 'candidate_scores' => $top]; }
    return ['product_id' => (int) $best['product_id'], 'match_method' => (string) $best['method'], 'confidence' => $best_score, 'reason_code' => '', 'tokens' => $tokens, 'trace' => $trace, 'candidate_scores' => $top];
}

function ado_quote_group_key(array $line): string {
    return implode('|', [(string) ($line['door_id'] ?? ''), (string) ((int) ($line['product_id'] ?? 0)), ado_quote_compact_token((string) ($line['raw_line'] ?? ''))]);
}

function ado_build_cart_lines_from_scope(array $scope_payload): array {
    $doors = (array) ($scope_payload['result']['doors'] ?? []);
    $lines = [];
    $unmatched = [];
    $debug_log = [];
    foreach ($doors as $door) {
        if (!is_array($door)) { continue; }
        $door_id = (string) ($door['door_id'] ?? '');
        $door_number = (string) ($door['door_id'] ?? '');
        foreach ((array) ($door['items'] ?? []) as $item) {
            if (!is_array($item)) { continue; }
            $qty = ado_quote_item_qty($item);
            $match = ado_quote_match_item($item);
            $pid = (int) ($match['product_id'] ?? 0);
            $debug_entry = [
                'door_id' => $door_id,
                'door_number' => $door_number,
                'raw_line' => (string) ($item['raw'] ?? ''),
                'model' => (string) ($item['catalog'] ?? ''),
                'description' => (string) ($item['desc'] ?? ''),
                'qty' => $qty,
                'tokens' => array_values((array) ($match['tokens'] ?? [])),
                'matched_product_id' => $pid,
                'matched_by' => (string) ($match['match_method'] ?? 'none'),
                'confidence' => (int) ($match['confidence'] ?? 0),
                'reason_code' => (string) ($match['reason_code'] ?? ''),
                'attempts' => array_values((array) ($match['trace'] ?? [])),
                'candidate_scores' => array_values((array) ($match['candidate_scores'] ?? [])),
            ];
            $debug_log[] = $debug_entry;
            if ($pid <= 0) {
                $unmatched[] = [
                    'door_id' => $door_id,
                    'door_number' => $door_number,
                    'model' => (string) ($item['catalog'] ?? ''),
                    'description' => (string) ($item['desc'] ?? ''),
                    'qty' => $qty,
                    'raw_line' => (string) ($item['raw'] ?? ''),
                    'tokens' => $debug_entry['tokens'],
                    'reason_code' => (string) ($match['reason_code'] ?? 'NO_CANDIDATES'),
                    'next_action' => 'Add alias, enrich product meta, or create a real Woo product.',
                ];
                continue;
            }
            $line = [
                'product_id' => $pid,
                'qty' => $qty,
                'door_id' => $door_id,
                'door_number' => $door_number,
                'raw_line' => (string) ($item['raw'] ?? ''),
                'source_model' => (string) ($item['catalog'] ?? ''),
                'source_desc' => (string) ($item['desc'] ?? ''),
                'match_method' => (string) ($match['match_method'] ?? ''),
                'match_confidence' => (int) ($match['confidence'] ?? 0),
            ];
            $key = ado_quote_group_key($line);
            if (!isset($lines[$key])) { $lines[$key] = $line; }
            else { $lines[$key]['qty'] += $qty; }
        }
    }
    return ['lines' => array_values($lines), 'unmatched' => array_values($unmatched), 'debug_log' => array_values($debug_log)];
}

function ado_render_unmatched_html(array $unmatched): string {
    if (!$unmatched) { return ''; }
    ob_start();
    echo '<div class="ado-card" style="border-color:#f59e0b;background:#fffaf0;"><h3 style="margin-top:0;">Unmatched Items</h3>';
    echo '<table class="ado-table"><thead><tr><th>Door</th><th>Model</th><th>Description</th><th>Qty</th><th>Reason</th><th>Raw Line</th></tr></thead><tbody>';
    foreach ($unmatched as $row) {
        if (!is_array($row)) { continue; }
        echo '<tr><td>' . esc_html((string) ($row['door_number'] ?? '')) . '</td><td>' . esc_html((string) ($row['model'] ?? '')) . '</td><td>' . esc_html((string) ($row['description'] ?? '')) . '</td><td>' . esc_html((string) ($row['qty'] ?? '')) . '</td><td>' . esc_html((string) ($row['reason_code'] ?? '')) . '</td><td>' . esc_html((string) ($row['raw_line'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    return (string) ob_get_clean();
}

function ado_render_debug_log_html(array $debug_log): string {
    ob_start();
    echo '<div class="ado-card"><h3 style="margin-top:0;">Output Window</h3>';
    if (!$debug_log) {
        echo '<p class="ado-muted">No match trace available yet.</p></div>';
        return (string) ob_get_clean();
    }
    foreach ($debug_log as $entry) {
        if (!is_array($entry)) { continue; }
        $product = !empty($entry['matched_product_id']) ? wc_get_product((int) $entry['matched_product_id']) : null;
        echo '<details style="margin-bottom:10px;"><summary><strong>' . esc_html((string) ($entry['door_number'] ?? '')) . '</strong> | ' . esc_html((string) ($entry['raw_line'] ?? '')) . '</summary><div style="padding-top:8px;">';
        echo '<p><strong>Result:</strong> ' . esc_html($product ? $product->get_name() : 'UNMATCHED') . '</p>';
        echo '<p><strong>Method:</strong> ' . esc_html((string) ($entry['matched_by'] ?? 'none')) . ' | <strong>Confidence:</strong> ' . esc_html((string) ($entry['confidence'] ?? 0)) . '</p>';
        if (!empty($entry['reason_code'])) { echo '<p><strong>Reason:</strong> ' . esc_html((string) $entry['reason_code']) . '</p>'; }
        echo '<pre style="max-height:220px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;">' . esc_html(wp_json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div></details>';
    }
    echo '</div>';
    return (string) ob_get_clean();
}

function ado_render_matched_lines_html(array $lines): string {
    if (!$lines) { return '<div class="ado-card"><p class="ado-muted">No matched quote lines yet.</p></div>'; }
    ob_start();
    echo '<div class="ado-card"><h3 style="margin-top:0;">Generated Quote Output</h3><table class="ado-table"><thead><tr><th>Door</th><th>Product</th><th>SKU</th><th>Qty</th><th>Match</th><th>Raw Line</th></tr></thead><tbody>';
    foreach ($lines as $line) {
        if (!is_array($line)) { continue; }
        $product = wc_get_product((int) ($line['product_id'] ?? 0));
        echo '<tr><td>' . esc_html((string) ($line['door_number'] ?? '')) . '</td><td>' . esc_html($product ? $product->get_name() : ('Product #' . (int) ($line['product_id'] ?? 0))) . '</td><td>' . esc_html($product ? $product->get_sku() : '') . '</td><td>' . esc_html((string) ($line['qty'] ?? 0)) . '</td><td>' . esc_html((string) ($line['match_method'] ?? '')) . ' (' . esc_html((string) ($line['match_confidence'] ?? 0)) . ')</td><td>' . esc_html((string) ($line['raw_line'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    return (string) ob_get_clean();
}

function ado_render_quote_result_html(array $draft): string {
    $items = is_array($draft['items'] ?? null) ? array_values((array) $draft['items']) : [];
    $unmatched = is_array($draft['unmatched'] ?? null) ? array_values((array) $draft['unmatched']) : [];
    $debug_log = is_array($draft['debug_log'] ?? null) ? array_values((array) $draft['debug_log']) : [];
    return ado_render_unmatched_html($unmatched) . ado_render_matched_lines_html($items) . ado_render_debug_log_html($debug_log);
}

function ado_render_quote_drafts_html(int $user_id): string {
    $drafts = ado_get_quote_drafts($user_id);
    if (!$drafts) { return '<p class="ado-muted">No saved quote carts yet.</p>'; }
    ob_start();
    foreach ($drafts as $d) {
        $id = esc_attr((string) ($d['id'] ?? ''));
        echo '<div class="ado-draft"><div class="ado-row"><strong>' . esc_html((string) ($d['name'] ?? 'Quote Draft')) . '</strong><span class="ado-chip">' . esc_html((string) ($d['total_items'] ?? 0)) . ' items</span></div><div class="ado-row"><small>' . esc_html((string) ($d['created_at'] ?? '')) . '</small>';
        if (!empty($d['unmatched_count'])) { echo '<small class="ado-warning">Unmatched: ' . esc_html((string) $d['unmatched_count']) . '</small>'; }
        echo '</div><div class="ado-row"><button class="button ado-load-draft" data-id="' . $id . '">Load</button><button class="button ado-show-draft-output" data-id="' . $id . '">View Output</button><button class="button ado-rename-draft" data-id="' . $id . '">Rename</button><button class="button ado-delete-draft" data-id="' . $id . '">Delete</button></div></div>';
    }
    return (string) ob_get_clean();
}

function ado_find_draft_by_id(int $user_id, string $draft_id): ?array {
    foreach (ado_get_quote_drafts($user_id) as $draft) {
        if ((string) ($draft['id'] ?? '') === $draft_id) { return is_array($draft) ? $draft : null; }
    }
    return null;
}

function ado_assert_client_ajax(): int {
    if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Please sign in.'], 401); }
    if (!ado_is_client()) { wp_send_json_error(['message' => 'Client access only.'], 403); }
    check_ajax_referer('ado_quote_nonce', 'nonce');
    return (int) get_current_user_id();
}

add_action('save_post_product', static function (int $post_id, WP_Post $post, bool $update): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (wp_is_post_revision($post_id)) { return; }
    ado_quote_rebuild_generated_aliases();
}, 20, 3);

add_action('trashed_post', static function (int $post_id): void {
    if (get_post_type($post_id) === 'product') { ado_quote_rebuild_generated_aliases(); }
});

add_action('untrashed_post', static function (int $post_id): void {
    if (get_post_type($post_id) === 'product') { ado_quote_rebuild_generated_aliases(); }
});

add_action('deleted_post', static function (int $post_id): void {
    if (get_post_type($post_id) === 'product') { ado_quote_rebuild_generated_aliases(); }
});

add_filter('woocommerce_get_item_data', static function (array $item_data, array $cart_item): array {
    foreach (['adq_door_number' => 'Door', 'adq_source_model' => 'Model', 'adq_match_method' => 'Match', 'adq_source_raw' => 'Raw Line'] as $key => $label) {
        if (!empty($cart_item[$key])) { $item_data[] = ['key' => $label, 'value' => wp_kses_post((string) $cart_item[$key])]; }
    }
    return $item_data;
}, 10, 2);

add_action('woocommerce_checkout_create_order_line_item', static function ($item, string $cart_item_key, array $values): void {
    foreach (['_adq_door_id' => 'adq_door_id', '_adq_door_number' => 'adq_door_number', '_adq_source_model' => 'adq_source_model', '_adq_source_desc' => 'adq_source_desc', '_adq_source_raw' => 'adq_source_raw', '_adq_match_method' => 'adq_match_method', '_adq_match_confidence' => 'adq_match_confidence'] as $meta_key => $cart_key) {
        if (isset($values[$cart_key]) && $values[$cart_key] !== '') { $item->add_meta_data($meta_key, $values[$cart_key], true); }
    }
}, 10, 3);

add_action('wp_ajax_ado_scope_to_quote_cart', static function (): void {
    $uid = ado_assert_client_ajax();
    if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'WooCommerce cart unavailable.'], 500); }
    $scope_url = esc_url_raw((string) ($_POST['scope_url'] ?? ''));
    $quote_name = sanitize_text_field((string) ($_POST['quote_name'] ?? ''));
    if ($scope_url === '') { wp_send_json_error(['message' => 'Missing scoped JSON URL.'], 400); }
    if ($quote_name === '') { $quote_name = 'Quote ' . wp_date('Y-m-d H:i'); }
    $scope_path = ado_scope_url_to_path($scope_url);
    if ($scope_path === '' || !file_exists($scope_path)) { wp_send_json_error(['message' => 'Scoped JSON file not found.'], 404); }
    $payload = json_decode((string) file_get_contents($scope_path), true);
    if (!is_array($payload) || empty($payload['result']['doors'])) { wp_send_json_error(['message' => 'Invalid scoped JSON payload.'], 400); }

    $mapped = ado_build_cart_lines_from_scope($payload);
    WC()->cart->empty_cart();
    $snapshot = [];
    $total_items = 0;
    foreach ((array) $mapped['lines'] as $line) {
        if (!is_array($line)) { continue; }
        $pid = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) { continue; }
        $cart_item_data = [
            'adq_door_id' => (string) ($line['door_id'] ?? ''),
            'adq_door_number' => (string) ($line['door_number'] ?? ''),
            'adq_source_model' => (string) ($line['source_model'] ?? ''),
            'adq_source_desc' => (string) ($line['source_desc'] ?? ''),
            'adq_source_raw' => (string) ($line['raw_line'] ?? ''),
            'adq_match_method' => (string) ($line['match_method'] ?? ''),
            'adq_match_confidence' => (int) ($line['match_confidence'] ?? 0),
        ];
        $cart_key = WC()->cart->add_to_cart($pid, $qty, 0, [], $cart_item_data);
        if (!$cart_key) { continue; }
        $snapshot[] = array_merge($line, ['cart_item_key' => $cart_key]);
        $total_items += $qty;
    }
    if (!$snapshot) {
        wp_send_json_error([
            'message' => 'No existing WooCommerce products could be matched from this scope.',
            'unmatched_count' => count((array) $mapped['unmatched']),
            'result_html' => ado_render_unmatched_html(array_values((array) $mapped['unmatched'])) . ado_render_debug_log_html(array_values((array) $mapped['debug_log'])),
        ], 400);
    }

    $drafts = ado_get_quote_drafts($uid);
    $draft_id = wp_generate_uuid4();
    $draft = ['id' => $draft_id, 'name' => $quote_name, 'created_at' => wp_date('Y-m-d H:i'), 'items' => $snapshot, 'total_items' => $total_items, 'scope_url' => $scope_url, 'scope_path' => $scope_path, 'unmatched' => array_values((array) $mapped['unmatched']), 'unmatched_count' => count((array) $mapped['unmatched']), 'debug_log' => array_values((array) $mapped['debug_log'])];
    $drafts[] = $draft;
    ado_save_quote_drafts($uid, $drafts);
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('ado_last_scope_url', $scope_url);
        WC()->session->set('ado_last_scope_path', $scope_path);
        WC()->session->set('ado_last_quote_draft_id', $draft_id);
    }
    wp_send_json_success(['message' => 'Quote cart created.', 'cart_url' => wc_get_cart_url(), 'drafts_html' => ado_render_quote_drafts_html($uid), 'matched_line_count' => count((array) $snapshot), 'unmatched_count' => count((array) $mapped['unmatched']), 'result_html' => ado_render_quote_result_html($draft)]);
});

add_action('wp_ajax_ado_show_quote_draft_output', static function (): void {
    $uid = ado_assert_client_ajax();
    $draft_id = sanitize_text_field((string) ($_POST['draft_id'] ?? ''));
    $draft = ado_find_draft_by_id($uid, $draft_id);
    if (!$draft) { wp_send_json_error(['message' => 'Quote draft not found.'], 404); }
    wp_send_json_success(['message' => 'Quote output loaded.', 'result_html' => ado_render_quote_result_html($draft)]);
});

add_action('wp_ajax_ado_save_current_cart_quote', static function (): void {
    $uid = ado_assert_client_ajax();
    if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'WooCommerce cart unavailable.'], 500); }
    $name = sanitize_text_field((string) ($_POST['quote_name'] ?? ''));
    if ($name === '') { $name = 'Manual Quote ' . wp_date('Y-m-d H:i'); }
    $items = [];
    foreach (WC()->cart->get_cart() as $row) {
        $items[] = ['product_id' => (int) ($row['product_id'] ?? 0), 'qty' => (int) ($row['quantity'] ?? 1), 'door_id' => (string) ($row['adq_door_id'] ?? ''), 'door_number' => (string) ($row['adq_door_number'] ?? ''), 'source_model' => (string) ($row['adq_source_model'] ?? ''), 'source_desc' => (string) ($row['adq_source_desc'] ?? ''), 'raw_line' => (string) ($row['adq_source_raw'] ?? ''), 'match_method' => (string) ($row['adq_match_method'] ?? ''), 'match_confidence' => (int) ($row['adq_match_confidence'] ?? 0)];
    }
    if (!$items) { wp_send_json_error(['message' => 'Cart is empty.'], 400); }
    $drafts = ado_get_quote_drafts($uid);
    $drafts[] = ['id' => wp_generate_uuid4(), 'name' => $name, 'created_at' => wp_date('Y-m-d H:i'), 'items' => $items, 'total_items' => array_sum(array_map(static function (array $row): int { return (int) ($row['qty'] ?? 0); }, $items)), 'unmatched' => [], 'unmatched_count' => 0, 'debug_log' => []];
    ado_save_quote_drafts($uid, $drafts);
    wp_send_json_success(['message' => 'Quote draft saved.', 'drafts_html' => ado_render_quote_drafts_html($uid)]);
});

add_action('wp_ajax_ado_load_quote_draft', static function (): void {
    $uid = ado_assert_client_ajax();
    if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'WooCommerce cart unavailable.'], 500); }
    $draft_id = sanitize_text_field((string) ($_POST['draft_id'] ?? ''));
    $target = ado_find_draft_by_id($uid, $draft_id);
    if (!$target) { wp_send_json_error(['message' => 'Quote draft not found.'], 404); }
    WC()->cart->empty_cart();
    foreach ((array) ($target['items'] ?? []) as $item) {
        if (!is_array($item)) { continue; }
        $pid = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 1);
        if ($pid <= 0 || $qty <= 0) { continue; }
        WC()->cart->add_to_cart($pid, $qty, 0, [], ['adq_door_id' => (string) ($item['door_id'] ?? ''), 'adq_door_number' => (string) ($item['door_number'] ?? ''), 'adq_source_model' => (string) ($item['source_model'] ?? ''), 'adq_source_desc' => (string) ($item['source_desc'] ?? ''), 'adq_source_raw' => (string) ($item['raw_line'] ?? ''), 'adq_match_method' => (string) ($item['match_method'] ?? ''), 'adq_match_confidence' => (int) ($item['match_confidence'] ?? 0)]);
    }
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('ado_last_scope_url', (string) ($target['scope_url'] ?? ''));
        WC()->session->set('ado_last_scope_path', (string) ($target['scope_path'] ?? ''));
        WC()->session->set('ado_last_quote_draft_id', (string) ($target['id'] ?? ''));
    }
    wp_send_json_success(['message' => 'Quote cart loaded.', 'cart_url' => wc_get_cart_url(), 'checkout_url' => wc_get_checkout_url(), 'result_html' => ado_render_quote_result_html($target)]);
});

add_action('wp_ajax_ado_delete_quote_draft', static function (): void {
    $uid = ado_assert_client_ajax();
    $draft_id = sanitize_text_field((string) ($_POST['draft_id'] ?? ''));
    $drafts = array_values(array_filter(ado_get_quote_drafts($uid), static fn($d) => (string) ($d['id'] ?? '') !== $draft_id));
    ado_save_quote_drafts($uid, $drafts);
    wp_send_json_success(['message' => 'Quote draft deleted.', 'drafts_html' => ado_render_quote_drafts_html($uid)]);
});

add_action('wp_ajax_ado_rename_quote_draft', static function (): void {
    $uid = ado_assert_client_ajax();
    $draft_id = sanitize_text_field((string) ($_POST['draft_id'] ?? ''));
    $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
    if ($name === '') { wp_send_json_error(['message' => 'Name is required.'], 400); }
    $drafts = ado_get_quote_drafts($uid);
    foreach ($drafts as &$draft) {
        if ((string) ($draft['id'] ?? '') === $draft_id) { $draft['name'] = $name; }
    }
    unset($draft);
    ado_save_quote_drafts($uid, $drafts);
    wp_send_json_success(['message' => 'Quote draft renamed.', 'drafts_html' => ado_render_quote_drafts_html($uid)]);
});

add_shortcode('ado_quote_workspace', static function (): string {
    if (!is_user_logged_in()) { return '<p>Please sign in to create quotes.</p>'; }
    if (!ado_is_client()) { return '<p>This area is for client accounts only.</p>'; }
    $uid = (int) get_current_user_id();
    $nonce = wp_create_nonce('ado_quote_nonce');
    ob_start(); ?>
    <div class="ado-card">
      <h3>Quote Generator</h3>
      <p class="ado-muted">Upload the hardware schedule PDF. When scoped JSON is ready, the page builds the quote from existing WooCommerce products only.</p>
      <label>Quote Name <input id="ado-quote-name" type="text" placeholder="Project Name - Quote 1"></label>
      <button id="ado-create-from-parse" class="button button-primary" type="button" disabled>Retry Quote Build From Last Parse</button>
      <a id="ado-go-cart" class="button" style="display:none;" href="<?php echo esc_url(wc_get_cart_url()); ?>">Open Quote Cart</a>
      <p id="ado-quote-status" class="ado-muted"></p>
      <div id="ado-parser-output" style="display:none;margin-top:12px;">
        <h4 style="margin:0 0 8px;">Parser Output</h4>
        <pre id="ado-parser-output-json" style="max-height:260px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;"></pre>
      </div>
      <?php echo do_shortcode('[contact-form]'); ?>
    </div>
    <div class="ado-card">
      <h3>Saved Quote Carts</h3>
      <div class="ado-row"><input id="ado-manual-quote-name" type="text" placeholder="Save current cart as quote..."><button id="ado-save-current-cart" class="button" type="button">Save Current Cart as Quote</button></div>
      <div id="ado-drafts-wrap"><?php echo ado_render_quote_drafts_html($uid); ?></div>
    </div>
    <div id="ado-generated-output"></div>
    <script>
    (function($){
      var latestScope = '';
      var isCreating = false;
      function status(msg, err){ $('#ado-quote-status').text(msg || '').css('color', err ? '#b42318' : '#344054'); }
      function post(action, data, cb){
        $.post('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', Object.assign({action: action, nonce: '<?php echo esc_js($nonce); ?>'}, data || {}))
          .done(function(r){ cb(r || {success:false,data:{message:'Request failed'}}); })
          .fail(function(){ cb({success:false,data:{message:'Request failed'}}); });
      }
      function showParserOutput(payload){
        $('#ado-parser-output-json').text(JSON.stringify(payload || {}, null, 2));
        $('#ado-parser-output').show();
      }
      function showGeneratedOutput(html){ $('#ado-generated-output').html(html || ''); }
      function bindDrafts(){
        $('#ado-drafts-wrap .ado-load-draft').off('click').on('click', function(){
          post('ado_load_quote_draft', {draft_id: $(this).data('id')}, function(r){
            if(!r.success){ status(r.data && r.data.message ? r.data.message : 'Failed', true); return; }
            showGeneratedOutput(r.data && r.data.result_html ? r.data.result_html : '');
            $('#ado-go-cart').show();
            status(r.data.message || 'Quote cart loaded.', false);
          });
        });
        $('#ado-drafts-wrap .ado-show-draft-output').off('click').on('click', function(){
          post('ado_show_quote_draft_output', {draft_id: $(this).data('id')}, function(r){
            if(!r.success){ status(r.data && r.data.message ? r.data.message : 'Failed', true); return; }
            showGeneratedOutput(r.data && r.data.result_html ? r.data.result_html : '');
            status(r.data.message || 'Quote output loaded.', false);
          });
        });
        $('#ado-drafts-wrap .ado-delete-draft').off('click').on('click', function(){
          post('ado_delete_quote_draft', {draft_id: $(this).data('id')}, function(r){
            if(!r.success){ status(r.data && r.data.message ? r.data.message : 'Failed', true); return; }
            $('#ado-drafts-wrap').html(r.data.drafts_html || '');
            bindDrafts();
            status(r.data.message || 'Deleted.', false);
          });
        });
        $('#ado-drafts-wrap .ado-rename-draft').off('click').on('click', function(){
          var n = window.prompt('Rename quote');
          if(!n){ return; }
          post('ado_rename_quote_draft', {draft_id: $(this).data('id'), name: n}, function(r){
            if(!r.success){ status(r.data && r.data.message ? r.data.message : 'Failed', true); return; }
            $('#ado-drafts-wrap').html(r.data.drafts_html || '');
            bindDrafts();
            status(r.data.message || 'Renamed.', false);
          });
        });
      }
      function createQuoteFromLatestScope(){
        if (!latestScope || isCreating) { return; }
        isCreating = true;
        status('Scoped JSON ready. Building quote...', false);
        post('ado_scope_to_quote_cart', {scope_url: latestScope, quote_name: $('#ado-quote-name').val() || ''}, function(r){
          isCreating = false;
          if(!r.success){ showGeneratedOutput(r.data && r.data.result_html ? r.data.result_html : ''); status(r.data && r.data.message ? r.data.message : 'Failed', true); return; }
          $('#ado-drafts-wrap').html(r.data.drafts_html || '');
          bindDrafts();
          showGeneratedOutput(r.data && r.data.result_html ? r.data.result_html : '');
          $('#ado-go-cart').show();
          status((r.data.message || 'Quote cart created.') + ' Matched: ' + (r.data.matched_line_count || 0) + ', Unmatched: ' + (r.data.unmatched_count || 0), false);
        });
      }
      bindDrafts();
      $(document).ajaxSuccess(function(_e, _x, _s, r){
        if (r && r.success && r.data && r.data.download_url_scope) {
          latestScope = r.data.download_url_scope;
          $('#ado-create-from-parse').prop('disabled', false);
          showParserOutput(r.data);
          createQuoteFromLatestScope();
        }
      });
      $('#ado-create-from-parse').on('click', function(){
        if(!latestScope){ status('No parsed scope detected yet.', true); return; }
        createQuoteFromLatestScope();
      });
      $('#ado-save-current-cart').on('click', function(){
        post('ado_save_current_cart_quote', {quote_name: $('#ado-manual-quote-name').val() || ''}, function(r){
          if(!r.success){ status(r.data && r.data.message ? r.data.message : 'Failed', true); return; }
          $('#ado-drafts-wrap').html(r.data.drafts_html || '');
          bindDrafts();
          status(r.data.message || 'Quote draft saved.', false);
        });
      });
    })(jQuery);
    </script>
    <?php return (string) ob_get_clean();
});

add_action('woocommerce_cart_calculate_fees', static function ($cart): void {
    if (!is_a($cart, 'WC_Cart')) { return; }
    if (is_admin() && !defined('DOING_AJAX')) { return; }
    if ($cart->is_empty()) { return; }
    $qty_total = 0;
    foreach ($cart->get_cart() as $row) { $qty_total += (int) ($row['quantity'] ?? 0); }
    if ($qty_total <= 0) { return; }
    $tiers = [100 => 0.15, 50 => 0.10, 20 => 0.05];
    $discount_pct = 0.0;
    foreach ($tiers as $min_qty => $pct) {
        if ($qty_total >= $min_qty) { $discount_pct = $pct; break; }
    }
    if ($discount_pct <= 0) { return; }
    $subtotal = (float) $cart->get_subtotal();
    if ($subtotal <= 0) { return; }
    $discount = round($subtotal * $discount_pct, 2);
    if ($discount <= 0) { return; }
    $label = sprintf('Quantity Discount (%d%% for %d+ items)', (int) round($discount_pct * 100), array_key_first(array_filter($tiers, static fn($p) => $p === $discount_pct)));
    $cart->add_fee($label, -$discount, false);
}, 20, 1);
