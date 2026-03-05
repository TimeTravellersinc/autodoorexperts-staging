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
    if (strpos($scope_url, $uploads['baseurl']) !== 0) { return ''; }
    $rel = ltrim(substr($scope_url, strlen($uploads['baseurl'])), '/');
    return trailingslashit($uploads['basedir']) . $rel;
}

function ado_extract_item_candidates(array $item): array {
    $candidates = [];
    foreach (['catalog', 'desc', 'raw'] as $k) {
        if (empty($item[$k]) || !is_string($item[$k])) { continue; }
        $v = strtoupper(trim($item[$k]));
        if ($v !== '') { $candidates[] = $v; }
        if (preg_match_all('/\b[A-Z0-9]{2,}(?:[-\/][A-Z0-9]{1,})+\b/', $v, $m)) {
            foreach ($m[0] as $token) { $candidates[] = strtoupper(trim($token)); }
        }
        if (preg_match_all('/\b[A-Z0-9]{3,8}\b/', $v, $m2)) {
            foreach ($m2[0] as $token) { $candidates[] = strtoupper(trim($token)); }
        }
    }
    return array_values(array_unique(array_filter($candidates)));
}

function ado_is_valid_catalog_token(string $token): bool {
    $token = strtoupper(trim($token));
    if ($token === '' || strlen($token) > 32) {
        return false;
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/]+$/', $token)) {
        return false;
    }
    // Avoid words like "AUTOMATIC" becoming SKU placeholders.
    if (!preg_match('/\d/', $token)) {
        return false;
    }
    return true;
}

function ado_get_or_create_placeholder_product(array $item): int {
    if (!function_exists('wc_get_product_id_by_sku') || !class_exists('WC_Product_Simple')) {
        return 0;
    }
    $candidates = ado_extract_item_candidates($item);
    $sku = '';
    foreach ($candidates as $cand) {
        if (ado_is_valid_catalog_token($cand)) {
            $sku = $cand;
            break;
        }
    }
    if ($sku === '') {
        $seed = strtoupper((string) ($item['catalog'] ?? ''));
        if ($seed === '') {
            $seed = strtoupper((string) ($item['desc'] ?? ''));
        }
        if ($seed === '') {
            $seed = strtoupper((string) ($item['raw'] ?? ''));
        }
        $sku = 'AUTO-' . substr(md5($seed), 0, 8);
    }

    $existing = (int) wc_get_product_id_by_sku($sku);
    if ($existing > 0) {
        return $existing;
    }

    $name = trim((string) ($item['catalog'] ?? ''));
    if ($name === '') {
        $name = 'Catalog ' . $sku;
    }

    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_regular_price((string) apply_filters('ado_placeholder_price', '0'));
    $product->set_short_description((string) ($item['desc'] ?? 'Auto-created from parser scoped JSON'));
    return (int) $product->save();
}

function ado_find_product_id_for_item(array $item): int {
    global $wpdb;
    foreach (ado_extract_item_candidates($item) as $cand) {
        $id = (int) wc_get_product_id_by_sku($cand);
        if ($id > 0) { return $id; }
    }
    foreach (ado_extract_item_candidates($item) as $cand) {
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_title LIKE %s ORDER BY ID DESC LIMIT 1",
            '%' . $wpdb->esc_like($cand) . '%'
        ));
        if ($id > 0) { return $id; }
    }
    return ado_get_or_create_placeholder_product($item);
}

function ado_build_cart_lines_from_scope(array $scope_payload): array {
    $doors = (array) ($scope_payload['result']['doors'] ?? []);
    $lines = [];
    $unmatched = [];
    foreach ($doors as $door) {
        if (!is_array($door)) { continue; }
        $door_id = (string) ($door['door_id'] ?? '');
        foreach ((array) ($door['items'] ?? []) as $item) {
            if (!is_array($item)) { continue; }
            $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 1;
            if ($qty < 1) { $qty = 1; }
            $pid = ado_find_product_id_for_item($item);
            if ($pid <= 0) {
                $unmatched[] = [
                    'door_id' => $door_id,
                    'catalog' => (string) ($item['catalog'] ?? ''),
                    'desc' => (string) ($item['desc'] ?? ''),
                    'qty' => $qty,
                ];
                continue;
            }
            if (!isset($lines[$pid])) { $lines[$pid] = 0; }
            $lines[$pid] += $qty;
        }
    }
    return ['lines' => $lines, 'unmatched' => $unmatched];
}

