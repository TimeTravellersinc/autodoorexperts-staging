<?php
if (defined('ADO_QUOTE_CARTS_LOADED')) {
    return;
}
define('ADO_QUOTE_CARTS_LOADED', true);

function ado_quote_integration(): ADO_Quote_Integration
{
    return ADO_Quote_Integration::instance();
}

function ado_quote_url(int $quote_id): string
{
    return esc_url(home_url('/portal/quotes/' . $quote_id . '/'));
}

function ado_quote_ordered_url(int $quote_id): string
{
    return esc_url(add_query_arg(['view' => 'projects', 'quote_id' => $quote_id], home_url('/client-dashboard/')));
}

function ado_quote_checkout_url(int $quote_id): string
{
    $base = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
    return esc_url(add_query_arg(['ado_quote_id' => max(0, $quote_id)], $base));
}

function ado_quote_totals_html(array $totals): string
{
    $subtotal = isset($totals['subtotal']) ? (float) $totals['subtotal'] : 0.0;
    return function_exists('wc_price') ? (string) wc_price($subtotal) : ('$' . number_format($subtotal, 2));
}

function ado_quote_post_row(WP_Post $post): array
{
    $id = (int) $post->ID;
    $status = (string) get_post_meta($id, '_adq_status', true);
    $status = $status !== '' ? $status : 'draft';
    $totals = get_post_meta($id, '_adq_totals', true);
    $totals = is_array($totals) ? $totals : [];
    $snapshot = get_post_meta($id, '_adq_cart_snapshot', true);
    $snapshot = is_array($snapshot) ? $snapshot : [];
    $unmatched = get_post_meta($id, '_adq_unmatched_items', true);
    $unmatched = is_array($unmatched) ? $unmatched : [];
    $excluded = get_post_meta($id, '_adq_excluded_items', true);
    $excluded = is_array($excluded) ? $excluded : [];
    $created = (string) get_post_meta($id, '_adq_created_at', true);
    if ($created === '') {
        $created = (string) $post->post_date;
    }

    $items_total = 0;
    foreach ($snapshot as $line) {
        $items_total += max(0, (int) ($line['qty'] ?? 0));
    }

    return [
        'id' => $id,
        'name' => (string) $post->post_title,
        'status' => $status,
        'created_at' => wp_date('Y-m-d H:i', strtotime($created) ?: time()),
        'subtotal' => (float) ($totals['subtotal'] ?? 0),
        'subtotal_html' => ado_quote_totals_html($totals),
        'total_items' => $items_total,
        'unmatched_count' => count($unmatched) + count($excluded),
        'door_count' => count((array) get_post_meta($id, '_adq_doors', true)),
        'scope_url' => (string) get_post_meta($id, '_adq_scope_url', true),
        'order_id' => (int) get_post_meta($id, '_adq_order_id', true),
    ];
}

function ado_get_quote_drafts(int $user_id): array
{
    $rows = [];
    foreach (ado_quote_integration()->get_user_quotes($user_id) as $quote) {
        if (!($quote instanceof WP_Post)) {
            continue;
        }
        $rows[] = ado_quote_post_row($quote);
    }
    return $rows;
}

function ado_quote_grouped_lines(int $quote_id): array
{
    $doors = get_post_meta($quote_id, '_adq_doors', true);
    $doors = is_array($doors) ? $doors : [];
    $snapshot = get_post_meta($quote_id, '_adq_cart_snapshot', true);
    $snapshot = is_array($snapshot) ? $snapshot : [];

    $door_map = [];
    foreach ($doors as $door) {
        if (!is_array($door)) {
            continue;
        }
        $door_id = (string) ($door['door_id'] ?? '');
        if ($door_id === '') {
            continue;
        }
        $door_map[$door_id] = [
            'door' => $door,
            'lines' => [],
        ];
    }

    foreach ($snapshot as $line) {
        if (!is_array($line)) {
            continue;
        }
        $door_id = (string) ($line['door_id'] ?? '');
        if ($door_id === '') {
            continue;
        }
        if (!isset($door_map[$door_id])) {
            $door_map[$door_id] = [
                'door' => [
                    'door_id' => $door_id,
                    'door_number' => (string) ($line['door_number'] ?? ''),
                    'door_label' => (string) ($line['door_label'] ?? ('Door ' . (string) ($line['door_number'] ?? 'Unknown'))),
                    'desc' => '',
                    'location' => '',
                    'is_scoped' => true,
                    'has_operator' => false,
                ],
                'lines' => [],
            ];
        }
        $product_id = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        $line_type = (string) ($line['line_type'] ?? 'catalog');
        $product = $product_id > 0 ? wc_get_product($product_id) : null;
        if ($line_type === 'manual') {
            $unit = max(0.0, (float) ($line['manual_unit_price'] ?? 0.0));
            $line['product_name'] = (string) ($line['manual_description'] ?? 'Manual line item');
            $line['sku'] = (string) ($line['manual_sku'] ?? ($line['source_model'] ?? ''));
            $line['display_model'] = (string) ($line['manual_sku'] ?? ($line['source_model'] ?? $line['model'] ?? ''));
            $line['display_description'] = (string) ($line['manual_description'] ?? $line['description'] ?? 'Manual line item');
            $line['line_total'] = $unit * max(1, $qty);
            $line['unit_price'] = $unit;
        } else {
            $line['product_name'] = $product ? (string) $product->get_name() : ('Product #' . $product_id);
            $line['sku'] = $product ? (string) $product->get_sku() : '';
            $line['display_model'] = $product ? ado_quote_product_display_model($product) : (string) ($line['source_model'] ?? $line['model'] ?? '');
            $line['display_description'] = $product ? ado_quote_product_display_description($product) : (string) ($line['description'] ?? $line['source_desc'] ?? '');
            $line['line_total'] = $product ? ((float) $product->get_price('edit') * max(1, $qty)) : 0;
            $line['unit_price'] = $product ? (float) $product->get_price('edit') : 0.0;
        }
        $door_map[$door_id]['lines'][] = $line;
    }

    return array_values($door_map);
}

function ado_quote_product_display_model(WC_Product $product): string
{
    foreach (['_manufacturer_part_number', 'manufacturer_part_number', '_ado_model', '_ado_catalog', 'manufacturer_sku', 'alternate_sku', 'mpn'] as $meta_key) {
        $value = trim((string) $product->get_meta($meta_key, true));
        if ($value !== '') {
            return $value;
        }
    }
    $sku = trim((string) $product->get_sku());
    if ($sku !== '') {
        return $sku;
    }
    return trim((string) $product->get_name());
}

function ado_quote_product_display_description(WC_Product $product): string
{
    $name = trim((string) $product->get_name());
    if ($name !== '') {
        return $name;
    }
    return trim((string) $product->get_short_description());
}

function ado_quote_door_notes(int $quote_id): array
{
    return ado_quote_integration()->get_quote_door_notes($quote_id);
}

function ado_quote_line_adjustments(int $quote_id): array
{
    return ado_quote_integration()->get_quote_line_adjustments($quote_id);
}

function ado_quote_line_action_meta(array $row): array
{
    $line_key = trim((string) ($row['line_key'] ?? ''));
    $normalized_model = ado_qm_compact((string) ($row['normalized_model'] ?? ''));
    if ($normalized_model === '') {
        $normalized_model = ado_qm_compact((string) ($row['source_model'] ?? $row['model'] ?? $row['display_model'] ?? $row['sku'] ?? ''));
    }
    $decision_key = trim((string) ($row['decision_key'] ?? ''));
    if ($decision_key === '' && $normalized_model !== '') {
        $decision_key = '*|' . $normalized_model;
    }
    $query = trim((string) ($row['model'] ?? $row['source_model'] ?? $row['display_model'] ?? $row['sku'] ?? ''));
    if ($query === '') {
        $query = trim((string) ($row['description'] ?? $row['raw_line'] ?? ''));
    }
    return [
        'line_key' => $line_key,
        'decision_key' => $decision_key,
        'normalized_model' => $normalized_model,
        'query' => $query,
    ];
}

function ado_quote_render_line_actions(int $quote_id, array $meta): string
{
    $line_key = trim((string) ($meta['line_key'] ?? ''));
    if ($quote_id <= 0 || $line_key === '') {
        return '';
    }
    $decision_key = trim((string) ($meta['decision_key'] ?? ''));
    $normalized_model = trim((string) ($meta['normalized_model'] ?? ''));
    $query = trim((string) ($meta['query'] ?? ''));
    ob_start();
    ?>
    <div class="qr-line-actions">
      <button
        type="button"
        class="qr-mini-btn primary ado-line-match"
        data-quote-id="<?php echo esc_attr((string) $quote_id); ?>"
        data-line-key="<?php echo esc_attr($line_key); ?>"
        data-decision-key="<?php echo esc_attr($decision_key); ?>"
        data-normalized-model="<?php echo esc_attr($normalized_model); ?>"
        data-search-query="<?php echo esc_attr($query); ?>"
      >Match</button>
      <button
        type="button"
        class="qr-mini-btn danger ado-line-delete"
        data-quote-id="<?php echo esc_attr((string) $quote_id); ?>"
        data-line-key="<?php echo esc_attr($line_key); ?>"
        data-decision-key="<?php echo esc_attr($decision_key); ?>"
        data-normalized-model="<?php echo esc_attr($normalized_model); ?>"
      >Delete</button>
    </div>
    <?php
    return (string) ob_get_clean();
}

function ado_quote_find_line_context(int $quote_id, string $line_key): array
{
    if ($quote_id <= 0 || $line_key === '') {
        return [];
    }
    $line_key = trim($line_key);
    foreach ([
        '_adq_unmatched_items' => 'unmatched',
        '_adq_excluded_items' => 'dropped',
        '_adq_cart_snapshot' => 'matched',
    ] as $meta_key => $source) {
        $rows = get_post_meta($quote_id, $meta_key, true);
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['line_key'] ?? '') !== $line_key) {
                continue;
            }
            return [
                'source' => $source,
                'row' => $row,
            ];
        }
    }
    return [];
}

function ado_quote_search_match_products(string $query, int $limit = 18): array
{
    $query = trim($query);
    if ($query === '' || $limit <= 0) {
        return [];
    }
    $index = function_exists('ado_qm_get_index') ? ado_qm_get_index() : [];
    $products = is_array($index['products'] ?? null) ? (array) $index['products'] : [];
    if (!$products) {
        return [];
    }

    $needle_norm = ado_qm_normalize_text($query);
    $needle_compact = ado_qm_compact($query);
    $needle_words = preg_split('/[^A-Z0-9]+/', $needle_norm) ?: [];
    $needle_words = array_values(array_filter($needle_words, static fn(string $word): bool => strlen($word) >= 2));

    $rows = [];
    foreach ($products as $product_id => $product) {
        if (!is_array($product)) {
            continue;
        }
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            continue;
        }
        if (!wc_get_product($product_id)) {
            continue;
        }
        $score = 0;
        $sku_compact = ado_qm_compact((string) ($product['sku'] ?? ''));
        $title_norm = ado_qm_normalize_text((string) ($product['title'] ?? ''));

        if ($needle_compact !== '') {
            if ($sku_compact === $needle_compact) {
                $score = max($score, 220);
            } elseif ($sku_compact !== '' && str_starts_with($sku_compact, $needle_compact)) {
                $score = max($score, 180);
            } elseif ($sku_compact !== '' && strpos($sku_compact, $needle_compact) !== false) {
                $score = max($score, 135);
            }
            foreach ((array) ($product['model_map'] ?? []) as $model_compact => $model_meta) {
                $model_compact = ado_qm_compact((string) $model_compact);
                if ($model_compact === '') {
                    continue;
                }
                if ($model_compact === $needle_compact) {
                    $score = max($score, 210);
                    break;
                }
                if (str_starts_with($model_compact, $needle_compact)) {
                    $score = max($score, 170);
                } elseif (strpos($model_compact, $needle_compact) !== false) {
                    $score = max($score, 125);
                }
                $display = ado_qm_normalize_text((string) ($model_meta['display'] ?? ''));
                if ($display !== '' && $needle_norm !== '' && strpos($display, $needle_norm) !== false) {
                    $score = max($score, 150);
                }
            }
        }

        if ($needle_norm !== '') {
            if ($title_norm === $needle_norm) {
                $score = max($score, 160);
            } elseif ($title_norm !== '' && strpos($title_norm, $needle_norm) !== false) {
                $score = max($score, 120);
            }
        }
        if ($needle_words) {
            $overlap = 0;
            foreach ($needle_words as $word) {
                if ($title_norm !== '' && strpos($title_norm, $word) !== false) {
                    $overlap++;
                }
            }
            if ($overlap > 0) {
                $score += $overlap * 9;
            }
        }

        if ($score <= 0) {
            continue;
        }

        $rows[] = [
            'product_id' => $product_id,
            'sku' => (string) ($product['sku'] ?? ''),
            'title' => (string) ($product['title'] ?? ('Product #' . $product_id)),
            'brand' => (string) ($product['brand'] ?? ''),
            'score' => $score,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $score_cmp = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
        if ($score_cmp !== 0) {
            return $score_cmp;
        }
        return ((int) ($a['product_id'] ?? 0)) <=> ((int) ($b['product_id'] ?? 0));
    });

    return array_slice($rows, 0, max(1, $limit));
}

function ado_quote_unmatched_flash_key(): string
{
    return '_adq_quote_unmatched_flash';
}

