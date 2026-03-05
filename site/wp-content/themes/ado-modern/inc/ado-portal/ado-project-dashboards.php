<?php
// ADO client/technician dashboards + Woo project metadata
if (defined('ADO_PROJECT_DASHBOARDS_LOADED')) { return; }
define('ADO_PROJECT_DASHBOARDS_LOADED', true);

add_filter('woocommerce_checkout_fields', static function (array $fields): array {
    $fields['order']['ado_po_number'] = [
        'type' => 'text',
        'label' => 'Purchase Order Number',
        'required' => false,
        'class' => ['form-row-wide'],
        'priority' => 40,
    ];
    $fields['order']['ado_preferred_visit_date'] = [
        'type' => 'date',
        'label' => 'Preferred Visit Date (Soft Booking)',
        'required' => false,
        'class' => ['form-row-wide'],
        'priority' => 41,
    ];
    return $fields;
});

add_action('woocommerce_checkout_create_order', static function (WC_Order $order): void {
    $po = sanitize_text_field((string) ($_POST['ado_po_number'] ?? ''));
    $visit = sanitize_text_field((string) ($_POST['ado_preferred_visit_date'] ?? ''));
    if ($po !== '') { $order->update_meta_data('_ado_po_number', $po); }
    if ($visit !== '') { $order->update_meta_data('_ado_next_visit_date', $visit); }
    $order->update_meta_data('_ado_project_status', 'soft_booked');
    if (function_exists('WC') && WC()->session) {
        $scope_url = (string) WC()->session->get('ado_last_scope_url');
        $scope_path = (string) WC()->session->get('ado_last_scope_path');
        $draft_id = (string) WC()->session->get('ado_last_quote_draft_id');
        if ($scope_url !== '') { $order->update_meta_data('_ado_scoped_json_url', $scope_url); }
        if ($scope_path !== '') { $order->update_meta_data('_ado_scoped_json_path', $scope_path); }
        if ($draft_id !== '') { $order->update_meta_data('_ado_quote_draft_id', $draft_id); }
    }
}, 10, 1);

add_filter('woocommerce_account_menu_items', static function (array $items): array {
    if (isset($items['orders'])) { $items['orders'] = 'Projects'; }
    return $items;
});

