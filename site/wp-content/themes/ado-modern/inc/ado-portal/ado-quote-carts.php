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
        'unmatched_count' => count($unmatched),
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
            $line['line_total'] = $unit * max(1, $qty);
            $line['unit_price'] = $unit;
        } else {
            $line['product_name'] = $product ? (string) $product->get_name() : ('Product #' . $product_id);
            $line['sku'] = $product ? (string) $product->get_sku() : '';
            $line['line_total'] = $product ? ((float) $product->get_price('edit') * max(1, $qty)) : 0;
            $line['unit_price'] = $product ? (float) $product->get_price('edit') : 0.0;
        }
        $door_map[$door_id]['lines'][] = $line;
    }

    return array_values($door_map);
}

function ado_quote_door_notes(int $quote_id): array
{
    return ado_quote_integration()->get_quote_door_notes($quote_id);
}

function ado_quote_line_adjustments(int $quote_id): array
{
    return ado_quote_integration()->get_quote_line_adjustments($quote_id);
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
    echo '<table class="ado-table"><thead><tr><th>Door</th><th>Model</th><th>Description</th><th>Qty</th><th>Raw Line</th></tr></thead><tbody>';
    foreach ($unmatched as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '<tr>';
        echo '<td>' . esc_html((string) ($row['door_number'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['model'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['description'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['qty'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['raw_line'] ?? '')) . '</td>';
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

function ado_quote_group_match_state(array $group, array $unmatched_by_door): string
{
    $door = (array) ($group['door'] ?? []);
    if (empty($door['has_operator'])) {
        return 'out-of-scope';
    }
    $door_id = (string) ($door['door_id'] ?? '');
    $door_number = (string) ($door['door_number'] ?? '');
    $unmatched = $unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? [];
    $lines = array_values((array) ($group['lines'] ?? []));

    if (!$lines && $unmatched) {
        return 'none';
    }
    if (!$lines) {
        return 'empty';
    }

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        if ((string) ($line['line_type'] ?? '') === 'manual') {
            return 'manual';
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

    return 'full';
}

function ado_quote_group_match_label(string $state, int $unmatched_count): string
{
    if ($state === 'out-of-scope') {
        return 'Out of scope';
    }
    if ($state === 'manual') {
        return 'Manual pricing';
    }
    if ($state === 'none') {
        return 'Needs review';
    }
    if ($state === 'fuzzy') {
        return $unmatched_count > 0 ? 'Partial match' : 'Needs review';
    }
    if ($state === 'empty') {
        return 'No items';
    }
    return 'Matched';
}

function ado_quote_review_summary(int $quote_id): array
{
    $groups = ado_quote_grouped_lines($quote_id);
    $unmatched_by_door = ado_quote_unmatched_by_door($quote_id);
    $summary = [
        'doors_total' => count($groups),
        'doors_in_scope' => 0,
        'matched_doors' => 0,
        'fuzzy_doors' => 0,
        'unknown_doors' => 0,
        'manual_doors' => 0,
        'out_of_scope_doors' => 0,
        'review_items' => 0,
        'manual_lines' => 0,
    ];

    foreach ($groups as $group) {
        $state = ado_quote_group_match_state($group, $unmatched_by_door);
        if ($state !== 'out-of-scope') {
            $summary['doors_in_scope']++;
        }
        if ($state === 'full') {
            $summary['matched_doors']++;
        } elseif ($state === 'fuzzy') {
            $summary['fuzzy_doors']++;
        } elseif ($state === 'none') {
            $summary['unknown_doors']++;
        } elseif ($state === 'manual') {
            $summary['manual_doors']++;
        } elseif ($state === 'out-of-scope') {
            $summary['out_of_scope_doors']++;
        }
        foreach ((array) ($group['lines'] ?? []) as $line) {
            if (is_array($line) && (string) ($line['line_type'] ?? '') === 'manual') {
                $summary['manual_lines']++;
            }
        }
    }

    $summary['review_items'] = $summary['fuzzy_doors'] + $summary['unknown_doors'] + $summary['manual_doors'];
    return $summary;
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
    $match_review = ado_render_quote_match_review($quote_id);
    $debug_log = ado_render_quote_debug_log($quote_id);
    $unmatched_by_door = ado_quote_unmatched_by_door($quote_id);
    $scope_file = wp_basename((string) ($row['scope_url'] ?? ''));
    $status = strtolower((string) ($row['status'] ?? 'draft'));

    ob_start();
    ?>
    <style>
      .ado-quote-review{--ado-bg:#f3f4f6;--ado-surface:#fff;--ado-surface-2:#f8fafc;--ado-border:#e5e7eb;--ado-border-light:#eef2f7;--ado-text:#0f172a;--ado-muted:#64748b;--ado-accent:#1d4ed8;--ado-accent-soft:#eff6ff;--ado-green:#059669;--ado-green-soft:#ecfdf5;--ado-green-border:#a7f3d0;--ado-warn:#d97706;--ado-warn-soft:#fffbeb;--ado-warn-border:#fcd34d;--ado-danger:#dc2626;--ado-danger-soft:#fef2f2;--ado-danger-border:#fca5a5;}
      .ado-quote-review{margin-top:24px;color:var(--ado-text);}
      .ado-quote-review *{box-sizing:border-box;}
      .ado-quote-review .qr-hero{margin-bottom:18px;}
      .ado-quote-review .qr-title{font-size:28px;line-height:1.1;font-weight:800;letter-spacing:-0.03em;margin:0 0 6px;}
      .ado-quote-review .qr-subtitle{margin:0;color:var(--ado-muted);font-size:14px;}
      .ado-quote-review .qr-chip-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
      .ado-quote-review .qr-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;background:var(--ado-surface);border:1px solid var(--ado-border);font-size:12px;font-weight:700;color:var(--ado-text);}
      .ado-quote-review .qr-chip.status-draft{background:var(--ado-accent-soft);border-color:#bfdbfe;color:var(--ado-accent);}
      .ado-quote-review .qr-chip.status-submitted,
      .ado-quote-review .qr-chip.status-ordered{background:var(--ado-green-soft);border-color:var(--ado-green-border);color:var(--ado-green);}
      .ado-quote-review .qr-banner{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:18px 20px;border-radius:16px;background:linear-gradient(135deg,var(--ado-green-soft),#f8fffb);border:1px solid var(--ado-green-border);margin-bottom:22px;}
      .ado-quote-review .qr-banner-title{margin:0 0 4px;font-size:15px;font-weight:800;color:#065f46;}
      .ado-quote-review .qr-banner-copy{margin:0;color:#047857;font-size:13px;}
      .ado-quote-review .qr-banner-stats{display:flex;gap:10px;flex-wrap:wrap;}
      .ado-quote-review .qr-stat{min-width:86px;padding:8px 12px;border-radius:12px;background:#fff;border:1px solid var(--ado-green-border);text-align:center;}
      .ado-quote-review .qr-stat-value{display:block;font-size:18px;font-weight:800;color:var(--ado-text);}
      .ado-quote-review .qr-stat-label{display:block;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ado-muted);margin-top:2px;}
      .ado-quote-review .qr-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:22px;align-items:start;}
      .ado-quote-review .qr-main{min-width:0;}
      .ado-quote-review .qr-sidebar{position:sticky;top:96px;display:flex;flex-direction:column;gap:16px;}
      .ado-quote-review .qr-card{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:16px;overflow:hidden;box-shadow:0 2px 10px rgba(15,23,42,.05);}
      .ado-quote-review .qr-card-header{padding:16px 18px;border-bottom:1px solid var(--ado-border);display:flex;align-items:center;justify-content:space-between;gap:12px;}
      .ado-quote-review .qr-card-title{margin:0;font-size:15px;font-weight:800;}
      .ado-quote-review .qr-card-body{padding:16px 18px;}
      .ado-quote-review .qr-actions{display:flex;flex-direction:column;gap:10px;}
      .ado-quote-review .qr-button,.ado-quote-review .qr-button:visited{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 14px;border-radius:10px;border:1px solid var(--ado-border);background:#fff;color:var(--ado-text);font-weight:700;text-decoration:none;cursor:pointer;}
      .ado-quote-review .qr-button.primary{background:var(--ado-accent);border-color:var(--ado-accent);color:#fff;}
      .ado-quote-review .qr-button.secondary{background:var(--ado-surface-2);}
      .ado-quote-review .qr-summary-list{display:flex;flex-direction:column;gap:12px;}
      .ado-quote-review .qr-summary-row{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:var(--ado-muted);}
      .ado-quote-review .qr-summary-row strong{color:var(--ado-text);}
      .ado-quote-review .qr-summary-total{margin-top:12px;padding-top:14px;border-top:1px solid var(--ado-border);display:flex;justify-content:space-between;align-items:baseline;}
      .ado-quote-review .qr-summary-total strong{font-size:28px;line-height:1;font-weight:800;}
      .ado-quote-review .qr-flags{display:flex;flex-direction:column;gap:8px;}
      .ado-quote-review .qr-flag{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;font-size:12px;font-weight:700;}
      .ado-quote-review .qr-flag.warn{background:var(--ado-warn-soft);border:1px solid var(--ado-warn-border);color:#92400e;}
      .ado-quote-review .qr-flag.neutral{background:var(--ado-surface-2);border:1px solid var(--ado-border);color:var(--ado-muted);}
      .ado-quote-review .qr-door-tabs{display:flex;gap:10px;overflow:auto;padding:4px 2px 14px;margin-bottom:0;}
      .ado-quote-review .qr-door-tab{min-width:170px;padding:12px 14px;border-radius:14px;border:1px solid var(--ado-border);background:var(--ado-surface);text-align:left;cursor:pointer;transition:.15s ease;}
      .ado-quote-review .qr-door-tab:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(15,23,42,.06);}
      .ado-quote-review .qr-door-tab.active{border-color:#93c5fd;background:var(--ado-accent-soft);box-shadow:0 0 0 2px rgba(29,78,216,.08);}
      .ado-quote-review .qr-door-tab.match-full{border-left:4px solid var(--ado-green);}
      .ado-quote-review .qr-door-tab.match-fuzzy{border-left:4px solid var(--ado-warn);}
      .ado-quote-review .qr-door-tab.match-none{border-left:4px solid var(--ado-danger);}
      .ado-quote-review .qr-door-tab.match-empty{border-left:4px solid #94a3b8;}
      .ado-quote-review .qr-door-tab-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
      .ado-quote-review .qr-door-num{font-size:14px;font-weight:800;color:var(--ado-text);}
      .ado-quote-review .qr-door-status{font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--ado-muted);}
      .ado-quote-review .qr-door-tab-label{font-size:12px;color:var(--ado-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
      .ado-quote-review .qr-door-tab-meta{margin-top:8px;font-size:11px;color:var(--ado-muted);display:flex;justify-content:space-between;gap:8px;}
      .ado-quote-review .qr-panels{display:block;}
      .ado-quote-review .qr-panel{display:none;}
      .ado-quote-review .qr-panel.active{display:block;}
      .ado-quote-review .qr-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px;border-bottom:1px solid var(--ado-border);}
      .ado-quote-review .qr-panel-title{margin:0 0 4px;font-size:20px;font-weight:800;}
      .ado-quote-review .qr-panel-copy{margin:0;color:var(--ado-muted);font-size:13px;}
      .ado-quote-review .qr-panel-metrics{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;}
      .ado-quote-review .qr-metric{padding:8px 10px;border-radius:12px;background:var(--ado-surface-2);border:1px solid var(--ado-border);text-align:center;min-width:88px;}
      .ado-quote-review .qr-metric strong{display:block;font-size:15px;}
      .ado-quote-review .qr-metric span{display:block;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ado-muted);margin-top:2px;}
      .ado-quote-review .qr-table-wrap{padding:18px;}
      .ado-quote-review table.qr-table{width:100%;border-collapse:collapse;}
      .ado-quote-review .qr-table th{padding:0 0 10px;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--ado-muted);text-align:left;border-bottom:1px solid var(--ado-border);}
      .ado-quote-review .qr-table td{padding:12px 0;border-bottom:1px solid var(--ado-border-light);vertical-align:top;}
      .ado-quote-review .qr-table tr:last-child td{border-bottom:none;}
      .ado-quote-review .qr-product{font-weight:700;color:var(--ado-text);}
      .ado-quote-review .qr-product-sub{display:block;margin-top:4px;font-size:12px;color:var(--ado-muted);}
      .ado-quote-review .qr-pill{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;background:var(--ado-surface-2);border:1px solid var(--ado-border);font-size:11px;font-weight:700;color:var(--ado-text);}
      .ado-quote-review .qr-pill.model{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}
      .ado-quote-review .qr-pill.fuzzy{background:var(--ado-warn-soft);border-color:var(--ado-warn-border);color:#92400e;}
      .ado-quote-review .qr-pill.none{background:var(--ado-danger-soft);border-color:var(--ado-danger-border);color:#991b1b;}
      .ado-quote-review .qr-empty{padding:24px 18px;color:var(--ado-muted);}
      .ado-quote-review .ado-card{margin-top:18px;}
      @media (max-width: 1100px){.ado-quote-review .qr-layout{grid-template-columns:1fr;}.ado-quote-review .qr-sidebar{position:static;}}
      @media (max-width: 700px){.ado-quote-review .qr-panel-head{flex-direction:column;}.ado-quote-review .qr-door-tabs{padding-bottom:10px;}.ado-quote-review .qr-table-wrap{overflow:auto;}.ado-quote-review .qr-title{font-size:24px;}}
    </style>
    <div class="ado-quote-review" data-quote-id="<?php echo esc_attr((string) $quote_id); ?>">
      <div class="qr-hero">
        <h2 class="qr-title"><?php echo esc_html((string) $row['name']); ?></h2>
        <p class="qr-subtitle">Review the extracted quote by door, verify hardware, and then submit the quote to checkout. Each tab below represents one project door with its own hardware grouping.</p>
        <div class="qr-chip-row">
          <span class="qr-chip status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst((string) $row['status'])); ?></span>
          <span class="qr-chip"><?php echo esc_html((string) count($groups)); ?> doors</span>
          <span class="qr-chip"><?php echo esc_html((string) $row['total_items']); ?> items</span>
          <?php if ($scope_file !== '') : ?>
            <span class="qr-chip"><?php echo esc_html($scope_file); ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="qr-banner">
        <div>
          <h3 class="qr-banner-title">Scoped quote extracted and grouped by door</h3>
          <p class="qr-banner-copy">Use the tabs to inspect each door separately. Any unmatched or uncertain hardware stays visible in review until it is corrected.</p>
        </div>
        <div class="qr-banner-stats">
          <div class="qr-stat"><span class="qr-stat-value"><?php echo esc_html((string) count($groups)); ?></span><span class="qr-stat-label">Doors</span></div>
          <div class="qr-stat"><span class="qr-stat-value"><?php echo esc_html((string) $row['total_items']); ?></span><span class="qr-stat-label">Items</span></div>
          <div class="qr-stat"><span class="qr-stat-value"><?php echo esc_html((string) ($row['unmatched_count'] ?? 0)); ?></span><span class="qr-stat-label">Unmatched</span></div>
        </div>
      </div>

      <div class="qr-layout">
        <div class="qr-main">
          <?php if ($groups) : ?>
            <div class="qr-door-tabs" role="tablist" aria-label="Quote doors">
              <?php foreach ($groups as $index => $group) :
                  $door = (array) ($group['door'] ?? []);
                  $totals = ado_quote_group_totals($group);
                  $state = ado_quote_group_match_state($group, $unmatched_by_door);
                  $door_id = (string) ($door['door_id'] ?? ('door-' . $index));
                  $door_number = (string) ($door['door_number'] ?? ('Door ' . ($index + 1)));
                  $door_label = (string) ($door['door_label'] ?? ('Door ' . $door_number));
                  $unmatched_rows = $unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? [];
              ?>
                <button type="button" class="qr-door-tab match-<?php echo esc_attr($state); ?><?php echo $index === 0 ? ' active' : ''; ?>" data-door-tab="<?php echo esc_attr($door_id); ?>" role="tab" aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                  <div class="qr-door-tab-top">
                    <span class="qr-door-num"><?php echo esc_html($door_number); ?></span>
                    <span class="qr-door-status"><?php echo esc_html(ado_quote_group_match_label($state, count($unmatched_rows))); ?></span>
                  </div>
                  <div class="qr-door-tab-label"><?php echo esc_html($door_label); ?></div>
                  <div class="qr-door-tab-meta">
                    <span><?php echo esc_html((string) $totals['count']); ?> lines</span>
                    <span><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) $totals['subtotal']])); ?></span>
                  </div>
                </button>
              <?php endforeach; ?>
            </div>

            <div class="qr-panels">
              <?php foreach ($groups as $index => $group) :
                  $door = (array) ($group['door'] ?? []);
                  $lines = array_values((array) ($group['lines'] ?? []));
                  $totals = ado_quote_group_totals($group);
                  $state = ado_quote_group_match_state($group, $unmatched_by_door);
                  $door_id = (string) ($door['door_id'] ?? ('door-' . $index));
                  $door_number = (string) ($door['door_number'] ?? ('Door ' . ($index + 1)));
                  $door_label = (string) ($door['door_label'] ?? ('Door ' . $door_number));
                  $unmatched_rows = $unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? [];
              ?>
                <section class="qr-card qr-panel<?php echo $index === 0 ? ' active' : ''; ?>" data-door-panel="<?php echo esc_attr($door_id); ?>" role="tabpanel">
                  <div class="qr-panel-head">
                    <div>
                      <h3 class="qr-panel-title"><?php echo esc_html($door_label); ?></h3>
                      <p class="qr-panel-copy">
                        <?php
                        $panel_meta = [];
                        if (!empty($door['location'])) {
                            $panel_meta[] = 'Location: ' . (string) $door['location'];
                        }
                        if (!empty($door['desc'])) {
                            $panel_meta[] = (string) $door['desc'];
                        }
                        if (!empty($door['has_operator'])) {
                            $panel_meta[] = 'Operator scope';
                        }
                        echo esc_html($panel_meta ? implode(' | ', $panel_meta) : 'Door-specific hardware extracted from the uploaded schedule.');
                        ?>
                      </p>
                    </div>
                    <div class="qr-panel-metrics">
                      <div class="qr-metric"><strong><?php echo esc_html((string) $totals['count']); ?></strong><span>Lines</span></div>
                      <div class="qr-metric"><strong><?php echo esc_html((string) $totals['qty']); ?></strong><span>Total Qty</span></div>
                      <div class="qr-metric"><strong><?php echo esc_html((string) count($unmatched_rows)); ?></strong><span>Needs Review</span></div>
                    </div>
                  </div>
                  <div class="qr-table-wrap">
                    <?php if ($lines) : ?>
                      <table class="qr-table">
                        <thead>
                          <tr>
                            <th>Hardware</th>
                            <th>SKU</th>
                            <th>Model</th>
                            <th>Qty</th>
                            <th>Unit</th>
                            <th>Line Total</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($lines as $line) :
                              $match_method = strtolower((string) ($line['match_method'] ?? ''));
                              $confidence = (float) ($line['match_confidence'] ?? 0);
                              $pill_class = ($match_method === 'title_contains' || $match_method === 'fuzzy' || ($confidence > 0 && $confidence < 95)) ? ' fuzzy' : '';
                              $unit_price = max(0.0, (float) ($line['line_total'] ?? 0) / max(1, (int) ($line['qty'] ?? 1)));
                          ?>
                            <tr>
                              <td>
                                <span class="qr-product"><?php echo esc_html((string) ($line['product_name'] ?? '')); ?></span>
                                <?php if (!empty($line['description']) || !empty($line['raw_line'])) : ?>
                                  <span class="qr-product-sub"><?php echo esc_html((string) ($line['description'] ?? $line['raw_line'] ?? '')); ?></span>
                                <?php endif; ?>
                              </td>
                              <td><span class="qr-pill model<?php echo esc_attr($pill_class); ?>"><?php echo esc_html((string) ($line['sku'] ?? '')); ?></span></td>
                              <td><span class="qr-pill model<?php echo esc_attr($pill_class); ?>"><?php echo esc_html((string) ($line['model'] ?? $line['source_model'] ?? '')); ?></span></td>
                              <td><?php echo esc_html((string) ((int) ($line['qty'] ?? 0))); ?></td>
                              <td><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => $unit_price])); ?></td>
                              <td><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) ($line['line_total'] ?? 0)])); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else : ?>
                      <div class="qr-empty">No matched hardware lines are available for this door yet.</div>
                    <?php endif; ?>
                  </div>
                </section>
              <?php endforeach; ?>
            </div>
          <?php else : ?>
            <div class="qr-card">
              <div class="qr-card-body">
                <p class="qr-empty">No grouped items are available for this quote.</p>
              </div>
            </div>
          <?php endif; ?>

          <?php echo $flash_banner; ?>
          <?php echo $match_review; ?>
          <?php echo $debug_log; ?>
        </div>

        <aside class="qr-sidebar">
          <div class="qr-card">
            <div class="qr-card-header">
              <h3 class="qr-card-title">Quote Summary</h3>
            </div>
            <div class="qr-card-body">
              <div class="qr-summary-list">
                <div class="qr-summary-row"><span>Quote status</span><strong><?php echo esc_html(ucfirst((string) $row['status'])); ?></strong></div>
                <div class="qr-summary-row"><span>Doors scoped</span><strong><?php echo esc_html((string) count($groups)); ?></strong></div>
                <div class="qr-summary-row"><span>Hardware quantity</span><strong><?php echo esc_html((string) $row['total_items']); ?></strong></div>
                <div class="qr-summary-row"><span>Unmatched lines</span><strong><?php echo esc_html((string) ($row['unmatched_count'] ?? 0)); ?></strong></div>
              </div>
              <div class="qr-summary-total">
                <span>Subtotal</span>
                <strong><?php echo wp_kses_post((string) $row['subtotal_html']); ?></strong>
              </div>
            </div>
          </div>

          <div class="qr-card">
            <div class="qr-card-header">
              <h3 class="qr-card-title">Actions</h3>
            </div>
            <div class="qr-card-body">
              <div class="qr-actions">
                <?php if ((string) $row['status'] !== 'ordered') : ?>
                  <a class="qr-button primary" href="<?php echo esc_url(ado_quote_checkout_url($quote_id)); ?>">Checkout This Quote</a>
                <?php elseif ((int) $row['order_id'] > 0) : ?>
                  <a class="qr-button primary" href="<?php echo esc_url(wc_get_endpoint_url('view-order', (string) ((int) $row['order_id']), wc_get_page_permalink('myaccount'))); ?>">Open Project Order #<?php echo esc_html((string) ((int) $row['order_id'])); ?></a>
                <?php endif; ?>
                <?php if ($can_rerun) : ?>
                  <button class="qr-button secondary ado-rerun-match" type="button" data-id="<?php echo esc_attr((string) $quote_id); ?>">Re-run Matching</button>
                <?php endif; ?>
                <a class="qr-button secondary" href="<?php echo esc_url(home_url('/portal/quotes/')); ?>">Back to My Quotes</a>
              </div>
            </div>
          </div>

          <div class="qr-card">
            <div class="qr-card-header">
              <h3 class="qr-card-title">Review Flags</h3>
            </div>
            <div class="qr-card-body">
              <div class="qr-flags">
                <div class="qr-flag <?php echo !empty($row['unmatched_count']) ? 'warn' : 'neutral'; ?>">
                  <span>Unmatched hardware lines</span>
                  <span><?php echo esc_html((string) ($row['unmatched_count'] ?? 0)); ?></span>
                </div>
                <div class="qr-flag neutral">
                  <span>Grouped door tabs</span>
                  <span><?php echo esc_html((string) count($groups)); ?></span>
                </div>
                <div class="qr-flag neutral">
                  <span>Quote source</span>
                  <span><?php echo esc_html($scope_file !== '' ? $scope_file : 'Scoped JSON'); ?></span>
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
    <script>
      (function(){
        var root = document.querySelector('.ado-quote-review[data-quote-id="<?php echo esc_js((string) $quote_id); ?>"]');
        if (!root) { return; }
        var tabs = root.querySelectorAll('[data-door-tab]');
        var panels = root.querySelectorAll('[data-door-panel]');
        function activate(doorId){
          tabs.forEach(function(tab){
            var active = tab.getAttribute('data-door-tab') === doorId;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
          });
          panels.forEach(function(panel){
            panel.classList.toggle('active', panel.getAttribute('data-door-panel') === doorId);
          });
        }
        tabs.forEach(function(tab){
          tab.addEventListener('click', function(){
            activate(tab.getAttribute('data-door-tab'));
          });
        });
        if (tabs.length && !root.querySelector('.qr-door-tab.active')) {
          activate(tabs[0].getAttribute('data-door-tab'));
        }
      })();
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

    if ($product_id > 0) {
        $selected = null;
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
    $new_unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $new_unmatched = is_array($new_unmatched) ? $new_unmatched : [];
    ado_set_quote_unmatched_flash($uid, $quote_id, $new_unmatched);
    wp_send_json_success([
        'message' => $message,
        'quote_url' => ado_quote_url($quote_id),
        'unmatched_count' => count($new_unmatched),
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
    foreach (['corrected_model', 'manual_description', 'manual_sku', 'manual_unit_price'] as $key) {
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