function ado_set_quote_unmatched_flash(int $user_id, int $quote_id, array $unmatched): void
{
    if ($user_id <= 0) {
        return;
    }

    $rows = [];
    foreach ($unmatched as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    if (!$rows || $quote_id <= 0) {
        delete_user_meta($user_id, ado_quote_unmatched_flash_key());
        return;
    }

    update_user_meta($user_id, ado_quote_unmatched_flash_key(), [
        'quote_id' => $quote_id,
        'rows' => array_values($rows),
        'created_at' => time(),
    ]);
}

function ado_consume_quote_unmatched_flash(int $user_id, int $quote_id): array
{
    if ($user_id <= 0 || $quote_id <= 0) {
        return [];
    }

    $flash = get_user_meta($user_id, ado_quote_unmatched_flash_key(), true);
    if (!is_array($flash)) {
        return [];
    }

    if ((int) ($flash['quote_id'] ?? 0) !== $quote_id) {
        return [];
    }

    delete_user_meta($user_id, ado_quote_unmatched_flash_key());

    $rows = $flash['rows'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter($rows, static function ($row): bool {
        return is_array($row);
    }));
}

function ado_render_quote_drafts_html(int $user_id): string
{
    $quotes = ado_get_quote_drafts($user_id);
    if (!$quotes) {
        return '<p class="ado-muted">No quotes yet.</p>';
    }
    ob_start();
    foreach ($quotes as $quote) {
        $status = strtolower((string) ($quote['status'] ?? 'draft'));
        $status_label = ucfirst($status);
        echo '<div class="ado-draft">';
        echo '<div class="ado-row"><strong>' . esc_html((string) $quote['name']) . '</strong><span class="ado-chip">' . esc_html($status_label) . '</span><span class="ado-chip">' . esc_html((string) ($quote['total_items'] ?? 0)) . ' items</span></div>';
        echo '<div class="ado-row"><small>' . esc_html((string) ($quote['created_at'] ?? '')) . '</small><small>Subtotal: ' . wp_kses_post((string) ($quote['subtotal_html'] ?? '')) . '</small></div>';
        if (!empty($quote['unmatched_count'])) {
            echo '<div class="ado-row"><small class="ado-warning">Unmatched items: ' . esc_html((string) $quote['unmatched_count']) . '</small></div>';
        }
        echo '<div class="ado-row">';
        echo '<a class="button" href="' . ado_quote_url((int) $quote['id']) . '">Open</a>';
        if ($status !== 'ordered') {
            echo '<a class="button button-primary" href="' . ado_quote_checkout_url((int) $quote['id']) . '">Checkout</a>';
            echo '<button class="button ado-rename-draft" data-id="' . esc_attr((string) $quote['id']) . '">Rename</button>';
            echo '<button class="button ado-delete-draft" data-id="' . esc_attr((string) $quote['id']) . '">Delete</button>';
        }
        echo '</div>';
        echo '</div>';
    }
    return (string) ob_get_clean();
}

function ado_render_quote_unmatched_banner(int $user_id, int $quote_id): string
{
    $unmatched = ado_consume_quote_unmatched_flash($user_id, $quote_id);
    if (!$unmatched) {
        return '';
    }
    ob_start();
    echo '<div class="ado-card" style="border-color:#f59e0b;background:#fffaf0;"><h3 style="margin-top:0;">Unmatched Items</h3>';
    echo '<p class="ado-warning">Some scoped hardware lines could not be matched to WooCommerce products.</p>';
    echo '<table class="ado-table"><thead><tr><th>Door</th><th>Model</th><th>Description</th><th>Qty</th><th>Raw Line</th><th>Actions</th></tr></thead><tbody>';
    foreach ($unmatched as $row) {
        if (!is_array($row)) {
            continue;
        }
        $action_meta = ado_quote_line_action_meta($row);
        echo '<tr>';
        echo '<td>' . esc_html((string) ($row['door_number'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['model'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['description'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['qty'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['raw_line'] ?? '')) . '</td>';
        echo '<td>' . ado_quote_render_line_actions($quote_id, $action_meta) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return (string) ob_get_clean();
}

function ado_render_quote_review_actions_html(array $row, int $quote_id): string
{
    $candidates = array_values((array) ($row['candidate_products'] ?? []));
    $line_key = (string) ($row['line_key'] ?? '');
    if ($quote_id <= 0 || $line_key === '' || !$candidates) {
        return '';
    }

    ob_start();
    echo '<div class="ado-match-review">';
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $product_id = (int) ($candidate['product_id'] ?? 0);
        if ($product_id <= 0) {
            continue;
        }
        $label = trim((string) ($candidate['sku'] ?? ''));
        if ($label === '') {
            $label = 'Product #' . $product_id;
        }
        echo '<div style="margin-bottom:8px;">';
        echo '<button type="button" class="button button-small ado-match-review-choice" data-quote-id="' . esc_attr((string) $quote_id) . '" data-line-key="' . esc_attr($line_key) . '" data-product-id="' . esc_attr((string) $product_id) . '">' . esc_html($label) . '</button>';
        echo '<div class="ado-muted" style="margin-top:4px;">' . esc_html((string) ($candidate['title'] ?? '')) . ' [' . esc_html((string) ($candidate['score'] ?? 0)) . ']</div>';
        echo '</div>';
    }
    echo '<button type="button" class="button ado-match-review-reject" data-quote-id="' . esc_attr((string) $quote_id) . '" data-line-key="' . esc_attr($line_key) . '">None of these</button>';
    echo '</div>';
    return (string) ob_get_clean();
}

function ado_render_quote_match_review(int $quote_id): string
{
    if (!current_user_can('manage_woocommerce')) {
        return '';
    }
    $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $unmatched = is_array($unmatched) ? $unmatched : [];
    if (!$unmatched) {
        return '';
    }

    $show_review = false;
    foreach ($unmatched as $row) {
        if (is_array($row) && !empty($row['candidate_products'])) {
            $show_review = true;
            break;
        }
    }
    if (!$show_review) {
        return '';
    }

    ob_start();
    echo '<div class="ado-card" style="border-color:#f59e0b;background:#fffdf7;"><h3 style="margin-top:0;">Match Review</h3>';
    echo '<p class="ado-muted">Choose the correct WooCommerce product for ambiguous lines. Your choice is saved and reused on future quote builds.</p>';
    echo '<table class="ado-table"><thead><tr><th>Door</th><th>Model</th><th>Description</th><th>Qty</th><th>Reason</th><th>Review</th></tr></thead><tbody>';
    foreach ($unmatched as $row) {
        if (!is_array($row) || empty($row['candidate_products'])) {
            continue;
        }
        echo '<tr>';
        echo '<td>' . esc_html((string) ($row['door_number'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['model'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['description'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['qty'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['reason_code'] ?? '')) . '</td>';
        echo '<td>' . ado_render_quote_review_actions_html($row, $quote_id) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return (string) ob_get_clean();
}

function ado_render_quote_dropped_log(int $quote_id): string
{
    if (!current_user_can('manage_woocommerce')) {
        return '';
    }
    $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $excluded = get_post_meta($quote_id, '_adq_excluded_items', true);
    $rows = array_merge(is_array($unmatched) ? $unmatched : [], is_array($excluded) ? $excluded : []);
    if (!$rows) {
        return '';
    }

    ob_start();
    echo '<div class="ado-card"><h3 style="margin-top:0;">Dropped Items Log</h3>';
    echo '<p class="ado-muted">These scoped rows were not included in the quote because they did not resolve to a WooCommerce product.</p>';
    echo '<table class="ado-table"><thead><tr><th>Door</th><th>Model</th><th>Description</th><th>Qty</th><th>Reason</th><th>Raw Line</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $action_meta = ado_quote_line_action_meta($row);
        echo '<tr>';
        echo '<td>' . esc_html((string) ($row['door_number'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['model'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['description'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['qty'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['excluded_reason'] ?? $row['reason_code'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['raw_line'] ?? '')) . '</td>';
        echo '<td>' . ado_quote_render_line_actions($quote_id, $action_meta) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return (string) ob_get_clean();
}

function ado_render_quote_debug_log(int $quote_id): string
{
    if (!current_user_can('manage_woocommerce')) {
        return '';
    }
    $debug_log = get_post_meta($quote_id, '_adq_match_log', true);
    $debug_log = is_array($debug_log) ? $debug_log : [];
    if (!$debug_log) {
        return '';
    }

    ob_start();
    echo '<div class="ado-card"><h3 style="margin-top:0;">Match Debug</h3>';
    foreach ($debug_log as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        echo '<details style="margin-bottom:10px;"><summary><strong>' . esc_html((string) ($entry['door_number'] ?? '')) . '</strong> | ' . esc_html((string) ($entry['raw_line'] ?? '')) . '</summary>';
        echo '<pre style="max-height:220px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;">' . esc_html(wp_json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '</details>';
    }
    echo '</div>';
    return (string) ob_get_clean();
}

function ado_quote_group_totals(array $group): array
{
    $lines = array_values((array) ($group['lines'] ?? []));
    $qty = 0;
    $subtotal = 0.0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $qty += max(0, (int) ($line['qty'] ?? 0));
        $subtotal += (float) ($line['line_total'] ?? 0);
    }

    return [
        'qty' => $qty,
        'subtotal' => $subtotal,
        'count' => count($lines),
    ];
}

function ado_quote_unmatched_by_door(int $quote_id): array
{
    $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $unmatched = is_array($unmatched) ? $unmatched : [];
    $map = [];
    foreach ($unmatched as $row) {
        if (!is_array($row)) {
            continue;
        }
        $door_id = (string) ($row['door_id'] ?? '');
        if ($door_id === '') {
            $door_id = 'door-number:' . (string) ($row['door_number'] ?? '');
        }
        if (!isset($map[$door_id])) {
            $map[$door_id] = [];
        }
        $map[$door_id][] = $row;
    }
    return $map;
}

function ado_quote_excluded_by_door(int $quote_id): array
{
    $excluded = get_post_meta($quote_id, '_adq_excluded_items', true);
    $excluded = is_array($excluded) ? $excluded : [];
    $map = [];
    foreach ($excluded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $door_id = (string) ($row['door_id'] ?? '');
        if ($door_id === '') {
            $door_id = 'door-number:' . (string) ($row['door_number'] ?? '');
        }
        if (!isset($map[$door_id])) {
            $map[$door_id] = [];
        }
        $map[$door_id][] = $row;
    }
    return $map;
}

function ado_quote_group_match_state(array $group, array $unmatched_by_door): string
{
    $door = (array) ($group['door'] ?? []);
    $door_id = (string) ($door['door_id'] ?? '');
    $door_number = (string) ($door['door_number'] ?? '');
    $unmatched = $unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? [];
    $lines = array_values((array) ($group['lines'] ?? []));
    $is_scoped = !empty($door['is_scoped']);
    $has_operator = !empty($door['has_operator']);

    if (!$is_scoped || !$has_operator) {
        return 'out_of_scope';
    }

    if (!$lines && $unmatched) {
        return 'unknown';
    }
    if (!$lines) {
        return 'unknown';
    }

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $method = strtolower((string) ($line['match_method'] ?? ''));
        $confidence = (float) ($line['match_confidence'] ?? 0);
        if ($method === 'title_contains' || $method === 'fuzzy' || ($confidence > 0 && $confidence < 95)) {
            return 'fuzzy';
        }
    }

    if ($unmatched) {
        return 'fuzzy';
    }

    return 'matched';
}

function ado_quote_group_match_label(string $state, int $unmatched_count): string
{
    if ($state === 'unknown') {
        return 'Unknown';
    }
    if ($state === 'fuzzy') {
        return $unmatched_count > 0 ? 'Fuzzy Match' : 'Fuzzy Match';
    }
    if ($state === 'out_of_scope') {
        return 'Out of Scope';
    }
    return 'Matched';
}

function ado_quote_review_summary(int $quote_id): array
{
    $groups = ado_quote_grouped_lines($quote_id);
    $unmatched_by_door = ado_quote_unmatched_by_door($quote_id);
    $excluded_by_door = ado_quote_excluded_by_door($quote_id);
    $summary = [
        'doors_total' => count($groups),
        'doors_in_scope' => count($groups),
        'matched' => 0,
        'fuzzy' => 0,
        'unknown' => 0,
        'out_of_scope' => 0,
    ];

    foreach ($groups as $group) {
        $door = (array) ($group['door'] ?? []);
        $door_id = (string) ($door['door_id'] ?? '');
        $door_number = (string) ($door['door_number'] ?? '');
        $state = ado_quote_group_match_state($group, $unmatched_by_door);
        if (!isset($summary[$state])) {
            $state = 'unknown';
        }
        $summary[$state]++;
        if (!empty($excluded_by_door[$door_id] ?? $excluded_by_door['door-number:' . $door_number] ?? []) && $state !== 'out_of_scope') {
            $summary['out_of_scope']++;
        }
    }
    $summary['doors_in_scope'] = max(0, (int) $summary['doors_total'] - (int) $summary['out_of_scope']);
    return $summary;
}

function ado_quote_state_card_class(string $state): string
{
    if ($state === 'matched') {
        return 'match-full';
    }
    if ($state === 'fuzzy') {
        return 'match-fuzzy';
    }
    if ($state === 'unknown') {
        return 'match-none';
    }
    return 'no-scope';
}

function ado_quote_flag_class(string $state): string
{
    if ($state === 'unknown') {
        return 'danger';
    }
    if ($state === 'fuzzy') {
        return 'warn';
    }
    return 'neutral';
}

function ado_quote_flag_label(string $state): string
{
    if ($state === 'unknown') {
        return 'Unknown model';
    }
    if ($state === 'fuzzy') {
        return 'Fuzzy match';
    }
    if ($state === 'out_of_scope') {
        return 'Out of scope';
    }
    return 'Matched';
}

function ado_render_quote_inline_review(array $row, int $quote_id, array $adjustment = []): string
{
    if (!is_array($row)) {
        return '';
    }

    $line_key = (string) ($row['line_key'] ?? '');
    if ($line_key === '') {
        return '';
    }

    $reason_code = strtoupper(trim((string) ($row['reason_code'] ?? '')));
    $reason_label = $reason_code !== '' ? str_replace('_', ' ', strtolower($reason_code)) : 'review';
    $reason_label = ucwords($reason_label);
    $actions_html = ado_quote_render_line_actions($quote_id, ado_quote_line_action_meta($row));

    ob_start();
    ?>
    <div class="qr-inline-review qr-inline-review-<?php echo esc_attr(strtolower((string) ($row['reason_code'] ?? 'review'))); ?>">
      <div class="qr-inline-copy">
        <strong><?php echo esc_html((string) ($row['model'] ?? 'Unknown model')); ?></strong>
        <span><?php echo esc_html((string) ($row['description'] ?? $row['raw_line'] ?? '')); ?></span>
        <span class="qr-inline-reason"><?php echo esc_html($reason_label); ?></span>
      </div>
      <?php if ($actions_html !== '') : ?>
        <div class="qr-inline-actions">
          <?php echo $actions_html; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function ado_render_quote_detail(int $user_id, int $quote_id): string
{
    $quote = ado_quote_integration()->get_quote($quote_id);
    if (!$quote) {
        return '<p class="ado-muted">Quote not found.</p>';
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $user_id) && !current_user_can('manage_woocommerce')) {
        return '<p class="ado-muted">Quote access denied.</p>';
    }

    $row = ado_quote_post_row($quote);
    $groups = ado_quote_grouped_lines($quote_id);
    $can_rerun = current_user_can('manage_woocommerce') || (ado_is_client($user_id) && $row['status'] !== 'ordered');
    $flash_banner = ado_render_quote_unmatched_banner($user_id, $quote_id);
    $debug_log = ado_render_quote_debug_log($quote_id);
    $unmatched_by_door = ado_quote_unmatched_by_door($quote_id);
    $excluded_by_door = ado_quote_excluded_by_door($quote_id);
    $summary = ado_quote_review_summary($quote_id);
    $scope_file = wp_basename((string) ($row['scope_url'] ?? ''));
    $status = strtolower((string) ($row['status'] ?? 'draft'));
    $door_notes = ado_quote_door_notes($quote_id);
    $line_adjustments = ado_quote_line_adjustments($quote_id);
    $nonce = wp_create_nonce('ado_quote_nonce');
    $review_items = [];
    $default_open_door_id = '';
    foreach ($groups as $idx => $group) {
        $door = (array) ($group['door'] ?? []);
        $door_id = (string) ($door['door_id'] ?? ('door-' . $idx));
        $door_number = (string) ($door['door_number'] ?? ('Door ' . ($idx + 1)));
        $door_label = (string) ($door['door_label'] ?? ('Door ' . $door_number));
        $state = ado_quote_group_match_state($group, $unmatched_by_door);
        if ($default_open_door_id === '' && in_array($state, ['fuzzy', 'unknown'], true)) {
            $default_open_door_id = $door_id;
        }
        if (!in_array($state, ['fuzzy', 'unknown'], true)) {
            continue;
        }
        $review_count = count((array) ($unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? []));
        $review_count += count((array) ($excluded_by_door[$door_id] ?? $excluded_by_door['door-number:' . $door_number] ?? []));
        $review_items[] = [
            'door_id' => $door_id,
            'door_number' => $door_number,
            'door_label' => $door_label,
            'state' => $state,
            'label' => $state === 'fuzzy' ? 'Fuzzy match, confirm model' : 'Unknown model, manual entry',
            'count' => max(1, $review_count),
        ];
    }
    if ($default_open_door_id === '' && $groups) {
        $first_group = (array) ($groups[0]['door'] ?? []);
        $default_open_door_id = (string) ($first_group['door_id'] ?? 'door-0');
    }

    ob_start();
    ?>
    <style>
      .ado-quote-review{--ado-surface:#fff;--ado-surface-2:#f7f8fa;--ado-border:#e2e5ea;--ado-border-light:#eef0f3;--ado-accent:#1a56db;--ado-accent-soft:#eff4ff;--ado-accent-glow:rgba(26,86,219,.15);--ado-green:#059669;--ado-green-soft:#ecfdf5;--ado-green-border:#a7f3d0;--ado-warn:#d97706;--ado-warn-soft:#fffbeb;--ado-warn-border:#fcd34d;--ado-danger:#dc2626;--ado-danger-soft:#fef2f2;--ado-danger-border:#fca5a5;--ado-text:#0f172a;--ado-muted:#94a3b8;--ado-secondary:#475569;--ado-radius:12px;--ado-radius-sm:7px;}
      .ado-quote-review{margin-top:24px;color:var(--ado-text);} .ado-quote-review *{box-sizing:border-box;}
      .ado-quote-review .qr-hero{margin-bottom:18px;} .ado-quote-review .qr-title{font-size:22px;font-weight:800;letter-spacing:-.5px;margin:0 0 3px;} .ado-quote-review .qr-subtitle{font-size:13px;color:var(--ado-muted);margin:0;}
      .ado-quote-review .qr-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;} .ado-quote-review .qr-chip{display:inline-flex;align-items:center;gap:5px;background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:5px;padding:4px 10px;font-size:11px;font-weight:700;color:var(--ado-secondary);} .ado-quote-review .qr-chip.status-draft{background:var(--ado-accent-soft);border-color:#bfdbfe;color:var(--ado-accent);} .ado-quote-review .qr-chip.status-submitted,.ado-quote-review .qr-chip.status-ordered{background:var(--ado-green-soft);border-color:var(--ado-green-border);color:var(--ado-green);}
      .ado-quote-review .qr-banner{background:linear-gradient(135deg,var(--ado-green-soft),#f0fdf4);border:1px solid var(--ado-green-border);border-radius:var(--ado-radius);padding:14px 18px;display:flex;align-items:center;gap:14px;margin-bottom:22px;} .ado-quote-review .qr-banner-main{flex:1;} .ado-quote-review .qr-banner-title{font-weight:700;color:#065f46;font-size:14px;margin:0 0 2px;} .ado-quote-review .qr-banner-copy{font-size:12px;color:#047857;margin:0;} .ado-quote-review .qr-banner-stats{display:flex;gap:14px;flex-shrink:0;} .ado-quote-review .qr-stat{text-align:center;padding:6px 14px;background:#fff;border:1px solid var(--ado-green-border);border-radius:6px;} .ado-quote-review .qr-stat strong{display:block;font-size:17px;font-weight:800;} .ado-quote-review .qr-stat span{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ado-muted);margin-top:1px;} .ado-quote-review .qr-stat.matched strong{color:var(--ado-green);} .ado-quote-review .qr-stat.fuzzy strong{color:var(--ado-warn);} .ado-quote-review .qr-stat.unknown strong{color:var(--ado-danger);} .ado-quote-review .qr-stat.no-scope strong{color:var(--ado-muted);}
      .ado-quote-review .qr-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;} .ado-quote-review .qr-section-title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;margin:0;} .ado-quote-review .qr-section-icon{width:24px;height:24px;border-radius:6px;background:var(--ado-accent-soft);color:var(--ado-accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;} .ado-quote-review .qr-section-actions{display:flex;gap:7px;flex-wrap:wrap;}
      .ado-quote-review .qr-layout{display:grid;grid-template-columns:1fr 310px;gap:20px;align-items:flex-start;} .ado-quote-review .qr-sidebar{position:sticky;top:74px;display:flex;flex-direction:column;gap:14px;}
      .ado-quote-review .qr-sidecard{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:var(--ado-radius);overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.05);} .ado-quote-review .qr-sidehead{padding:14px 18px 12px;border-bottom:1px solid var(--ado-border);display:flex;align-items:center;justify-content:space-between;} .ado-quote-review .qr-sidetitle{font-size:14px;font-weight:700;margin:0;} .ado-quote-review .qr-sidebody{padding:14px 18px;} .ado-quote-review .qr-siderow{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--ado-border-light);font-size:13px;} .ado-quote-review .qr-siderow:last-child{border-bottom:none;} .ado-quote-review .qr-total{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:var(--ado-text);margin-top:2px;} .ado-quote-review .qr-total span{font-size:13px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.06em;} .ado-quote-review .qr-total strong{font-size:24px;font-weight:800;color:#fff;}
      .ado-quote-review .qr-flag-list{display:flex;flex-direction:column;gap:6px;} .ado-quote-review .qr-flag-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;font-size:12px;border:1px solid transparent;} .ado-quote-review .qr-flag-item.warn{background:var(--ado-warn-soft);border-color:var(--ado-warn-border);color:#92400e;} .ado-quote-review .qr-flag-item.danger{background:var(--ado-danger-soft);border-color:var(--ado-danger-border);color:#991b1b;} .ado-quote-review .qr-flag-item.neutral{background:var(--ado-surface-2);border-color:var(--ado-border);color:var(--ado-secondary);} .ado-quote-review .qr-flag-jump{appearance:none;border:0;background:transparent;color:inherit;display:flex;align-items:center;gap:8px;font:inherit;padding:0;cursor:pointer;flex:1;min-width:0;text-align:left;} .ado-quote-review .qr-flag-jump strong{margin-left:auto;} .ado-quote-review .qr-flag-actions{display:flex;gap:6px;align-items:center;} .ado-quote-review .qr-flag-actions .qr-mini-btn{padding:4px 8px;font-size:10.5px;line-height:1.15;}
      .ado-quote-review .qr-btn,.ado-quote-review .qr-btn:visited{display:inline-flex;align-items:center;justify-content:center;width:100%;padding:12px;border-radius:var(--ado-radius-sm);text-decoration:none;font-weight:700;border:1px solid var(--ado-border);background:var(--ado-surface);color:var(--ado-text);cursor:pointer;} .ado-quote-review .qr-btn.primary{background:var(--ado-accent);color:#fff;border-color:var(--ado-accent);} .ado-quote-review .qr-btn.secondary{background:transparent;color:var(--ado-secondary);} .ado-quote-review .qr-btn.inline{width:auto;padding:8px 12px;} .ado-quote-review .qr-btn+.qr-btn{margin-top:8px;}
      .ado-quote-review .qr-door-list{display:flex;flex-direction:column;gap:10px;} .ado-quote-review .qr-door-card{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:var(--ado-radius);overflow:hidden;transition:box-shadow .18s ease;} .ado-quote-review .qr-door-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);} .ado-quote-review .qr-door-card.match-full{border-left:3px solid var(--ado-green);} .ado-quote-review .qr-door-card.match-fuzzy{border-left:3px solid var(--ado-warn);} .ado-quote-review .qr-door-card.match-none{border-left:3px solid var(--ado-danger);} .ado-quote-review .qr-door-card.no-scope{border-left:3px solid var(--ado-muted);opacity:.85;}
      .ado-quote-review .qr-door-header{display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;user-select:none;background:var(--ado-surface);} .ado-quote-review .qr-door-num{width:36px;height:36px;border-radius:8px;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;} .ado-quote-review .qr-door-card.match-full .qr-door-num{background:var(--ado-green-soft);color:var(--ado-green);} .ado-quote-review .qr-door-card.match-fuzzy .qr-door-num{background:var(--ado-warn-soft);color:var(--ado-warn);} .ado-quote-review .qr-door-card.match-none .qr-door-num{background:var(--ado-danger-soft);color:var(--ado-danger);} .ado-quote-review .qr-door-card.no-scope .qr-door-num{background:#f0f1f3;color:var(--ado-muted);} .ado-quote-review .qr-door-title{flex:1;} .ado-quote-review .qr-door-title strong{display:block;font-size:13.5px;} .ado-quote-review .qr-door-title span{display:block;font-size:11.5px;color:var(--ado-muted);margin-top:1px;}
      .ado-quote-review .qr-door-tag{font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--ado-accent-soft);color:var(--ado-accent);} .ado-quote-review .qr-door-tag.out-scope{background:#f0f1f3;color:var(--ado-muted);border:1px solid var(--ado-border);} .ado-quote-review .qr-door-badge{font-size:10.5px;font-weight:700;letter-spacing:.05em;padding:3px 9px;border-radius:20px;display:flex;align-items:center;gap:4px;} .ado-quote-review .qr-door-card.match-full .qr-door-badge{background:var(--ado-green-soft);color:var(--ado-green);border:1px solid var(--ado-green-border);} .ado-quote-review .qr-door-card.match-fuzzy .qr-door-badge{background:var(--ado-warn-soft);color:var(--ado-warn);border:1px solid var(--ado-warn-border);} .ado-quote-review .qr-door-card.match-none .qr-door-badge{background:var(--ado-danger-soft);color:var(--ado-danger);border:1px solid var(--ado-danger-border);} .ado-quote-review .qr-door-card.no-scope .qr-door-badge{background:#f0f1f3;color:var(--ado-muted);border:1px solid var(--ado-border);} .ado-quote-review .qr-door-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;} .ado-quote-review .qr-door-scope-chip{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.03em;border:1px solid var(--ado-border);background:var(--ado-surface-2);color:var(--ado-secondary);} .ado-quote-review .qr-door-scope-chip.in{background:var(--ado-green-soft);border-color:var(--ado-green-border);color:var(--ado-green);} .ado-quote-review .qr-door-scope-chip.out{background:var(--ado-danger-soft);border-color:var(--ado-danger-border);color:var(--ado-danger);} .ado-quote-review .qr-door-scope-chip.override{background:var(--ado-accent-soft);border-color:#bfdbfe;color:var(--ado-accent);} .ado-quote-review .qr-door-scope-btn{display:inline-flex;align-items:center;gap:6px;background:var(--ado-accent-soft);border-color:#bfdbfe;color:var(--ado-accent);} .ado-quote-review .qr-door-scope-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#fff;border:1px solid #bfdbfe;color:var(--ado-accent);font-size:10px;font-weight:800;} .ado-quote-review .qr-door-total{font-size:14px;font-weight:800;min-width:90px;text-align:right;} .ado-quote-review .qr-door-chevron{color:var(--ado-muted);transition:transform .2s ease;} .ado-quote-review .qr-door-card.open .qr-door-chevron{transform:rotate(90deg);} .ado-quote-review .qr-door-body{border-top:1px solid var(--ado-border);padding:14px 16px;display:none;flex-direction:column;gap:10px;background:var(--ado-surface-2);} .ado-quote-review .qr-door-card.open .qr-door-body{display:flex;}
      .ado-quote-review .qr-table{width:100%;border-collapse:collapse;font-size:12.5px;} .ado-quote-review .qr-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ado-muted);padding:0 0 7px;text-align:left;border-bottom:1px solid var(--ado-border);} .ado-quote-review .qr-table td{padding:8px 0;border-bottom:1px solid var(--ado-border-light);vertical-align:top;} .ado-quote-review .qr-table tr:last-child td{border-bottom:none;} .ado-quote-review .qr-model{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:5px;padding:3px 8px;color:var(--ado-secondary);display:inline-block;} .ado-quote-review .qr-model.fuzzy{background:var(--ado-warn-soft);border-color:var(--ado-warn-border);color:var(--ado-warn);} .ado-quote-review .qr-model.none{background:var(--ado-danger-soft);border-color:var(--ado-danger-border);color:var(--ado-danger);} .ado-quote-review .qr-desc{font-weight:500;color:var(--ado-text);} .ado-quote-review .qr-desc.subtle{color:var(--ado-muted);}
      .ado-quote-review .qr-inline-review{padding:10px 12px;border-radius:6px;display:flex;flex-direction:column;gap:10px;} .ado-quote-review .qr-inline-review-no_candidates,.ado-quote-review .qr-inline-review-manual_price{background:var(--ado-danger-soft);border:1px solid var(--ado-danger-border);} .ado-quote-review .qr-inline-review-ambiguous,.ado-quote-review .qr-inline-review-low_confidence{background:var(--ado-warn-soft);border:1px solid var(--ado-warn-border);} .ado-quote-review .qr-inline-copy{display:flex;flex-direction:column;gap:4px;font-size:12px;} .ado-quote-review .qr-inline-copy span{color:var(--ado-secondary);} .ado-quote-review .qr-inline-candidates,.ado-quote-review .qr-inline-manual{display:flex;gap:8px;flex-wrap:wrap;} .ado-quote-review .qr-mini-btn{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:4px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;} .ado-quote-review .qr-mini-btn.primary{background:var(--ado-accent);border-color:var(--ado-accent);color:#fff;} .ado-quote-review .qr-mini-btn.secondary{background:transparent;} .ado-quote-review .qr-input{background:#fff;border:1px solid var(--ado-border);border-radius:5px;padding:7px 9px;font-size:12px;min-width:150px;outline:none;} .ado-quote-review .qr-input:focus,.ado-quote-review .qr-notes:focus,.ado-quote-review .qr-po-input:focus{border-color:var(--ado-accent);box-shadow:0 0 0 3px var(--ado-accent-glow);} .ado-quote-review .qr-notes-row{display:flex;gap:10px;align-items:flex-start;padding-top:8px;border-top:1px solid var(--ado-border-light);} .ado-quote-review .qr-notes-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--ado-muted);width:90px;flex-shrink:0;padding-top:8px;} .ado-quote-review .qr-notes-wrap{flex:1;} .ado-quote-review .qr-notes{width:100%;background:#fff;border:1px solid var(--ado-border);border-radius:6px;padding:7px 10px;font-size:12.5px;color:var(--ado-secondary);resize:vertical;min-height:56px;} .ado-quote-review .qr-po-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--ado-muted);margin-bottom:5px;} .ado-quote-review .qr-po-input{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:var(--ado-radius-sm);font-size:13.5px;padding:9px 12px;outline:none;width:100%;margin-bottom:8px;} .ado-quote-review .qr-note{font-size:11.5px;color:var(--ado-secondary);line-height:1.5;padding:8px 10px;background:var(--ado-warn-soft);border:1px solid var(--ado-warn-border);border-radius:6px;margin-bottom:8px;} .ado-quote-review .ado-card{margin-top:18px;}
      .ado-quote-review .qr-line-actions{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}
      .ado-quote-review .qr-mini-btn.danger{background:#fee2e2;border-color:#fca5a5;color:#b91c1c}
      .ado-quote-review .qr-inline-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      .ado-quote-review .qr-inline-reason{display:inline-flex;align-items:center;gap:4px;width:max-content;padding:2px 8px;border-radius:999px;background:rgba(15,23,42,.08);font-size:10px;letter-spacing:.05em;text-transform:uppercase}
      .ado-quote-review .qr-match-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.54);z-index:10020}
      .ado-quote-review .qr-match-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(760px,92vw);max-height:82vh;overflow:auto;background:#fff;border:1px solid var(--ado-border);border-radius:12px;box-shadow:0 20px 40px rgba(15,23,42,.28);z-index:10021;padding:14px}
      .ado-quote-review .qr-match-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
      .ado-quote-review .qr-match-search-row{display:flex;align-items:center;gap:8px}
      .ado-quote-review .qr-match-search-row .qr-input{flex:1;min-width:0}
      .ado-quote-review .qr-match-search-status{margin-top:8px}
      .ado-quote-review .qr-match-results{display:flex;flex-direction:column;gap:8px;margin-top:10px}
      .ado-quote-review .qr-match-row{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:9px 10px;border:1px solid var(--ado-border);border-radius:8px;background:#fff}
      .ado-quote-review .qr-match-row-copy{display:flex;flex-direction:column;gap:3px;min-width:0}
      .ado-quote-review .qr-match-row-copy strong{font-size:12px;color:var(--ado-text);word-break:break-word}
      .ado-quote-review .qr-match-row-copy small{font-size:11px;color:var(--ado-muted);word-break:break-word}
      .ado-quote-review .qr-scope-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.54);z-index:10022}
      .ado-quote-review .qr-scope-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(980px,94vw);max-height:84vh;overflow:auto;background:#fff;border:1px solid var(--ado-border);border-radius:12px;box-shadow:0 20px 40px rgba(15,23,42,.28);z-index:10023;padding:14px}
      .ado-quote-review .qr-scope-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
      .ado-quote-review .qr-scope-title{display:flex;flex-direction:column;gap:4px}
      .ado-quote-review .qr-scope-title strong{font-size:15px}
      .ado-quote-review .qr-scope-title span{font-size:11.5px;color:var(--ado-muted)}
      .ado-quote-review .qr-scope-status{font-size:12px;color:var(--ado-secondary);margin-bottom:8px}
      .ado-quote-review .qr-scope-empty{padding:12px;border:1px dashed var(--ado-border);border-radius:8px;background:var(--ado-surface-2);font-size:12px;color:var(--ado-muted)}
      .ado-quote-review .qr-scope-list{display:flex;flex-direction:column;gap:8px}
      .ado-quote-review .qr-scope-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;padding:10px;border:1px solid var(--ado-border);border-radius:8px;background:#fff}
      .ado-quote-review .qr-scope-copy{display:flex;flex-direction:column;gap:4px;min-width:0}
      .ado-quote-review .qr-scope-copy strong{font-size:12px;word-break:break-word}
      .ado-quote-review .qr-scope-copy .qr-model{width:max-content}
      .ado-quote-review .qr-scope-copy small{font-size:11px;color:var(--ado-muted);word-break:break-word}
      .ado-quote-review .qr-scope-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
      .ado-quote-review .qr-scope-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;border:1px solid var(--ado-border);background:var(--ado-surface-2);color:var(--ado-secondary)}
      .ado-quote-review .qr-scope-pill.in{background:var(--ado-green-soft);border-color:var(--ado-green-border);color:var(--ado-green)}
      .ado-quote-review .qr-scope-pill.out{background:var(--ado-danger-soft);border-color:var(--ado-danger-border);color:var(--ado-danger)}
      @media (max-width:1100px){.ado-quote-review .qr-layout{grid-template-columns:1fr;}.ado-quote-review .qr-sidebar{position:static;}} @media (max-width:700px){.ado-quote-review .qr-banner{flex-direction:column;align-items:flex-start;}.ado-quote-review .qr-section-head{align-items:flex-start;flex-direction:column;}.ado-quote-review .qr-door-header{flex-wrap:wrap;}.ado-quote-review .qr-inline-manual{flex-direction:column;}.ado-quote-review .qr-input{min-width:100%;}.ado-quote-review .qr-match-search-row{flex-direction:column;align-items:stretch;}.ado-quote-review .qr-line-actions{width:100%;}.ado-quote-review .qr-line-actions .qr-mini-btn{flex:1;}.ado-quote-review .qr-scope-row{grid-template-columns:1fr;}.ado-quote-review .qr-scope-actions{justify-content:flex-start;}.ado-quote-review .qr-flag-item{flex-wrap:wrap;}}
    </style>
    <div class="ado-quote-review" data-quote-id="<?php echo esc_attr((string) $quote_id); ?>">
      <div class="qr-hero">
        <h2 class="qr-title">Review Extracted Schedule</h2>
        <p class="qr-subtitle">AI extracted <strong><?php echo esc_html((string) count($groups)); ?> doors</strong> from your hardware schedule. Review each one, fix flagged items, then submit the quote.</p>
        <div class="qr-chip-row">
          <span class="qr-chip status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst((string) $row['status'])); ?></span>
          <span class="qr-chip"><?php echo esc_html((string) $row['name']); ?></span>
          <?php if ($scope_file !== '') : ?><span class="qr-chip"><?php echo esc_html($scope_file); ?></span><?php endif; ?>
        </div>
      </div>
      <div class="qr-banner"><div class="qr-banner-main"><div class="qr-banner-title">Extraction complete - <?php echo esc_html((string) $summary['doors_total']); ?> doors found</div><div class="qr-banner-copy">Review fuzzy and unknown rows, then submit with PO to lock pricing.</div></div><div class="qr-banner-stats"><div class="qr-stat"><strong><?php echo esc_html((string) $summary['matched']); ?></strong><span>Matched</span></div><div class="qr-stat"><strong><?php echo esc_html((string) $summary['fuzzy']); ?></strong><span>Fuzzy</span></div><div class="qr-stat"><strong><?php echo esc_html((string) $summary['unknown']); ?></strong><span>Unknown</span></div><div class="qr-stat"><strong><?php echo esc_html((string) $summary['out_of_scope']); ?></strong><span>No Scope</span></div></div></div>
      <div class="qr-layout">
        <div class="qr-main">
          <div class="qr-section-head">
            <h3 class="qr-section-title"><span class="qr-section-icon"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg></span>Door-by-Door Review</h3>
            <div class="qr-section-actions">
              <button type="button" class="qr-btn secondary inline ado-expand-all">Expand All</button>
              <button type="button" class="qr-btn secondary inline ado-collapse-all">Collapse All</button>
            </div>
          </div>
          <div class="qr-door-list">
            <?php foreach ($groups as $index => $group) :
                $door = (array) ($group['door'] ?? []);
                $lines = array_values((array) ($group['lines'] ?? []));
                $totals = ado_quote_group_totals($group);
                $state = ado_quote_group_match_state($group, $unmatched_by_door);
                $door_id = (string) ($door['door_id'] ?? ('door-' . $index));
                $door_number = (string) ($door['door_number'] ?? ('Door ' . ($index + 1)));
                $door_label = (string) ($door['door_label'] ?? ('Door ' . $door_number));
                $original_rows = ado_quote_original_door_rows($quote_id, $door_id, $door_number);
                $scope_total = count($original_rows);
                $scope_in_scope = 0;
                $scope_overrides = 0;
                foreach ($original_rows as $original_row) {
                    if (!is_array($original_row)) {
                        continue;
                    }
                    if (!empty($original_row['in_scope'])) {
                        $scope_in_scope++;
                    }
                    if ((string) ($original_row['decision'] ?? '') !== '') {
                        $scope_overrides++;
                    }
                }
                $scope_out_scope = max(0, $scope_total - $scope_in_scope);
                $note = (string) ($door_notes[$door_id] ?? ($door['notes'] ?? ''));
                $open = $door_id === $default_open_door_id;
            ?>
            <div class="qr-door-card <?php echo esc_attr(ado_quote_state_card_class($state)); ?><?php echo $open ? ' open' : ''; ?>" id="qr-door-<?php echo esc_attr($door_id); ?>" data-door-id="<?php echo esc_attr($door_id); ?>">
              <div class="qr-door-header">
                <div class="qr-door-num"><?php echo esc_html($door_number); ?></div>
                <div class="qr-door-title"><strong><?php echo esc_html($door_label); ?></strong><span><?php echo esc_html(trim(implode(' | ', array_filter([(string) ($door['location'] ?? ''), (string) ($door['desc'] ?? '')])))); ?></span></div>
                <span class="qr-door-tag"><?php echo esc_html(!empty($door['door_type']) ? (string) $door['door_type'] : 'Scoped door'); ?></span>
                <span class="qr-door-badge"><?php echo esc_html(ado_quote_group_match_label($state, count((array) ($unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? [])))); ?></span>
                <div class="qr-door-meta">
                  <span class="qr-door-scope-chip in">In <?php echo esc_html((string) $scope_in_scope); ?></span>
                  <span class="qr-door-scope-chip out">Out <?php echo esc_html((string) $scope_out_scope); ?></span>
                  <?php if ($scope_overrides > 0) : ?><span class="qr-door-scope-chip override">Overrides <?php echo esc_html((string) $scope_overrides); ?></span><?php endif; ?>
                </div>
                <button
                  type="button"
                  class="qr-mini-btn secondary qr-door-scope-btn ado-open-scope-modal"
                  data-quote-id="<?php echo esc_attr((string) $quote_id); ?>"
                  data-door-id="<?php echo esc_attr($door_id); ?>"
                  data-door-number="<?php echo esc_attr($door_number); ?>"
                  data-door-label="<?php echo esc_attr($door_label); ?>"
                >Original Extraction <span class="qr-door-scope-count"><?php echo esc_html((string) $scope_total); ?></span></button>
                <div class="qr-door-total"><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) $totals['subtotal']])); ?></div>
                <svg class="qr-door-chevron" width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 01.708 0l6 6a.5.5 0 010 .708l-6 6a.5.5 0 01-.708-.708L10.293 8 4.646 2.354a.5.5 0 010-.708z" clip-rule="evenodd"/></svg>
              </div>
              <div class="qr-door-body">
                <?php if ($lines) : ?>
                  <table class="qr-table">
                    <thead>
                      <tr>
                        <th>Model</th>
                        <th>Description</th>
                        <th style="text-align:center">Qty</th>
                        <th>Unit Price</th>
                        <th>Line Total</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($lines as $line) :
                          $line_state = ((string) ($line['line_type'] ?? '') === 'manual')
                              ? 'none'
                              : ((((float) ($line['match_confidence'] ?? 0)) > 0 && ((float) ($line['match_confidence'] ?? 0)) < 95) ? 'fuzzy' : '');
                          $line_actions = ado_quote_render_line_actions($quote_id, ado_quote_line_action_meta($line));
                      ?>
                      <tr>
                        <td><span class="qr-model<?php echo $line_state ? ' ' . esc_attr($line_state) : ''; ?>"><?php echo esc_html((string) ($line['display_model'] ?? $line['sku'] ?? $line['model'] ?? $line['source_model'] ?? '')); ?></span></td>
                        <td>
                          <span class="qr-desc"><?php echo esc_html((string) ($line['display_description'] ?? $line['product_name'] ?? $line['description'] ?? '')); ?></span>
                          <?php if (!empty($line['line_type']) && $line['line_type'] === 'manual') : ?><span class="qr-desc subtle"><br>Manual pricing line</span><?php endif; ?>
                        </td>
                        <td style="text-align:center"><?php echo esc_html((string) ((int) ($line['qty'] ?? 0))); ?></td>
                        <td><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) ($line['unit_price'] ?? 0)])); ?></td>
                        <td><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) ($line['line_total'] ?? 0)])); ?></td>
                        <td><?php echo $line_actions; ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
                <?php $door_unmatched = (array) ($unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? []); ?>
                <?php foreach ($door_unmatched as $review_row) : if (!is_array($review_row)) { continue; } $line_key = (string) ($review_row['line_key'] ?? ''); $line_adjustment = $line_key !== '' ? ((array) ($line_adjustments[$line_key] ?? [])) : []; ?>
                  <?php echo ado_render_quote_inline_review($review_row, $quote_id, $line_adjustment); ?>
                <?php endforeach; ?>
                <?php $door_excluded = (array) ($excluded_by_door[$door_id] ?? $excluded_by_door['door-number:' . $door_number] ?? []); ?>
                <?php if ($state === 'out_of_scope') : ?>
                  <div class="qr-inline-review qr-inline-review-low_confidence"><div class="qr-inline-copy"><strong>Out of Scope</strong><span>This door is included for completeness but has no operator scope and no pricing.</span></div></div>
                <?php endif; ?>
                <?php foreach ($door_excluded as $excluded_row) : if (!is_array($excluded_row)) { continue; } $excluded_actions = ado_quote_render_line_actions($quote_id, ado_quote_line_action_meta($excluded_row)); ?>
                  <div class="qr-inline-review qr-inline-review-low_confidence">
                    <div class="qr-inline-copy">
                      <strong><?php echo esc_html((string) ($excluded_row['model'] ?? 'Excluded line')); ?></strong>
                      <span><?php echo esc_html((string) ($excluded_row['excluded_reason'] ?? 'Excluded from scope')); ?></span>
                    </div>
                    <?php if ($excluded_actions !== '') : ?><div class="qr-inline-actions"><?php echo $excluded_actions; ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <div class="qr-notes-row"><div class="qr-notes-label">Notes</div><div class="qr-notes-wrap"><textarea class="qr-notes" data-quote-id="<?php echo esc_attr((string) $quote_id); ?>" data-door-id="<?php echo esc_attr($door_id); ?>" placeholder="Add install notes, special conditions, or clarification for this door."><?php echo esc_textarea($note); ?></textarea><button type="button" class="qr-mini-btn primary ado-save-door-note" data-quote-id="<?php echo esc_attr((string) $quote_id); ?>" data-door-id="<?php echo esc_attr($door_id); ?>" style="margin-top:8px;">Save note</button></div></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php echo $flash_banner; ?>
          <?php echo $debug_log; ?>
          <?php echo ado_render_quote_dropped_log($quote_id); ?>
        </div>
        <aside class="qr-sidebar">
          <div class="qr-sidecard"><div class="qr-sidehead"><div class="qr-sidetitle">Quote Summary</div><span style="font-size:11px;color:var(--ado-muted)">Live - auto-updates</span></div><div class="qr-sidebody"><div class="qr-siderow"><span>Project</span><strong><?php echo esc_html((string) $row['name']); ?></strong></div><div class="qr-siderow"><span>Doors in scope</span><strong><?php echo esc_html((string) $summary['doors_in_scope']); ?> of <?php echo esc_html((string) $summary['doors_total']); ?></strong></div><div class="qr-siderow"><span>Matched</span><strong><?php echo esc_html((string) $summary['matched']); ?></strong></div><div class="qr-siderow"><span>Fuzzy</span><strong><?php echo esc_html((string) $summary['fuzzy']); ?></strong></div><div class="qr-siderow"><span>Unknown</span><strong><?php echo esc_html((string) $summary['unknown']); ?></strong></div><div class="qr-siderow"><span>No Scope</span><strong><?php echo esc_html((string) $summary['out_of_scope']); ?></strong></div></div><div class="qr-total"><span>Est. Total</span><strong><?php echo wp_kses_post((string) $row['subtotal_html']); ?></strong></div></div>
          <div class="qr-sidecard">
            <div class="qr-sidehead"><div class="qr-sidetitle">Items Needing Review</div></div>
            <div class="qr-sidebody">
              <div class="qr-flag-list">
                <?php if ($review_items) : ?>
                  <?php foreach ($review_items as $review_item) : ?>
                    <div class="qr-flag-item <?php echo esc_attr(ado_quote_flag_class((string) $review_item['state'])); ?>">
                      <button type="button" class="qr-flag-jump" data-scroll-door="<?php echo esc_attr((string) $review_item['door_id']); ?>">
                        <span><?php echo esc_html((string) $review_item['door_number']); ?> - <?php echo esc_html((string) $review_item['label']); ?></span>
                        <strong><?php echo esc_html((string) ($review_item['count'] ?? 1)); ?></strong>
                      </button>
                      <div class="qr-flag-actions">
                        <button
                          type="button"
                          class="qr-mini-btn secondary ado-open-scope-from-flag"
                          data-quote-id="<?php echo esc_attr((string) $quote_id); ?>"
                          data-door-id="<?php echo esc_attr((string) $review_item['door_id']); ?>"
                          data-door-number="<?php echo esc_attr((string) $review_item['door_number']); ?>"
                          data-door-label="<?php echo esc_attr((string) $review_item['door_label']); ?>"
                        >Scope</button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php else : ?>
                  <div class="qr-flag-item neutral"><span>No open review items</span><strong>0</strong></div>
                <?php endif; ?>
              </div>
              <div style="font-size:11.5px;color:var(--ado-muted);margin-top:10px;line-height:1.5;">Jump to any flagged door or open Scope to classify pre-scope rows directly.</div>
            </div>
          </div>
          <div class="qr-sidecard"><div class="qr-sidehead"><div class="qr-sidetitle">Submit &amp; Approve</div></div><div class="qr-sidebody"><div class="qr-po-label">Purchase Order Number</div><input class="qr-po-input ado-po-number" type="text" placeholder="e.g. PO-2026-0041"><div class="qr-note">Submitting locks pricing for matched lines. Unknown lines can be manually priced before submit.</div><?php if ((string) $row['status'] !== 'ordered') : ?><button class="qr-btn primary ado-submit-quote" type="button" data-id="<?php echo esc_attr((string) $quote_id); ?>">Submit Quote Request</button><a class="qr-btn" href="<?php echo esc_url(ado_quote_checkout_url($quote_id)); ?>">Checkout This Quote</a><?php elseif ((int) $row['order_id'] > 0) : ?><a class="qr-btn primary" href="<?php echo esc_url(wc_get_endpoint_url('view-order', (string) ((int) $row['order_id']), wc_get_page_permalink('myaccount'))); ?>">Open Project Order #<?php echo esc_html((string) ((int) $row['order_id'])); ?></a><?php endif; ?><?php if ($can_rerun) : ?><button class="qr-btn ado-rerun-match" type="button" data-id="<?php echo esc_attr((string) $quote_id); ?>">Re-run Matching</button><?php endif; ?><button type="button" class="qr-btn secondary ado-close-review-session">Close Review</button></div></div>
        </aside>
      </div>
      <div class="qr-match-modal-backdrop" hidden></div>
      <div class="qr-match-modal" hidden>
        <div class="qr-match-modal-head">
          <strong>Match Line To Product</strong>
          <button type="button" class="qr-mini-btn secondary ado-close-match-modal">Close</button>
        </div>
        <div class="qr-match-search-row">
          <input type="text" class="qr-input qr-match-search-input" placeholder="Search by SKU, model, or product title">
          <button type="button" class="qr-mini-btn primary ado-run-match-search">Search</button>
        </div>
        <div class="qr-match-search-status ado-row-sub"></div>
        <div class="qr-match-results"></div>
      </div>
      <div class="qr-scope-modal-backdrop" hidden></div>
      <div class="qr-scope-modal" hidden>
        <div class="qr-scope-head">
          <div class="qr-scope-title">
            <strong>Original Hardware Extraction</strong>
            <span class="ado-scope-door-label">Review parser rows before scoping.</span>
          </div>
          <button type="button" class="qr-mini-btn secondary ado-close-scope-modal">Close</button>
        </div>
        <div class="qr-scope-status">Loading...</div>
        <div class="qr-scope-list"></div>
      </div>
    </div>
    <script>
      (function($){
        var root = $('.ado-quote-review[data-quote-id="<?php echo esc_js((string) $quote_id); ?>"]');
        if (!root.length) { return; }
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var matchModal = root.find('.qr-match-modal');
        var matchBackdrop = root.find('.qr-match-modal-backdrop');
        var matchStatus = root.find('.qr-match-search-status');
        var matchResults = root.find('.qr-match-results');
        var matchInput = root.find('.qr-match-search-input');
        var matchContext = null;
        var scopeModal = root.find('.qr-scope-modal');
        var scopeBackdrop = root.find('.qr-scope-modal-backdrop');
        var scopeStatus = root.find('.qr-scope-status');
        var scopeList = root.find('.qr-scope-list');
        var scopeDoorLabel = root.find('.ado-scope-door-label');
        var scopeContext = null;

        function closeMatchModal(){
          matchContext = null;
          matchResults.empty();
          matchStatus.text('');
          matchInput.val('');
          matchModal.prop('hidden', true).attr('hidden', 'hidden');
          matchBackdrop.prop('hidden', true).attr('hidden', 'hidden');
        }
        function captureMatchContext(button){
          matchContext = {
            quoteId: parseInt(button.attr('data-quote-id') || '0', 10) || 0,
            lineKey: String(button.attr('data-line-key') || ''),
            decisionKey: String(button.attr('data-decision-key') || ''),
            normalizedModel: String(button.attr('data-normalized-model') || ''),
            query: String(button.attr('data-search-query') || '')
          };
          if (!matchContext.quoteId || !matchContext.lineKey) {
            window.alert('Line context is missing.');
            return false;
          }
          return true;
        }
        function openMatchModal(button){
          if (!captureMatchContext(button)) {
            return;
          }
          matchResults.empty();
          matchStatus.text('Search for the correct product.');
          matchInput.val(matchContext.query || matchContext.normalizedModel || '').focus();
          matchModal.prop('hidden', false).removeAttr('hidden');
          matchBackdrop.prop('hidden', false).removeAttr('hidden');
          if ((matchContext.query || '').length >= 2) {
            runMatchSearch(matchContext.query);
          }
        }
        function renderMatchResults(products){
          matchResults.empty();
          if (!products || !products.length) {
            matchStatus.text('No products found. Try another query.');
            return;
          }
          matchStatus.text(products.length + ' product matches found.');
          products.forEach(function(product){
            var productId = parseInt(product.product_id || 0, 10) || 0;
            if (!productId) {
              return;
            }
            var row = $('<div class="qr-match-row"></div>');
            var copy = $('<div class="qr-match-row-copy"></div>');
            copy.append($('<strong></strong>').text(String(product.sku || ('Product #' + productId))));
            copy.append($('<small></small>').text(String(product.title || '')));
            if (product.brand) {
              copy.append($('<small></small>').text(String(product.brand)));
            }
            var button = $('<button type="button" class="qr-mini-btn primary ado-match-product-choice">Use Match</button>');
            button.attr('data-product-id', String(productId));
            row.append(copy).append(button);
            matchResults.append(row);
          });
        }
        function runMatchSearch(query){
          if (!matchContext) {
            return;
          }
          var searchQuery = $.trim(String(query || ''));
          if (searchQuery.length < 2) {
            matchStatus.text('Enter at least 2 characters to search.');
            matchResults.empty();
            return;
          }
          matchStatus.text('Searching products...');
          $.post(ajaxUrl, {
            action: 'ado_quote_search_review_products',
            nonce: nonce,
            quote_id: matchContext.quoteId,
            query: searchQuery
          }).done(function(res){
            if (!res || !res.success) {
              matchStatus.text((res && res.data && res.data.message) ? res.data.message : 'Search failed.');
              return;
            }
            renderMatchResults((res.data && res.data.products) ? res.data.products : []);
          }).fail(function(){
            matchStatus.text('Search failed.');
          });
        }
        function applyLineDecision(decision, productId){
          if (!matchContext) {
            return;
          }
          $.post(ajaxUrl, {
            action: 'ado_apply_quote_line_decision',
            nonce: nonce,
            quote_id: matchContext.quoteId,
            line_key: matchContext.lineKey,
            decision: decision,
            product_id: productId || 0,
            decision_key: matchContext.decisionKey,
            normalized_model: matchContext.normalizedModel
          }).done(function(res){
            if (!res || !res.success) {
              window.alert((res && res.data && res.data.message) ? res.data.message : 'Failed to update line.');
              return;
            }
            if (res.data && res.data.quote_url) {
              window.location.href = res.data.quote_url;
              return;
            }
            window.location.reload();
          }).fail(function(xhr){
            var message = 'Failed to update line.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
              message = String(xhr.responseJSON.data.message);
            } else if (xhr && xhr.responseText) {
              try {
                var parsed = JSON.parse(xhr.responseText);
                if (parsed && parsed.data && parsed.data.message) {
                  message = String(parsed.data.message);
                }
              } catch (e) {}
            }
            window.alert(message);
          });
        }
        function closeScopeModal(){
          scopeContext = null;
          scopeDoorLabel.text('Review parser rows before scoping.');
          scopeStatus.text('');
          scopeList.empty();
          scopeModal.prop('hidden', true).attr('hidden', 'hidden');
          scopeBackdrop.prop('hidden', true).attr('hidden', 'hidden');
        }
        function renderScopeRows(rows){
          scopeList.empty();
          if (!rows || !rows.length) {
            scopeList.append($('<div class="qr-scope-empty"></div>').text('No original parser rows were found for this door.'));
            return;
          }
          rows.forEach(function(row){
            var signature = String(row.signature || '');
            var catalog = String(row.catalog || '');
            var description = String(row.description || '');
            var raw = String(row.raw || '');
            var qty = parseInt(row.qty || 1, 10) || 1;
            var inScope = !!row.in_scope;
            var decision = String(row.decision || '');
            var canToggle = !!row.can_toggle;

            var wrapper = $('<div class="qr-scope-row"></div>');
            var copy = $('<div class="qr-scope-copy"></div>');
            var modelText = catalog || description || raw || 'Unknown line';
            copy.append($('<strong></strong>').text(modelText));
            if (catalog) {
              copy.append($('<span class="qr-model"></span>').text(catalog));
            }
            if (description && description !== modelText) {
              copy.append($('<small></small>').text(description));
            }
            if (raw && raw !== description && raw !== modelText) {
              copy.append($('<small></small>').text(raw));
            }
            copy.append($('<small></small>').text('Qty: ' + qty));

            var actions = $('<div class="qr-scope-actions"></div>');
            var scopePill = $('<span class="qr-scope-pill"></span>').text(inScope ? 'In Scope' : 'Out Of Scope');
            if (inScope) {
              scopePill.addClass('in');
            } else {
              scopePill.addClass('out');
            }
            actions.append(scopePill);

            if (decision) {
              actions.append($('<span class="qr-scope-pill"></span>').text('Saved: ' + decision));
            }

            var includeBtn = $('<button type="button" class="qr-mini-btn primary ado-scope-include">Add To Scope</button>');
            includeBtn.attr('data-signature', signature);
            var excludeBtn = $('<button type="button" class="qr-mini-btn danger ado-scope-exclude">Remove From Scope</button>');
            excludeBtn.attr('data-signature', signature);
            if (!canToggle || !signature) {
              includeBtn.prop('disabled', true);
              excludeBtn.prop('disabled', true);
            }
            actions.append(includeBtn).append(excludeBtn);
            wrapper.append(copy).append(actions);
            scopeList.append(wrapper);
          });
        }
        function loadScopeRows(){
          if (!scopeContext) {
            return;
          }
          scopeStatus.text('Loading original extraction rows...');
          scopeList.empty();
          $.post(ajaxUrl, {
            action: 'ado_quote_door_original_items',
            nonce: nonce,
            quote_id: scopeContext.quoteId,
            door_id: scopeContext.doorId,
            door_number: scopeContext.doorNumber
          }).done(function(res){
            if (!res || !res.success) {
              scopeStatus.text((res && res.data && res.data.message) ? res.data.message : 'Failed to load original extraction rows.');
              renderScopeRows([]);
              return;
            }
            var rows = (res.data && res.data.rows) ? res.data.rows : [];
            scopeStatus.text(rows.length + ' original extraction row(s) found for this door.');
            renderScopeRows(rows);
          }).fail(function(xhr){
            var message = 'Failed to load original extraction rows.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
              message = String(xhr.responseJSON.data.message);
            }
            scopeStatus.text(message);
            renderScopeRows([]);
          });
        }
        function openScopeModal(button){
          scopeContext = {
            quoteId: parseInt(button.attr('data-quote-id') || '0', 10) || 0,
            doorId: String(button.attr('data-door-id') || ''),
            doorNumber: String(button.attr('data-door-number') || ''),
            doorLabel: String(button.attr('data-door-label') || '')
          };
          if (!scopeContext.quoteId || (!scopeContext.doorId && !scopeContext.doorNumber)) {
            window.alert('Door context is missing.');
            return;
          }
          scopeDoorLabel.text(scopeContext.doorLabel ? ('Door: ' + scopeContext.doorLabel) : 'Review parser rows before scoping.');
          scopeModal.prop('hidden', false).removeAttr('hidden');
          scopeBackdrop.prop('hidden', false).removeAttr('hidden');
          loadScopeRows();
        }
        function applyScopeDecision(decision, signature){
          if (!scopeContext || !signature) {
            return;
          }
          scopeStatus.text('Saving scope update and rebuilding quote...');
          $.post(ajaxUrl, {
            action: 'ado_apply_quote_scope_item_decision',
            nonce: nonce,
            quote_id: scopeContext.quoteId,
            door_id: scopeContext.doorId,
            door_number: scopeContext.doorNumber,
            signature: signature,
            decision: decision
          }).done(function(res){
            if (!res || !res.success) {
              window.alert((res && res.data && res.data.message) ? res.data.message : 'Failed to update scope.');
              return;
            }
            if (res.data && res.data.quote_url) {
              window.location.href = res.data.quote_url;
              return;
            }
            window.location.reload();
          }).fail(function(xhr){
            var message = 'Failed to update scope.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
              message = String(xhr.responseJSON.data.message);
            }
            window.alert(message);
          });
        }

        root.on('click', '.qr-door-header', function(){ $(this).closest('.qr-door-card').toggleClass('open'); });
        root.on('click', '.ado-expand-all', function(){ root.find('.qr-door-card').addClass('open'); });
        root.on('click', '.ado-collapse-all', function(){ root.find('.qr-door-card').removeClass('open'); });
        root.on('click', '[data-scroll-door]', function(){ var id = $(this).data('scroll-door'); var card = $('#qr-door-' + id); if (!card.length) { return; } card.addClass('open'); card[0].scrollIntoView({behavior:'smooth', block:'center'}); card.css('box-shadow', '0 0 0 3px rgba(220,38,38,.2),0 4px 12px rgba(0,0,0,.08)'); window.setTimeout(function(){ card.css('box-shadow', ''); }, 1600); });
        root.on('click', '.ado-save-door-note', function(){ var button = $(this); var doorId = button.data('door-id'); var note = root.find('.qr-notes[data-door-id="' + doorId + '"]').val() || ''; $.post(ajaxUrl, {action:'ado_save_quote_door_note', nonce:nonce, quote_id:button.data('quote-id'), door_id:doorId, note:note}).done(function(res){ if (!res || !res.success) { window.alert((res && res.data && res.data.message) ? res.data.message : 'Failed to save note.'); return; } button.text('Saved'); setTimeout(function(){ button.text('Save note'); }, 1200); }).fail(function(){ window.alert('Failed to save note.'); }); });
        root.on('click', '.ado-line-match', function(){ openMatchModal($(this)); });
        root.on('click', '.ado-line-delete', function(){
          var button = $(this);
          if (!captureMatchContext(button)) {
            return;
          }
          if (!window.confirm('Delete this line and apply this decision to future matches with the same key?')) {
            return;
          }
          applyLineDecision('delete', 0);
        });
        root.on('click', '.ado-open-scope-modal', function(ev){ ev.preventDefault(); ev.stopPropagation(); openScopeModal($(this)); });
        root.on('click', '.ado-open-scope-from-flag', function(ev){ ev.preventDefault(); ev.stopPropagation(); openScopeModal($(this)); });
        root.on('click', '.ado-close-scope-modal', function(){ closeScopeModal(); });
        root.on('click', '.ado-scope-include', function(){ applyScopeDecision('include', String($(this).attr('data-signature') || '')); });
        root.on('click', '.ado-scope-exclude', function(){
          var signature = String($(this).attr('data-signature') || '');
          if (!signature) {
            return;
          }
          if (!window.confirm('Remove this item from scoped matching for this door and apply this key decision going forward?')) {
            return;
          }
          applyScopeDecision('exclude', signature);
        });
        root.on('click', '.ado-run-match-search', function(){ runMatchSearch(matchInput.val() || ''); });
        root.on('keydown', '.qr-match-search-input', function(ev){ if (ev.key === 'Enter') { ev.preventDefault(); runMatchSearch(matchInput.val() || ''); } });
        root.on('click', '.ado-close-match-modal', function(){ closeMatchModal(); });
        root.on('click', '.ado-match-product-choice', function(){
          var productId = parseInt($(this).attr('data-product-id') || '0', 10) || 0;
          if (!productId) {
            return;
          }
          applyLineDecision('match', productId);
        });
        matchBackdrop.on('click', function(){ closeMatchModal(); });
        scopeBackdrop.on('click', function(){ closeScopeModal(); });
        $(document).off('keydown.adoQuoteMatchModal').on('keydown.adoQuoteMatchModal', function(ev){ if (ev.key === 'Escape' && !matchModal.prop('hidden')) { closeMatchModal(); } if (ev.key === 'Escape' && !scopeModal.prop('hidden')) { closeScopeModal(); } });
        root.on('click', '.ado-submit-quote', function(){ var quoteId = $(this).data('id'); var po = $.trim(root.find('.ado-po-number').val() || ''); if (!po) { window.alert('PO number is required before submit.'); return; } $.post(ajaxUrl, {action:'ado_client_quote_transition', nonce:nonce, quote_id:quoteId, target_status:'submitted', po_number:po}).done(function(res){ if (!res || !res.success) { window.alert((res && res.data && res.data.message) ? res.data.message : 'Failed to submit quote.'); return; } $(document).trigger('ado:quote-transitioned', [res]); window.alert('Quote submitted.'); }).fail(function(){ window.alert('Failed to submit quote.'); }); });
        root.on('click', '.ado-close-review-session', function(){ $('#ado-client-close-drawer').trigger('click'); });
      })(jQuery);
    </script>
    <?php

    return (string) ob_get_clean();
}

