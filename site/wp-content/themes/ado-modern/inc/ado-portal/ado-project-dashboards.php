<?php
// ADO client/technician dashboards + Woo project metadata
if (defined('ADO_PROJECT_DASHBOARDS_LOADED')) { return; }
define('ADO_PROJECT_DASHBOARDS_LOADED', true);

function ado_is_client_checkout_context(): bool {
    if (is_admin() && !wp_doing_ajax()) { return false; }
    if (!is_user_logged_in() || !ado_is_client()) { return false; }
    if (wp_doing_ajax()) { return true; }
    return function_exists('is_checkout') && is_checkout();
}

function ado_get_client_checkout_profile_defaults(): array {
    $user = wp_get_current_user();
    $base_location = function_exists('wc_get_base_location') ? (array) wc_get_base_location() : [];
    $defaults = [
        'billing_first_name' => (string) get_user_meta($user->ID, 'billing_first_name', true),
        'billing_last_name' => (string) get_user_meta($user->ID, 'billing_last_name', true),
        'billing_company' => (string) get_user_meta($user->ID, 'billing_company', true),
        'billing_country' => (string) get_user_meta($user->ID, 'billing_country', true),
        'billing_address_1' => (string) get_user_meta($user->ID, 'billing_address_1', true),
        'billing_address_2' => (string) get_user_meta($user->ID, 'billing_address_2', true),
        'billing_city' => (string) get_user_meta($user->ID, 'billing_city', true),
        'billing_state' => (string) get_user_meta($user->ID, 'billing_state', true),
        'billing_postcode' => (string) get_user_meta($user->ID, 'billing_postcode', true),
        'billing_phone' => (string) get_user_meta($user->ID, 'billing_phone', true),
        'billing_email' => (string) get_user_meta($user->ID, 'billing_email', true),
    ];

    if ($defaults['billing_first_name'] === '') { $defaults['billing_first_name'] = (string) $user->first_name; }
    if ($defaults['billing_last_name'] === '') { $defaults['billing_last_name'] = (string) $user->last_name; }
    if ($defaults['billing_country'] === '') { $defaults['billing_country'] = (string) ($base_location['country'] ?? ''); }
    if ($defaults['billing_state'] === '') { $defaults['billing_state'] = (string) ($base_location['state'] ?? ''); }
    if ($defaults['billing_email'] === '') { $defaults['billing_email'] = (string) $user->user_email; }

    return $defaults;
}

if (!function_exists('ado_order_project_quote_id')) {
    function ado_order_project_quote_id(WC_Order $order): int {
        $quote_id = (int) $order->get_meta('_ado_quote_id');
        if ($quote_id > 0) {
            return $quote_id;
        }
        $draft_id = (int) $order->get_meta('_ado_quote_draft_id');
        if ($draft_id > 0) {
            return $draft_id;
        }
        return 0;
    }
}

if (!function_exists('ado_order_project_name')) {
    function ado_order_project_name(WC_Order $order): string {
        $name = trim((string) $order->get_meta('_ado_project_name'));
        if ($name !== '') {
            return $name;
        }

        $quote_id = ado_order_project_quote_id($order);
        if ($quote_id > 0) {
            $name = trim((string) get_post_meta($quote_id, '_adq_project_name', true));
            if ($name !== '') {
                return $name;
            }
            $name = trim((string) get_the_title($quote_id));
            if ($name !== '') {
                return $name;
            }
        }

        $company = trim((string) $order->get_billing_company());
        if ($company !== '') {
            return $company;
        }

        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $item_name = trim((string) $item->get_name());
            if ($item_name !== '') {
                return $item_name;
            }
        }

        return 'Project #' . (string) $order->get_id();
    }
}