function ado_render_quote_drafts_html(int $user_id): string {
    $drafts = ado_get_quote_drafts($user_id);
    if (!$drafts) { return '<p class="ado-muted">No saved quote carts yet.</p>'; }
    ob_start();
    foreach ($drafts as $d) {
        $id = esc_attr((string) ($d['id'] ?? ''));
        echo '<div class="ado-draft">';
        echo '<div class="ado-row"><strong>' . esc_html((string) ($d['name'] ?? 'Quote Draft')) . '</strong><span class="ado-chip">' . esc_html((string) ($d['total_items'] ?? 0)) . ' items</span></div>';
        echo '<div class="ado-row"><small>' . esc_html((string) ($d['created_at'] ?? '')) . '</small>';
        if (!empty($d['unmatched_count'])) {
            echo '<small class="ado-warning">Unmatched: ' . esc_html((string) $d['unmatched_count']) . '</small>';
        }
        echo '</div><div class="ado-row">';
        echo '<button class="button ado-load-draft" data-id="' . $id . '">Load</button>';
        echo '<button class="button ado-rename-draft" data-id="' . $id . '">Rename</button>';
        echo '<button class="button ado-delete-draft" data-id="' . $id . '">Delete</button>';
        echo '</div></div>';
    }
    return ob_get_clean();
}

function ado_assert_client_ajax(): int {
    if (!is_user_logged_in()) { wp_send_json_error(['message' => 'Please sign in.'], 401); }
    if (!ado_is_client()) { wp_send_json_error(['message' => 'Client access only.'], 403); }
    check_ajax_referer('ado_quote_nonce', 'nonce');
    return (int) get_current_user_id();
}

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
    if (empty($mapped['lines'])) {
        wp_send_json_error(['message' => 'No Woo products matched parser output.', 'unmatched' => array_slice((array) $mapped['unmatched'], 0, 20)], 400);
    }

    WC()->cart->empty_cart();
    $snapshot = [];
    $total_items = 0;
    foreach ((array) $mapped['lines'] as $pid => $qty) {
        if (WC()->cart->add_to_cart((int) $pid, (int) $qty)) {
            $snapshot[] = ['product_id' => (int) $pid, 'qty' => (int) $qty];
            $total_items += (int) $qty;
        }
    }

    $drafts = ado_get_quote_drafts($uid);
    $draft_id = wp_generate_uuid4();
    $drafts[] = [
        'id' => $draft_id,
        'name' => $quote_name,
        'created_at' => wp_date('Y-m-d H:i'),
        'items' => $snapshot,
        'total_items' => $total_items,
        'scope_url' => $scope_url,
        'scope_path' => $scope_path,
        'unmatched_count' => count((array) $mapped['unmatched']),
    ];
    ado_save_quote_drafts($uid, $drafts);
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('ado_last_scope_url', $scope_url);
        WC()->session->set('ado_last_scope_path', $scope_path);
        WC()->session->set('ado_last_quote_draft_id', $draft_id);
    }
    wp_send_json_success([
        'message' => 'Quote cart created.',
        'cart_url' => wc_get_cart_url(),
        'drafts_html' => ado_render_quote_drafts_html($uid),
        'matched_line_count' => count((array) $mapped['lines']),
        'unmatched_count' => count((array) $mapped['unmatched']),
    ]);
});