function ado_assert_client_ajax(): int
{
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Please sign in.'], 401);
    }
    if (!ado_is_client()) {
        wp_send_json_error(['message' => 'Client access only.'], 403);
    }
    check_ajax_referer('ado_quote_nonce', 'nonce');
    return (int) get_current_user_id();
}

add_action('wp_ajax_ado_scope_to_quote_cart', static function (): void {
    $uid = ado_assert_client_ajax();
    $scope_url = esc_url_raw((string) ($_POST['scope_url'] ?? ''));
    $quote_name = sanitize_text_field((string) ($_POST['quote_name'] ?? ''));
    $debug = (!empty($_POST['debug']) && current_user_can('manage_woocommerce'));
    if ($scope_url === '') {
        wp_send_json_error(['message' => 'Missing scoped JSON URL.'], 400);
    }

    $created = ado_quote_integration()->create_quote_from_scope_url($uid, $scope_url, $quote_name, $debug);
    if (empty($created['ok'])) {
        wp_send_json_error([
            'message' => (string) ($created['message'] ?? 'Failed to create quote.'),
            'unmatched' => array_values((array) ($created['unmatched'] ?? [])),
            'debug_log' => array_values((array) ($created['debug_log'] ?? [])),
        ], 400);
    }

    $quote_id = (int) ($created['quote_id'] ?? 0);
    // Ensure consistent initial state for unified status-driven dashboard.
    if ($quote_id > 0) {
        $parser_url = ado_quote_parser_url_from_scope_url($scope_url);
        $parser_payload = $parser_url !== '' ? ado_quote_load_payload_from_url($parser_url) : [];
        if ($parser_payload) {
            ado_quote_store_parser_snapshot($quote_id, $parser_url, $parser_payload);
        }
        $current_status = strtolower((string) get_post_meta($quote_id, '_adq_status', true));
        if ($current_status === '') {
            ado_quote_integration()->update_quote_status($quote_id, 'draft');
        }
    }
    $unmatched = $quote_id > 0 ? get_post_meta($quote_id, '_adq_unmatched_items', true) : [];
    $unmatched = is_array($unmatched) ? $unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $unmatched);
    wp_send_json_success([
        'message' => 'Quote created from scoped JSON.',
        'quote_id' => $quote_id,
        'quote_url' => ado_quote_url($quote_id),
        'drafts_html' => ado_render_quote_drafts_html($uid),
        'unmatched_count' => count($unmatched),
        'debug_log' => $debug ? array_values((array) ($created['debug_log'] ?? [])) : [],
    ]);
});