if (!function_exists('ado_order_project_address')) {
    function ado_order_project_address(WC_Order $order): string {
        $address = trim((string) $order->get_meta('_ado_project_address'));
        if ($address !== '') {
            return $address;
        }

        $quote_id = ado_order_project_quote_id($order);
        if ($quote_id > 0) {
            $address = trim((string) get_post_meta($quote_id, '_adq_project_address', true));
            if ($address !== '') {
                return $address;
            }
        }

        $shipping_parts = array_filter(array_map('trim', [
            (string) $order->get_shipping_address_1(),
            (string) $order->get_shipping_address_2(),
            trim((string) $order->get_shipping_city() . ' ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode()),
        ]), static fn(string $part): bool => $part !== '');
        if ($shipping_parts) {
            return implode(', ', $shipping_parts);
        }

        $billing_parts = array_filter(array_map('trim', [
            (string) $order->get_billing_address_1(),
            (string) $order->get_billing_address_2(),
            trim((string) $order->get_billing_city() . ' ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode()),
        ]), static fn(string $part): bool => $part !== '');
        if ($billing_parts) {
            return implode(', ', $billing_parts);
        }

        return '';
    }
}

if (!function_exists('ado_project_timeline_normalize_string_list')) {
    function ado_project_timeline_normalize_string_list($values): array {
        $rows = [];
        if (is_string($values)) {
            $values = preg_split('/\r\n|\r|\n/', $values);
        }
        if (!is_array($values)) {
            return [];
        }
        foreach ($values as $value) {
            $value = sanitize_textarea_field((string) $value);
            $value = trim(preg_replace('/\s+/', ' ', $value));
            if ($value === '') {
                continue;
            }
            $rows[$value] = true;
        }
        return array_values(array_keys($rows));
    }
}

if (!function_exists('ado_project_timeline_parse_timestamp')) {
    function ado_project_timeline_parse_timestamp($value): int {
        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return $timestamp;
            }
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }
        $timestamp = strtotime($raw);
        return $timestamp === false ? 0 : (int) $timestamp;
    }
}

if (!function_exists('ado_project_timeline_actor_name')) {
    function ado_project_timeline_actor_name(int $user_id, string $fallback = ''): string {
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user instanceof WP_User) {
                $name = trim((string) $user->display_name);
                if ($name !== '') {
                    return $name;
                }
                $name = trim((string) $user->user_login);
                if ($name !== '') {
                    return $name;
                }
            }
        }
        $fallback = trim((string) $fallback);
        return $fallback !== '' ? $fallback : 'System';
    }
}

if (!function_exists('ado_project_timeline_category_label')) {
    function ado_project_timeline_category_label(string $category): string {
        $category = sanitize_key($category);
        $labels = [
            'quote' => 'Quote',
            'booking' => 'Booking',
            'site_readiness' => 'Site Readiness',
            'door_update' => 'Door Update',
            'door_workflow' => 'Door Workflow',
            'note' => 'Note',
            'media' => 'Media',
            'project_data' => 'Project Data',
            'general' => 'Update',
        ];
        return (string) ($labels[$category] ?? 'Update');
    }
}

if (!function_exists('ado_project_timeline_action_label')) {
    function ado_project_timeline_action_label(string $action): string {
        $action = sanitize_key($action);
        $labels = [
            'submitted' => 'Submitted',
            'created' => 'Created',
            'updated' => 'Updated',
            'cancelled' => 'Cancelled',
            'deleted' => 'Deleted',
            'added' => 'Added',
            'removed' => 'Removed',
        ];
        return (string) ($labels[$action] ?? ucfirst($action !== '' ? $action : 'Updated'));
    }
}

