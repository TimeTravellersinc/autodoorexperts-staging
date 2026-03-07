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
    echo '<table class="ado-table"><thead><tr><th>Door</th><th>Model</th><th>Description</th><th>Qty</th><th>Reason</th><th>Raw Line</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '<tr>';
        echo '<td>' . esc_html((string) ($row['door_number'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['model'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['description'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['qty'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['excluded_reason'] ?? $row['reason_code'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['raw_line'] ?? '')) . '</td>';
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
    $excluded_by_door = ado_quote_excluded_by_door($quote_id);
    $summary = [
        'doors_total' => count($groups),
        'doors_in_scope' => count($groups),
        'matched_doors' => 0,
        'empty_doors' => 0,
        'manual_lines' => 0,
        'dropped_rows' => 0,
    ];

    foreach ($groups as $group) {
        $lines = array_values((array) ($group['lines'] ?? []));
        if ($lines) {
            $summary['matched_doors']++;
        } else {
            $summary['empty_doors']++;
        }
        $door = (array) ($group['door'] ?? []);
        $door_id = (string) ($door['door_id'] ?? '');
        $door_number = (string) ($door['door_number'] ?? '');
        $summary['dropped_rows'] += count((array) ($unmatched_by_door[$door_id] ?? $unmatched_by_door['door-number:' . $door_number] ?? []));
        $summary['dropped_rows'] += count((array) ($excluded_by_door[$door_id] ?? $excluded_by_door['door-number:' . $door_number] ?? []));
        foreach ($lines as $line) {
            if (is_array($line) && (string) ($line['line_type'] ?? '') === 'manual') {
                $summary['manual_lines']++;
            }
        }
    }
    return $summary;
}

function ado_quote_flag_class(string $state): string
{
    if ($state === 'manual' || $state === 'none') {
        return 'danger';
    }
    if ($state === 'fuzzy') {
        return 'warn';
    }
    return 'neutral';
}

function ado_quote_flag_label(string $state): string
{
    if ($state === 'manual') {
        return 'Manual pricing';
    }
    if ($state === 'none') {
        return 'Unknown model';
    }
    if ($state === 'fuzzy') {
        return 'Fuzzy match';
    }
    return 'Review';
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

    $candidates = array_values((array) ($row['candidate_products'] ?? []));
    $corrected_model = (string) ($adjustment['corrected_model'] ?? '');
    $manual_description = (string) ($adjustment['manual_description'] ?? ($row['description'] ?? ''));
    $manual_sku = (string) ($adjustment['manual_sku'] ?? '');
    $manual_price = isset($adjustment['manual_unit_price']) && $adjustment['manual_unit_price'] !== null ? number_format((float) $adjustment['manual_unit_price'], 2, '.', '') : '';

    ob_start();
    ?>
    <div class="qr-inline-review qr-inline-review-<?php echo esc_attr(strtolower((string) ($row['reason_code'] ?? 'review'))); ?>">
      <div class="qr-inline-copy">
        <strong><?php echo esc_html((string) ($row['model'] ?? 'Unknown model')); ?></strong>
        <span><?php echo esc_html((string) ($row['description'] ?? $row['raw_line'] ?? '')); ?></span>
      </div>
      <?php if ($candidates) : ?>
        <div class="qr-inline-candidates">
          <?php foreach ($candidates as $candidate) :
              if (!is_array($candidate) || (int) ($candidate['product_id'] ?? 0) <= 0) {
                  continue;
              }
          ?>
            <button type="button" class="qr-mini-btn ado-match-review-choice" data-quote-id="<?php echo esc_attr((string) $quote_id); ?>" data-line-key="<?php echo esc_attr($line_key); ?>" data-product-id="<?php echo esc_attr((string) ((int) ($candidate['product_id'] ?? 0))); ?>">
              <?php echo esc_html((string) ($candidate['sku'] ?? ('Product #' . (int) ($candidate['product_id'] ?? 0)))); ?>
            </button>
          <?php endforeach; ?>
          <button type="button" class="qr-mini-btn secondary ado-match-review-reject" data-quote-id="<?php echo esc_attr((string) $quote_id); ?>" data-line-key="<?php echo esc_attr($line_key); ?>">None match</button>
        </div>
      <?php endif; ?>
      <div class="qr-inline-manual">
        <input class="qr-input qr-adjust-model" type="text" placeholder="Corrected model" value="<?php echo esc_attr($corrected_model); ?>">
        <input class="qr-input qr-adjust-desc" type="text" placeholder="Manual description" value="<?php echo esc_attr($manual_description); ?>">
        <input class="qr-input qr-adjust-sku" type="text" placeholder="Manual SKU" value="<?php echo esc_attr($manual_sku); ?>">
        <input class="qr-input qr-adjust-price" type="text" placeholder="Manual unit price" value="<?php echo esc_attr($manual_price); ?>">
        <button type="button" class="qr-mini-btn primary ado-save-line-adjustment" data-quote-id="<?php echo esc_attr((string) $quote_id); ?>" data-line-key="<?php echo esc_attr($line_key); ?>">Save</button>
      </div>
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
    $summary = ado_quote_review_summary($quote_id);
    $scope_file = wp_basename((string) ($row['scope_url'] ?? ''));
    $status = strtolower((string) ($row['status'] ?? 'draft'));
    $door_notes = ado_quote_door_notes($quote_id);
    $line_adjustments = ado_quote_line_adjustments($quote_id);
    $nonce = wp_create_nonce('ado_quote_nonce');

    ob_start();
    ?>
    <style>
      .ado-quote-review{--ado-surface:#fff;--ado-surface-2:#f7f8fa;--ado-border:#e2e5ea;--ado-border-light:#eef0f3;--ado-accent:#1a56db;--ado-accent-soft:#eff4ff;--ado-accent-glow:rgba(26,86,219,.15);--ado-green:#059669;--ado-green-soft:#ecfdf5;--ado-green-border:#a7f3d0;--ado-warn:#d97706;--ado-warn-soft:#fffbeb;--ado-warn-border:#fcd34d;--ado-danger:#dc2626;--ado-danger-soft:#fef2f2;--ado-danger-border:#fca5a5;--ado-text:#0f172a;--ado-muted:#94a3b8;--ado-secondary:#475569;--ado-radius:12px;--ado-radius-sm:7px;}
      .ado-quote-review{margin-top:24px;color:var(--ado-text);} .ado-quote-review *{box-sizing:border-box;}
      .ado-quote-review .qr-hero{margin-bottom:18px;} .ado-quote-review .qr-title{font-size:22px;font-weight:800;letter-spacing:-.5px;margin:0 0 3px;} .ado-quote-review .qr-subtitle{font-size:13px;color:var(--ado-muted);margin:0;}
      .ado-quote-review .qr-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;} .ado-quote-review .qr-chip{display:inline-flex;align-items:center;gap:5px;background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:5px;padding:4px 10px;font-size:11px;font-weight:700;color:var(--ado-secondary);} .ado-quote-review .qr-chip.status-draft{background:var(--ado-accent-soft);border-color:#bfdbfe;color:var(--ado-accent);} .ado-quote-review .qr-chip.status-submitted,.ado-quote-review .qr-chip.status-ordered{background:var(--ado-green-soft);border-color:var(--ado-green-border);color:var(--ado-green);}
      .ado-quote-review .qr-banner{background:linear-gradient(135deg,var(--ado-green-soft),#f0fdf4);border:1px solid var(--ado-green-border);border-radius:var(--ado-radius);padding:14px 18px;display:flex;align-items:center;gap:14px;margin-bottom:22px;} .ado-quote-review .qr-banner-main{flex:1;} .ado-quote-review .qr-banner-title{font-weight:700;color:#065f46;font-size:14px;margin:0 0 2px;} .ado-quote-review .qr-banner-copy{font-size:12px;color:#047857;margin:0;} .ado-quote-review .qr-banner-stats{display:flex;gap:14px;flex-shrink:0;} .ado-quote-review .qr-stat{text-align:center;padding:6px 14px;background:#fff;border:1px solid var(--ado-green-border);border-radius:6px;} .ado-quote-review .qr-stat strong{display:block;font-size:17px;font-weight:800;} .ado-quote-review .qr-stat span{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ado-muted);margin-top:1px;}
      .ado-quote-review .qr-layout{display:grid;grid-template-columns:1fr 310px;gap:20px;align-items:flex-start;} .ado-quote-review .qr-sidebar{position:sticky;top:74px;display:flex;flex-direction:column;gap:14px;}
      .ado-quote-review .qr-sidecard{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:var(--ado-radius);overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.05);} .ado-quote-review .qr-sidehead{padding:14px 18px 12px;border-bottom:1px solid var(--ado-border);display:flex;align-items:center;justify-content:space-between;} .ado-quote-review .qr-sidetitle{font-size:14px;font-weight:700;margin:0;} .ado-quote-review .qr-sidebody{padding:14px 18px;} .ado-quote-review .qr-siderow{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--ado-border-light);font-size:13px;} .ado-quote-review .qr-siderow:last-child{border-bottom:none;} .ado-quote-review .qr-total{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:var(--ado-text);margin-top:2px;} .ado-quote-review .qr-total span{font-size:13px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.06em;} .ado-quote-review .qr-total strong{font-size:24px;font-weight:800;color:#fff;}
      .ado-quote-review .qr-flag-list{display:flex;flex-direction:column;gap:6px;} .ado-quote-review .qr-flag-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;font-size:12px;cursor:pointer;border:1px solid transparent;} .ado-quote-review .qr-flag-item.warn{background:var(--ado-warn-soft);border-color:var(--ado-warn-border);color:#92400e;} .ado-quote-review .qr-flag-item.danger{background:var(--ado-danger-soft);border-color:var(--ado-danger-border);color:#991b1b;} .ado-quote-review .qr-flag-item.neutral{background:var(--ado-surface-2);border-color:var(--ado-border);color:var(--ado-secondary);} .ado-quote-review .qr-flag-item strong{margin-left:auto;}
      .ado-quote-review .qr-btn,.ado-quote-review .qr-btn:visited{display:inline-flex;align-items:center;justify-content:center;width:100%;padding:12px;border-radius:var(--ado-radius-sm);text-decoration:none;font-weight:700;border:1px solid var(--ado-border);background:var(--ado-surface);color:var(--ado-text);cursor:pointer;} .ado-quote-review .qr-btn.primary{background:var(--ado-accent);color:#fff;border-color:var(--ado-accent);} .ado-quote-review .qr-btn+.qr-btn{margin-top:8px;}
      .ado-quote-review .qr-door-list{display:flex;flex-direction:column;gap:10px;} .ado-quote-review .qr-door-card{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:var(--ado-radius);overflow:hidden;transition:box-shadow .18s ease;} .ado-quote-review .qr-door-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);} .ado-quote-review .qr-door-card.match-full{border-left:3px solid var(--ado-green);} .ado-quote-review .qr-door-card.match-fuzzy{border-left:3px solid var(--ado-warn);} .ado-quote-review .qr-door-card.match-manual,.ado-quote-review .qr-door-card.match-none{border-left:3px solid var(--ado-danger);} .ado-quote-review .qr-door-card.match-out-of-scope,.ado-quote-review .qr-door-card.match-empty{border-left:3px solid var(--ado-muted);opacity:.85;}
      .ado-quote-review .qr-door-header{display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;user-select:none;background:var(--ado-surface);} .ado-quote-review .qr-door-num{width:36px;height:36px;border-radius:8px;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;} .ado-quote-review .qr-door-card.match-full .qr-door-num{background:var(--ado-green-soft);color:var(--ado-green);} .ado-quote-review .qr-door-card.match-fuzzy .qr-door-num{background:var(--ado-warn-soft);color:var(--ado-warn);} .ado-quote-review .qr-door-card.match-manual .qr-door-num,.ado-quote-review .qr-door-card.match-none .qr-door-num{background:var(--ado-danger-soft);color:var(--ado-danger);} .ado-quote-review .qr-door-card.match-out-of-scope .qr-door-num,.ado-quote-review .qr-door-card.match-empty .qr-door-num{background:#f0f1f3;color:var(--ado-muted);} .ado-quote-review .qr-door-title{flex:1;} .ado-quote-review .qr-door-title strong{display:block;font-size:13.5px;} .ado-quote-review .qr-door-title span{display:block;font-size:11.5px;color:var(--ado-muted);margin-top:1px;}
      .ado-quote-review .qr-door-tag{font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--ado-accent-soft);color:var(--ado-accent);} .ado-quote-review .qr-door-tag.out-scope{background:#f0f1f3;color:var(--ado-muted);border:1px solid var(--ado-border);} .ado-quote-review .qr-door-badge{font-size:10.5px;font-weight:700;letter-spacing:.05em;padding:3px 9px;border-radius:20px;display:flex;align-items:center;gap:4px;} .ado-quote-review .qr-door-card.match-full .qr-door-badge{background:var(--ado-green-soft);color:var(--ado-green);border:1px solid var(--ado-green-border);} .ado-quote-review .qr-door-card.match-fuzzy .qr-door-badge{background:var(--ado-warn-soft);color:var(--ado-warn);border:1px solid var(--ado-warn-border);} .ado-quote-review .qr-door-card.match-manual .qr-door-badge,.ado-quote-review .qr-door-card.match-none .qr-door-badge{background:var(--ado-danger-soft);color:var(--ado-danger);border:1px solid var(--ado-danger-border);} .ado-quote-review .qr-door-card.match-out-of-scope .qr-door-badge,.ado-quote-review .qr-door-card.match-empty .qr-door-badge{background:#f0f1f3;color:var(--ado-muted);border:1px solid var(--ado-border);} .ado-quote-review .qr-door-total{font-size:14px;font-weight:800;min-width:90px;text-align:right;} .ado-quote-review .qr-door-chevron{color:var(--ado-muted);transition:transform .2s ease;} .ado-quote-review .qr-door-card.open .qr-door-chevron{transform:rotate(90deg);} .ado-quote-review .qr-door-body{border-top:1px solid var(--ado-border);padding:14px 16px;display:none;flex-direction:column;gap:10px;background:var(--ado-surface-2);} .ado-quote-review .qr-door-card.open .qr-door-body{display:flex;}
      .ado-quote-review .qr-table{width:100%;border-collapse:collapse;font-size:12.5px;} .ado-quote-review .qr-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ado-muted);padding:0 0 7px;text-align:left;border-bottom:1px solid var(--ado-border);} .ado-quote-review .qr-table td{padding:8px 0;border-bottom:1px solid var(--ado-border-light);vertical-align:top;} .ado-quote-review .qr-table tr:last-child td{border-bottom:none;} .ado-quote-review .qr-model{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:5px;padding:3px 8px;color:var(--ado-secondary);display:inline-block;} .ado-quote-review .qr-model.fuzzy{background:var(--ado-warn-soft);border-color:var(--ado-warn-border);color:var(--ado-warn);} .ado-quote-review .qr-model.none{background:var(--ado-danger-soft);border-color:var(--ado-danger-border);color:var(--ado-danger);} .ado-quote-review .qr-desc{font-weight:500;color:var(--ado-text);} .ado-quote-review .qr-desc.subtle{color:var(--ado-muted);}
      .ado-quote-review .qr-inline-review{padding:10px 12px;border-radius:6px;display:flex;flex-direction:column;gap:10px;} .ado-quote-review .qr-inline-review-no_candidates,.ado-quote-review .qr-inline-review-manual_price{background:var(--ado-danger-soft);border:1px solid var(--ado-danger-border);} .ado-quote-review .qr-inline-review-ambiguous,.ado-quote-review .qr-inline-review-low_confidence{background:var(--ado-warn-soft);border:1px solid var(--ado-warn-border);} .ado-quote-review .qr-inline-copy{display:flex;flex-direction:column;gap:4px;font-size:12px;} .ado-quote-review .qr-inline-copy span{color:var(--ado-secondary);} .ado-quote-review .qr-inline-candidates,.ado-quote-review .qr-inline-manual{display:flex;gap:8px;flex-wrap:wrap;} .ado-quote-review .qr-mini-btn{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:4px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;} .ado-quote-review .qr-mini-btn.primary{background:var(--ado-accent);border-color:var(--ado-accent);color:#fff;} .ado-quote-review .qr-mini-btn.secondary{background:transparent;} .ado-quote-review .qr-input{background:#fff;border:1px solid var(--ado-border);border-radius:5px;padding:7px 9px;font-size:12px;min-width:150px;outline:none;} .ado-quote-review .qr-input:focus,.ado-quote-review .qr-notes:focus,.ado-quote-review .qr-po-input:focus{border-color:var(--ado-accent);box-shadow:0 0 0 3px var(--ado-accent-glow);} .ado-quote-review .qr-notes-row{display:flex;gap:10px;align-items:flex-start;padding-top:8px;border-top:1px solid var(--ado-border-light);} .ado-quote-review .qr-notes-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--ado-muted);width:90px;flex-shrink:0;padding-top:8px;} .ado-quote-review .qr-notes-wrap{flex:1;} .ado-quote-review .qr-notes{width:100%;background:#fff;border:1px solid var(--ado-border);border-radius:6px;padding:7px 10px;font-size:12.5px;color:var(--ado-secondary);resize:vertical;min-height:56px;} .ado-quote-review .qr-po-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--ado-muted);margin-bottom:5px;} .ado-quote-review .qr-po-input{background:var(--ado-surface);border:1px solid var(--ado-border);border-radius:var(--ado-radius-sm);font-size:13.5px;padding:9px 12px;outline:none;width:100%;margin-bottom:8px;} .ado-quote-review .qr-note{font-size:11.5px;color:var(--ado-secondary);line-height:1.5;padding:8px 10px;background:var(--ado-warn-soft);border:1px solid var(--ado-warn-border);border-radius:6px;margin-bottom:8px;} .ado-quote-review .ado-card{margin-top:18px;}
      @media (max-width:1100px){.ado-quote-review .qr-layout{grid-template-columns:1fr;}.ado-quote-review .qr-sidebar{position:static;}} @media (max-width:700px){.ado-quote-review .qr-banner{flex-direction:column;align-items:flex-start;}.ado-quote-review .qr-door-header{flex-wrap:wrap;}.ado-quote-review .qr-inline-manual{flex-direction:column;}.ado-quote-review .qr-input{min-width:100%;}}
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
      <div class="qr-banner"><div class="qr-banner-main"><div class="qr-banner-title">Quote is built from WooCommerce matches only</div><div class="qr-banner-copy">Only scoped items that resolved to a WooCommerce product are included below. Everything else is dropped from the quote and stored in a separate internal log.</div></div><div class="qr-banner-stats"><div class="qr-stat"><strong><?php echo esc_html((string) $summary['matched_doors']); ?></strong><span>With Matches</span></div><div class="qr-stat"><strong><?php echo esc_html((string) $summary['empty_doors']); ?></strong><span>No Matches</span></div><div class="qr-stat"><strong><?php echo esc_html((string) $summary['dropped_rows']); ?></strong><span>Dropped</span></div></div></div>
      <div class="qr-layout">
        <div class="qr-main">
          <div class="qr-door-list">
            <?php foreach ($groups as $index => $group) :
                $door = (array) ($group['door'] ?? []);
                $lines = array_values((array) ($group['lines'] ?? []));
                $totals = ado_quote_group_totals($group);
                $state = ado_quote_group_match_state($group, $unmatched_by_door);
                $door_id = (string) ($door['door_id'] ?? ('door-' . $index));
                $door_number = (string) ($door['door_number'] ?? ('Door ' . ($index + 1)));
                $door_label = (string) ($door['door_label'] ?? ('Door ' . $door_number));
                $note = (string) ($door_notes[$door_id] ?? ($door['notes'] ?? ''));
                $open = $index === 0;
            ?>
            <div class="qr-door-card match-<?php echo esc_attr($state); ?><?php echo $open ? ' open' : ''; ?>" id="qr-door-<?php echo esc_attr($door_id); ?>" data-door-id="<?php echo esc_attr($door_id); ?>">
              <div class="qr-door-header">
                <div class="qr-door-num"><?php echo esc_html($door_number); ?></div>
                <div class="qr-door-title"><strong><?php echo esc_html($door_label); ?></strong><span><?php echo esc_html(trim(implode(' | ', array_filter([(string) ($door['location'] ?? ''), (string) ($door['desc'] ?? '')])))); ?></span></div>
                <span class="qr-door-tag"><?php echo esc_html(!empty($door['door_type']) ? (string) $door['door_type'] : 'Scoped door'); ?></span>
                <span class="qr-door-badge"><?php echo esc_html($lines ? 'Matched' : 'No matched items'); ?></span>
                <div class="qr-door-total"><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) $totals['subtotal']])); ?></div>
                <svg class="qr-door-chevron" width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 01.708 0l6 6a.5.5 0 010 .708l-6 6a.5.5 0 01-.708-.708L10.293 8 4.646 2.354a.5.5 0 010-.708z" clip-rule="evenodd"/></svg>
              </div>
              <div class="qr-door-body">
                <?php if ($lines) : ?><table class="qr-table"><thead><tr><th>Model</th><th>Description</th><th style="text-align:center">Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead><tbody><?php foreach ($lines as $line) : $line_state = ((string) ($line['line_type'] ?? '') === 'manual') ? 'none' : ((((float) ($line['match_confidence'] ?? 0)) > 0 && ((float) ($line['match_confidence'] ?? 0)) < 95) ? 'fuzzy' : ''); ?><tr><td><span class="qr-model<?php echo $line_state ? ' ' . esc_attr($line_state) : ''; ?>"><?php echo esc_html((string) ($line['display_model'] ?? $line['sku'] ?? $line['model'] ?? $line['source_model'] ?? '')); ?></span></td><td><span class="qr-desc"><?php echo esc_html((string) ($line['display_description'] ?? $line['product_name'] ?? $line['description'] ?? '')); ?></span><?php if (!empty($line['line_type']) && $line['line_type'] === 'manual') : ?><span class="qr-desc subtle"><br>Manual pricing line</span><?php endif; ?></td><td style="text-align:center"><?php echo esc_html((string) ((int) ($line['qty'] ?? 0))); ?></td><td><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) ($line['unit_price'] ?? 0)])); ?></td><td><?php echo wp_kses_post(ado_quote_totals_html(['subtotal' => (float) ($line['line_total'] ?? 0)])); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
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
          <div class="qr-sidecard"><div class="qr-sidehead"><div class="qr-sidetitle">Quote Summary</div><span style="font-size:11px;color:var(--ado-muted)">Matched products only</span></div><div class="qr-sidebody"><div class="qr-siderow"><span>Project</span><strong><?php echo esc_html((string) $row['name']); ?></strong></div><div class="qr-siderow"><span>Scoped doors</span><strong><?php echo esc_html((string) $summary['doors_in_scope']); ?></strong></div><div class="qr-siderow"><span>Doors with matches</span><strong><?php echo esc_html((string) $summary['matched_doors']); ?></strong></div><div class="qr-siderow"><span>Doors without matches</span><strong><?php echo esc_html((string) $summary['empty_doors']); ?></strong></div><div class="qr-siderow"><span>Dropped items</span><strong><?php echo esc_html((string) $summary['dropped_rows']); ?></strong></div><div class="qr-siderow"><span>Hardware quantity</span><strong><?php echo esc_html((string) $row['total_items']); ?></strong></div></div><div class="qr-total"><span>Est. Total</span><strong><?php echo wp_kses_post((string) $row['subtotal_html']); ?></strong></div></div>
          <div class="qr-sidecard"><div class="qr-sidehead"><div class="qr-sidetitle">Submit &amp; Approve</div></div><div class="qr-sidebody"><div class="qr-po-label">Purchase Order Number</div><input class="qr-po-input" type="text" placeholder="e.g. PO-2026-0041" disabled><div class="qr-note">Submitting locks in the current quote structure. Manual-priced or unresolved items can still be followed up separately.</div><?php if ((string) $row['status'] !== 'ordered') : ?><a class="qr-btn primary" href="<?php echo esc_url(ado_quote_checkout_url($quote_id)); ?>">Checkout This Quote</a><?php elseif ((int) $row['order_id'] > 0) : ?><a class="qr-btn primary" href="<?php echo esc_url(wc_get_endpoint_url('view-order', (string) ((int) $row['order_id']), wc_get_page_permalink('myaccount'))); ?>">Open Project Order #<?php echo esc_html((string) ((int) $row['order_id'])); ?></a><?php endif; ?><?php if ($can_rerun) : ?><button class="qr-btn ado-rerun-match" type="button" data-id="<?php echo esc_attr((string) $quote_id); ?>">Re-run Matching</button><?php endif; ?><a class="qr-btn" href="<?php echo esc_url(home_url('/portal/quotes/')); ?>">Back to My Quotes</a></div></div>
        </aside>
      </div>
    </div>
    <script>
      (function($){
        var root = $('.ado-quote-review[data-quote-id="<?php echo esc_js((string) $quote_id); ?>"]');
        if (!root.length) { return; }
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        root.on('click', '.qr-door-header', function(){ $(this).closest('.qr-door-card').toggleClass('open'); });
        root.on('click', '[data-scroll-door]', function(){ var id = $(this).data('scroll-door'); var card = $('#qr-door-' + id); if (!card.length) { return; } card.addClass('open'); card[0].scrollIntoView({behavior:'smooth', block:'center'}); });
        root.on('click', '.ado-save-door-note', function(){ var button = $(this); var doorId = button.data('door-id'); var note = root.find('.qr-notes[data-door-id="' + doorId + '"]').val() || ''; $.post(ajaxUrl, {action:'ado_save_quote_door_note', nonce:nonce, quote_id:button.data('quote-id'), door_id:doorId, note:note}).done(function(res){ if (!res || !res.success) { window.alert((res && res.data && res.data.message) ? res.data.message : 'Failed to save note.'); return; } button.text('Saved'); setTimeout(function(){ button.text('Save note'); }, 1200); }).fail(function(){ window.alert('Failed to save note.'); }); });
        root.on('click', '.ado-save-line-adjustment', function(){ var button = $(this); var wrap = button.closest('.qr-inline-review'); $.post(ajaxUrl, {action:'ado_save_quote_line_adjustment', nonce:nonce, quote_id:button.data('quote-id'), line_key:button.data('line-key'), corrected_model:wrap.find('.qr-adjust-model').val() || '', manual_description:wrap.find('.qr-adjust-desc').val() || '', manual_sku:wrap.find('.qr-adjust-sku').val() || '', manual_unit_price:wrap.find('.qr-adjust-price').val() || ''}).done(function(res){ if (!res || !res.success) { window.alert((res && res.data && res.data.message) ? res.data.message : 'Failed to save line adjustment.'); return; } if (res.data && res.data.quote_url) { window.location.href = res.data.quote_url; return; } window.location.reload(); }).fail(function(){ window.alert('Failed to save line adjustment.'); }); });
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