function ado_quote_scope_token_key(string $token): string
{
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $token);
    if ($token === '') {
        return '';
    }
    return 'ado_scope_payload_' . $token;
}

function ado_quote_scope_learning_option_key(): string
{
    return 'ado_quote_scope_learning_v1';
}

function ado_quote_scope_learning(): array
{
    $rows = get_option(ado_quote_scope_learning_option_key(), []);
    if (!is_array($rows)) {
        return [];
    }
    $clean = [];
    foreach ($rows as $signature => $row) {
        if (!is_string($signature) || !is_array($row)) {
            continue;
        }
        $signature = ado_qm_compact($signature);
        if ($signature === '') {
            continue;
        }
        $decision = (string) ($row['decision'] ?? '');
        if (!in_array($decision, ['include', 'exclude'], true)) {
            continue;
        }
        $clean[$signature] = [
            'decision' => $decision,
            'include_count' => max(0, (int) ($row['include_count'] ?? 0)),
            'exclude_count' => max(0, (int) ($row['exclude_count'] ?? 0)),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'sample' => (string) ($row['sample'] ?? ''),
        ];
    }
    return $clean;
}

function ado_quote_scope_learning_record(string $signature, string $decision, string $sample = ''): void
{
    $signature = ado_qm_compact($signature);
    if ($signature === '' || !in_array($decision, ['include', 'exclude'], true)) {
        return;
    }
    $rows = ado_quote_scope_learning();
    $entry = is_array($rows[$signature] ?? null) ? $rows[$signature] : [
        'decision' => $decision,
        'include_count' => 0,
        'exclude_count' => 0,
        'updated_at' => '',
        'sample' => '',
    ];
    if ($decision === 'include') {
        $entry['include_count'] = (int) ($entry['include_count'] ?? 0) + 1;
    } else {
        $entry['exclude_count'] = (int) ($entry['exclude_count'] ?? 0) + 1;
    }
    $entry['decision'] = $decision;
    $entry['updated_at'] = current_time('mysql');
    if ($sample !== '') {
        $entry['sample'] = $sample;
    }
    $rows[$signature] = $entry;
    update_option(ado_quote_scope_learning_option_key(), $rows, false);
}