if (!function_exists('ado_project_timeline_events')) {
    function ado_project_timeline_events(WC_Order $order): array {
        $raw_events = $order->get_meta('_ado_project_timeline_events');
        if (!is_array($raw_events)) {
            return [];
        }
        $events = [];
        foreach ($raw_events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $title = sanitize_text_field((string) ($event['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $event_ts = ado_project_timeline_parse_timestamp($event['event_ts'] ?? ($event['occurred_at'] ?? ''));
            if ($event_ts <= 0) {
                $event_ts = current_time('timestamp');
            }
            $occurred_at = trim((string) ($event['occurred_at'] ?? ''));
            if ($occurred_at === '') {
                $occurred_at = wp_date('Y-m-d H:i', $event_ts);
            }
            $category = sanitize_key((string) ($event['category'] ?? 'general'));
            $action = sanitize_key((string) ($event['action'] ?? 'updated'));
            $events[] = [
                'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($event['id'] ?? ''))),
                'event_ts' => $event_ts,
                'occurred_at' => $occurred_at,
                'created_at' => wp_date('M j, Y g:i A', $event_ts),
                'title' => $title,
                'summary' => sanitize_textarea_field((string) ($event['summary'] ?? '')),
                'category' => $category,
                'category_label' => sanitize_text_field((string) ($event['category_label'] ?? ado_project_timeline_category_label($category))),
                'action' => $action,
                'action_label' => sanitize_text_field((string) ($event['action_label'] ?? ado_project_timeline_action_label($action))),
                'actor_id' => (int) ($event['actor_id'] ?? 0),
                'actor_name' => sanitize_text_field((string) ($event['actor_name'] ?? '')),
                'actor_role' => sanitize_key((string) ($event['actor_role'] ?? 'system')),
                'door_ids' => ado_project_timeline_normalize_string_list($event['door_ids'] ?? []),
                'door_labels' => ado_project_timeline_normalize_string_list($event['door_labels'] ?? []),
                'details' => ado_project_timeline_normalize_string_list($event['details'] ?? []),
            ];
        }
        return array_values($events);
    }
}

if (!function_exists('ado_project_timeline_append_event')) {
    function ado_project_timeline_append_event(WC_Order $order, array $event): array {
        $events = ado_project_timeline_events($order);
        $event_ts = ado_project_timeline_parse_timestamp($event['event_ts'] ?? ($event['occurred_at'] ?? ''));
        if ($event_ts <= 0) {
            $event_ts = current_time('timestamp');
        }
        $title = sanitize_text_field((string) ($event['title'] ?? 'Project updated'));
        if ($title === '') {
            $title = 'Project updated';
        }
        $category = sanitize_key((string) ($event['category'] ?? 'general'));
        $action = sanitize_key((string) ($event['action'] ?? 'updated'));
        $actor_id = (int) ($event['actor_id'] ?? 0);
        $actor_name = ado_project_timeline_actor_name($actor_id, (string) ($event['actor_name'] ?? ''));
        $row = [
            'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($event['id'] ?? ('evt_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12))))),
            'event_ts' => $event_ts,
            'occurred_at' => wp_date('Y-m-d H:i', $event_ts),
            'created_at' => wp_date('M j, Y g:i A', $event_ts),
            'title' => $title,
            'summary' => sanitize_textarea_field((string) ($event['summary'] ?? '')),
            'category' => $category,
            'category_label' => sanitize_text_field((string) ($event['category_label'] ?? ado_project_timeline_category_label($category))),
            'action' => $action,
            'action_label' => sanitize_text_field((string) ($event['action_label'] ?? ado_project_timeline_action_label($action))),
            'actor_id' => $actor_id,
            'actor_name' => $actor_name,
            'actor_role' => sanitize_key((string) ($event['actor_role'] ?? 'system')),
            'door_ids' => ado_project_timeline_normalize_string_list($event['door_ids'] ?? []),
            'door_labels' => ado_project_timeline_normalize_string_list($event['door_labels'] ?? []),
            'details' => ado_project_timeline_normalize_string_list($event['details'] ?? []),
        ];
        $events[] = $row;
        if (count($events) > 1200) {
            $events = array_slice($events, -1200);
        }
        $order->update_meta_data('_ado_project_timeline_events', array_values($events));
        return $row;
    }
}

if (!function_exists('ado_project_timeline_rows')) {
    function ado_project_timeline_rows(WC_Order $order, int $limit = 240): array {
        $limit = max(1, min(1200, $limit));
        $events = ado_project_timeline_events($order);

        $po_number = trim((string) $order->get_meta('_ado_po_number'));
        if ($po_number === '') {
            $po_number = 'N/A';
        }
        $quote_ts = 0;
        $created = $order->get_date_created();
        if ($created instanceof WC_DateTime) {
            $quote_ts = (int) $created->getTimestamp();
        }
        if ($quote_ts <= 0) {
            $quote_ts = current_time('timestamp');
        }
        $quote_actor = method_exists($order, 'get_formatted_billing_full_name')
            ? trim((string) $order->get_formatted_billing_full_name())
            : '';
        if ($quote_actor === '') {
            $quote_actor = 'Client';
        }
        $quote_row = [
            'id' => 'quote_accepted_origin',
            'event_ts' => $quote_ts,
            'occurred_at' => wp_date('Y-m-d H:i', $quote_ts),
            'created_at' => wp_date('M j, Y g:i A', $quote_ts),
            'title' => 'Quote Accepted, Purchase Order ' . $po_number . ' submitted',
            'summary' => 'Project created from accepted quote and entered scheduling workflow.',
            'category' => 'quote',
            'category_label' => 'Quote',
            'action' => 'submitted',
            'action_label' => 'Submitted',
            'actor_id' => 0,
            'actor_name' => $quote_actor,
            'actor_role' => 'client',
            'door_ids' => [],
            'door_labels' => [],
            'details' => [
                'PO Number: ' . $po_number,
                'Project ID: ' . (string) $order->get_id(),
            ],
        ];

        $rows = [$quote_row];
        foreach ($events as $event) {
            if (stripos((string) ($event['title'] ?? ''), 'Quote Accepted, Purchase Order ') === 0) {
                continue;
            }
            $rows[] = $event;
        }
        usort($rows, static function (array $left, array $right): int {
            $left_ts = (int) ($left['event_ts'] ?? 0);
            $right_ts = (int) ($right['event_ts'] ?? 0);
            if ($left_ts === $right_ts) {
                return strcmp((string) ($right['id'] ?? ''), (string) ($left['id'] ?? ''));
            }
            return $right_ts <=> $left_ts;
        });
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }
        return array_values($rows);
    }
}

