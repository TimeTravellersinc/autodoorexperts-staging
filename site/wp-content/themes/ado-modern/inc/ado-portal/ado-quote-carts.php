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
        $product = $product_id > 0 ? wc_get_product($product_id) : null;
        $line['product_name'] = $product ? (string) $product->get_name() : ('Product #' . $product_id);
        $line['sku'] = $product ? (string) $product->get_sku() : '';
        $line['line_total'] = $product ? ((float) $product->get_price('edit') * max(1, $qty)) : 0;
        $door_map[$door_id]['lines'][] = $line;
    }

    return array_values($door_map);
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
            echo '<button class="button ado-load-draft" data-id="' . esc_attr((string) $quote['id']) . '">Load to Cart</button>';
            echo '<button class="button ado-rename-draft" data-id="' . esc_attr((string) $quote['id']) . '">Rename</button>';
            echo '<button class="button ado-delete-draft" data-id="' . esc_attr((string) $quote['id']) . '">Delete</button>';
        }
        echo '</div>';
        echo '</div>';
    }
    return (string) ob_get_clean();
}

function ado_render_quote_unmatched_banner(int $quote_id): string
{
    $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
    $unmatched = is_array($unmatched) ? $unmatched : [];
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

    ob_start();
    echo '<div class="ado-card"><h3 style="margin-top:0;">' . esc_html((string) $row['name']) . '</h3>';
    echo '<div class="ado-row"><span class="ado-chip">' . esc_html(ucfirst((string) $row['status'])) . '</span><span class="ado-chip">Items: ' . esc_html((string) $row['total_items']) . '</span><span class="ado-chip">Subtotal: ' . wp_kses_post((string) $row['subtotal_html']) . '</span></div>';
    echo '<div class="ado-row">';
    if ((string) $row['status'] !== 'ordered') {
        echo '<button class="button button-primary ado-load-draft" data-id="' . esc_attr((string) $quote_id) . '">Load Quote to Cart</button>';
        echo '<a class="button" href="' . esc_url(wc_get_checkout_url()) . '">Go to Checkout</a>';
    } elseif ((int) $row['order_id'] > 0) {
        echo '<a class="button" href="' . esc_url(wc_get_endpoint_url('view-order', (string) ((int) $row['order_id']), wc_get_page_permalink('myaccount'))) . '">Open Project Order #' . esc_html((string) ((int) $row['order_id'])) . '</a>';
    }
    if ($can_rerun) {
        echo '<button class="button ado-rerun-match" data-id="' . esc_attr((string) $quote_id) . '">Re-run Matching</button>';
    }
    echo '</div>';
    echo '</div>';

    echo ado_render_quote_unmatched_banner($quote_id);

    if (!$groups) {
        echo '<div class="ado-card"><p class="ado-muted">No grouped items are available for this quote.</p></div>';
    } else {
        foreach ($groups as $group) {
            $door = (array) ($group['door'] ?? []);
            $lines = (array) ($group['lines'] ?? []);
            echo '<div class="ado-card">';
            echo '<h4 style="margin-top:0;">' . esc_html((string) ($door['door_label'] ?? ('Door ' . (string) ($door['door_number'] ?? 'Unknown')))) . '</h4>';
            if (!empty($door['location'])) {
                echo '<p class="ado-muted">Location: ' . esc_html((string) $door['location']) . '</p>';
            }
            echo '<table class="ado-table"><thead><tr><th>Product</th><th>SKU</th><th>Model</th><th>Qty</th><th>Est. Line Total</th></tr></thead><tbody>';
            foreach ($lines as $line) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($line['product_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($line['sku'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($line['model'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ((int) ($line['qty'] ?? 0))) . '</td>';
                echo '<td>' . wp_kses_post(ado_quote_totals_html(['subtotal' => (float) ($line['line_total'] ?? 0)])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
    }

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
    wp_send_json_success([
        'message' => 'Quote created from scoped JSON.',
        'quote_id' => $quote_id,
        'quote_url' => ado_quote_url($quote_id),
        'drafts_html' => ado_render_quote_drafts_html($uid),
        'unmatched_count' => (int) ($created['unmatched_count'] ?? 0),
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
        'message' => 'Quote loaded into cart.',
        'cart_url' => (string) ($loaded['cart_url'] ?? wc_get_cart_url()),
        'checkout_url' => (string) ($loaded['checkout_url'] ?? wc_get_checkout_url()),
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
    wp_send_json_success([
        'message' => (string) ($rerun['message'] ?? 'Re-run completed.'),
        'quote_url' => ado_quote_url($quote_id),
        'drafts_html' => ado_render_quote_drafts_html($uid),
        'debug_log' => $debug ? array_values((array) ($rerun['debug_log'] ?? [])) : [],
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
        $('#ado-drafts-wrap .ado-load-draft').off('click').on('click', function(){
          post('ado_load_quote_draft', {draft_id: $(this).data('id')}, function(res){
            if (!res.success) { status(res.data && res.data.message ? res.data.message : 'Failed', true); return; }
            window.location.href = (res.data && res.data.checkout_url) ? res.data.checkout_url : '<?php echo esc_js(wc_get_checkout_url()); ?>';
          });
        });
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