add_action('wp_ajax_ado_save_current_cart_quote', static function (): void {
    $uid = ado_assert_client_ajax();
    if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'WooCommerce cart unavailable.'], 500); }
    $name = sanitize_text_field((string) ($_POST['quote_name'] ?? ''));
    if ($name === '') { $name = 'Manual Quote ' . wp_date('Y-m-d H:i'); }
    $items = [];
    foreach (WC()->cart->get_cart() as $row) {
        $items[] = ['product_id' => (int) ($row['product_id'] ?? 0), 'qty' => (int) ($row['quantity'] ?? 1)];
    }
    if (!$items) { wp_send_json_error(['message' => 'Cart is empty.'], 400); }
    $drafts = ado_get_quote_drafts($uid);
    $drafts[] = ['id' => wp_generate_uuid4(), 'name' => $name, 'created_at' => wp_date('Y-m-d H:i'), 'items' => $items, 'total_items' => array_sum(array_column($items, 'qty')), 'unmatched_count' => 0];
    ado_save_quote_drafts($uid, $drafts);
    wp_send_json_success(['message' => 'Quote draft saved.', 'drafts_html' => ado_render_quote_drafts_html($uid)]);
});

add_action('wp_ajax_ado_load_quote_draft', static function (): void {
    $uid = ado_assert_client_ajax();
    $draft_id = sanitize_text_field((string) ($_POST['draft_id'] ?? ''));
    $target = null;
    foreach (ado_get_quote_drafts($uid) as $d) { if ((string) ($d['id'] ?? '') === $draft_id) { $target = $d; break; } }
    if (!$target) { wp_send_json_error(['message' => 'Quote draft not found.'], 404); }
    WC()->cart->empty_cart();
    foreach ((array) ($target['items'] ?? []) as $item) {
        $pid = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 1);
        if ($pid > 0 && $qty > 0) { WC()->cart->add_to_cart($pid, $qty); }
    }
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('ado_last_scope_url', (string) ($target['scope_url'] ?? ''));
        WC()->session->set('ado_last_scope_path', (string) ($target['scope_path'] ?? ''));
        WC()->session->set('ado_last_quote_draft_id', (string) ($target['id'] ?? ''));
    }
    wp_send_json_success(['message' => 'Quote cart loaded.', 'cart_url' => wc_get_cart_url()]);
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
    foreach ($drafts as &$d) { if ((string) ($d['id'] ?? '') === $draft_id) { $d['name'] = $name; } }
    unset($d);
    ado_save_quote_drafts($uid, $drafts);
    wp_send_json_success(['message' => 'Quote draft renamed.', 'drafts_html' => ado_render_quote_drafts_html($uid)]);
});