function ado_handle_checkout_po_document_upload(): string {
    if (empty($_FILES['ado_po_document']['tmp_name'])) { return ''; }

    require_once ABSPATH . 'wp-admin/includes/file.php';

    $upload = wp_handle_upload($_FILES['ado_po_document'], ['test_form' => false]);
    if (!empty($upload['error'])) {
        throw new Exception('PO document upload failed. Please try again or leave the document blank for now.');
    }

    return !empty($upload['url']) ? esc_url_raw((string) $upload['url']) : '';
}

add_filter('woocommerce_checkout_fields', static function (array $fields): array {
    if (!ado_is_client_checkout_context()) { return $fields; }

    foreach (ado_get_client_checkout_profile_defaults() as $key => $default) {
        if (!isset($fields['billing'][$key])) { continue; }
        $fields['billing'][$key]['type'] = 'hidden';
        $fields['billing'][$key]['required'] = false;
        $fields['billing'][$key]['class'] = ['ado-checkout-hidden-field'];
        $fields['billing'][$key]['default'] = $default;
    }

    $fields['billing']['ado_po_number'] = [
        'type' => 'text',
        'label' => 'Purchase Order Number',
        'required' => true,
        'class' => ['form-row-wide'],
        'priority' => 5,
        'autocomplete' => 'off',
    ];

    unset($fields['shipping'], $fields['account'], $fields['order']['order_comments'], $fields['order']['ado_preferred_visit_date']);

    return $fields;
});

add_filter('woocommerce_enable_order_notes_field', static function ($enabled): bool {
    return ado_is_client_checkout_context() ? false : (bool) $enabled;
});

add_filter('woocommerce_cart_needs_shipping_address', static function ($needs_shipping_address): bool {
    return ado_is_client_checkout_context() ? false : (bool) $needs_shipping_address;
});

add_filter('woocommerce_cart_needs_payment', static function ($needs_payment, $cart): bool {
    return ado_is_client_checkout_context() ? false : (bool) $needs_payment;
}, 10, 2);

add_action('woocommerce_before_checkout_billing_form', static function (): void {
    if (!ado_is_client_checkout_context()) { return; }
    echo '<p class="ado-muted">Enter the PO number for this quote. Uploading the PO document is optional for now.</p>';
});

add_action('woocommerce_after_checkout_billing_form', static function (): void {
    if (!ado_is_client_checkout_context()) { return; }
    ?>
    <p class="form-row form-row-wide" id="ado_po_document_field">
        <label for="ado_po_document">Purchase Order Document <span class="optional">(optional)</span></label>
        <input type="file" name="ado_po_document" id="ado_po_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
        <span class="description">Attach the PO file if it is ready. You can leave this blank for now.</span>
    </p>
    <style>
        .ado-checkout-hidden-field { display: none !important; }
    </style>
    <?php
});

add_action('woocommerce_after_checkout_validation', static function (array $data, WP_Error $errors): void {
    if (!ado_is_client_checkout_context()) { return; }
    if (empty($_FILES['ado_po_document'])) { return; }

    $upload_error = (int) ($_FILES['ado_po_document']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upload_error === UPLOAD_ERR_NO_FILE) { return; }

    if ($upload_error !== UPLOAD_ERR_OK || empty($_FILES['ado_po_document']['tmp_name'])) {
        $errors->add('ado_po_document_upload', 'PO document upload failed. Please try again or leave the document blank for now.');
    }
}, 10, 2);