function ado_quote_parser_url_from_scope_url(string $scope_url): string
{
    $scope_url = esc_url_raw(trim($scope_url));
    if ($scope_url === '') {
        return '';
    }
    $parser_url = (string) preg_replace('/-scoped(?=-\d+\.json|\.json)/i', '-parser', $scope_url, 1);
    if ($parser_url === '' || $parser_url === $scope_url) {
        $parser_url = str_replace('-scoped', '-parser', $scope_url);
    }
    return $parser_url !== $scope_url ? esc_url_raw($parser_url) : '';
}

function ado_quote_load_payload_from_url(string $payload_url): array
{
    $payload_url = esc_url_raw(trim($payload_url));
    if ($payload_url === '') {
        return [];
    }
    $path = ado_quote_integration()->scope_url_to_path($payload_url);
    if ($path === '' || !file_exists($path)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($path), true);
    return is_array($json) ? $json : [];
}

function ado_quote_store_parser_snapshot(int $quote_id, string $parser_url, array $parser_payload): void
{
    if ($quote_id <= 0) {
        return;
    }
    $parser_url = esc_url_raw(trim($parser_url));
    if ($parser_url !== '') {
        update_post_meta($quote_id, '_adq_parser_url', $parser_url);
    }
    if ($parser_payload) {
        $json = wp_json_encode($parser_payload, JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            update_post_meta($quote_id, '_adq_parser_json_snapshot', wp_slash($json));
        }
    }
}

function ado_quote_parser_payload_for_quote(int $quote_id): array
{
    if ($quote_id <= 0) {
        return [];
    }
    $snapshot = (string) get_post_meta($quote_id, '_adq_parser_json_snapshot', true);
    $payload = $snapshot !== '' ? json_decode($snapshot, true) : null;
    if (is_array($payload)) {
        return $payload;
    }
    $parser_url = (string) get_post_meta($quote_id, '_adq_parser_url', true);
    if ($parser_url === '') {
        $scope_url = (string) get_post_meta($quote_id, '_adq_scope_url', true);
        $parser_url = ado_quote_parser_url_from_scope_url($scope_url);
    }
    $payload = ado_quote_load_payload_from_url($parser_url);
    if ($payload) {
        ado_quote_store_parser_snapshot($quote_id, $parser_url, $payload);
    }
    return $payload;
}

function ado_quote_scope_item_signature(array $item): string
{
    $catalog = trim((string) ($item['catalog'] ?? ''));
    $raw = trim((string) ($item['raw'] ?? ''));
    $desc = trim((string) ($item['desc'] ?? $item['description'] ?? ''));
    $seed = $catalog !== '' ? $catalog : ($raw !== '' ? $raw : $desc);
    $signature = ado_qm_compact($seed);
    if ($signature !== '') {
        return $signature;
    }
    return ado_qm_compact($desc . ' ' . $raw);
}

function ado_quote_scope_item_fingerprint(array $item): string
{
    $qty = max(1, (int) ($item['qty'] ?? 1));
    $parts = [
        (string) $qty,
        trim((string) ($item['catalog'] ?? '')),
        trim((string) ($item['desc'] ?? $item['description'] ?? '')),
        trim((string) ($item['raw'] ?? '')),
    ];
    return md5(implode('|', $parts));
}

function ado_quote_scope_item_overrides(int $quote_id): array
{
    if ($quote_id <= 0) {
        return [];
    }
    $rows = get_post_meta($quote_id, '_adq_scope_item_overrides', true);
    if (!is_array($rows)) {
        return [];
    }
    $clean = [];
    foreach ($rows as $signature => $row) {
        if (!is_string($signature) || !is_array($row)) {
            continue;
        }
        $signature = ado_qm_compact($signature);
        $decision = (string) ($row['decision'] ?? '');
        if ($signature === '' || !in_array($decision, ['include', 'exclude'], true)) {
            continue;
        }
        $clean[$signature] = [
            'decision' => $decision,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'sample' => (string) ($row['sample'] ?? ''),
        ];
    }
    return $clean;
}

function ado_quote_set_scope_item_override(int $quote_id, string $signature, string $decision, string $sample = ''): void
{
    $signature = ado_qm_compact($signature);
    if ($quote_id <= 0 || $signature === '' || !in_array($decision, ['include', 'exclude'], true)) {
        return;
    }
    $rows = ado_quote_scope_item_overrides($quote_id);
    $rows[$signature] = [
        'decision' => $decision,
        'updated_at' => current_time('mysql'),
        'sample' => $sample,
    ];
    update_post_meta($quote_id, '_adq_scope_item_overrides', $rows);
}

function ado_quote_scope_door_key(array $door): string
{
    $door_id = trim((string) ($door['door_id'] ?? ''));
    if ($door_id !== '') {
        return 'id:' . $door_id;
    }
    $door_number = trim((string) ($door['door_number'] ?? ''));
    return $door_number !== '' ? ('number:' . strtoupper($door_number)) : '';
}

function ado_quote_apply_scope_decisions_to_payload(array $scoped_payload, array $parser_payload, array $quote_overrides = [], array $learning = []): array
{
    $scoped_doors = (array) ($scoped_payload['result']['doors'] ?? []);
    $parser_doors = (array) ($parser_payload['result']['doors'] ?? []);
    if (!$scoped_doors || !$parser_doors) {
        return $scoped_payload;
    }

    $decision_map = [];
    foreach ($learning as $signature => $row) {
        if (!is_array($row)) {
            continue;
        }
        $signature = ado_qm_compact((string) $signature);
        $decision = (string) ($row['decision'] ?? '');
        if ($signature === '' || !in_array($decision, ['include', 'exclude'], true)) {
            continue;
        }
        $decision_map[$signature] = $decision;
    }
    foreach ($quote_overrides as $signature => $row) {
        if (!is_array($row)) {
            continue;
        }
        $signature = ado_qm_compact((string) $signature);
        $decision = (string) ($row['decision'] ?? '');
        if ($signature === '' || !in_array($decision, ['include', 'exclude'], true)) {
            continue;
        }
        $decision_map[$signature] = $decision;
    }
    if (!$decision_map) {
        return $scoped_payload;
    }

    $scoped_index = [];
    foreach ($scoped_doors as $idx => $door) {
        if (!is_array($door)) {
            continue;
        }
        $key = ado_quote_scope_door_key($door);
        if ($key !== '') {
            $scoped_index[$key] = (int) $idx;
        }
    }

    foreach ($parser_doors as $parser_door) {
        if (!is_array($parser_door)) {
            continue;
        }
        $door_key = ado_quote_scope_door_key($parser_door);
        if ($door_key === '' || !isset($scoped_index[$door_key])) {
            continue;
        }
        $scoped_idx = (int) $scoped_index[$door_key];
        $scoped_door = is_array($scoped_doors[$scoped_idx] ?? null) ? $scoped_doors[$scoped_idx] : [];
        $scoped_items = array_values(array_filter((array) ($scoped_door['items'] ?? []), static fn($row): bool => is_array($row)));
        $existing_fingerprints = [];
        $next_items = [];
        foreach ($scoped_items as $scoped_item) {
            $signature = ado_quote_scope_item_signature($scoped_item);
            if ($signature !== '' && (($decision_map[$signature] ?? '') === 'exclude')) {
                continue;
            }
            $fingerprint = ado_quote_scope_item_fingerprint($scoped_item);
            if ($fingerprint !== '') {
                $existing_fingerprints[$fingerprint] = true;
            }
            $next_items[] = $scoped_item;
        }

        foreach (array_values(array_filter((array) ($parser_door['items'] ?? []), static fn($row): bool => is_array($row))) as $parser_item) {
            $signature = ado_quote_scope_item_signature($parser_item);
            if ($signature === '' || (($decision_map[$signature] ?? '') !== 'include')) {
                continue;
            }
            $fingerprint = ado_quote_scope_item_fingerprint($parser_item);
            if ($fingerprint !== '' && isset($existing_fingerprints[$fingerprint])) {
                continue;
            }
            $added = $parser_item;
            $added['_scope_kept'] = true;
            $added['_scope_reason'] = 'user_scope_include';
            $signals = array_values((array) ($added['_scope_signals'] ?? []));
            $signals[] = 'user_include';
            $added['_scope_signals'] = array_values(array_unique(array_map(static fn($signal): string => trim((string) $signal), $signals)));
            $next_items[] = $added;
            if ($fingerprint !== '') {
                $existing_fingerprints[$fingerprint] = true;
            }
        }

        $scoped_door['items'] = $next_items;
        $scoped_doors[$scoped_idx] = $scoped_door;
    }

    if (!isset($scoped_payload['result']) || !is_array($scoped_payload['result'])) {
        $scoped_payload['result'] = [];
    }
    $scoped_payload['result']['doors'] = array_values($scoped_doors);
    return $scoped_payload;
}

function ado_quote_scoped_payload_for_quote(int $quote_id): array
{
    if ($quote_id <= 0) {
        return [];
    }
    $snapshot = (string) get_post_meta($quote_id, '_adq_scoped_json_snapshot', true);
    $payload = $snapshot !== '' ? json_decode($snapshot, true) : null;
    if (is_array($payload)) {
        return $payload;
    }
    $scope_path = (string) get_post_meta($quote_id, '_adq_scope_path', true);
    if ($scope_path === '') {
        $scope_url = (string) get_post_meta($quote_id, '_adq_scope_url', true);
        if ($scope_url !== '') {
            $scope_path = ado_quote_integration()->scope_url_to_path($scope_url);
            if ($scope_path !== '') {
                update_post_meta($quote_id, '_adq_scope_path', $scope_path);
            }
        }
    }
    if ($scope_path !== '' && file_exists($scope_path)) {
        $payload = json_decode((string) file_get_contents($scope_path), true);
        if (is_array($payload)) {
            return $payload;
        }
    }
    return [];
}

function ado_quote_save_scoped_payload_for_quote(int $quote_id, array $payload): bool
{
    if ($quote_id <= 0 || !$payload) {
        return false;
    }
    $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }
    update_post_meta($quote_id, '_adq_scoped_json_snapshot', wp_slash($json));
    update_post_meta($quote_id, '_adq_updated_at', current_time('mysql'));
    return true;
}

function ado_quote_original_door_rows(int $quote_id, string $door_id, string $door_number): array
{
    $parser_payload = ado_quote_parser_payload_for_quote($quote_id);
    if (!$parser_payload) {
        return [];
    }
    $scoped_payload = ado_quote_scoped_payload_for_quote($quote_id);
    $scoped_doors = (array) ($scoped_payload['result']['doors'] ?? []);
    $parser_doors = (array) ($parser_payload['result']['doors'] ?? []);
    $target_keys = [];
    if ($door_id !== '') {
        $target_keys['id:' . $door_id] = true;
    }
    if ($door_number !== '') {
        $target_keys['number:' . strtoupper($door_number)] = true;
        // Parser payloads may store numeric/label door keys as door_id instead of door_number.
        $target_keys['id:' . $door_number] = true;
    }
    if (!$target_keys) {
        return [];
    }

    $parser_door = null;
    foreach ($parser_doors as $door) {
        if (!is_array($door)) {
            continue;
        }
        if (!empty($target_keys[ado_quote_scope_door_key($door) ?? ''])) {
            $parser_door = $door;
            break;
        }
    }
    if (!is_array($parser_door)) {
        return [];
    }

    $scoped_signatures = [];
    foreach ($scoped_doors as $door) {
        if (!is_array($door) || empty($target_keys[ado_quote_scope_door_key($door) ?? ''])) {
            continue;
        }
        foreach ((array) ($door['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $signature = ado_quote_scope_item_signature($item);
            if ($signature !== '') {
                $scoped_signatures[$signature] = true;
            }
        }
    }

    $learning = ado_quote_scope_learning();
    $overrides = ado_quote_scope_item_overrides($quote_id);
    $rows = [];
    $seen = [];
    foreach ((array) ($parser_door['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $signature = ado_quote_scope_item_signature($item);
        $fingerprint = ado_quote_scope_item_fingerprint($item);
        if ($fingerprint === '' || isset($seen[$fingerprint])) {
            continue;
        }
        $seen[$fingerprint] = true;
        $catalog = trim((string) ($item['catalog'] ?? ''));
        $desc = trim((string) ($item['desc'] ?? ''));
        $raw = trim((string) ($item['raw'] ?? ''));
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $in_scope = $signature !== '' ? !empty($scoped_signatures[$signature]) : false;
        $decision = '';
        if ($signature !== '' && is_array($overrides[$signature] ?? null)) {
            $decision = (string) ($overrides[$signature]['decision'] ?? '');
        } elseif ($signature !== '' && is_array($learning[$signature] ?? null)) {
            $decision = (string) ($learning[$signature]['decision'] ?? '');
        }
        $rows[] = [
            'signature' => $signature,
            'catalog' => $catalog,
            'description' => $desc,
            'raw' => $raw,
            'qty' => $qty,
            'in_scope' => $in_scope,
            'decision' => in_array($decision, ['include', 'exclude'], true) ? $decision : '',
            'can_toggle' => $signature !== '',
        ];
    }
    return $rows;
}

function ado_quote_stage_scoped_payload(int $user_id, string $scope_url): array
{
    $scope_url = esc_url_raw($scope_url);
    if ($scope_url === '') {
        return ['ok' => false, 'message' => 'Missing scoped JSON URL.'];
    }

    $scope_path = ado_quote_integration()->scope_url_to_path($scope_url);
    if ($scope_path === '' || !file_exists($scope_path)) {
        return ['ok' => false, 'message' => 'Scoped JSON file not found.'];
    }

    $payload = json_decode((string) file_get_contents($scope_path), true);
    if (!is_array($payload)) {
        return ['ok' => false, 'message' => 'Scoped JSON payload is invalid.'];
    }
    $doors = (array) ($payload['result']['doors'] ?? []);
    if (!$doors) {
        return ['ok' => false, 'message' => 'Scoped JSON contains no doors.'];
    }

    $parser_url = ado_quote_parser_url_from_scope_url($scope_url);
    $parser_payload = $parser_url !== '' ? ado_quote_load_payload_from_url($parser_url) : [];
    if ($parser_payload) {
        $payload = ado_quote_apply_scope_decisions_to_payload(
            $payload,
            $parser_payload,
            [],
            ado_quote_scope_learning()
        );
    }

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $token = wp_generate_password(32, false, false);
        $token = preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
    }
    $key = ado_quote_scope_token_key($token);
    if ($key === '') {
        return ['ok' => false, 'message' => 'Failed to stage scoped payload.'];
    }

    $ttl_seconds = 30 * MINUTE_IN_SECONDS;
    set_transient($key, [
        'user_id' => (int) $user_id,
        'created_at' => time(),
        'scope_url' => (string) $scope_url,
        'parser_url' => (string) $parser_url,
        'parser_payload' => $parser_payload,
        'payload' => $payload,
    ], $ttl_seconds);

    return [
        'ok' => true,
        'scope_token' => $token,
        'expires_in' => $ttl_seconds,
    ];
}

function ado_quote_create_from_scope_token(int $user_id, string $token, string $quote_name = ''): array
{
    $token = preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
    $key = ado_quote_scope_token_key($token);
    if ($key === '') {
        return ['ok' => false, 'message' => 'Invalid staged payload token.'];
    }
    $staged = get_transient($key);
    if (!is_array($staged) || empty($staged['payload']) || (int) ($staged['user_id'] ?? 0) !== (int) $user_id) {
        return ['ok' => false, 'message' => 'Staged payload not found or expired. Please re-upload.'];
    }
    $payload = (array) ($staged['payload'] ?? []);

    $created = ado_quote_integration()->create_quote_from_payload($user_id, $payload, [
        'name' => $quote_name,
        'scope_url' => (string) ($staged['scope_url'] ?? ''),
        'scope_path' => '',
        'debug' => false,
    ]);
    if (empty($created['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($created['message'] ?? 'Failed to create quote.'),
            'unmatched' => array_values((array) ($created['unmatched'] ?? [])),
            'excluded' => array_values((array) ($created['excluded'] ?? [])),
            'debug_log' => array_values((array) ($created['debug_log'] ?? [])),
        ];
    }

    $quote_id = (int) ($created['quote_id'] ?? 0);
    if ($quote_id > 0) {
        $parser_url = esc_url_raw((string) ($staged['parser_url'] ?? ''));
        $parser_payload = is_array($staged['parser_payload'] ?? null) ? (array) $staged['parser_payload'] : [];
        if (!$parser_payload) {
            if ($parser_url === '') {
                $parser_url = ado_quote_parser_url_from_scope_url((string) ($staged['scope_url'] ?? ''));
            }
            if ($parser_url !== '') {
                $parser_payload = ado_quote_load_payload_from_url($parser_url);
            }
        }
        if ($parser_payload) {
            ado_quote_store_parser_snapshot($quote_id, $parser_url, $parser_payload);
        }
    }

    delete_transient($key);
    return $created;
}

add_action('wp_ajax_ado_stage_scoped_payload', static function (): void {
    $uid = ado_assert_client_ajax();
    $scope_url = esc_url_raw((string) ($_POST['scope_url'] ?? ''));
    $staged = ado_quote_stage_scoped_payload($uid, $scope_url);
    if (empty($staged['ok'])) {
        wp_send_json_error(['message' => (string) ($staged['message'] ?? 'Failed to stage scoped payload.')], 400);
    }
    wp_send_json_success([
        'scope_token' => (string) ($staged['scope_token'] ?? ''),
        'expires_in' => (int) ($staged['expires_in'] ?? 0),
    ]);
});

add_action('wp_ajax_ado_scope_token_to_quote_cart', static function (): void {
    $uid = ado_assert_client_ajax();
    $scope_token = sanitize_text_field((string) ($_POST['scope_token'] ?? ''));
    $quote_name = sanitize_text_field((string) ($_POST['quote_name'] ?? ''));
    if ($scope_token === '') {
        wp_send_json_error(['message' => 'Missing staged payload token.'], 400);
    }

    $created = ado_quote_create_from_scope_token($uid, $scope_token, $quote_name);
    if (empty($created['ok'])) {
        wp_send_json_error([
            'message' => (string) ($created['message'] ?? 'Failed to create quote.'),
            'unmatched' => array_values((array) ($created['unmatched'] ?? [])),
            'excluded' => array_values((array) ($created['excluded'] ?? [])),
            'debug_log' => array_values((array) ($created['debug_log'] ?? [])),
        ], 400);
    }

    $quote_id = (int) ($created['quote_id'] ?? 0);
    if ($quote_id > 0) {
        $current_status = strtolower((string) get_post_meta($quote_id, '_adq_status', true));
        if ($current_status === '') {
            ado_quote_integration()->update_quote_status($quote_id, 'draft');
        }
    }
    $unmatched = $quote_id > 0 ? get_post_meta($quote_id, '_adq_unmatched_items', true) : [];
    $unmatched = is_array($unmatched) ? $unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $unmatched);
    wp_send_json_success([
        'message' => 'Quote created from staged scoped JSON.',
        'quote_id' => $quote_id,
        'quote_url' => ado_quote_url($quote_id),
        'drafts_html' => ado_render_quote_drafts_html($uid),
        'unmatched_count' => count($unmatched),
        'debug_log' => array_values((array) ($created['debug_log'] ?? [])),
    ]);
});

add_action('wp_ajax_ado_load_quote_draft', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['draft_id'] ?? 0);
    if ($quote_id <= 0) {
        wp_send_json_error(['message' => 'Quote not found.'], 404);
    }

    $loaded = ado_quote_integration()->load_quote_to_cart($quote_id, $uid);
    if (empty($loaded['ok'])) {
        wp_send_json_error(['message' => (string) ($loaded['message'] ?? 'Failed to load quote.')], 400);
    }
    ado_quote_integration()->update_quote_status($quote_id, 'submitted');

    wp_send_json_success([
        'message' => 'Quote checkout is ready.',
        'cart_url' => (string) ($loaded['cart_url'] ?? wc_get_cart_url()),
        'checkout_url' => ado_quote_checkout_url($quote_id),
    ]);
});

add_action('wp_ajax_ado_delete_quote_draft', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['draft_id'] ?? 0);
    if ($quote_id <= 0) {
        wp_send_json_error(['message' => 'Quote not found.'], 404);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    wp_trash_post($quote_id);
    wp_send_json_success(['message' => 'Quote deleted.', 'drafts_html' => ado_render_quote_drafts_html($uid)]);
});