add_shortcode('ado_quote_workspace', static function (): string {
    if (!is_user_logged_in()) { return '<p>Please sign in to create quotes.</p>'; }
    if (!ado_is_client()) { return '<p>This area is for client accounts only.</p>'; }
    $uid = (int) get_current_user_id();
    $nonce = wp_create_nonce('ado_quote_nonce');
    ob_start(); ?>
    <div class="ado-card"><h3>Quote Generator</h3><p class="ado-muted">Parse PDF, then convert to a Woo quote cart.</p>
      <label>Quote Name <input id="ado-quote-name" type="text" placeholder="Project Name - Quote 1"></label>
      <button id="ado-create-from-parse" class="button button-primary" type="button" disabled>Create Quote Cart From Last Parse</button>
      <a id="ado-go-cart" class="button" style="display:none;" href="<?php echo esc_url(wc_get_cart_url()); ?>">Open Quote Cart</a>
      <p id="ado-quote-status" class="ado-muted"></p><?php echo do_shortcode('[contact-form]'); ?>
    </div>
    <div class="ado-card"><h3>Saved Quote Carts</h3><div class="ado-row"><input id="ado-manual-quote-name" type="text" placeholder="Save current cart as quote..."><button id="ado-save-current-cart" class="button" type="button">Save Current Cart as Quote</button></div><div id="ado-drafts-wrap"><?php echo ado_render_quote_drafts_html($uid); ?></div></div>
    <script>
    (function($){var latestScope='';function status(msg,err){$('#ado-quote-status').text(msg||'').css('color',err?'#b42318':'#344054');}
      function post(a,d,cb){$.post('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',Object.assign({action:a,nonce:'<?php echo esc_js($nonce); ?>'},d||{})).done(cb).fail(function(){cb({success:false,data:{message:'Request failed'}});});}
      function bindDrafts(){ $('#ado-drafts-wrap .ado-load-draft').off('click').on('click',function(){post('ado_load_quote_draft',{draft_id:$(this).data('id')},function(r){if(!r.success){status(r.data&&r.data.message?r.data.message:'Failed',true);return;}window.location.href=r.data.cart_url;});});
        $('#ado-drafts-wrap .ado-delete-draft').off('click').on('click',function(){post('ado_delete_quote_draft',{draft_id:$(this).data('id')},function(r){if(!r.success){status(r.data&&r.data.message?r.data.message:'Failed',true);return;}$('#ado-drafts-wrap').html(r.data.drafts_html||'');bindDrafts();status(r.data.message,false);});});
        $('#ado-drafts-wrap .ado-rename-draft').off('click').on('click',function(){var n=prompt('Rename quote');if(!n){return;}post('ado_rename_quote_draft',{draft_id:$(this).data('id'),name:n},function(r){if(!r.success){status(r.data&&r.data.message?r.data.message:'Failed',true);return;}$('#ado-drafts-wrap').html(r.data.drafts_html||'');bindDrafts();status(r.data.message,false);});});}
      bindDrafts();
      $(document).ajaxSuccess(function(_e,_x,_s,r){if(r&&r.success&&r.data&&r.data.download_url_scope){latestScope=r.data.download_url_scope;$('#ado-create-from-parse').prop('disabled',false);status('Parser finished. Click "Create Quote Cart From Last Parse".',false);}});
      $('#ado-create-from-parse').on('click',function(){if(!latestScope){status('No parsed scope detected yet.',true);return;}post('ado_scope_to_quote_cart',{scope_url:latestScope,quote_name:$('#ado-quote-name').val()||''},function(r){if(!r.success){status(r.data&&r.data.message?r.data.message:'Failed',true);return;}status(r.data.message+' Matched: '+(r.data.matched_line_count||0)+', Unmatched: '+(r.data.unmatched_count||0),false);$('#ado-drafts-wrap').html(r.data.drafts_html||'');bindDrafts();$('#ado-go-cart').show();});});
      $('#ado-save-current-cart').on('click',function(){post('ado_save_current_cart_quote',{quote_name:$('#ado-manual-quote-name').val()||''},function(r){if(!r.success){status(r.data&&r.data.message?r.data.message:'Failed',true);return;}$('#ado-drafts-wrap').html(r.data.drafts_html||'');bindDrafts();status(r.data.message,false);});});
    })(jQuery);
    </script>
    <?php return ob_get_clean();
});

// Sliding scale pricing by total quote quantity.
add_action('woocommerce_cart_calculate_fees', static function ($cart): void {
    if (!is_a($cart, 'WC_Cart')) { return; }
    if (is_admin() && !defined('DOING_AJAX')) { return; }
    if ($cart->is_empty()) { return; }

    $qty_total = 0;
    foreach ($cart->get_cart() as $row) {
        $qty_total += (int) ($row['quantity'] ?? 0);
    }
    if ($qty_total <= 0) { return; }

    $tiers = [
        100 => 0.15,
        50  => 0.10,
        20  => 0.05,
    ];
    $discount_pct = 0.0;
    foreach ($tiers as $min_qty => $pct) {
        if ($qty_total >= $min_qty) {
            $discount_pct = $pct;
            break;
        }
    }
    if ($discount_pct <= 0) { return; }

    $subtotal = (float) $cart->get_subtotal();
    if ($subtotal <= 0) { return; }
    $discount = round($subtotal * $discount_pct, 2);
    if ($discount <= 0) { return; }

    $label = sprintf('Quantity Discount (%d%% for %d+ items)', (int) round($discount_pct * 100), array_key_first(array_filter($tiers, static fn($p) => $p === $discount_pct)));
    $cart->add_fee($label, -$discount, false);
}, 20, 1);