function ado_orders_for_client(int $user_id): array {
    return wc_get_orders(['customer_id' => $user_id, 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC']);
}

add_shortcode('ado_client_projects', static function (): string {
    if (!is_user_logged_in() || !ado_is_client()) { return '<p>Client access only.</p>'; }
    $orders = ado_orders_for_client((int) get_current_user_id());
    if (!$orders) { return '<p class="ado-muted">No projects yet.</p>'; }
    ob_start();
    foreach ($orders as $order) {
        $oid = $order->get_id();
        $scope_path = (string) $order->get_meta('_ado_scoped_json_path');
        $door_count = 0;
        if ($scope_path !== '' && file_exists($scope_path)) {
            $json = json_decode((string) file_get_contents($scope_path), true);
            $door_count = (int) ($json['result']['door_count'] ?? 0);
        }
        echo '<div class="ado-project">';
        echo '<div class="ado-row"><strong>Project #' . esc_html((string) $oid) . '</strong><span class="ado-chip">' . esc_html(wc_get_order_status_name($order->get_status())) . '</span></div>';
        echo '<div class="ado-row"><small>Total: ' . wp_kses_post($order->get_formatted_order_total()) . '</small><small>Doors: ' . esc_html((string) $door_count) . '</small></div>';
        if ($order->get_meta('_ado_po_number')) {
            echo '<div><small>PO: ' . esc_html((string) $order->get_meta('_ado_po_number')) . '</small></div>';
        }
        if ($order->get_meta('_ado_next_visit_date')) {
            echo '<div><small>Upcoming visit: ' . esc_html((string) $order->get_meta('_ado_next_visit_date')) . '</small></div>';
        }
        echo '</div>';
    }
    return ob_get_clean();
});

add_shortcode('ado_client_dashboard', static function (): string {
    if (!is_user_logged_in() || !ado_is_client()) { return '<p>Client access only.</p>'; }
    $orders = ado_orders_for_client((int) get_current_user_id());
    $outstanding_total = 0.0;
    $outstanding_count = 0;
    $upcoming = [];
    $critical = [];
    foreach ($orders as $order) {
        $wave_status = strtolower((string) $order->get_meta('_ado_wave_status'));
        if (in_array($wave_status, ['pending', 'overdue', 'unpaid'], true)) {
            $due = (float) $order->get_meta('_ado_wave_amount_due');
            $outstanding_total += $due > 0 ? $due : (float) $order->get_total();
            $outstanding_count++;
        }
        if ($order->get_meta('_ado_next_visit_date')) {
            $upcoming[] = ['date' => (string) $order->get_meta('_ado_next_visit_date'), 'id' => $order->get_id()];
        }
        if ($order->get_meta('_ado_critical_notes')) {
            $critical[] = ['id' => $order->get_id(), 'note' => (string) $order->get_meta('_ado_critical_notes')];
        }
    }
    usort($upcoming, static fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
    ob_start(); ?>
    <div class="ado-grid-cards">
      <div class="ado-card"><h3>Outstanding Invoices</h3><p class="ado-metric">$<?php echo esc_html(number_format($outstanding_total, 2)); ?></p><p><?php echo esc_html((string) $outstanding_count); ?> invoice(s) pending/overdue</p></div>
      <div class="ado-card"><h3>Upcoming Scheduled Visits</h3><?php if ($upcoming) { foreach (array_slice($upcoming, 0, 3) as $u) { echo '<p><strong>' . esc_html($u['date']) . '</strong> · Project #' . esc_html((string) $u['id']) . '</p>'; } } else { echo '<p class="ado-muted">No scheduled visits yet.</p>'; } ?></div>
      <div class="ado-card"><h3>Critical / High Priority Notes</h3><?php if ($critical) { foreach (array_slice($critical, 0, 3) as $c) { echo '<p><strong>Project #' . esc_html((string) $c['id']) . ':</strong> ' . esc_html($c['note']) . '</p>'; } } else { echo '<p class="ado-muted">No critical notes.</p>'; } ?></div>
    </div>
    <div class="ado-card"><h3>Actions</h3><div class="ado-row"><a class="button button-primary" href="<?php echo esc_url(home_url('/new-quote/')); ?>">Generate New Quote</a><a class="button" href="<?php echo esc_url(wc_get_cart_url()); ?>">Quotes (Cart)</a><a class="button" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">Project Tracking</a></div><?php echo do_shortcode('[ado_client_projects]'); ?></div>
    <?php return ob_get_clean();
});

add_shortcode('ado_technician_portal', static function (): string {
    if (!is_user_logged_in() || !ado_is_technician()) { return '<p>Technician access only.</p>'; }
    $uid = (int) get_current_user_id();
    $orders = wc_get_orders(['limit' => 100, 'orderby' => 'date', 'order' => 'DESC']);
    $assigned = [];
    foreach ($orders as $order) {
        $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $order->get_meta('_ado_technician_ids')))));
        if (in_array($uid, $ids, true)) { $assigned[] = $order; }
    }
    $nonce = wp_create_nonce('ado_tech_nonce');
    ob_start();
    echo '<div class="ado-card"><h3>Technician Portal</h3>';
    $calendar_posts = get_posts(['post_type' => 'calendar', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
    if (!empty($calendar_posts)) {
        echo '<div class="ado-card"><h3>Schedule (Google Calendar)</h3>' . do_shortcode('[calendar id="' . (int) $calendar_posts[0] . '"]') . '</div>';
    }
    if (!$assigned) { echo '<p class="ado-muted">No projects assigned.</p></div>'; return ob_get_clean(); }
    echo '<p class="ado-muted">Use this area for project notes, photos, and hours.</p></div>';
    foreach ($assigned as $order) {
        echo '<div class="ado-card"><h3>Project #' . esc_html((string) $order->get_id()) . '</h3>';
        echo '<form class="ado-tech-log-form" enctype="multipart/form-data"><input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '"><label>Hours <input type="number" step="0.25" min="0" name="hours"></label><label>Priority <select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="critical">Critical</option></select></label><label>Note <textarea name="note" rows="3" required></textarea></label><label>Upload photo/doc <input type="file" name="attachment"></label><button class="button button-primary" type="submit">Save Technician Log</button></form>';
        $logs = $order->get_meta('_ado_tech_logs');
        if (is_array($logs) && $logs) {
            foreach (array_slice(array_reverse($logs), 0, 5) as $log) {
                echo '<p><strong>' . esc_html((string) ($log['created_at'] ?? '')) . '</strong> · ' . esc_html((string) ($log['hours'] ?? 0)) . 'h · ' . esc_html((string) ($log['priority'] ?? 'normal')) . '<br>' . esc_html((string) ($log['note'] ?? '')) . '</p>';
            }
        }
        echo '</div>';
    }
    ?>
    <script>
    (function($){$('.ado-tech-log-form').on('submit',function(e){e.preventDefault();var fd=new FormData(this);fd.append('action','ado_add_tech_log');fd.append('nonce','<?php echo esc_js($nonce); ?>');$.ajax({url:'<?php echo esc_js(admin_url('admin-ajax.php')); ?>',method:'POST',data:fd,processData:false,contentType:false}).done(function(r){if(!r.success){alert(r.data&&r.data.message?r.data.message:'Failed');return;}location.reload();}).fail(function(){alert('Failed to save technician log.');});});})(jQuery);
    </script>
    <?php return ob_get_clean();
});

add_action('wp_ajax_ado_add_tech_log', static function (): void {
    if (!is_user_logged_in() || !ado_is_technician()) { wp_send_json_error(['message' => 'Technician access only.'], 403); }
    check_ajax_referer('ado_tech_nonce', 'nonce');
    $order = wc_get_order((int) ($_POST['order_id'] ?? 0));
    if (!$order) { wp_send_json_error(['message' => 'Project not found.'], 404); }
    $note = sanitize_textarea_field((string) ($_POST['note'] ?? ''));
    if ($note === '') { wp_send_json_error(['message' => 'Note is required.'], 400); }
    $hours = (float) ($_POST['hours'] ?? 0);
    $priority = sanitize_key((string) ($_POST['priority'] ?? 'normal'));
    $attachment_url = '';
    if (!empty($_FILES['attachment']['tmp_name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($_FILES['attachment'], ['test_form' => false]);
        if (empty($upload['error']) && !empty($upload['url'])) { $attachment_url = esc_url_raw((string) $upload['url']); }
    }
    $logs = $order->get_meta('_ado_tech_logs');
    if (!is_array($logs)) { $logs = []; }
    $logs[] = ['created_at' => wp_date('Y-m-d H:i'), 'user_id' => get_current_user_id(), 'hours' => $hours, 'priority' => in_array($priority, ['normal', 'high', 'critical'], true) ? $priority : 'normal', 'note' => $note, 'attachment_url' => $attachment_url];
    $order->update_meta_data('_ado_tech_logs', $logs);
    if (in_array($priority, ['high', 'critical'], true)) {
        $order->update_meta_data('_ado_critical_notes', trim((string) $order->get_meta('_ado_critical_notes') . "\n" . $note));
    }
    $order->save();
    wp_send_json_success(['message' => 'Technician log saved.']);
});

add_action('woocommerce_admin_order_data_after_billing_address', static function ($order): void {
    if (!($order instanceof WC_Order)) { return; }
    echo '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;"><h4>ADO Project Fields</h4>';
    woocommerce_wp_text_input(['id' => '_ado_wave_invoice_id', 'label' => 'Wave Invoice ID', 'value' => $order->get_meta('_ado_wave_invoice_id')]);
    woocommerce_wp_text_input(['id' => '_ado_wave_invoice_url', 'label' => 'Wave Invoice URL', 'value' => $order->get_meta('_ado_wave_invoice_url')]);
    woocommerce_wp_select(['id' => '_ado_wave_status', 'label' => 'Wave Status', 'value' => $order->get_meta('_ado_wave_status'), 'options' => ['' => 'Select', 'pending' => 'Pending', 'overdue' => 'Overdue', 'paid' => 'Paid']]);
    woocommerce_wp_text_input(['id' => '_ado_wave_amount_due', 'label' => 'Wave Amount Due', 'value' => $order->get_meta('_ado_wave_amount_due')]);
    woocommerce_wp_text_input(['id' => '_ado_next_visit_date', 'label' => 'Next Visit Date', 'value' => $order->get_meta('_ado_next_visit_date')]);
    woocommerce_wp_text_input(['id' => '_ado_technician_ids', 'label' => 'Technician User IDs (comma-separated)', 'value' => $order->get_meta('_ado_technician_ids')]);
    woocommerce_wp_textarea_input(['id' => '_ado_critical_notes', 'label' => 'Critical/High Priority Notes', 'value' => $order->get_meta('_ado_critical_notes')]);
    echo '</div>';
});

add_action('woocommerce_process_shop_order_meta', static function ($order_id): void {
    $order = wc_get_order($order_id);
    if (!$order) { return; }
    foreach (['_ado_wave_invoice_id', '_ado_wave_invoice_url', '_ado_wave_status', '_ado_wave_amount_due', '_ado_next_visit_date', '_ado_technician_ids', '_ado_critical_notes'] as $key) {
        if (!isset($_POST[$key])) { continue; }
        $value = wp_unslash((string) $_POST[$key]);
        $value = ($key === '_ado_critical_notes') ? sanitize_textarea_field($value) : sanitize_text_field($value);
        $order->update_meta_data($key, $value);
    }
    $order->save();
});