add_action('wp_ajax_ado_rename_quote_draft', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['draft_id'] ?? 0);
    $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
    if ($quote_id <= 0 || $name === '') {
        wp_send_json_error(['message' => 'Quote and name are required.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    wp_update_post(['ID' => $quote_id, 'post_title' => $name]);
    update_post_meta($quote_id, '_adq_updated_at', current_time('mysql'));
    wp_send_json_success(['message' => 'Quote renamed.', 'drafts_html' => ado_render_quote_drafts_html($uid)]);
});

add_action('wp_ajax_ado_rerun_quote_matching', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    if ($quote_id <= 0) {
        wp_send_json_error(['message' => 'Quote not found.'], 404);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }
    $debug = current_user_can('manage_woocommerce');
    $rerun = ado_quote_integration()->rerun_matching($quote_id, $debug);
    if (empty($rerun['ok'])) {
        wp_send_json_error(['message' => (string) ($rerun['message'] ?? 'Re-run failed.')], 400);
    }
    $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $unmatched = is_array($unmatched) ? $unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $unmatched);
    wp_send_json_success([
        'message' => (string) ($rerun['message'] ?? 'Re-run completed.'),
        'quote_url' => ado_quote_url($quote_id),
        'drafts_html' => ado_render_quote_drafts_html($uid),
        'unmatched_count' => count($unmatched),
        'debug_log' => $debug ? array_values((array) ($rerun['debug_log'] ?? [])) : [],
    ]);
});

add_action('wp_ajax_ado_resolve_quote_match_review', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $line_key = sanitize_text_field((string) ($_POST['line_key'] ?? ''));
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if ($quote_id <= 0 || $line_key === '') {
        wp_send_json_error(['message' => 'Quote and line key are required.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $unmatched = is_array($unmatched) ? $unmatched : [];
    $review_row = null;
    foreach ($unmatched as $row) {
        if (is_array($row) && (string) ($row['line_key'] ?? '') === $line_key) {
            $review_row = $row;
            break;
        }
    }
    if (!$review_row) {
        wp_send_json_error(['message' => 'Match review row not found.'], 404);
    }

    $decision_key = (string) ($review_row['decision_key'] ?? '');
    $normalized_model = (string) ($review_row['normalized_model'] ?? '');
    $candidates = array_values((array) ($review_row['candidate_products'] ?? []));
    if (!$candidates) {
        wp_send_json_error(['message' => 'This row has no review candidates.'], 400);
    }

    $selected = null;
    if ($product_id > 0) {
        foreach ($candidates as $candidate) {
            if ((int) ($candidate['product_id'] ?? 0) === $product_id) {
                $selected = $candidate;
                break;
            }
        }
        if (!$selected) {
            wp_send_json_error(['message' => 'Selected product is not valid for this row.'], 400);
        }
        ado_qm_save_override_choice($decision_key, $normalized_model, (string) ($selected['brand'] ?? ''), $product_id);
        $message = 'Match saved and quote rebuilt.';
    } else {
        ado_qm_save_rejection($decision_key, array_map(static fn(array $row): int => (int) ($row['product_id'] ?? 0), $candidates));
        $message = 'Candidates rejected and quote rebuilt.';
    }

    $debug = current_user_can('manage_woocommerce');
    $rerun = ado_quote_integration()->rerun_matching($quote_id, $debug);
    if (empty($rerun['ok'])) {
        wp_send_json_error(['message' => (string) ($rerun['message'] ?? 'Failed to rebuild quote.')], 400);
    }
    if ($product_id > 0 && is_array($selected)) {
        $still_unmatched = false;
        $latest_unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
        $latest_unmatched = is_array($latest_unmatched) ? $latest_unmatched : [];
        foreach ($latest_unmatched as $row) {
            if (is_array($row) && (string) ($row['line_key'] ?? '') === $line_key) {
                $still_unmatched = true;
                break;
            }
        }
        if ($still_unmatched) {
            $product = wc_get_product($product_id);
            $sku = trim((string) ($selected['sku'] ?? ($product ? $product->get_sku() : '')));
            $title = trim((string) ($selected['title'] ?? ($product ? $product->get_name() : 'Manual corrected line')));
            $unit_price = $product ? (float) $product->get_price('edit') : 0.0;
            ado_quote_integration()->save_quote_line_adjustment($quote_id, $line_key, [
                'corrected_model' => $sku,
                'manual_sku' => $sku,
                'manual_description' => $title,
                'manual_unit_price' => $unit_price,
            ]);
            $rerun = ado_quote_integration()->rerun_matching($quote_id, $debug);
            if (empty($rerun['ok'])) {
                wp_send_json_error(['message' => (string) ($rerun['message'] ?? 'Failed to apply accepted match.')], 400);
            }
        }
    }
    $new_unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $new_unmatched = is_array($new_unmatched) ? $new_unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $new_unmatched);
    wp_send_json_success([
        'message' => $message,
        'quote_url' => ado_quote_url($quote_id),
        'unmatched_count' => count($new_unmatched),
    ]);
});

add_action('wp_ajax_ado_quote_search_review_products', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $query = sanitize_text_field((string) ($_POST['query'] ?? ''));
    if ($quote_id <= 0) {
        wp_send_json_error(['message' => 'Quote not found.'], 404);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }
    if (strlen(trim($query)) < 2) {
        wp_send_json_success(['products' => []]);
    }
    wp_send_json_success([
        'products' => ado_quote_search_match_products($query, 18),
    ]);
});