add_action('woocommerce_checkout_create_order', static function (WC_Order $order): void {
    $po = sanitize_text_field((string) ($_POST['ado_po_number'] ?? ''));
    if ($po === '') {
        throw new Exception('Purchase Order Number is required.');
    }

    $order->update_meta_data('_ado_po_number', $po);

    $po_file_url = ado_handle_checkout_po_document_upload();
    if ($po_file_url !== '') {
        $order->update_meta_data('_ado_po_file_url', $po_file_url);
    }

    $order->update_meta_data('_ado_project_status', 'soft_booked');
    if (function_exists('WC') && WC()->session) {
        $scope_url = (string) WC()->session->get('ado_last_scope_url');
        $scope_path = (string) WC()->session->get('ado_last_scope_path');
        $draft_id = (string) WC()->session->get('ado_last_quote_draft_id');
        if ($scope_url !== '') { $order->update_meta_data('_ado_scoped_json_url', $scope_url); }
        if ($scope_path !== '') { $order->update_meta_data('_ado_scoped_json_path', $scope_path); }
        if ($draft_id !== '') {
            $order->update_meta_data('_ado_quote_draft_id', $draft_id);
            $order->update_meta_data('_ado_quote_id', (int) $draft_id);
        }
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
        if ($order->get_meta('_ado_po_file_url')) {
            echo '<div><small><a href="' . esc_url((string) $order->get_meta('_ado_po_file_url')) . '" target="_blank" rel="noopener">View PO document</a></small></div>';
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
    if (function_exists('ado_project_timeline_append_event')) {
        $door_hint = '';
        if (preg_match('/\bdoor\s*[:#-]?\s*([A-Za-z0-9\-]+)/i', $note, $matches)) {
            $door_hint = trim((string) ($matches[1] ?? ''));
        }
        $timeline_details = [
            'Priority: ' . strtoupper((string) $priority),
        ];
        if ($hours > 0) {
            $timeline_details[] = 'Hours: ' . number_format($hours, 2) . 'h';
        }
        if ($attachment_url !== '') {
            $timeline_details[] = 'Attachment uploaded: ' . basename((string) parse_url($attachment_url, PHP_URL_PATH));
            $timeline_details[] = 'Attachment URL: ' . $attachment_url;
        }
        ado_project_timeline_append_event($order, [
            'title' => $attachment_url !== '' ? 'Technician Note + Attachment Submitted' : 'Technician Note Submitted',
            'summary' => $note,
            'category' => $attachment_url !== '' ? 'media' : 'note',
            'action' => 'submitted',
            'actor_id' => (int) get_current_user_id(),
            'actor_name' => ado_project_timeline_actor_name((int) get_current_user_id()),
            'actor_role' => 'technician',
            'door_ids' => $door_hint !== '' ? [$door_hint] : [],
            'door_labels' => $door_hint !== '' ? ['Door ' . $door_hint] : [],
            'details' => $timeline_details,
        ]);
    }
    $order->save();
    wp_send_json_success(['message' => 'Technician log saved.']);
});

add_action('woocommerce_admin_order_data_after_billing_address', static function ($order): void {
    if (!($order instanceof WC_Order)) { return; }
    echo '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;"><h4>ADO Project Fields</h4>';
    woocommerce_wp_text_input(['id' => '_ado_po_number', 'label' => 'PO Number', 'value' => $order->get_meta('_ado_po_number')]);
    if ($order->get_meta('_ado_po_file_url')) {
        echo '<p class="form-field"><label>PO Document</label><span><a href="' . esc_url((string) $order->get_meta('_ado_po_file_url')) . '" target="_blank" rel="noopener">View uploaded PO document</a></span></p>';
    }
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
    $field_labels = [
        '_ado_po_number' => 'PO Number',
        '_ado_wave_invoice_id' => 'Invoice ID',
        '_ado_wave_invoice_url' => 'Invoice URL',
        '_ado_wave_status' => 'Invoice Status',
        '_ado_wave_amount_due' => 'Invoice Amount Due',
        '_ado_next_visit_date' => 'Confirmed Visit Date',
        '_ado_technician_ids' => 'Assigned Technician IDs',
        '_ado_critical_notes' => 'Critical Notes',
    ];
    $changed_lines = [];
    foreach (array_keys($field_labels) as $key) {
        if (!isset($_POST[$key])) { continue; }
        $old_value = trim((string) $order->get_meta($key));
        $value = wp_unslash((string) $_POST[$key]);
        $value = ($key === '_ado_critical_notes') ? sanitize_textarea_field($value) : sanitize_text_field($value);
        if ($old_value === trim((string) $value)) {
            continue;
        }
        $order->update_meta_data($key, $value);
        $changed_lines[] = (string) ($field_labels[$key] ?? $key)
            . ': '
            . ($old_value !== '' ? $old_value : '(blank)')
            . ' -> '
            . (trim((string) $value) !== '' ? trim((string) $value) : '(blank)');
    }
    if ($changed_lines && function_exists('ado_project_timeline_append_event')) {
        $admin_user_id = (int) get_current_user_id();
        ado_project_timeline_append_event($order, [
            'title' => 'Project Data Updated',
            'summary' => 'Order-level project fields were updated.',
            'category' => 'project_data',
            'action' => 'updated',
            'actor_id' => $admin_user_id,
            'actor_name' => ado_project_timeline_actor_name($admin_user_id),
            'actor_role' => 'admin',
            'details' => $changed_lines,
        ]);
    }
    $order->save();
});