add_action('wp_ajax_ado_apply_quote_line_decision', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $line_key = sanitize_text_field((string) ($_POST['line_key'] ?? ''));
    $decision = sanitize_key((string) ($_POST['decision'] ?? 'match'));
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $decision_key = sanitize_text_field((string) ($_POST['decision_key'] ?? ''));
    $normalized_model = sanitize_text_field((string) ($_POST['normalized_model'] ?? ''));
    if ($quote_id <= 0 || $line_key === '') {
        wp_send_json_error(['message' => 'Quote and line key are required.'], 400);
    }
    if (!in_array($decision, ['match', 'delete'], true)) {
        wp_send_json_error(['message' => 'Invalid line decision.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    $context = ado_quote_find_line_context($quote_id, $line_key);
    $line_row = is_array($context['row'] ?? null) ? (array) ($context['row'] ?? []) : [];
    if ($decision_key === '') {
        $decision_key = trim((string) ($line_row['decision_key'] ?? ''));
    }
    if ($normalized_model === '') {
        $normalized_model = trim((string) ($line_row['normalized_model'] ?? ''));
    }
    if ($normalized_model === '') {
        $normalized_model = trim((string) ($line_row['source_model'] ?? $line_row['model'] ?? $line_row['display_model'] ?? $line_row['sku'] ?? ''));
    }
    $normalized_model = ado_qm_compact($normalized_model);
    if ($decision_key === '' && $normalized_model !== '') {
        $decision_key = '*|' . $normalized_model;
    }
    if ($decision_key === '' && $normalized_model === '') {
        wp_send_json_error(['message' => 'Line context is missing key metadata for this decision.'], 400);
    }

    if ($decision === 'match') {
        if ($product_id <= 0) {
            wp_send_json_error(['message' => 'Please choose a product to match.'], 400);
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Selected product was not found.'], 404);
        }
        $brand = function_exists('ado_qm_infer_brand_from_title')
            ? ado_qm_infer_brand_from_title((string) $product->get_name())
            : '';
        if ($decision_key !== '' && $normalized_model !== '') {
            ado_qm_save_override_choice($decision_key, $normalized_model, $brand, $product_id);
        }
        ado_quote_integration()->save_quote_line_adjustment($quote_id, $line_key, [
            'drop_line' => false,
            'corrected_model' => '',
            'manual_description' => '',
            'manual_sku' => '',
            'manual_unit_price' => '',
        ]);
        $message = 'Match saved and propagated to matching lines.';
    } else {
        if ($decision_key !== '' && $normalized_model !== '' && function_exists('ado_qm_save_override_deletion')) {
            ado_qm_save_override_deletion($decision_key, $normalized_model);
        }
        ado_quote_integration()->save_quote_line_adjustment($quote_id, $line_key, [
            'drop_line' => true,
            'corrected_model' => '',
            'manual_description' => '',
            'manual_sku' => '',
            'manual_unit_price' => '',
        ]);
        $message = 'Line deleted and future matches with this key will be excluded.';
    }

    $debug = current_user_can('manage_woocommerce');
    $rerun = ado_quote_integration()->rerun_matching($quote_id, $debug);
    if (empty($rerun['ok'])) {
        wp_send_json_error(['message' => (string) ($rerun['message'] ?? 'Failed to rebuild quote.')], 400);
    }

    $new_unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $new_unmatched = is_array($new_unmatched) ? $new_unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $new_unmatched);

    wp_send_json_success([
        'message' => $message,
        'quote_url' => ado_quote_url($quote_id),
        'unmatched_count' => count($new_unmatched),
        'summary' => ado_quote_review_summary($quote_id),
    ]);
});

add_action('wp_ajax_ado_quote_door_original_items', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $door_id = sanitize_text_field((string) ($_POST['door_id'] ?? ''));
    $door_number = sanitize_text_field((string) ($_POST['door_number'] ?? ''));
    if ($quote_id <= 0 || ($door_id === '' && $door_number === '')) {
        wp_send_json_error(['message' => 'Quote and door context are required.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    $rows = ado_quote_original_door_rows($quote_id, $door_id, $door_number);
    wp_send_json_success([
        'rows' => array_values($rows),
        'count' => count($rows),
    ]);
});

add_action('wp_ajax_ado_apply_quote_scope_item_decision', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $door_id = sanitize_text_field((string) ($_POST['door_id'] ?? ''));
    $door_number = sanitize_text_field((string) ($_POST['door_number'] ?? ''));
    $signature = ado_qm_compact((string) ($_POST['signature'] ?? ''));
    $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
    if ($quote_id <= 0 || ($door_id === '' && $door_number === '') || $signature === '' || !in_array($decision, ['include', 'exclude'], true)) {
        wp_send_json_error(['message' => 'Invalid scope update payload.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    $rows = ado_quote_original_door_rows($quote_id, $door_id, $door_number);
    if (!$rows) {
        wp_send_json_error(['message' => 'Original extraction rows were not found for this door.'], 404);
    }
    $matched_row = null;
    foreach ($rows as $row) {
        if (!is_array($row) || ado_qm_compact((string) ($row['signature'] ?? '')) !== $signature) {
            continue;
        }
        $matched_row = $row;
        break;
    }
    if (!is_array($matched_row)) {
        wp_send_json_error(['message' => 'Selected line is no longer available in the original extraction.'], 404);
    }

    $sample = trim((string) ($matched_row['catalog'] ?? ''));
    if ($sample === '') {
        $sample = trim((string) ($matched_row['description'] ?? ''));
    }
    if ($sample === '') {
        $sample = trim((string) ($matched_row['raw'] ?? ''));
    }

    ado_quote_set_scope_item_override($quote_id, $signature, $decision, $sample);
    ado_quote_scope_learning_record($signature, $decision, $sample);

    $scoped_payload = ado_quote_scoped_payload_for_quote($quote_id);
    $parser_payload = ado_quote_parser_payload_for_quote($quote_id);
    if (!$scoped_payload || !$parser_payload) {
        wp_send_json_error(['message' => 'Scope payload is unavailable for this quote.'], 400);
    }

    $updated_payload = ado_quote_apply_scope_decisions_to_payload(
        $scoped_payload,
        $parser_payload,
        ado_quote_scope_item_overrides($quote_id),
        ado_quote_scope_learning()
    );
    if (!ado_quote_save_scoped_payload_for_quote($quote_id, $updated_payload)) {
        wp_send_json_error(['message' => 'Failed to save updated scope snapshot.'], 500);
    }

    $debug = current_user_can('manage_woocommerce');
    $rerun = ado_quote_integration()->rerun_matching($quote_id, $debug);
    if (empty($rerun['ok'])) {
        wp_send_json_error(['message' => (string) ($rerun['message'] ?? 'Failed to rebuild quote after scope update.')], 400);
    }

    $new_unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $new_unmatched = is_array($new_unmatched) ? $new_unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $new_unmatched);

    wp_send_json_success([
        'message' => 'Scope updated and quote rebuilt.',
        'quote_url' => ado_quote_url($quote_id),
        'unmatched_count' => count($new_unmatched),
        'summary' => ado_quote_review_summary($quote_id),
    ]);
});

add_action('wp_ajax_ado_save_quote_door_note', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $door_id = sanitize_text_field((string) ($_POST['door_id'] ?? ''));
    $note = (string) ($_POST['note'] ?? '');
    if ($quote_id <= 0 || $door_id === '') {
        wp_send_json_error(['message' => 'Quote and door are required.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }
    ado_quote_integration()->save_quote_door_note($quote_id, $door_id, $note);
    wp_send_json_success([
        'message' => 'Door note saved.',
        'summary' => ado_quote_review_summary($quote_id),
    ]);
});

add_action('wp_ajax_ado_save_quote_line_adjustment', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $line_key = sanitize_text_field((string) ($_POST['line_key'] ?? ''));
    if ($quote_id <= 0 || $line_key === '') {
        wp_send_json_error(['message' => 'Quote and line are required.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    $payload = [];
    foreach (['corrected_model', 'manual_description', 'manual_sku', 'manual_unit_price', 'drop_line'] as $key) {
        if (array_key_exists($key, $_POST)) {
            $payload[$key] = wp_unslash($_POST[$key]);
        }
    }
    if (!$payload) {
        wp_send_json_error(['message' => 'No adjustment values were provided.'], 400);
    }

    ado_quote_integration()->save_quote_line_adjustment($quote_id, $line_key, $payload);
    $debug = current_user_can('manage_woocommerce');
    $rerun = ado_quote_integration()->rerun_matching($quote_id, $debug);
    if (empty($rerun['ok'])) {
        wp_send_json_error(['message' => (string) ($rerun['message'] ?? 'Failed to rebuild quote.')], 400);
    }

    $new_unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $new_unmatched = is_array($new_unmatched) ? $new_unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $new_unmatched);

    wp_send_json_success([
        'message' => 'Quote line updated.',
        'quote_url' => ado_quote_url($quote_id),
        'summary' => ado_quote_review_summary($quote_id),
        'unmatched_count' => count($new_unmatched),
    ]);
});

add_shortcode('ado_quote_workspace', static function (): string {
    if (!is_user_logged_in()) {
        return '<p>Please sign in to create quotes.</p>';
    }
    if (!ado_is_client()) {
        return '<p>This area is for client accounts only.</p>';
    }

    $uid = (int) get_current_user_id();
    $quote_id = isset($_GET['quote_id']) ? (int) $_GET['quote_id'] : 0;
    $nonce = wp_create_nonce('ado_quote_nonce');
    $is_debug = current_user_can('manage_woocommerce');

    ob_start();
    ?>
    <div class="ado-card">
      <h3>New Quote (Upload Hardware Schedule PDF)</h3>
      <p class="ado-muted">Upload a hardware schedule PDF. When scoped JSON is ready, create a WooCommerce quote from it.</p>
      <div class="ado-row">
        <label>Quote Name <input id="ado-quote-name" type="text" placeholder="Project Name - Quote"></label>
        <?php if ($is_debug) : ?>
          <label><input id="ado-debug-toggle" type="checkbox"> Debug matching logs</label>
        <?php endif; ?>
      </div>
      <button id="ado-create-from-parse" class="button button-primary" type="button" disabled>Create Quote From Last Parsed Scope</button>
      <p id="ado-quote-status" class="ado-muted"></p>
      <pre id="ado-quote-debug" style="display:none;max-height:240px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;"></pre>
      <?php echo do_shortcode('[contact-form]'); ?>
    </div>

    <div class="ado-card">
      <h3>My Quotes</h3>
      <div id="ado-drafts-wrap"><?php echo ado_render_quote_drafts_html($uid); ?></div>
    </div>

    <?php if ($quote_id > 0) : ?>
      <?php echo ado_render_quote_detail($uid, $quote_id); ?>
    <?php endif; ?>

    <script>
    (function($){
      var latestScope = '';
      var nonce = <?php echo wp_json_encode($nonce); ?>;
      var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      var canDebug = <?php echo $is_debug ? 'true' : 'false'; ?>;
      function status(msg, err){ $('#ado-quote-status').text(msg || '').css('color', err ? '#b42318' : '#344054'); }
      function post(action, data, cb){
        $.post(ajaxUrl, Object.assign({action: action, nonce: nonce}, data || {}))
          .done(function(r){ cb(r || {success:false,data:{message:'Request failed'}}); })
          .fail(function(){ cb({success:false,data:{message:'Request failed'}}); });
      }
      function bindQuoteButtons(){
        $('#ado-drafts-wrap .ado-delete-draft').off('click').on('click', function(){
          if (!window.confirm('Delete this quote?')) { return; }
          post('ado_delete_quote_draft', {draft_id: $(this).data('id')}, function(res){
            if (!res.success) { status(res.data && res.data.message ? res.data.message : 'Failed', true); return; }
            $('#ado-drafts-wrap').html((res.data && res.data.drafts_html) ? res.data.drafts_html : '');
            bindQuoteButtons();
            status(res.data && res.data.message ? res.data.message : 'Deleted.', false);
          });
        });
        $('#ado-drafts-wrap .ado-rename-draft').off('click').on('click', function(){
          var n = window.prompt('Rename quote');
          if (!n) { return; }
          post('ado_rename_quote_draft', {draft_id: $(this).data('id'), name: n}, function(res){
            if (!res.success) { status(res.data && res.data.message ? res.data.message : 'Failed', true); return; }
            $('#ado-drafts-wrap').html((res.data && res.data.drafts_html) ? res.data.drafts_html : '');
            bindQuoteButtons();
            status(res.data && res.data.message ? res.data.message : 'Renamed.', false);
          });
        });
      }
      bindQuoteButtons();

      $(document).on('click', '.ado-rerun-match', function(){
        var id = $(this).data('id');
        post('ado_rerun_quote_matching', {quote_id: id}, function(res){
          if (!res.success) { status(res.data && res.data.message ? res.data.message : 'Failed', true); return; }
          if (res.data && res.data.quote_url) { window.location.href = res.data.quote_url; return; }
          window.location.reload();
        });
      });

      $(document).on('click', '.ado-match-review-choice', function(){
        post('ado_resolve_quote_match_review', {
          quote_id: $(this).data('quote-id'),
          line_key: $(this).data('line-key'),
          product_id: $(this).data('product-id')
        }, function(res){
          if (!res.success) { status(res.data && res.data.message ? res.data.message : 'Failed', true); return; }
          if (res.data && res.data.quote_url) { window.location.href = res.data.quote_url; return; }
          window.location.reload();
        });
      });

      $(document).on('click', '.ado-match-review-reject', function(){
        post('ado_resolve_quote_match_review', {
          quote_id: $(this).data('quote-id'),
          line_key: $(this).data('line-key'),
          product_id: 0
        }, function(res){
          if (!res.success) { status(res.data && res.data.message ? res.data.message : 'Failed', true); return; }
          if (res.data && res.data.quote_url) { window.location.href = res.data.quote_url; return; }
          window.location.reload();
        });
      });

      $(document).ajaxSuccess(function(_e,_x,_s,res){
        if (res && res.success && res.data && res.data.download_url_scope) {
          latestScope = res.data.download_url_scope;
          $('#ado-create-from-parse').prop('disabled', false);
          status('Scoped JSON ready. Click "Create Quote From Last Parsed Scope".', false);
        }
      });

      $('#ado-create-from-parse').on('click', function(){
        if (!latestScope) { status('No scoped JSON detected yet.', true); return; }
        var debugOn = canDebug && $('#ado-debug-toggle').is(':checked') ? 1 : 0;
        post('ado_scope_to_quote_cart', {scope_url: latestScope, quote_name: $('#ado-quote-name').val() || '', debug: debugOn}, function(res){
          if (!res.success) {
            status(res.data && res.data.message ? res.data.message : 'Failed', true);
            return;
          }
          if (res.data && res.data.drafts_html) {
            $('#ado-drafts-wrap').html(res.data.drafts_html);
            bindQuoteButtons();
          }
          if (canDebug) {
            var logs = (res.data && res.data.debug_log) ? res.data.debug_log : [];
            if (logs.length) {
              $('#ado-quote-debug').text(JSON.stringify(logs, null, 2)).show();
            } else {
              $('#ado-quote-debug').hide().text('');
            }
          }
          status((res.data && res.data.message ? res.data.message : 'Quote created.') + ' Unmatched: ' + (res.data && res.data.unmatched_count ? res.data.unmatched_count : 0), false);
          if (res.data && res.data.quote_url) {
            window.location.href = res.data.quote_url;
          }
        });
      });
    })(jQuery);
    </script>
    <?php
    return (string) ob_get_clean();
});

function ado_client_quote_dashboard_state_rows(int $user_id): array
{
    $rows = ado_get_quote_drafts($user_id);
    $state = ['new_quotes' => [], 'my_quotes' => []];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = strtolower((string) ($row['status'] ?? 'draft'));
        if (in_array($status, ['assigned', 'accepted', 'submitted', 'ordered'], true)) {
            $state['my_quotes'][] = $row;
        } else {
            $state['new_quotes'][] = $row;
        }
    }
    return $state;
}

add_action('wp_ajax_ado_client_quotes_state', static function (): void {
    $uid = ado_assert_client_ajax();
    wp_send_json_success(['state' => ado_client_quote_dashboard_state_rows($uid)]);
});

add_action('wp_ajax_ado_client_quote_detail_html', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    if ($quote_id <= 0) {
        wp_send_json_error(['message' => 'Quote not found.'], 404);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }
    wp_send_json_success([
        'html' => ado_render_quote_detail($uid, $quote_id),
    ]);
});

add_action('wp_ajax_ado_client_quote_transition', static function (): void {
    $uid = ado_assert_client_ajax();
    $quote_id = (int) ($_POST['quote_id'] ?? 0);
    $target = sanitize_key((string) ($_POST['target_status'] ?? ''));
    $po_number = sanitize_text_field((string) ($_POST['po_number'] ?? ''));
    if ($quote_id <= 0 || !in_array($target, ['assigned', 'accepted', 'submitted'], true)) {
        wp_send_json_error(['message' => 'Invalid quote transition request.'], 400);
    }
    if (!ado_quote_integration()->quote_belongs_to_user($quote_id, $uid) && !current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Quote access denied.'], 403);
    }

    $current = strtolower((string) get_post_meta($quote_id, '_adq_status', true));
    if ($current === '') {
        $current = 'draft';
    }
    $allowed = [
        'assigned' => ['draft', 'review_required', 'ready'],
        'accepted' => ['draft', 'review_required', 'ready', 'assigned'],
        'submitted' => ['draft', 'review_required', 'ready', 'assigned', 'accepted'],
    ];
    if (!in_array($current, (array) ($allowed[$target] ?? []), true)) {
        wp_send_json_error(['message' => 'Transition is not allowed from current quote status.'], 409);
    }
    if ($target === 'submitted' && $po_number === '') {
        wp_send_json_error(['message' => 'PO number is required before submitting.'], 400);
    }

    ado_quote_integration()->update_quote_status($quote_id, $target);
    if ($target === 'submitted') {
        update_post_meta($quote_id, '_adq_po_number', $po_number);
    }
    wp_send_json_success([
        'message' => 'Quote updated.',
        'state' => ado_client_quote_dashboard_state_rows($uid),
    ]);
});

add_shortcode('ado_client_quote_dashboard', static function (): string {
    if (!is_user_logged_in()) {
        return '<p>Please sign in to create quotes.</p>';
    }
    if (!ado_is_client()) {
        return '<p>This area is for client accounts only.</p>';
    }

    $uid = (int) get_current_user_id();
    $state = ado_client_quote_dashboard_state_rows($uid);
    $nonce = wp_create_nonce('ado_quote_nonce');
    $adx_nonce = wp_create_nonce('adx_parse_pdf');

    ob_start();
    ?>
    <div class="ado-client-quote-ui" id="adoClientQuoteUi" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-adx-nonce="<?php echo esc_attr($adx_nonce); ?>">
      <div class="ado-client-quote-shell">
        <section>
          <div class="ado-page-title">My Quotes</div>
          <div class="ado-filter-pills">
            <button class="ado-filter-pill is-active" data-filter="all">All Quotes</button>
            <button class="ado-filter-pill" data-filter="awaiting">Awaiting Approval</button>
            <button class="ado-filter-pill" data-filter="approved">Approved</button>
            <button class="ado-filter-pill" data-filter="drafts">Drafts</button>
            <button class="ado-filter-pill" data-filter="expired">Expired</button>
          </div>
          <div class="ado-quote-grid" id="ado-client-quotes"></div>
        </section>
      </div>
      <div class="ado-preview-backdrop" id="adoClientQuoteBackdrop" hidden></div>
      <div class="ado-upload-backdrop" id="adoClientUploadBackdrop" hidden></div>
      <div class="ado-preview-drawer extracted-session ado-panel" id="adoClientQuoteDrawer" hidden>
        <div class="ado-preview-head">
          <h3 style="margin:0;">Review Extracted Quote</h3>
          <button class="ado-btn" type="button" id="ado-client-close-drawer">Close</button>
        </div>
        <div id="ado-client-drawer-body"></div>
      </div>
      <div class="ado-upload-drawer ado-panel" id="adoClientUploadDrawer" hidden>
        <div class="ado-upload-drawer-head">
          <h3 style="margin:0;">Upload Hardware Schedule PDF</h3>
          <button class="ado-btn" type="button" id="ado-client-upload-drawer-close">Close</button>
        </div>
        <p class="ado-muted">Upload a PDF once. Extraction and quote creation will start automatically.</p>
        <div class="ado-upload-dropzone" id="ado-client-upload-zone">
          <input type="file" id="ado-client-upload-input" accept="application/pdf" hidden>
          <div class="ado-upload-icon" aria-hidden="true"></div>
          <div class="ado-upload-text">Drop your hardware schedule PDF here</div>
          <div class="ado-upload-hint">Or click to browse - PDF up to 25MB</div>
        </div>
        <div class="ado-upload-divider">
          <span>or</span>
          <button class="ado-link-btn" type="button" id="ado-client-enter-manual">Enter manually</button>
        </div>
        <p id="ado-client-upload-status" class="ado-muted" style="margin-top:10px;"></p>
        <div class="ado-parser-hidden" aria-hidden="true">
          <?php echo do_shortcode('[contact-form]'); ?>
        </div>
      </div>
    </div>
    <style>
      .ado-client-quote-shell{display:grid;grid-template-columns:1fr 320px;gap:18px}
      .ado-list-cols{display:grid;grid-template-columns:1fr 1fr;gap:12px}
      .ado-page-title{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;letter-spacing:-.4px;margin:0 0 4px}
      .ado-client-quote-shell{display:block}
      .ado-filter-pills{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;font-size:14px}
      .ado-filter-pills .ado-filter-pill{border:1px solid var(--border);background:transparent;color:var(--text-secondary);padding:6px 14px;border-radius:999px;font-weight:600;cursor:pointer;transition:all .2s ease}
      .ado-filter-pill.is-active{background:var(--accent);border-color:var(--accent);color:#fff}
      .ado-filter-pill:hover{border-color:var(--text-primary);color:var(--text-primary)}
      .ado-quote-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}
      @media (max-width:1024px){.ado-quote-grid{grid-template-columns:1fr}}
      .ado-q-row{border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:10px;background:#fff}
      .ado-q-meta{font-size:12px;color:#6b7280}
      .ado-q-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
      body.ado-preview-open{overflow:hidden}
      #adoClientQuoteUi [hidden]{display:none !important}
      .ado-preview-backdrop{position:fixed;inset:0;background:linear-gradient(140deg,rgba(2,6,21,.78) 0%,rgba(9,14,31,.9) 52%,rgba(4,7,18,.96) 100%);z-index:9990;opacity:0;pointer-events:none;transition:opacity .12s ease-out;will-change:opacity}
      .ado-preview-backdrop.is-open{opacity:1;pointer-events:auto}
      .ado-upload-backdrop{position:fixed;inset:0;background:linear-gradient(125deg,rgba(3,6,16,.72) 0%,rgba(6,10,28,.84) 55%,rgba(8,12,32,.93) 100%);z-index:9988;opacity:0;pointer-events:none;transition:opacity .1s ease-out;will-change:opacity}
      .ado-upload-backdrop.is-open{opacity:1;pointer-events:auto}
      .ado-panel{position:fixed;top:0;right:0;bottom:0;background:#fff;border-left:1px solid #e5e7eb;transform:translate3d(104%,0,0);opacity:1;will-change:transform;animation-fill-mode:forwards;backface-visibility:hidden;transform-style:flat;contain:layout paint style;z-index:10001}
      .ado-panel.is-open{animation:panelIn 180ms cubic-bezier(0.18,0.92,0.28,1.04) forwards}
      .ado-panel.is-closing{animation:panelOut 145ms cubic-bezier(0.42,0.02,0.68,1) forwards}
      @keyframes panelIn{0%{transform:translate3d(104%,0,0)}14%{transform:translate3d(70%,0,0)}84%{transform:translate3d(-.55%,0,0)}100%{transform:translate3d(0,0,0)}}
      @keyframes panelOut{0%{transform:translate3d(0,0,0)}16%{transform:translate3d(.28%,0,0)}86%{transform:translate3d(78%,0,0)}100%{transform:translate3d(104%,0,0)}}
      .ado-preview-drawer{position:fixed;top:0;right:0;bottom:0;width:min(92vw,1480px);max-width:100vw;background:#fff;border-left:1px solid #e5e7eb;box-shadow:-14px 0 34px rgba(15,23,42,.12);z-index:9998;overflow-x:hidden;overflow-y:auto;padding:20px 24px 28px}
      body.admin-bar .ado-preview-drawer{top:32px}
      .ado-preview-drawer.extracted-session{left:auto}
      .ado-preview-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:12px;position:sticky;top:-20px;padding:20px 0 12px;background:#fff;z-index:2}
      #ado-client-drawer-body{overflow-x:hidden}
      #ado-client-drawer-body .ado-quote-review{margin-top:0;max-width:100%;overflow-x:hidden}
      #ado-client-drawer-body .ado-quote-review .qr-layout{max-width:100%;overflow-x:hidden}
      #ado-client-drawer-body .ado-quote-review .qr-main{min-width:0}
      #ado-client-drawer-body .ado-quote-review .qr-door-list,
      #ado-client-drawer-body .ado-quote-review .qr-door-card,
      #ado-client-drawer-body .ado-quote-review .qr-table{max-width:100%}
      #ado-client-drawer-body .ado-quote-review .qr-table{table-layout:fixed}
      #ado-client-drawer-body .ado-quote-review .qr-table th,
      #ado-client-drawer-body .ado-quote-review .qr-table td{word-break:break-word}
      .ado-upload-drawer{position:fixed;top:0;right:0;bottom:0;width:min(460px,100vw);background:#fff;border-left:1px solid #e5e7eb;z-index:10000;overflow:auto;padding:24px 28px;display:flex;flex-direction:column;gap:12px;box-shadow:-14px 0 34px rgba(15,23,42,.12);}
      body.admin-bar .ado-upload-drawer{top:32px;}
      @media (max-width:782px){body.admin-bar .ado-preview-drawer{top:46px}.ado-preview-drawer{width:100vw;padding:16px 16px 24px}.ado-preview-head{top:-16px;padding:16px 0 10px}.ado-upload-drawer{width:100vw}body.admin-bar .ado-upload-drawer{top:46px;}}
      .ado-upload-drawer-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;}
      .ado-link-btn{border:none;background:none;color:#1d4ed8;font-weight:600;font-size:12px;padding:0;cursor:pointer;}
      .ado-upload-dropzone{border:1.5px dashed #cbd5f5;border-radius:16px;padding:32px 20px;text-align:center;background:#f8fafc;margin-top:12px;cursor:pointer;transition:background .2s ease,border .2s ease;}
      .ado-upload-dropzone:hover,.ado-upload-dropzone.is-active{border-color:#1d4ed8;background:#ecf0ff;}
      .ado-upload-icon{width:48px;height:48px;margin:0 auto 8px;border-radius:50%;background:#e0e7ff;position:relative;}
      .ado-upload-icon::before,.ado-upload-icon::after{content:'';position:absolute;left:50%;top:50%;width:18px;height:2px;background:#312e81;border-radius:1px;transform-origin:center;}
      .ado-upload-icon::before{transform:translate(-50%,-50%) rotate(0deg);}
      .ado-upload-icon::after{transform:translate(-50%,-50%) rotate(90deg);}
      .ado-upload-text{font-size:16px;font-weight:700;margin-bottom:4px;color:#0f172a;}
      .ado-upload-hint{font-size:12px;color:#475569;}
      .ado-upload-divider{display:flex;align-items:center;gap:12px;margin-top:14px;font-size:12px;color:#64748b;}
      .ado-upload-divider::before,.ado-upload-divider::after{content:'';flex:1;height:1px;background:#e2e8f0;}
      .ado-parser-hidden{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;}
      @media (max-width:1024px){.ado-client-quote-shell,.ado-list-cols{grid-template-columns:1fr}}
    </style>
    <script>
      (function($){
        var root = $('#adoClientQuoteUi');
        if (!root.length) { return; }
        // Use attr() over jQuery .data() to avoid stale cached values during iterative UI changes.
        var ajaxUrl = root.attr('data-ajax') || root.data('ajax');
        var nonce = root.attr('data-nonce') || root.data('nonce');
        var adxNonce = root.attr('data-adx-nonce');
        var state = <?php echo wp_json_encode($state); ?>;
        var latestScope = '';
        var uploadStatus = $('#ado-client-upload-status');
        var quoteList = $('#ado-client-quotes');
        var filterPills = $('.ado-filter-pill');
        var currentFilter = 'all';
        var filterMap = {
          all: [],
          awaiting: ['submitted', 'assigned'],
          approved: ['accepted', 'ordered'],
          drafts: ['draft', 'review_required', 'ready'],
          expired: ['expired'],
        };
        var drawer = $('#adoClientQuoteDrawer');
        var drawerBackdrop = $('#adoClientQuoteBackdrop');
        var uploadBackdrop = $('#adoClientUploadBackdrop');
        var drawerBody = $('#ado-client-drawer-body');
        var uploadDrawer = $('#adoClientUploadDrawer');
        var uploadDrawerClose = $('#ado-client-upload-drawer-close');
        var newQuoteTrigger = $('#ado-client-new-quote-trigger');
        var panelAnimationTimers = {};
        var panelRequestToken = 0;
        var previewCache = {};
        var previewLoading = {};
        var previewPreloadQueue = [];
        var previewPreloadQueued = {};
        var previewPreloadScheduled = false;
        var previewPreloadRunning = false;

        function panelId(el){
          return el && el.length ? String(el.attr('id') || '') : '';
        }

        function isPanelVisible(el){
          return !!(el && el.length && !el.prop('hidden'));
        }

        function backdropForPanel(el){
          if (!el.length) {
            return drawerBackdrop;
          }
          return el.is(uploadDrawer) ? uploadBackdrop : drawerBackdrop;
        }

        function revealBackdrop(backdrop){
          if (!backdrop.length) {
            return;
          }
          backdrop.prop('hidden', false).removeAttr('hidden');
          requestAnimationFrame(function(){
            backdrop.addClass('is-open');
            if (!$('body').hasClass('ado-preview-open')) {
              $('body').addClass('ado-preview-open');
            }
          });
        }

        function concealBackdrop(backdrop){
          if (!backdrop.length) {
            return;
          }
          backdrop.removeClass('is-open');
          window.setTimeout(function(){
            if (!backdrop.hasClass('is-open')) {
              backdrop.prop('hidden', true).attr('hidden', 'hidden');
            }
            if (!drawerBackdrop.hasClass('is-open') && !uploadBackdrop.hasClass('is-open')) {
              $('body').removeClass('ado-preview-open');
            }
          }, 120);
        }

        function clearPanelAnimation(el){
          var id = panelId(el);
          if (!id) {
            return;
          }
          if (panelAnimationTimers[id]) {
            window.clearTimeout(panelAnimationTimers[id]);
            delete panelAnimationTimers[id];
          }
          el.off('.adoPanel').off('.adoBackdropReveal');
        }

        function showPanel(el, backdrop){
          if (!el || !el.length) {
            return;
          }
          clearPanelAnimation(el);
          el.prop('hidden', false).removeAttr('hidden');
          void el[0].offsetWidth;
          el.removeClass('is-closing').addClass('is-open');
          revealBackdrop(backdrop || drawerBackdrop);
        }

        function hidePanel(el, backdrop, done){
          if (!el || !el.length) {
            if (typeof done === 'function') {
              done();
            }
            return;
          }
          clearPanelAnimation(el);
          if (el.prop('hidden')) {
            el.removeClass('is-open is-closing');
            if (typeof done === 'function') {
              done();
            }
            return;
          }
          var id = panelId(el);
          var finished = false;
          var complete = function(){
            if (finished) {
              return;
            }
            finished = true;
            clearPanelAnimation(el);
            el.removeClass('is-open is-closing').prop('hidden', true).attr('hidden', 'hidden');
            if (!isPanelVisible(drawer) && !isPanelVisible(uploadDrawer)) {
              concealBackdrop(backdrop || drawerBackdrop);
            }
            if (typeof done === 'function') {
              done();
            }
          };
          el.removeClass('is-open').addClass('is-closing');
          el.one('animationend.adoPanel', function(event){
            if (event.target === el[0]) {
              complete();
            }
          });
          panelAnimationTimers[id] = window.setTimeout(complete, 165);
        }

        function closeAllPanelsExcept(targetId, done){
          var target = targetId ? String(targetId) : null;
          var panelsToClose = [];
          [uploadDrawer, drawer].forEach(function(panel){
            if (!panel.length) {
              return;
            }
            if (panelId(panel) === target) {
              return;
            }
            if (isPanelVisible(panel)) {
              panelsToClose.push(panel);
            }
          });
          if (!panelsToClose.length) {
            if (typeof done === 'function') {
              done();
            }
            return;
          }
          var remaining = panelsToClose.length;
          panelsToClose.forEach(function(panel){
            hidePanel(panel, backdropForPanel(panel), function(){
              remaining -= 1;
              if (remaining === 0 && typeof done === 'function') {
                done();
              }
            });
          });
        }

        function openUploadDrawer(){
          if (!uploadDrawer.length) {
            return;
          }
          var requestToken = ++panelRequestToken;
          closeAllPanelsExcept('adoClientUploadDrawer', function(){
            if (requestToken !== panelRequestToken) {
              return;
            }
            showPanel(uploadDrawer, uploadBackdrop);
          });
        }

        function closeUploadDrawer(){
          if (!uploadDrawer.length) {
            return;
          }
          panelRequestToken += 1;
          hidePanel(uploadDrawer, uploadBackdrop);
        }

        function openPreviewDrawer(html){
          if (!drawer.length) {
            return;
          }
          if (typeof html === 'string') {
            drawerBody.html(html);
          }
          var requestToken = ++panelRequestToken;
          closeAllPanelsExcept('adoClientQuoteDrawer', function(){
            if (requestToken !== panelRequestToken) {
              return;
            }
            showPanel(drawer, drawerBackdrop);
          });
        }

        function closePreviewDrawer(){
          if (!drawer.length) {
            return;
          }
          panelRequestToken += 1;
          hidePanel(drawer, drawerBackdrop);
        }

        function post(action, payload, cb){
          $.post(ajaxUrl, Object.assign({action:action, nonce:nonce}, payload || {}))
            .done(function(res){ cb(res || {success:false,data:{message:'Request failed'}}); })
            .fail(function(){ cb({success:false,data:{message:'Request failed'}}); });
        }
        function money(v){
          var n = Number(v || 0);
          return n.toLocaleString(undefined, {style:'currency', currency:'USD'});
        }
        function renderQuoteRows(rows){
          if (!rows || !rows.length) {
            return '<div class="ado-muted">No quotes.</div>';
          }
          return rows.map(function(q){
            var status = (q.status || 'draft').toLowerCase();
            var actions = '<button class="ado-btn" data-view="' + q.id + '">View</button>';
            if (['assigned', 'accepted', 'submitted', 'ordered'].indexOf(status) === -1) {
              actions += '<button class="ado-btn" data-go="assigned" data-id="' + q.id + '">Assign</button>';
              actions += '<button class="ado-btn" data-go="accepted" data-id="' + q.id + '">Accept</button>';
              actions += '<button class="ado-btn primary" data-go="submitted" data-id="' + q.id + '">Submit</button>';
            }
            return '<div class="ado-q-row"><div><strong>' + (q.name || ('Quote #' + q.id)) + '</strong></div>' +
              '<div class="ado-q-meta">' + (q.status || 'draft') + ' - ' + (q.created_at || '') + '</div>' +
              '<div class="ado-q-meta">Doors: ' + (q.door_count || 0) + ' • Items: ' + (q.total_items || 0) + ' • Unmatched: ' + (q.unmatched_count || 0) + '</div>' +
              '<div class="ado-q-meta">Subtotal: ' + money(q.subtotal || 0) + '</div>' +
              '<div class="ado-q-actions">' + actions + '</div></div>';
          }).join('');
        }
        function matchesFilter(row){
          if (!row || currentFilter === 'all') {
            return true;
          }
          var status = (row.status || 'draft').toLowerCase();
          var allowed = filterMap[currentFilter] || [];
          return allowed.length === 0 || allowed.indexOf(status) !== -1;
        }
        function renderQuotes(){
          var rows = (state.new_quotes || []).concat(state.my_quotes || []);
          quoteList.html(renderQuoteRows(rows.filter(matchesFilter)));
        }
        function renderState(){
          renderQuotes();
          preloadAllPreviews();
        }
        function refreshState(){
          post('ado_client_quotes_state', {}, function(res){
            if (!res || !res.success || !res.data || !res.data.state) { return; }
            state = res.data.state;
            renderState();
          });
        }

        function preloadPreview(quoteId, done){
          if (!quoteId || previewCache[quoteId] || previewLoading[quoteId]) {
            if (typeof done === 'function') {
              done();
            }
            return;
          }
          previewLoading[quoteId] = true;
          post('ado_client_quote_detail_html', {quote_id:quoteId}, function(res){
            previewLoading[quoteId] = false;
            if (!res || !res.success || !res.data || !res.data.html) {
              if (typeof done === 'function') {
                done();
              }
              return;
            }
            previewCache[quoteId] = res.data.html;
            if (typeof done === 'function') {
              done();
            }
          });
        }

        function runPreviewPreloadQueue(){
          if (previewPreloadRunning || !previewPreloadQueue.length) {
            return;
          }
          previewPreloadRunning = true;
          var quoteId = previewPreloadQueue.shift();
          delete previewPreloadQueued[quoteId];
          preloadPreview(quoteId, function(){
            previewPreloadRunning = false;
            if (previewPreloadQueue.length) {
              window.setTimeout(runPreviewPreloadQueue, 24);
            }
          });
        }

        function schedulePreviewWarmup(){
          if (previewPreloadScheduled) {
            return;
          }
          previewPreloadScheduled = true;
          var kickoff = function(){
            previewPreloadScheduled = false;
            runPreviewPreloadQueue();
          };
          if (window.requestIdleCallback) {
            window.requestIdleCallback(kickoff, {timeout: 320});
            return;
          }
          window.setTimeout(kickoff, 32);
        }

        function preloadAllPreviews(){
          var rows = (state.new_quotes || []).concat(state.my_quotes || []);
          rows.forEach(function(row){
            var quoteId = row && row.id ? String(row.id) : '';
            if (!quoteId || previewCache[quoteId] || previewLoading[quoteId] || previewPreloadQueued[quoteId]) {
              return;
            }
            previewPreloadQueued[quoteId] = true;
            previewPreloadQueue.push(quoteId);
          });
          schedulePreviewWarmup();
        }

        function setActiveFilter(filter){
          if (!filter) {
            return;
          }
          currentFilter = filter;
          filterPills.removeClass('is-active');
          filterPills.filter('[data-filter=\"' + filter + '\"]').addClass('is-active');
        }

        renderState();
        filterPills.on('click', function(){
          var filter = $(this).data('filter');
          if (!filter || filter === currentFilter) {
            return;
          }
          setActiveFilter(filter);
          renderQuotes();
        });

        var dropzone = $('#ado-client-upload-zone');
        var fileInput = $('#ado-client-upload-input');
        var maxPdfBytes = 25 * 1024 * 1024;

        function setUploadStatus(message, isError){
          uploadStatus.text(message || '').css('color', isError ? '#b42318' : '#0f766e');
        }

        function getAdxNonce(){
          var fromForm = $('#adx-form input[name=\"adx_nonce\"]').val();
          if (fromForm) { return String(fromForm); }
          if (window.ADX_UI_CONFIG && window.ADX_UI_CONFIG.nonce) { return String(window.ADX_UI_CONFIG.nonce); }
          return String(adxNonce || '');
        }

        function submitPDFFile(file){
          if (!file) { return; }
          var isPdf = (file.type === 'application/pdf') || /\.pdf$/i.test((file.name || ''));
          if (!isPdf) {
            setUploadStatus('Please upload a PDF file.', true);
            return;
          }
          if (file.size > maxPdfBytes) {
            setUploadStatus('PDF must be 25MB or smaller.', true);
            return;
          }
          setUploadStatus('Uploading hardware schedule...');
          var formData = new FormData();
          formData.append('action', 'adx_parse_pdf');
          formData.append('pdf', file, file.name);
          formData.append('adx_nonce', getAdxNonce());
          formData.append('adx_debug_mode', '0');
          $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res){
              if (!res || !res.success) {
                var message = (res && res.data && res.data.message) ? res.data.message : 'Extraction failed.';
                setUploadStatus(message + ' Please retry with a clean PDF.', true);
                return;
              }
              var scopeUrl = res.data && res.data.download_url_scope ? res.data.download_url_scope : '';
              if (!scopeUrl) {
                setUploadStatus('Extraction completed but no scoped JSON URL was returned.', true);
                return;
              }
              stageScope(scopeUrl, function(staged){
                if (!staged || !staged.ok) {
                  setUploadStatus((staged && staged.message) ? staged.message : 'Failed to stage scoped payload.', true);
                  return;
                }
                handleScopeCreate(staged.scope_token);
              });
            },
            error: function(xhr){
              var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                ? xhr.responseJSON.data.message
                : 'Extraction request failed.';
              setUploadStatus(message + ' Please retry upload.', true);
            }
          });
        }

        function highlightDropzone(active){
          if (dropzone.length) {
            dropzone.toggleClass('is-active', !!active);
          }
        }

        dropzone.on('click', function(){
          fileInput.trigger('click');
        });
        dropzone.on('dragover', function(event){
          event.preventDefault();
          highlightDropzone(true);
        });
        dropzone.on('dragleave drop', function(event){
          event.preventDefault();
          highlightDropzone(false);
          if (event.type === 'drop') {
            var transfer = event.originalEvent && event.originalEvent.dataTransfer ? event.originalEvent.dataTransfer : null;
            if (transfer && transfer.files && transfer.files.length) {
              submitPDFFile(transfer.files[0]);
            }
          }
        });
        fileInput.on('change', function(){
          var file = this.files && this.files[0];
          submitPDFFile(file);
          this.value = '';
        });

        setUploadStatus('Drop your hardware schedule PDF here or click to browse to start extraction.');

        function stageScope(scopeUrl, cb){
          post('ado_stage_scoped_payload', {scope_url: scopeUrl}, function(res){
            if (!res || !res.success || !res.data || !res.data.scope_token) {
              cb({ok:false, message:(res && res.data && res.data.message) ? res.data.message : 'Failed to stage scoped payload.'});
              return;
            }
            cb({ok:true, scope_token:res.data.scope_token});
          });
        }

        function handleScopeCreate(scopeToken){
          var scopeTokenValue = scopeToken || '';
          if (!scopeTokenValue) {
            setUploadStatus('Scoped payload token missing. Please retry upload.', true);
            return;
          }
          setUploadStatus('PDF extracted. Creating quote now...');
          post('ado_scope_token_to_quote_cart', {scope_token: scopeTokenValue, quote_name: ''}, function(createRes){
            if (!createRes || !createRes.success) {
              setUploadStatus((createRes && createRes.data && createRes.data.message) ? createRes.data.message + ' Click retry on the upload form.' : 'Failed to create quote. Please retry upload.', true);
              return;
            }
            setUploadStatus('Quote created successfully.');
            // New quotes are created as drafts; switch to Drafts so the new quote is visible deterministically.
            setActiveFilter('drafts');
            refreshState();
            if (createRes.data && createRes.data.quote_id) {
              post('ado_client_quote_detail_html', {quote_id:createRes.data.quote_id}, function(detailRes){
                if (!detailRes || !detailRes.success) { return; }
                var detailHtml = detailRes.data && detailRes.data.html ? detailRes.data.html : '';
                if (detailHtml) {
                  previewCache[createRes.data.quote_id] = detailHtml;
                }
                closeUploadDrawer();
                openPreviewDrawer(detailHtml);
            });
            }
          });
        }

        root.on('click', '[data-view]', function(){
          var quoteId = $(this).data('view');
          if (!quoteId) { return; }
          closeUploadDrawer();
          var cachedHtml = previewCache[quoteId];
          if (cachedHtml) {
            openPreviewDrawer(cachedHtml);
            return;
          }
          post('ado_client_quote_detail_html', {quote_id:quoteId}, function(res){
            if (!res || !res.success) {
              window.alert((res && res.data && res.data.message) ? res.data.message : 'Failed to load quote preview.');
              return;
            }
            var html = res.data && res.data.html ? res.data.html : '';
            if (html) {
              previewCache[quoteId] = html;
            }
            openPreviewDrawer(html);
          });
        });

        root.on('click', '[data-go]', function(){
          var target = $(this).data('go');
          var quoteId = $(this).data('id');
          if (!target || !quoteId) { return; }
          var po = '';
          if (target === 'submitted') {
            po = window.prompt('Enter PO number to submit this quote:') || '';
            if (!$.trim(po)) { return; }
          }
          var before = {new_quotes:(state.new_quotes || []).slice(), my_quotes:(state.my_quotes || []).slice()};
          var moving = null;
          state.new_quotes = (state.new_quotes || []).filter(function(row){
            if (String(row.id) === String(quoteId)) { moving = row; return false; }
            return true;
          });
          if (moving) {
            moving = Object.assign({}, moving, {status:target});
            state.my_quotes = [moving].concat(state.my_quotes || []);
          }
          renderState();
          post('ado_client_quote_transition', {quote_id:quoteId, target_status:target, po_number:po}, function(res){
            if (!res || !res.success) {
              state = before;
              renderState();
              window.alert((res && res.data && res.data.message) ? res.data.message : 'Transition failed.');
              return;
            }
            if (res.data && res.data.state) {
              state = res.data.state;
              renderState();
            } else {
              refreshState();
            }
          });
        });

        if (newQuoteTrigger.length) {
          newQuoteTrigger.on('click', function(event){
            event.preventDefault();
            openUploadDrawer();
          });
        }
        if (uploadDrawerClose.length) {
          uploadDrawerClose.on('click', function(){
            closeUploadDrawer();
          });
        }
        $(document).on('keydown', function(keyEvent){
          if (keyEvent.key !== 'Escape') {
            return;
          }
          if (isPanelVisible(drawer)) {
            closePreviewDrawer();
            return;
          }
          if (isPanelVisible(uploadDrawer)) {
            closeUploadDrawer();
          }
        });

        $('#ado-client-close-drawer').on('click', function(){ closePreviewDrawer(); });
        drawerBackdrop.on('click', function(){
          panelRequestToken += 1;
          closeAllPanelsExcept(null, function(){});
        });
        $(document).on('ado:quote-transitioned', function(_ev, res){
          if (res && res.data && res.data.state) {
            state = res.data.state;
            renderState();
          } else {
            refreshState();
          }
          closePreviewDrawer();
        });
      })(jQuery);
    </script>
    <?php
    return (string) ob_get_clean();
});

add_action('admin_menu', static function (): void {
    add_submenu_page(
        'woocommerce',
        'ADO Match Tools',
        'ADO Match Tools',
        'manage_woocommerce',
        'ado-match-tools',
        'ado_render_match_tools_page'
    );
});

function ado_render_match_tools_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Access denied.');
    }

    $preview = null;
    $rerun_result = null;
    if (!empty($_POST['ado_match_preview_nonce']) && wp_verify_nonce((string) $_POST['ado_match_preview_nonce'], 'ado_match_preview')) {
        $json = wp_unslash((string) ($_POST['scoped_json'] ?? ''));
        $payload = json_decode($json, true);
        if (is_array($payload)) {
            $tmp = ado_quote_integration()->create_quote_from_payload(get_current_user_id(), $payload, ['name' => 'Match Preview Temp', 'debug' => true]);
            if (!empty($tmp['ok']) && !empty($tmp['quote_id'])) {
                $qid = (int) $tmp['quote_id'];
                $preview = [
                    'quote_id' => $qid,
                    'unmatched' => get_post_meta($qid, '_adq_unmatched_items', true),
                    'debug_log' => get_post_meta($qid, '_adq_match_log', true),
                ];
                wp_delete_post($qid, true);
            } else {
                $preview = $tmp;
            }
        } else {
            $preview = ['ok' => false, 'message' => 'Invalid JSON payload.'];
        }
    }
    if (!empty($_POST['ado_rerun_nonce']) && wp_verify_nonce((string) $_POST['ado_rerun_nonce'], 'ado_rerun_match')) {
        $quote_id = (int) ($_POST['quote_id'] ?? 0);
        if ($quote_id > 0) {
            $rerun_result = ado_quote_integration()->rerun_matching($quote_id, true);
        }
    }
    ?>
    <div class="wrap">
      <h1>ADO Product Match Tools</h1>
      <h2>Preview Matching (paste scoped JSON)</h2>
      <form method="post">
        <?php wp_nonce_field('ado_match_preview', 'ado_match_preview_nonce'); ?>
        <textarea name="scoped_json" rows="16" style="width:100%;" placeholder='{"result":{"doors":[]}}'><?php echo esc_textarea((string) ($_POST['scoped_json'] ?? '')); ?></textarea>
        <p><button class="button button-primary" type="submit">Preview Matches</button></p>
      </form>
      <?php if ($preview !== null) : ?>
        <pre style="background:#111;color:#eee;padding:12px;max-height:320px;overflow:auto;"><?php echo esc_html(wp_json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
      <?php endif; ?>

      <h2>Re-run Matching For Quote</h2>
      <form method="post">
        <?php wp_nonce_field('ado_rerun_match', 'ado_rerun_nonce'); ?>
        <p><label>Quote ID <input type="number" name="quote_id" min="1" required></label></p>
        <p><button class="button" type="submit">Re-run Matching</button></p>
      </form>
      <?php if ($rerun_result !== null) : ?>
        <pre style="background:#111;color:#eee;padding:12px;max-height:320px;overflow:auto;"><?php echo esc_html(wp_json_encode($rerun_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
      <?php endif; ?>
    </div>
    <?php
}

