<?php
// Lightweight client app shell: consistent UI across dashboard views + live backend actions.
if (defined('ADO_CLIENT_DASHBOARD_APP_LOADED')) { return; }
define('ADO_CLIENT_DASHBOARD_APP_LOADED', true);

function ado_cd_view_url(string $view): string {
    $map = [
        'dashboard' => home_url('/portal/'),
        'new-quote' => home_url('/portal/new-quote/'),
        'quotes' => home_url('/portal/client-quotes/'),
        'projects' => home_url('/portal/client-projects/'),
        'schedule' => home_url('/portal/client-schedule/'),
        'site-docs' => home_url('/portal/site-docs/'),
        'invoices' => home_url('/portal/client-invoices/'),
    ];
    return esc_url((string) ($map[$view] ?? add_query_arg(['view' => $view], home_url('/client-dashboard/'))));
}

function ado_cd_client_orders(int $user_id): array {
    return function_exists('ado_orders_for_client')
        ? ado_orders_for_client($user_id)
        : wc_get_orders(['customer_id' => $user_id, 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC']);
}

function ado_cd_currency(float $amount): string {
    if (function_exists('wc_price')) {
        return wp_strip_all_tags((string) wc_price($amount));
    }
    return '$' . number_format($amount, 2);
}

function ado_cd_counts(int $user_id): array {
    $quotes_count = 0;
    if (function_exists('ado_get_quote_drafts')) {
        $quotes_count = count((array) ado_get_quote_drafts($user_id));
    }

    $overdue_count = 0;
    foreach (ado_cd_client_orders($user_id) as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $wave_status = strtolower(trim((string) $order->get_meta('_ado_wave_status')));
        if ($wave_status === 'overdue') { $overdue_count++; }
    }

    return [
        'quotes_count' => $quotes_count,
        'overdue_count' => $overdue_count,
    ];
}

function ado_cd_render_quotes_queue(int $user_id): string {
    if (!function_exists('ado_get_quote_drafts')) {
        return '<div class="ado-empty">Quote drafts are unavailable.</div>';
    }
    $orders_by_draft = ado_cd_orders_by_quote_draft_id($user_id);
    $drafts = array_values(array_filter(ado_cd_quote_drafts($user_id), static function (array $draft) use ($orders_by_draft): bool {
        $state = ado_cd_quote_state($draft, $orders_by_draft);
        return (string) ($state['tone'] ?? '') !== 'approved';
    }));
    if (!$drafts) {
        return '<div class="ado-empty">No saved quote drafts.</div>';
    }
    ob_start();
    echo '<table class="ado-table"><thead><tr><th>Quote</th><th>Value</th><th>Items</th><th>Saved</th><th></th></tr></thead><tbody>';
    foreach ($drafts as $draft) {
        $id = (string) ($draft['id'] ?? '');
        $name = (string) ($draft['name'] ?? 'Quote Draft');
        $total = ado_cd_quote_total($draft);
        echo '<tr>';
        echo '<td><strong>' . esc_html($name) . '</strong><div class="ado-row-sub">Awaiting client approval</div></td>';
        echo '<td>' . esc_html($total > 0 ? ado_cd_currency($total) : 'Review in cart') . '</td>';
        echo '<td>' . esc_html((string) ($draft['total_items'] ?? 0)) . '</td>';
        echo '<td>' . esc_html(ado_cd_quote_created_label($draft)) . '</td>';
        echo '<td><div class="ado-action-row"><button class="ado-btn primary ado-approve-quote" data-draft-id="' . esc_attr($id) . '">Approve</button><button class="ado-btn ado-request-quote-change" data-draft-id="' . esc_attr($id) . '">Request Changes</button><button class="ado-btn ado-decline-quote" data-draft-id="' . esc_attr($id) . '">Decline</button></div></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    return (string) ob_get_clean();
}

function ado_cd_render_invoices(int $user_id): string {
    $orders = ado_cd_client_orders($user_id);
    $rows = [];
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $wave_status = strtolower(trim((string) $order->get_meta('_ado_wave_status')));
        if (!in_array($wave_status, ['pending', 'overdue', 'unpaid'], true)) { continue; }
        $amount_due = (float) $order->get_meta('_ado_wave_amount_due');
        if ($amount_due <= 0) { $amount_due = (float) $order->get_total(); }
        $rows[] = [
            'invoice_id' => (string) $order->get_meta('_ado_wave_invoice_id'),
            'project' => trim((string) $order->get_billing_company()) ?: ('Project #' . $order->get_id()),
            'amount_due' => $amount_due,
            'status' => ($wave_status === 'overdue') ? 'Overdue' : 'Pending',
            'invoice_url' => (string) $order->get_meta('_ado_wave_invoice_url'),
        ];
    }
    if (!$rows) {
        return '<div class="ado-empty">No outstanding invoices.</div>';
    }
    ob_start();
    echo '<table class="ado-table"><thead><tr><th>Invoice</th><th>Project</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . esc_html($row['invoice_id'] !== '' ? $row['invoice_id'] : 'Unlinked') . '</td>';
        echo '<td>' . esc_html($row['project']) . '</td>';
        echo '<td>' . esc_html(ado_cd_currency((float) $row['amount_due'])) . '</td>';
        echo '<td>' . esc_html($row['status']) . '</td>';
        echo '<td>';
        if ($row['invoice_url'] !== '') {
            echo '<a class="ado-btn" style="padding:5px 9px;font-size:11px;" target="_blank" rel="noopener" href="' . esc_url($row['invoice_url']) . '">Open</a>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    return (string) ob_get_clean();
}

function ado_cd_render_schedule(int $user_id): string {
    $project_rows = ado_cd_project_rows_data($user_id);
    $visits = array_values(array_filter($project_rows, static function (array $row): bool {
        return (int) ($row['next_visit_ts'] ?? 0) > 0;
    }));
    usort($visits, static fn($a, $b) => ((int) ($a['next_visit_ts'] ?? 0)) <=> ((int) ($b['next_visit_ts'] ?? 0)));
    $current_ts = current_time('timestamp');
    $upcoming = array_values(array_filter($visits, static fn(array $row): bool => (int) ($row['next_visit_ts'] ?? 0) >= $current_ts));
    $history = array_reverse(array_values(array_filter($visits, static fn(array $row): bool => (int) ($row['next_visit_ts'] ?? 0) < $current_ts)));

    $calendar_month_ts = $upcoming ? (int) ($upcoming[0]['next_visit_ts'] ?? $current_ts) : $current_ts;
    $month_start = strtotime(wp_date('Y-m-01', $calendar_month_ts));
    $month_end = strtotime(wp_date('Y-m-t', $calendar_month_ts));
    $grid_start = strtotime('last sunday', $month_start);
    if ($grid_start === $month_start) {
        $grid_start = strtotime('-7 days', $grid_start);
    }
    $grid_end = strtotime('next saturday', $month_end);
    if ($grid_end === $month_end) {
        $grid_end = strtotime('+7 days', $grid_end);
    }
    $visit_days = [];
    foreach ($visits as $row) {
        $visit_days[wp_date('Y-m-d', (int) ($row['next_visit_ts'] ?? 0))][] = $row;
    }

    ob_start();
    echo '<div class="ado-page-head"><h2>Schedule</h2><p class="ado-page-sub">Upcoming visits, confirmation status, and visit requests for your projects.</p></div>';
    echo '<div class="ado-sched-layout">';
    echo '<div>';
    echo '<article class="ado-card" style="margin-bottom:20px;"><div class="ado-calendar-card">';
    echo '<div class="ado-cal-header"><div class="ado-cal-month">' . esc_html(wp_date('F Y', $calendar_month_ts)) . '</div><div class="ado-row-sub">AutoDoor Experts availability shown against your booked visits.</div></div>';
    echo '<div class="ado-cal-grid">';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $label) {
        echo '<div class="ado-cal-day-label">' . esc_html($label) . '</div>';
    }
    for ($day_ts = (int) $grid_start; $day_ts <= (int) $grid_end; $day_ts = strtotime('+1 day', $day_ts)) {
        $day_key = wp_date('Y-m-d', $day_ts);
        $is_current_month = wp_date('m', $day_ts) === wp_date('m', $calendar_month_ts);
        $is_today = wp_date('Y-m-d', $day_ts) === wp_date('Y-m-d', $current_ts);
        $has_visit = !empty($visit_days[$day_key]);
        $cell_classes = ['ado-cal-cell'];
        if (!$is_current_month) { $cell_classes[] = 'muted'; }
        if ($is_today) { $cell_classes[] = 'today'; }
        if ($has_visit) { $cell_classes[] = 'has-visit'; }
        echo '<div class="' . esc_attr(implode(' ', $cell_classes)) . '">';
        echo '<span>' . esc_html(wp_date('j', $day_ts)) . '</span>';
        if ($has_visit) {
            $tone = ((string) ($visit_days[$day_key][0]['status_tone'] ?? '') === 'pending') ? 'warn' : 'blue';
            echo '<span class="ado-cal-dot ' . esc_attr($tone) . '"></span>';
        }
        echo '</div>';
    }
    echo '</div></div></article>';

    if (!$upcoming) {
        echo '<article class="ado-card"><div class="ado-empty">No upcoming visits scheduled yet.</div></article>';
    } else {
        foreach ($upcoming as $row) {
            $visit_ts = (int) ($row['next_visit_ts'] ?? 0);
            $status_label = (string) ($row['status_tone'] ?? '') === 'pending' ? 'Confirm visit' : 'Confirmed';
            echo '<article class="ado-visit-card">';
            echo '<div class="ado-visit-card-top">';
            echo '<div class="ado-visit-date-box' . (wp_date('Y-m-d', $visit_ts) === wp_date('Y-m-d', $current_ts) ? ' today' : '') . '"><div class="ado-vdb-mo">' . esc_html(wp_date('M', $visit_ts)) . '</div><div class="ado-vdb-day">' . esc_html(wp_date('j', $visit_ts)) . '</div></div>';
            echo '<div class="ado-visit-info">';
            echo '<div class="ado-visit-title">' . esc_html((string) ($row['name'] ?? 'Project visit')) . '</div>';
            echo '<div class="ado-visit-meta">' . esc_html((string) ($row['next_visit'] ?? wp_date('M j, Y', $visit_ts)) . ' | ' . (string) ($row['technicians'] ?? 'Assigned by operations')) . '</div>';
            echo '<div class="ado-visit-tags">' . ado_cd_status_chip_html((string) ($row['status_label'] ?? 'Scheduled'), (string) ($row['status_tone'] ?? 'draft')) . '<span class="ado-mini-tag ' . esc_attr((string) ($row['status_tone'] ?? 'draft')) . '">' . esc_html($status_label) . '</span></div>';
            echo '</div></div>';
            if ((string) ($row['critical_note'] ?? '') !== '') {
                echo '<div class="ado-visit-card-body"><span class="ado-visit-note">' . esc_html((string) $row['critical_note']) . '</span></div>';
            }
            echo '</article>';
        }
    }
    echo '</div>';

    echo '<div>';
    echo '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Request a Visit</span></div><div style="padding:16px 20px 20px;">';
    echo '<form id="ado-visit-request-form" class="ado-request-form">';
    echo '<label>Project<select name="order_id">';
    foreach (ado_cd_project_options($user_id) as $option) {
        echo '<option value="' . esc_attr((string) ($option['id'] ?? 0)) . '">' . esc_html((string) ($option['label'] ?? 'Project')) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Preferred Date<input type="date" name="preferred_date"></label>';
    echo '<label>Preferred Time<select name="time_slot"><option value="Morning (7-11 AM)">Morning (7-11 AM)</option><option value="Afternoon (12-4 PM)">Afternoon (12-4 PM)</option><option value="Flexible">Flexible</option></select></label>';
    echo '<label>Reason for Visit<select name="reason"><option value="Installation - continue work">Installation - continue work</option><option value="Service / repair">Service / repair</option><option value="Inspection">Inspection</option><option value="Site survey">Site survey</option></select></label>';
    echo '<label>Notes for the team<textarea name="notes" placeholder="Any access instructions, site contact, or notes."></textarea></label>';
    echo '<button class="ado-btn primary" type="submit" style="width:100%;justify-content:center;margin-top:16px;">Submit Request</button>';
    echo '</form></div></article>';

    echo '<article class="ado-card" style="margin-top:16px;"><div class="ado-card-head"><span class="ado-card-title">Visit History</span></div>';
    if (!$history) {
        echo '<div class="ado-empty">Past visit history will appear here after scheduled work is completed.</div>';
    } else {
        echo '<div class="ado-history-list">';
        foreach (array_slice($history, 0, 6) as $row) {
            echo '<div class="ado-history-row"><span>' . esc_html(wp_date('M j, Y', (int) ($row['next_visit_ts'] ?? 0)) . ' | ' . (string) ($row['name'] ?? 'Project')) . '</span>' . ado_cd_status_chip_html('Done', 'completed') . '</div>';
        }
        echo '</div>';
    }
    echo '</article>';
    echo '</div>';
    echo '</div>';
    return (string) ob_get_clean();
}

function ado_cd_account_name(int $user_id): string {
    $company = trim((string) get_user_meta($user_id, 'billing_company', true));
    if ($company !== '') {
        return $company;
    }
    $user = get_userdata($user_id);
    if ($user && trim((string) $user->display_name) !== '') {
        return trim((string) $user->display_name);
    }
    return 'Client Account';
}

function ado_cd_order_label(WC_Order $order): string {
    $company = trim((string) $order->get_billing_company());
    if ($company !== '') {
        return $company;
    }
    foreach ($order->get_items() as $item) {
        $name = trim((string) $item->get_name());
        if ($name !== '') {
            return $name;
        }
    }
    return 'Project #' . $order->get_id();
}

function ado_cd_order_location(WC_Order $order): string {
    $parts = array_filter([
        trim((string) $order->get_billing_address_1()),
        trim((string) $order->get_billing_city()),
        trim((string) $order->get_billing_state()),
    ]);
    if ($parts) {
        return implode(', ', $parts);
    }
    return 'Site location pending';
}

function ado_cd_order_status_label(WC_Order $order): string {
    $status = strtolower((string) $order->get_status());
    if ($status === 'processing') { return 'In Progress'; }
    if ($status === 'completed') { return 'Completed'; }
    if ($status === 'on-hold') { return 'On Hold'; }
    if ($status === 'pending') { return 'Pending'; }
    return wc_get_order_status_name($status);
}

function ado_cd_status_tone_for_order(WC_Order $order): string {
    $status = strtolower((string) $order->get_status());
    if ($status === 'completed') { return 'completed'; }
    if ($status === 'processing') { return 'in-progress'; }
    if (in_array($status, ['pending', 'on-hold'], true)) { return 'pending'; }
    return 'draft';
}

function ado_cd_status_chip_html(string $label, string $tone): string {
    return '<span class="ado-status-chip ' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
}

function ado_cd_initials_from_label(string $label): string {
    $parts = preg_split('/\s+/', trim($label)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part === '') { continue; }
        $initials .= strtoupper((string) substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : 'CA';
}

function ado_cd_account_initials(int $user_id): string {
    return ado_cd_initials_from_label(ado_cd_account_name($user_id));
}

function ado_cd_day_greeting(): string {
    $hour = (int) wp_date('G');
    if ($hour < 12) { return 'Good morning'; }
    if ($hour < 18) { return 'Good afternoon'; }
    return 'Good evening';
}

function ado_cd_order_technician_names(WC_Order $order): string {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) $order->get_meta('_ado_technician_ids')))));
    if (!$ids) {
        return 'Assigned by operations';
    }
    $names = [];
    foreach ($ids as $user_id) {
        $user = get_userdata($user_id);
        if (!$user) { continue; }
        $names[] = trim((string) $user->display_name);
    }
    return $names ? implode(', ', $names) : 'Assigned by operations';
}

function ado_cd_scope_payload_from_path(string $scope_path): array {
    static $cache = [];
    if ($scope_path === '') {
        return [];
    }
    if (array_key_exists($scope_path, $cache)) {
        return (array) $cache[$scope_path];
    }
    if (!file_exists($scope_path)) {
        $cache[$scope_path] = [];
        return [];
    }
    $payload = json_decode((string) file_get_contents($scope_path), true);
    $cache[$scope_path] = is_array($payload) ? $payload : [];
    return (array) $cache[$scope_path];
}

function ado_cd_order_scope_payload(WC_Order $order): array {
    return ado_cd_scope_payload_from_path(trim((string) $order->get_meta('_ado_scoped_json_path')));
}

function ado_cd_draft_scope_payload(array $draft): array {
    return ado_cd_scope_payload_from_path(trim((string) ($draft['scope_path'] ?? '')));
}

function ado_cd_upload_path_to_url(string $path): string {
    $uploads = wp_upload_dir();
    $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
    $base_url = (string) ($uploads['baseurl'] ?? '');
    $normalized = wp_normalize_path($path);
    if ($normalized === '' || $base_dir === '' || strpos($normalized, $base_dir) !== 0) {
        return '';
    }
    $relative = ltrim((string) substr($normalized, strlen($base_dir)), '/');
    return trailingslashit($base_url) . $relative;
}

function ado_cd_scope_pdf_url(array $payload): string {
    return ado_cd_upload_path_to_url(trim((string) ($payload['meta']['stored_pdf_path'] ?? '')));
}

function ado_cd_draft_source_pdf_url(array $draft): string {
    return ado_cd_scope_pdf_url(ado_cd_draft_scope_payload($draft));
}

function ado_cd_normalize_door_ref(string $door_id): string {
    return strtoupper((string) preg_replace('/[^A-Z0-9]+/', '', $door_id));
}

function ado_cd_extract_operator_models(array $door): array {
    $preferred = [];
    $fallback = [];
    foreach ((array) ($door['items'] ?? []) as $item) {
        if (!is_array($item)) { continue; }
        $catalog = trim((string) ($item['catalog'] ?? ''));
        if ($catalog === '') { continue; }
        $desc = strtolower(trim((string) ($item['desc'] ?? '')));
        if ($desc !== '' && (strpos($desc, 'operator') !== false || strpos($desc, 'actuator') !== false || strpos($desc, 'drive') !== false)) {
            $preferred[] = $catalog;
            continue;
        }
        $fallback[] = $catalog;
    }
    $models = $preferred ?: $fallback;
    return array_values(array_unique(array_slice($models, 0, 3)));
}

function ado_cd_order_blocked_door_ids(WC_Order $order): array {
    $note = trim((string) $order->get_meta('_ado_critical_notes'));
    if ($note === '') {
        return [];
    }
    preg_match_all('/\bD?[0-9]{1,4}[A-Z]?\b/i', $note, $matches);
    $mentioned = array_values(array_unique(array_filter(array_map('ado_cd_normalize_door_ref', (array) ($matches[0] ?? [])))));
    if (!$mentioned) {
        return [];
    }
    $blocked = [];
    foreach ((array) (ado_cd_order_scope_payload($order)['result']['doors'] ?? []) as $door) {
        if (!is_array($door)) { continue; }
        $door_ref = ado_cd_normalize_door_ref((string) ($door['door_id'] ?? ''));
        if ($door_ref === '') { continue; }
        if (in_array($door_ref, $mentioned, true) || in_array('D' . $door_ref, $mentioned, true)) {
            $blocked[] = $door_ref;
        }
    }
    return array_values(array_unique($blocked));
}

function ado_cd_order_door_rows(WC_Order $order, int $limit = 0): array {
    $rows = [];
    $doors = (array) (ado_cd_order_scope_payload($order)['result']['doors'] ?? []);
    $blocked = ado_cd_order_blocked_door_ids($order);
    $order_tone = ado_cd_status_tone_for_order($order);
    $order_label = ado_cd_order_status_label($order);
    $critical_note = trim((string) $order->get_meta('_ado_critical_notes'));

    foreach ($doors as $door) {
        if (!is_array($door)) { continue; }
        $door_id = trim((string) ($door['door_id'] ?? ''));
        if ($door_id === '') { continue; }
        $door_ref = ado_cd_normalize_door_ref($door_id);
        $location = trim((string) ($door['desc'] ?? ''));
        if ($location === '') {
            $location = trim((string) ($door['header_line'] ?? ''));
        }
        if ($location === '') {
            $location = 'Door location pending';
        }
        $models = ado_cd_extract_operator_models($door);
        $tone = $order_tone;
        $label = $order_label;
        $note = '';
        if (in_array($door_ref, $blocked, true) || in_array('D' . $door_ref, $blocked, true)) {
            $tone = 'blocked';
            $label = 'Blocked';
            $note = $critical_note;
        } elseif ($tone === 'completed') {
            $label = 'Installed';
        } elseif ($tone === 'in-progress') {
            $label = 'In Progress';
        } elseif ($tone === 'pending') {
            $label = 'Pending';
        }
        $rows[] = [
            'door_id' => $door_id,
            'location' => $location,
            'models' => $models,
            'tone' => $tone,
            'label' => $label,
            'note' => $note,
        ];
        if ($limit > 0 && count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

function ado_cd_order_door_count(WC_Order $order): int {
    $payload = ado_cd_order_scope_payload($order);
    $count = (int) (($payload['result']['door_count'] ?? 0));
    if ($count > 0) {
        return $count;
    }
    return count((array) ($payload['result']['doors'] ?? []));
}

function ado_cd_order_open_flags(WC_Order $order): int {
    $blocked = ado_cd_order_blocked_door_ids($order);
    if ($blocked) {
        return count($blocked);
    }
    $note = trim((string) $order->get_meta('_ado_critical_notes'));
    if ($note === '') {
        return 0;
    }
    return count(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $note) ?: []), 'strlen'));
}

function ado_cd_project_rows_data(int $user_id): array {
    $rows = [];
    foreach (ado_cd_client_orders($user_id) as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $visit = trim((string) $order->get_meta('_ado_next_visit_date'));
        $visit_ts = $visit !== '' ? strtotime($visit) : false;
        $rows[] = [
            'order' => $order,
            'order_id' => $order->get_id(),
            'name' => ado_cd_order_label($order),
            'location' => ado_cd_order_location($order),
            'status_label' => ado_cd_order_status_label($order),
            'status_tone' => ado_cd_status_tone_for_order($order),
            'po' => trim((string) $order->get_meta('_ado_po_number')),
            'next_visit' => $visit,
            'next_visit_ts' => $visit_ts ? (int) $visit_ts : 0,
            'technicians' => ado_cd_order_technician_names($order),
            'door_count' => ado_cd_order_door_count($order),
            'door_rows' => ado_cd_order_door_rows($order),
            'open_flags' => ado_cd_order_open_flags($order),
            'critical_note' => trim((string) $order->get_meta('_ado_critical_notes')),
            'total_value' => (float) $order->get_total(),
            'value_html' => ado_cd_currency((float) $order->get_total()),
            'quote_draft_id' => trim((string) $order->get_meta('_ado_quote_draft_id')),
        ];
    }
    return $rows;
}

function ado_cd_quote_drafts(int $user_id): array {
    return function_exists('ado_get_quote_drafts') ? array_values((array) ado_get_quote_drafts($user_id)) : [];
}

function ado_cd_orders_by_quote_draft_id(int $user_id): array {
    $map = [];
    foreach (ado_cd_client_orders($user_id) as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $draft_id = trim((string) $order->get_meta('_ado_quote_draft_id'));
        if ($draft_id !== '') {
            $map[$draft_id] = $order;
        }
    }
    return $map;
}

function ado_cd_quote_preview_rows(array $draft, int $limit = 6): array {
    $rows = [];
    foreach ((array) ($draft['items'] ?? []) as $item) {
        if (!is_array($item)) { continue; }
        $product = wc_get_product((int) ($item['product_id'] ?? 0));
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $line_total = $product ? ((float) $product->get_price() * $qty) : 0.0;
        $rows[] = [
            'door' => trim((string) ($item['door_number'] ?? '')) !== '' ? (string) $item['door_number'] : 'Door pending',
            'item' => $product ? $product->get_name() : (trim((string) ($item['source_desc'] ?? '')) ?: 'Quoted line item'),
            'model' => $product ? ((string) $product->get_sku() ?: (string) ($item['source_model'] ?? '')) : (string) ($item['source_model'] ?? ''),
            'qty' => $qty,
            'line_total' => $line_total,
        ];
        if ($limit > 0 && count($rows) >= $limit) {
            break;
        }
    }
    return $rows;
}

function ado_cd_quote_total(array $draft): float {
    $total = 0.0;
    foreach ((array) ($draft['items'] ?? []) as $item) {
        if (!is_array($item)) { continue; }
        $product = wc_get_product((int) ($item['product_id'] ?? 0));
        if (!$product) { continue; }
        $total += (float) $product->get_price() * max(1, (int) ($item['qty'] ?? 1));
    }
    return $total;
}

function ado_cd_quote_created_label(array $draft): string {
    $created = trim((string) ($draft['created_at'] ?? ''));
    $ts = $created !== '' ? strtotime($created) : false;
    return $ts ? wp_date('M j, Y', (int) $ts) : ($created !== '' ? $created : 'Saved in portal');
}

function ado_cd_quote_state(array $draft, array $orders_by_draft): array {
    $draft_id = trim((string) ($draft['id'] ?? ''));
    if ($draft_id !== '' && isset($orders_by_draft[$draft_id]) && $orders_by_draft[$draft_id] instanceof WC_Order) {
        $order = $orders_by_draft[$draft_id];
        $po = trim((string) $order->get_meta('_ado_po_number'));
        return [
            'label' => $po !== '' ? 'Approved | ' . $po : 'Approved',
            'tone' => 'approved',
            'order' => $order,
        ];
    }
    if ((int) ($draft['total_items'] ?? 0) <= 0) {
        return ['label' => 'Draft', 'tone' => 'draft', 'order' => null];
    }
    return ['label' => 'Awaiting Your Approval', 'tone' => 'awaiting', 'order' => null];
}

function ado_cd_invoice_summary(int $user_id): array {
    $rows = ado_cd_render_invoice_rows_data($user_id);
    $summary = [
        'rows' => $rows,
        'overdue_total' => 0.0,
        'overdue_count' => 0,
        'pending_total' => 0.0,
        'pending_count' => 0,
        'paid_total' => 0.0,
        'paid_count' => 0,
        'total_total' => 0.0,
        'total_count' => count($rows),
        'first_overdue' => null,
    ];
    foreach ($rows as $row) {
        $amount = (float) ($row['amount_due'] ?? 0.0);
        $summary['total_total'] += $amount;
        $status_key = (string) ($row['status_key'] ?? '');
        if ($status_key === 'overdue') {
            $summary['overdue_total'] += $amount;
            $summary['overdue_count']++;
            if ($summary['first_overdue'] === null) {
                $summary['first_overdue'] = $row;
            }
        } elseif ($status_key === 'paid') {
            $summary['paid_total'] += $amount;
            $summary['paid_count']++;
        } else {
            $summary['pending_total'] += $amount;
            $summary['pending_count']++;
        }
    }
    return $summary;
}

function ado_cd_support_email(): string {
    return (string) apply_filters('ado_client_portal_support_email', get_option('admin_email'));
}

function ado_cd_billing_info_lines(int $user_id): array {
    $company = trim((string) get_user_meta($user_id, 'billing_company', true));
    $address_1 = trim((string) get_user_meta($user_id, 'billing_address_1', true));
    $address_2 = trim((string) get_user_meta($user_id, 'billing_address_2', true));
    $city = trim((string) get_user_meta($user_id, 'billing_city', true));
    $state = trim((string) get_user_meta($user_id, 'billing_state', true));
    $postcode = trim((string) get_user_meta($user_id, 'billing_postcode', true));
    $email = trim((string) get_user_meta($user_id, 'billing_email', true));
    $phone = trim((string) get_user_meta($user_id, 'billing_phone', true));

    $city_line = trim(implode(', ', array_filter([$city, $state])));
    if ($postcode !== '') {
        $city_line = trim($city_line . ' ' . $postcode);
    }

    return array_values(array_filter([
        $company,
        $address_1,
        $address_2,
        $city_line,
        $email,
        $phone,
    ], static fn($line): bool => trim((string) $line) !== ''));
}

function ado_cd_site_doc_owner_meta_key(): string {
    return '_ado_site_doc_owner';
}

function ado_cd_site_doc_order_meta_key(): string {
    return '_ado_site_doc_order_id';
}

function ado_cd_site_doc_project_meta_key(): string {
    return '_ado_site_doc_project_label';
}

function ado_cd_site_doc_type_meta_key(): string {
    return '_ado_site_doc_doc_type';
}

function ado_cd_project_options(int $user_id): array {
    $options = [['id' => 0, 'label' => 'General / No specific project']];
    foreach (ado_cd_client_orders($user_id) as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $options[] = [
            'id' => $order->get_id(),
            'label' => ado_cd_order_label($order),
        ];
    }
    return $options;
}

function ado_cd_collect_site_docs(int $user_id): array {
    $docs = [];
    $attachments = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 200,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [
            [
                'key' => ado_cd_site_doc_owner_meta_key(),
                'value' => $user_id,
                'compare' => '=',
            ],
        ],
    ]);

    foreach ($attachments as $attachment) {
        $attachment_id = (int) $attachment->ID;
        $file = (string) get_attached_file($attachment_id);
        $order_id = (int) get_post_meta($attachment_id, ado_cd_site_doc_order_meta_key(), true);
        $project_label = trim((string) get_post_meta($attachment_id, ado_cd_site_doc_project_meta_key(), true));
        if ($project_label === '') {
            $project_label = $order_id > 0 ? 'Project #' . $order_id : 'General / No specific project';
        }
        $docs[] = [
            'attachment_id' => $attachment_id,
            'order_id' => $order_id,
            'project_label' => $project_label,
            'type' => trim((string) get_post_meta($attachment_id, ado_cd_site_doc_type_meta_key(), true)),
            'file_name' => basename((string) $file),
            'extension' => strtolower((string) pathinfo((string) $file, PATHINFO_EXTENSION)),
            'size' => ($file !== '' && file_exists($file)) ? size_format((int) filesize($file), 1) : '',
            'url' => (string) wp_get_attachment_url($attachment_id),
            'created_at' => get_post_time('Y-m-d H:i:s', false, $attachment_id),
        ];
    }

    return $docs;
}

function ado_cd_site_doc_category(array $doc): string {
    $type = strtolower(trim((string) ($doc['type'] ?? '')));
    if ($type !== '') {
        return $type;
    }
    $name = strtolower(trim((string) ($doc['file_name'] ?? '')));
    if ($name === '') {
        return '';
    }
    if (strpos($name, 'hardware') !== false && strpos($name, 'schedule') !== false) { return 'hardware_schedule'; }
    if (strpos($name, 'plan') !== false || strpos($name, 'drawing') !== false || strpos($name, 'dwg') !== false || strpos($name, 'dxf') !== false) { return 'floor_plan'; }
    if (strpos($name, 'contact') !== false || strpos($name, 'gc') !== false) { return 'gc_contact'; }
    if (strpos($name, 'ahj') !== false || strpos($name, 'inspection') !== false || strpos($name, 'permit') !== false) { return 'ahj_requirements'; }
    if (strpos($name, 'access') !== false || strpos($name, 'instruction') !== false || strpos($name, 'logistics') !== false) { return 'site_access'; }
    return '';
}

function ado_cd_required_doc_rows(int $user_id): array {
    $labels = [
        'hardware_schedule' => 'Hardware schedule',
        'floor_plan' => 'Floor plan / door schedule',
        'gc_contact' => 'GC contact information',
        'ahj_requirements' => 'AHJ requirements',
        'site_access' => 'Site access instructions',
    ];
    $docs = ado_cd_collect_site_docs($user_id);
    $projects = array_values(array_filter(ado_cd_project_options($user_id), static function (array $option): bool {
        return trim((string) ($option['label'] ?? '')) !== '';
    }));
    if (!$projects) {
        $projects = [['id' => 0, 'label' => 'General / No specific project']];
    }
    $present_by_project = [];
    foreach ($docs as $doc) {
        $project_label = trim((string) ($doc['project_label'] ?? ''));
        if ($project_label === '') { continue; }
        $category = ado_cd_site_doc_category($doc);
        if ($category !== '') {
            $present_by_project[$project_label][$category] = true;
        }
    }
    $rows = [];
    foreach ($projects as $project) {
        $project_label = (string) ($project['label'] ?? 'General / No specific project');
        foreach ($labels as $key => $label) {
            $rows[] = [
                'label' => $label,
                'project_label' => $project_label,
                'present' => !empty($present_by_project[$project_label][$key]),
            ];
        }
    }
    return $rows;
}

function ado_cd_assert_client_ajax(): int {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Please sign in.'], 401);
    }
    if (!ado_is_client()) {
        wp_send_json_error(['message' => 'Client access only.'], 403);
    }
    check_ajax_referer('ado_client_portal_nonce', 'nonce');
    return (int) get_current_user_id();
}

function ado_cd_normalize_uploaded_files(array $files): array {
    $normalized = [];
    $count = is_array($files['name'] ?? null) ? count((array) $files['name']) : 0;
    for ($index = 0; $index < $count; $index++) {
        $normalized[] = [
            'name' => (string) ($files['name'][$index] ?? ''),
            'type' => (string) ($files['type'][$index] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
            'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$index] ?? 0),
        ];
    }
    return $normalized;
}

function ado_cd_create_attachment_from_upload(array $file, int $user_id, int $order_id, string $project_label, string $doc_type): int {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $uploaded = wp_handle_upload($file, ['test_form' => false]);
    if (!empty($uploaded['error'])) {
        throw new RuntimeException((string) $uploaded['error']);
    }

    $attachment = [
        'post_mime_type' => (string) ($uploaded['type'] ?? ''),
        'post_title' => sanitize_text_field((string) pathinfo((string) $file['name'], PATHINFO_FILENAME)),
        'post_status' => 'inherit',
        'post_author' => $user_id,
    ];
    $attachment_id = wp_insert_attachment($attachment, (string) $uploaded['file']);
    if (!$attachment_id || is_wp_error($attachment_id)) {
        throw new RuntimeException('Could not save attachment.');
    }

    $metadata = wp_generate_attachment_metadata($attachment_id, (string) $uploaded['file']);
    if (is_array($metadata)) {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    update_post_meta($attachment_id, ado_cd_site_doc_owner_meta_key(), $user_id);
    update_post_meta($attachment_id, ado_cd_site_doc_order_meta_key(), $order_id);
    update_post_meta($attachment_id, ado_cd_site_doc_project_meta_key(), $project_label);
    if ($doc_type !== '') {
        update_post_meta($attachment_id, ado_cd_site_doc_type_meta_key(), $doc_type);
    }
    return (int) $attachment_id;
}

add_filter('upload_mimes', static function (array $mimes): array {
    $mimes['dwg'] = 'image/vnd.dwg';
    $mimes['dxf'] = 'image/vnd.dxf';
    $mimes['doc'] = 'application/msword';
    $mimes['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $mimes['zip'] = 'application/zip';
    return $mimes;
});

add_action('wp_ajax_ado_cd_upload_site_docs', static function (): void {
    $user_id = ado_cd_assert_client_ajax();
    if (empty($_FILES['site_docs'])) {
        wp_send_json_error(['message' => 'Choose at least one file to upload.'], 400);
    }

    $order_id = (int) ($_POST['order_id'] ?? 0);
    $doc_type = sanitize_key((string) ($_POST['doc_type'] ?? ''));
    $project_label = 'General / No specific project';
    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order) || (int) $order->get_customer_id() !== $user_id) {
            wp_send_json_error(['message' => 'Project not found for this account.'], 404);
        }
        $project_label = ado_cd_order_label($order);
    }

    $uploaded_any = false;
    foreach (ado_cd_normalize_uploaded_files((array) $_FILES['site_docs']) as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'One of the files failed to upload.'], 400);
        }
        ado_cd_create_attachment_from_upload($file, $user_id, $order_id, $project_label, $doc_type);
        $uploaded_any = true;
    }

    if (!$uploaded_any) {
        wp_send_json_error(['message' => 'Choose at least one file to upload.'], 400);
    }

    wp_send_json_success(['message' => 'Files uploaded successfully.']);
});

add_action('wp_ajax_ado_cd_delete_site_doc', static function (): void {
    $user_id = ado_cd_assert_client_ajax();
    $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
    if ($attachment_id <= 0) {
        wp_send_json_error(['message' => 'Attachment not found.'], 404);
    }
    $owner_id = (int) get_post_meta($attachment_id, ado_cd_site_doc_owner_meta_key(), true);
    if ($owner_id !== $user_id) {
        wp_send_json_error(['message' => 'Document access denied.'], 403);
    }
    wp_delete_attachment($attachment_id, true);
    wp_send_json_success(['message' => 'Document removed.']);
});

add_action('wp_ajax_ado_cd_request_visit', static function (): void {
    $user_id = ado_cd_assert_client_ajax();
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $preferred_date = sanitize_text_field((string) ($_POST['preferred_date'] ?? ''));
    $time_slot = sanitize_text_field((string) ($_POST['time_slot'] ?? ''));
    $reason = sanitize_text_field((string) ($_POST['reason'] ?? ''));
    $notes = sanitize_textarea_field((string) ($_POST['notes'] ?? ''));

    if ($preferred_date === '' || $reason === '') {
        wp_send_json_error(['message' => 'Preferred date and visit reason are required.'], 400);
    }

    $project_label = 'General / No specific project';
    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order) || (int) $order->get_customer_id() !== $user_id) {
            wp_send_json_error(['message' => 'Project not found for this account.'], 404);
        }
        $project_label = ado_cd_order_label($order);
    }

    $subject = sprintf('Client portal visit request: %s', $project_label);
    $message = implode("\n", array_filter([
        'Account: ' . ado_cd_account_name($user_id),
        'Project: ' . $project_label,
        'Preferred date: ' . $preferred_date,
        'Preferred time: ' . ($time_slot !== '' ? $time_slot : 'Flexible'),
        'Reason: ' . $reason,
        $notes !== '' ? 'Notes: ' . $notes : '',
    ], 'strlen'));

    if (!wp_mail(ado_cd_support_email(), $subject, $message)) {
        wp_send_json_error(['message' => 'Visit request could not be sent right now.'], 500);
    }

    wp_send_json_success(['message' => 'Visit request sent to AutoDoor Experts.']);
});

add_action('wp_ajax_ado_cd_request_quote_change', static function (): void {
    $user_id = ado_cd_assert_client_ajax();
    $draft_id = sanitize_text_field((string) ($_POST['draft_id'] ?? ''));
    $notes = sanitize_textarea_field((string) ($_POST['notes'] ?? ''));
    if ($draft_id === '' || $notes === '') {
        wp_send_json_error(['message' => 'Quote note is required.'], 400);
    }
    $draft = function_exists('ado_find_draft_by_id') ? ado_find_draft_by_id($user_id, $draft_id) : null;
    if (!$draft) {
        wp_send_json_error(['message' => 'Quote draft not found.'], 404);
    }

    $subject = sprintf('Client requested quote changes: %s', (string) ($draft['name'] ?? 'Quote Draft'));
    $message = implode("\n", array_filter([
        'Account: ' . ado_cd_account_name($user_id),
        'Quote: ' . (string) ($draft['name'] ?? 'Quote Draft'),
        'Saved: ' . ado_cd_quote_created_label($draft),
        'Requested changes:',
        $notes,
    ], 'strlen'));

    if (!wp_mail(ado_cd_support_email(), $subject, $message)) {
        wp_send_json_error(['message' => 'Quote request could not be sent right now.'], 500);
    }

    wp_send_json_success(['message' => 'Change request sent to AutoDoor Experts.']);
});

function ado_cd_file_icon_class(array $doc): string {
    $extension = strtolower(trim((string) ($doc['extension'] ?? '')));
    if ($extension === 'pdf') { return 'pdf'; }
    if (in_array($extension, ['dwg', 'dxf'], true)) { return 'dwg'; }
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) { return 'img'; }
    if (in_array($extension, ['doc', 'docx'], true)) { return 'doc'; }
    return 'file';
}

function ado_cd_file_icon_label(array $doc): string {
    $extension = strtoupper(trim((string) ($doc['extension'] ?? '')));
    return $extension !== '' ? $extension : 'FILE';
}

function ado_cd_render_dashboard_overview(int $user_id): string {
    $project_rows = ado_cd_project_rows_data($user_id);
    $invoice_summary = ado_cd_invoice_summary($user_id);
    $orders_by_draft = ado_cd_orders_by_quote_draft_id($user_id);
    $pending_quotes = array_values(array_filter(ado_cd_quote_drafts($user_id), static function (array $draft) use ($orders_by_draft): bool {
        return (string) (ado_cd_quote_state($draft, $orders_by_draft)['tone'] ?? '') !== 'approved';
    }));
    $upcoming = array_values(array_filter($project_rows, static fn(array $row): bool => (int) ($row['next_visit_ts'] ?? 0) > 0));
    usort($upcoming, static fn($a, $b) => ((int) ($a['next_visit_ts'] ?? 0)) <=> ((int) ($b['next_visit_ts'] ?? 0)));
    $critical = array_values(array_filter($project_rows, static fn(array $row): bool => trim((string) ($row['critical_note'] ?? '')) !== ''));
    $in_progress_count = count(array_filter($project_rows, static fn(array $row): bool => (string) ($row['status_tone'] ?? '') === 'in-progress'));
    $pending_project_count = count(array_filter($project_rows, static fn(array $row): bool => (string) ($row['status_tone'] ?? '') === 'pending'));
    $outstanding_total = (float) ($invoice_summary['overdue_total'] ?? 0.0) + (float) ($invoice_summary['pending_total'] ?? 0.0);

    ob_start();
    if (is_array($invoice_summary['first_overdue'] ?? null)) {
        $first_overdue = (array) $invoice_summary['first_overdue'];
        echo '<div class="ado-alert-banner"><div class="ado-alert-text"><strong>' . esc_html((string) ($first_overdue['invoice_id'] ?? 'Invoice')) . ' is overdue.</strong> ' . esc_html((string) ($first_overdue['project'] ?? 'A project invoice') . ' currently shows ' . (string) ($first_overdue['amount_html'] ?? 'an outstanding balance') . '.') . '</div>';
        if (trim((string) ($first_overdue['invoice_url'] ?? '')) !== '') {
            echo '<a class="ado-alert-action" target="_blank" rel="noopener" href="' . esc_url((string) $first_overdue['invoice_url']) . '">Open Invoice</a>';
        }
        echo '</div>';
    }

    echo '<div class="ado-welcome-bar"><div class="ado-welcome-greeting">' . esc_html(ado_cd_day_greeting()) . '</div><div class="ado-welcome-name">' . esc_html(ado_cd_account_name($user_id)) . '</div></div>';

    echo '<div class="ado-stats-row">';
    echo '<article class="ado-stat-card"><div class="ado-stat-label">Outstanding Balance</div><div class="ado-stat-value">' . esc_html(ado_cd_currency($outstanding_total)) . '</div><div class="ado-stat-sub">Across ' . esc_html((string) ($invoice_summary['pending_count'] + $invoice_summary['overdue_count'])) . ' invoice(s)</div><div class="ado-stat-pill danger">' . esc_html((string) ($invoice_summary['overdue_count'] ?? 0)) . ' overdue</div></article>';
    echo '<article class="ado-stat-card"><div class="ado-stat-label">Active Projects</div><div class="ado-stat-value">' . esc_html((string) count($project_rows)) . '</div><div class="ado-stat-sub">' . esc_html((string) $in_progress_count) . ' in progress | ' . esc_html((string) $pending_project_count) . ' pending</div><div class="ado-stat-pill success">Tracked by door and PO</div></article>';
    echo '<article class="ado-stat-card"><div class="ado-stat-label">Next Visit</div><div class="ado-stat-value">' . esc_html($upcoming ? wp_date('M j', (int) ($upcoming[0]['next_visit_ts'] ?? 0)) : 'None') . '</div><div class="ado-stat-sub">' . esc_html($upcoming ? (string) ($upcoming[0]['name'] ?? 'Upcoming project') : 'No scheduled visits yet') . '</div><div class="ado-stat-pill warn">' . esc_html($upcoming ? ((string) ($upcoming[0]['status_tone'] ?? '') === 'pending' ? 'Needs confirmation' : 'Confirmed') : 'Upload docs to unlock') . '</div></article>';
    echo '</div>';

    echo '<div class="ado-main-grid">';
    echo '<div class="ado-main-col">';
    echo '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Active Projects</span><a class="ado-card-link" href="' . ado_cd_view_url('projects') . '">View all</a></div>';
    if (!$project_rows) {
        echo '<div class="ado-empty">No active client projects yet.</div>';
    } else {
        echo '<table class="ado-table"><thead><tr><th>Project</th><th>Status</th><th>Doors</th><th>Next Visit</th><th></th></tr></thead><tbody>';
        foreach (array_slice($project_rows, 0, 4) as $row) {
            echo '<tr>';
            echo '<td><div class="ado-row-title">' . esc_html((string) ($row['name'] ?? 'Project')) . '</div><div class="ado-row-sub">' . esc_html((string) ($row['location'] ?? '')) . '</div></td>';
            echo '<td>' . ado_cd_status_chip_html((string) ($row['status_label'] ?? 'Tracked'), (string) ($row['status_tone'] ?? 'draft')) . '</td>';
            echo '<td><strong>' . esc_html((string) ($row['door_count'] ?? 0)) . '</strong></td>';
            echo '<td>' . esc_html((string) ($row['next_visit'] ?? 'Not booked')) . '</td>';
            echo '<td><a class="ado-icon-link" href="' . ado_cd_view_url('projects') . '">Open</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</article>';

    echo '<article class="ado-card" style="margin-top:20px;"><div class="ado-card-head"><span class="ado-card-title">Quotes Awaiting Your Decision</span><a class="ado-card-link" href="' . ado_cd_view_url('quotes') . '">View all</a></div>';
    echo ado_cd_render_quotes_queue($user_id);
    echo '</article>';
    echo '</div>';

    echo '<div class="ado-right-col">';
    echo '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Quick Actions</span></div><div class="ado-quick-actions">';
    echo '<a class="ado-quick-action-btn" href="' . ado_cd_view_url('new-quote') . '"><span class="ado-qa-icon active">+</span><span class="ado-qa-label">New Quote</span></a>';
    echo '<a class="ado-quick-action-btn" href="' . ado_cd_view_url('site-docs') . '"><span class="ado-qa-icon">UP</span><span class="ado-qa-label">Upload Site Docs</span></a>';
    echo '<a class="ado-quick-action-btn" href="' . ado_cd_view_url('schedule') . '"><span class="ado-qa-icon">GO</span><span class="ado-qa-label">Book Visit</span></a>';
    echo '<a class="ado-quick-action-btn" href="mailto:' . esc_attr(ado_cd_support_email()) . '"><span class="ado-qa-icon">?</span><span class="ado-qa-label">Contact Us</span></a>';
    echo '</div></article>';

    echo '<article class="ado-card" style="margin-top:20px;"><div class="ado-card-head"><span class="ado-card-title">Critical Project Notes</span><a class="ado-card-link" href="' . ado_cd_view_url('projects') . '">All projects</a></div>';
    if (!$critical) {
        echo '<div class="ado-empty">No critical project notes at the moment.</div>';
    } else {
        foreach (array_slice($critical, 0, 3) as $row) {
            echo '<div class="ado-note-item"><div class="ado-note-flag critical"></div><div class="ado-note-copy"><div class="ado-note-project">' . esc_html((string) ($row['name'] ?? 'Project')) . '</div><div class="ado-note-text">' . esc_html((string) ($row['critical_note'] ?? '')) . '</div><div class="ado-note-meta">' . esc_html((string) (($row['next_visit'] ?? '') !== '' ? 'Next visit: ' . (string) $row['next_visit'] : 'Awaiting scheduling update')) . '</div></div><span class="ado-note-priority critical">Critical</span></div>';
        }
    }
    echo '</article>';

    echo '<article class="ado-card" style="margin-top:20px;"><div class="ado-card-head"><span class="ado-card-title">Upcoming Visits</span><a class="ado-card-link" href="' . ado_cd_view_url('schedule') . '">Full schedule</a></div>';
    if (!$upcoming) {
        echo '<div class="ado-empty">No upcoming visits scheduled yet.</div>';
    } else {
        foreach (array_slice($upcoming, 0, 3) as $row) {
            $visit_ts = (int) ($row['next_visit_ts'] ?? 0);
            echo '<div class="ado-schedule-item"><div class="ado-schedule-date-box' . (wp_date('Y-m-d', $visit_ts) === wp_date('Y-m-d') ? ' today' : '') . '"><div class="ado-s-month">' . esc_html(wp_date('M', $visit_ts)) . '</div><div class="ado-s-day">' . esc_html(wp_date('j', $visit_ts)) . '</div></div><div class="ado-schedule-info"><div class="ado-schedule-title">' . esc_html((string) ($row['name'] ?? 'Project visit')) . '</div><div class="ado-schedule-detail">' . esc_html((string) ($row['technicians'] ?? 'Assigned by operations')) . '</div><span class="ado-schedule-tag ' . esc_attr((string) ($row['status_tone'] ?? 'draft')) . '">' . esc_html((string) ($row['status_tone'] ?? '') === 'pending' ? 'Needs confirmation' : 'Confirmed') . '</span></div></div>';
        }
    }
    echo '</article>';

    echo '<article class="ado-card" style="margin-top:20px;"><div class="ado-card-head"><span class="ado-card-title">Invoices</span><a class="ado-card-link" href="' . ado_cd_view_url('invoices') . '">All invoices</a></div>';
    if (!$invoice_summary['rows']) {
        echo '<div class="ado-empty">No invoice metadata linked yet.</div>';
    } else {
        foreach (array_slice((array) $invoice_summary['rows'], 0, 3) as $row) {
            echo '<div class="ado-invoice-item"><div class="ado-invoice-icon ' . esc_attr((string) ($row['status_key'] ?? 'pending')) . '"></div><div class="ado-invoice-info"><div class="ado-invoice-num">' . esc_html((string) ($row['invoice_id'] ?: 'Unlinked invoice')) . '</div><div class="ado-invoice-proj">' . esc_html((string) ($row['project'] ?? 'Project')) . '</div></div><div class="ado-invoice-right"><div class="ado-invoice-amount">' . esc_html((string) ($row['amount_html'] ?? '')) . '</div><div class="ado-invoice-due ' . esc_attr((string) ($row['status_key'] ?? 'pending')) . '">' . esc_html((string) ($row['status_label'] ?? 'Pending')) . '</div></div></div>';
        }
    }
    echo '</article>';
    echo '</div>';
    echo '</div>';

    return (string) ob_get_clean();
}

function ado_cd_render_quote_workspace_panel(int $user_id): string {
    $drafts = ado_cd_quote_drafts($user_id);
    $cart_count = (function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;
    ob_start();
    echo '<div class="ado-page-head"><h2>Request a Quote</h2><p class="ado-page-sub">Upload your hardware schedule PDF and convert it into a quote-ready Woo cart without leaving the client portal.</p></div>';
    echo '<div class="ado-quote-steps"><div class="ado-step done"><div class="ado-step-num">1</div><div class="ado-step-label">Project Info</div></div><div class="ado-step-divider done"></div><div class="ado-step active"><div class="ado-step-num">2</div><div class="ado-step-label">Hardware Schedule</div></div><div class="ado-step-divider"></div><div class="ado-step"><div class="ado-step-num">3</div><div class="ado-step-label">Review &amp; Submit</div></div></div>';
    echo '<div class="ado-info-box">Upload the hardware schedule PDF and the parser will build a quote draft from existing WooCommerce products. You can review the result, save drafts, and move directly into checkout approval.</div>';
    echo '<div class="ado-quote-layout">';
    echo '<div><article class="ado-card ado-quote-workspace-shell"><div class="ado-card-head"><span class="ado-card-title">Hardware Schedule Upload</span><span class="ado-row-sub">Parser-backed quote builder</span></div><div style="padding:20px 24px;">';
    echo do_shortcode('[ado_quote_workspace]');
    echo '</div></article></div>';
    echo '<div><aside class="ado-quote-summary-card"><div class="ado-qs-title">Quote Summary</div><div class="ado-qs-row"><span class="ado-qs-label">Account</span><span class="ado-qs-val">' . esc_html(ado_cd_account_name($user_id)) . '</span></div><div class="ado-qs-row"><span class="ado-qs-label">Saved quotes</span><span class="ado-qs-val">' . esc_html((string) count($drafts)) . '</span></div><div class="ado-qs-row"><span class="ado-qs-label">Cart items</span><span class="ado-qs-val">' . esc_html((string) $cart_count) . '</span></div><div class="ado-qs-row"><span class="ado-qs-label">Flow</span><span class="ado-qs-val">PDF -> Quote -> PO</span></div><div class="ado-qs-total"><span class="ado-qs-label">Next step</span><span class="ado-qs-val">Checkout approval</span></div><div class="ado-qs-note">Manual door entry is still handled through the generated Woo quote cart after the parser builds the initial scope.</div></aside></div>';
    echo '</div>';
    return (string) ob_get_clean();
}

function ado_cd_render_quotes_workspace(int $user_id): string {
    $drafts = ado_cd_quote_drafts($user_id);
    $orders_by_draft = ado_cd_orders_by_quote_draft_id($user_id);
    usort($drafts, static function (array $a, array $b): int {
        return strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? ''));
    });
    ob_start();
    echo '<div class="ado-page-head"><h2>My Quotes</h2><p class="ado-page-sub">Saved quote requests, approved conversions, and the current quote cart.</p></div>';
    echo '<div class="ado-quotes-filters"><span class="ado-filter-chip active">All Quotes (' . esc_html((string) count($drafts)) . ')</span><span class="ado-filter-chip">Awaiting Approval</span><span class="ado-filter-chip">Approved</span><a class="ado-btn primary" href="' . ado_cd_view_url('new-quote') . '">+ New Quote</a></div>';

    if (!$drafts) {
        echo '<article class="ado-card"><div class="ado-empty">No saved quote drafts yet. Upload a hardware schedule to start a quote.</div></article>';
    } else {
        foreach ($drafts as $draft) {
            $draft_id = (string) ($draft['id'] ?? '');
            $state = ado_cd_quote_state($draft, $orders_by_draft);
            $tone = (string) ($state['tone'] ?? 'draft');
            $order = ($state['order'] ?? null) instanceof WC_Order ? $state['order'] : null;
            $card_class = $tone === 'approved' ? 'approved' : ($tone === 'awaiting' ? 'awaiting-action' : 'draft');
            $quote_total = ado_cd_quote_total($draft);
            $preview_rows = ado_cd_quote_preview_rows($draft);
            $pdf_url = ado_cd_draft_source_pdf_url($draft);
            echo '<article class="ado-quote-card ' . esc_attr($card_class) . '">';
            echo '<div class="ado-quote-card-header">';
            echo '<div><div class="ado-qch-num">' . esc_html((string) ($draft['name'] ?? 'Quote Draft')) . '</div><div class="ado-qch-project">' . esc_html($order ? ado_cd_order_label($order) : ('Saved ' . ado_cd_quote_created_label($draft))) . '</div></div>';
            echo ado_cd_status_chip_html((string) ($state['label'] ?? 'Draft'), $tone === 'awaiting' ? 'pending' : ($tone === 'approved' ? 'completed' : 'draft'));
            echo '<div class="ado-qch-right"><div class="ado-qch-amount">' . esc_html($quote_total > 0 ? ado_cd_currency($quote_total) : 'Review in cart') . '</div><div class="ado-qch-date">' . esc_html('Saved ' . ado_cd_quote_created_label($draft)) . '</div></div>';
            echo '</div>';
            if ($preview_rows) {
                echo '<div class="ado-quote-card-body">';
                if ($tone !== 'approved') {
                    echo '<div class="ado-expiry-warn">Quote draft is ready for review. Approve it through checkout to capture the PO and convert it into a project.</div>';
                }
                echo '<table class="ado-quote-door-table"><thead><tr><th>Door</th><th>Item</th><th>Model</th><th style="text-align:right">Qty</th><th style="text-align:right">Line Total</th></tr></thead><tbody>';
                foreach ($preview_rows as $row) {
                    echo '<tr><td>' . esc_html((string) ($row['door'] ?? '')) . '</td><td>' . esc_html((string) ($row['item'] ?? '')) . '</td><td><span class="ado-model-code">' . esc_html((string) ($row['model'] ?? 'Pending')) . '</span></td><td style="text-align:right">' . esc_html((string) ($row['qty'] ?? 0)) . '</td><td style="text-align:right">' . esc_html(ado_cd_currency((float) ($row['line_total'] ?? 0))) . '</td></tr>';
                }
                echo '</tbody></table>';
                echo '<div class="ado-quote-actions">';
                if ($tone === 'approved') {
                    echo '<a class="ado-btn primary" href="' . ado_cd_view_url('projects') . '">View Project</a>';
                    if ($order && trim((string) $order->get_meta('_ado_wave_invoice_url')) !== '') {
                        echo '<a class="ado-btn" target="_blank" rel="noopener" href="' . esc_url((string) $order->get_meta('_ado_wave_invoice_url')) . '">Open Invoice</a>';
                    }
                } else {
                    echo '<button class="ado-btn primary ado-approve-quote" type="button" data-draft-id="' . esc_attr($draft_id) . '">Approve Quote &amp; Enter PO</button>';
                    echo '<button class="ado-btn ado-request-quote-change" type="button" data-draft-id="' . esc_attr($draft_id) . '">Request Changes</button>';
                    echo '<button class="ado-btn ado-preview-quote" type="button" data-draft-id="' . esc_attr($draft_id) . '">View Output</button>';
                    echo '<button class="ado-btn ado-decline-quote" type="button" data-draft-id="' . esc_attr($draft_id) . '">Decline</button>';
                }
                if ($pdf_url !== '') {
                    echo '<a class="ado-btn" target="_blank" rel="noopener" href="' . esc_url($pdf_url) . '">Download Source PDF</a>';
                }
                echo '</div></div>';
            }
            echo '</article>';
        }
    }

    echo '<div id="ado-quote-preview-output"></div>';
    echo '<article class="ado-card" style="margin-top:20px;"><div class="ado-card-head"><span class="ado-card-title">Current Quote Cart</span><a class="ado-card-link" href="' . esc_url(wc_get_cart_url()) . '">Open cart</a></div><div style="padding:16px 18px;">' . do_shortcode('[woocommerce_cart]') . '</div></article>';
    return (string) ob_get_clean();
}

function ado_cd_render_projects_workspace(int $user_id): string {
    $projects = ado_cd_project_rows_data($user_id);
    ob_start();
    echo '<div class="ado-page-head"><h2>My Projects</h2><p class="ado-page-sub">Track project progress, PO-backed approvals, door-level scope, and critical site notes.</p></div>';
    if (!$projects) {
        echo '<article class="ado-card"><div class="ado-empty">No projects yet.</div></article>';
        return (string) ob_get_clean();
    }
    echo '<div class="ado-projects-grid">';
    foreach ($projects as $project) {
        $order = $project['order'];
        if (!($order instanceof WC_Order)) { continue; }
        echo '<article class="ado-project-card">';
        echo '<div class="ado-project-card-top">';
        echo '<div class="ado-project-icon ' . esc_attr((string) ($project['status_tone'] ?? 'draft')) . '"></div>';
        echo '<div class="ado-project-header"><div class="ado-project-name">' . esc_html((string) ($project['name'] ?? 'Project')) . '</div><div class="ado-project-location">' . esc_html((string) ($project['location'] ?? '')) . '</div><div class="ado-project-meta-row">' . ado_cd_status_chip_html((string) ($project['status_label'] ?? 'Tracked'), (string) ($project['status_tone'] ?? 'draft')) . '<span class="ado-tag tag-blue">' . esc_html((string) (($project['po'] ?? '') !== '' ? $project['po'] : 'PO pending')) . '</span><span class="ado-tag tag-muted">' . esc_html($order->get_date_created() ? 'Started ' . $order->get_date_created()->date_i18n('M j') : 'Recently added') . '</span></div></div>';
        echo '<div class="ado-project-value"><div class="ado-project-value-amount">' . esc_html((string) ($project['value_html'] ?? '')) . '</div><div class="ado-project-value-label">Contract value</div></div>';
        echo '</div>';

        echo '<div class="ado-project-stats">';
        echo '<div class="ado-proj-stat"><div class="ado-proj-stat-label">Doors</div><div class="ado-proj-stat-val">' . esc_html((string) ($project['door_count'] ?? 0)) . '</div><div class="ado-proj-stat-sub">Door-level scope</div></div>';
        echo '<div class="ado-proj-stat"><div class="ado-proj-stat-label">Technician</div><div class="ado-proj-stat-val small">' . esc_html((string) ($project['technicians'] ?? 'Assigned by operations')) . '</div><div class="ado-proj-stat-sub">Current assignment</div></div>';
        echo '<div class="ado-proj-stat"><div class="ado-proj-stat-label">Next Visit</div><div class="ado-proj-stat-val small">' . esc_html((string) (($project['next_visit'] ?? '') !== '' ? $project['next_visit'] : 'Not scheduled')) . '</div><div class="ado-proj-stat-sub">Client-visible schedule</div></div>';
        echo '<div class="ado-proj-stat"><div class="ado-proj-stat-label">Open Flags</div><div class="ado-proj-stat-val' . ((int) ($project['open_flags'] ?? 0) > 0 ? ' danger' : '') . '">' . esc_html((string) ($project['open_flags'] ?? 0)) . '</div><div class="ado-proj-stat-sub">Require attention</div></div>';
        echo '</div>';

        if (!empty($project['door_rows'])) {
            echo '<div class="ado-project-doors"><table class="ado-door-table"><thead><tr><th>Door</th><th>Location</th><th>Operator</th><th>Status</th><th>Notes</th></tr></thead><tbody>';
            foreach (array_slice((array) $project['door_rows'], 0, 8) as $door_row) {
                $tone = (string) ($door_row['tone'] ?? 'draft');
                echo '<tr>';
                echo '<td><span class="ado-door-num ' . esc_attr($tone === 'blocked' ? 'blocked' : ($tone === 'completed' ? 'done' : '')) . '">' . esc_html((string) ($door_row['door_id'] ?? '')) . '</span></td>';
                echo '<td>' . esc_html((string) ($door_row['location'] ?? 'Door location pending')) . '</td>';
                echo '<td><span class="ado-model-code">' . esc_html(!empty($door_row['models']) ? implode(', ', (array) $door_row['models']) : 'Scope pending') . '</span></td>';
                echo '<td>' . ado_cd_status_chip_html((string) ($door_row['label'] ?? 'Tracked'), $tone === 'blocked' ? 'pending' : $tone) . '</td>';
                echo '<td>' . esc_html((string) ($door_row['note'] ?? '-')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<div class="ado-project-footer">';
        if (trim((string) ($project['critical_note'] ?? '')) !== '') {
            echo '<div class="ado-proj-note-preview critical">' . esc_html((string) $project['critical_note']) . '</div>';
        } else {
            echo '<div class="ado-proj-note-preview">No critical notes on this project right now.</div>';
        }
        echo '<a class="ado-btn" href="' . ado_cd_view_url('site-docs') . '">Site Docs</a><a class="ado-btn primary" href="mailto:' . esc_attr(ado_cd_support_email()) . '">Contact Us</a>';
        echo '</div>';
        echo '</article>';
    }
    echo '</div>';
    return (string) ob_get_clean();
}

function ado_cd_render_invoice_rows_data(int $user_id): array {
    $orders = ado_cd_client_orders($user_id);
    $rows = [];
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $wave_status = strtolower(trim((string) $order->get_meta('_ado_wave_status')));
        if ($wave_status === '') { continue; }
        $amount_due = (float) $order->get_meta('_ado_wave_amount_due');
        if ($amount_due <= 0) { $amount_due = (float) $order->get_total(); }
        $rows[] = [
            'invoice_id' => (string) $order->get_meta('_ado_wave_invoice_id'),
            'project' => ado_cd_order_label($order),
            'amount_due' => $amount_due,
            'amount_html' => ado_cd_currency($amount_due),
            'status_label' => ucfirst($wave_status),
            'status_key' => $wave_status,
            'invoice_url' => (string) $order->get_meta('_ado_wave_invoice_url'),
            'created' => $order->get_date_created() ? $order->get_date_created()->date_i18n('M j, Y') : '',
        ];
    }
    return $rows;
}

function ado_cd_render_invoices_workspace(int $user_id): string {
    $summary = ado_cd_invoice_summary($user_id);
    $rows = (array) ($summary['rows'] ?? []);
    $primary_invoice = is_array($summary['first_overdue'] ?? null) ? (array) $summary['first_overdue'] : ($rows[0] ?? null);
    ob_start();
    echo '<div class="ado-page-head"><h2>Invoices</h2><p class="ado-page-sub">Payment history and outstanding balances from Wave-linked invoice metadata.</p></div>';
    echo '<div class="ado-inv-summary-row">';
    echo '<div class="ado-inv-stat danger"><div class="ado-inv-stat-val">' . esc_html(ado_cd_currency((float) ($summary['overdue_total'] ?? 0.0))) . '</div><div class="ado-inv-stat-label">Overdue balance</div><div class="ado-mini-tag pending">' . esc_html((string) ($summary['overdue_count'] ?? 0)) . ' overdue</div></div>';
    echo '<div class="ado-inv-stat warn"><div class="ado-inv-stat-val">' . esc_html(ado_cd_currency((float) ($summary['pending_total'] ?? 0.0))) . '</div><div class="ado-inv-stat-label">Pending</div><div class="ado-mini-tag warn">' . esc_html((string) ($summary['pending_count'] ?? 0)) . ' pending</div></div>';
    echo '<div class="ado-inv-stat success"><div class="ado-inv-stat-val">' . esc_html(ado_cd_currency((float) ($summary['paid_total'] ?? 0.0))) . '</div><div class="ado-inv-stat-label">Paid</div><div class="ado-mini-tag completed">' . esc_html((string) ($summary['paid_count'] ?? 0)) . ' paid</div></div>';
    echo '<div class="ado-inv-stat"><div class="ado-inv-stat-val">' . esc_html(ado_cd_currency((float) ($summary['total_total'] ?? 0.0))) . '</div><div class="ado-inv-stat-label">Total tracked</div><div class="ado-mini-tag">' . esc_html((string) ($summary['total_count'] ?? 0)) . ' invoice(s)</div></div>';
    echo '</div>';

    echo '<div class="ado-inv-layout"><div>';
    if (is_array($summary['first_overdue'] ?? null)) {
        $first_overdue = (array) $summary['first_overdue'];
        echo '<div class="ado-overdue-banner"><strong>' . esc_html((string) ($first_overdue['invoice_id'] ?? 'Invoice')) . ' is overdue.</strong> ' . esc_html((string) ($first_overdue['project'] ?? 'This project') . ' has an overdue balance of ' . (string) ($first_overdue['amount_html'] ?? '') . '.') . '</div>';
    }
    echo '<div class="ado-inv-table-wrap">';
    if (!$rows) {
        echo '<div class="ado-empty">No invoice metadata linked yet.</div>';
    } else {
        echo '<table class="ado-inv-table"><thead><tr><th>Invoice</th><th>Project</th><th>Created</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $status_key = (string) ($row['status_key'] ?? 'pending');
            echo '<tr>';
            echo '<td><div class="ado-inv-num">' . esc_html((string) (($row['invoice_id'] ?? '') !== '' ? $row['invoice_id'] : 'Unlinked')) . '</div></td>';
            echo '<td><div>' . esc_html((string) ($row['project'] ?? 'Project')) . '</div></td>';
            echo '<td>' . esc_html((string) ($row['created'] ?? '')) . '</td>';
            echo '<td><div class="ado-inv-amount ' . esc_attr($status_key) . '">' . esc_html((string) ($row['amount_html'] ?? '')) . '</div></td>';
            echo '<td>' . ado_cd_status_chip_html((string) ($row['status_label'] ?? 'Pending'), $status_key === 'overdue' ? 'pending' : ($status_key === 'paid' ? 'completed' : 'pending')) . '</td>';
            echo '<td>' . (($row['invoice_url'] ?? '') !== '' ? '<a class="ado-btn primary" target="_blank" rel="noopener" href="' . esc_url((string) $row['invoice_url']) . '">Open</a>' : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';

    echo '<div class="ado-inv-side">';
    if (is_array($primary_invoice)) {
        echo '<div class="ado-pay-card"><div class="ado-pay-card-title">Billing Action</div><div class="ado-pay-highlight"><div class="ado-pay-highlight-label">' . esc_html((string) (($primary_invoice['invoice_id'] ?? '') !== '' ? $primary_invoice['invoice_id'] : 'Invoice')) . '</div><div class="ado-pay-highlight-value">' . esc_html((string) ($primary_invoice['amount_html'] ?? '')) . '</div><div class="ado-pay-highlight-sub">' . esc_html((string) ($primary_invoice['status_label'] ?? 'Pending') . ' | ' . (string) ($primary_invoice['project'] ?? 'Project')) . '</div></div>';
        if (trim((string) ($primary_invoice['invoice_url'] ?? '')) !== '') {
            echo '<a class="ado-btn primary" style="width:100%;justify-content:center;margin-top:16px;" target="_blank" rel="noopener" href="' . esc_url((string) $primary_invoice['invoice_url']) . '">Open Invoice in Wave</a>';
        } else {
            echo '<div class="ado-row-sub">Wave payment link not connected yet for this invoice.</div>';
        }
        echo '</div>';
    }
    echo '<article class="ado-card" style="margin-top:16px;"><div class="ado-card-head"><span class="ado-card-title">Billing Information</span></div><div class="ado-billing-lines">';
    foreach (ado_cd_billing_info_lines($user_id) as $line) {
        echo '<div>' . esc_html((string) $line) . '</div>';
    }
    if (!ado_cd_billing_info_lines($user_id)) {
        echo '<div class="ado-row-sub">Billing profile details are not populated for this account yet.</div>';
    }
    echo '</div></article>';
    echo '</div></div>';
    return (string) ob_get_clean();
}

function ado_cd_render_site_docs_workspace(int $user_id): string {
    $docs = ado_cd_collect_site_docs($user_id);
    $groups = [];
    foreach ($docs as $doc) {
        $groups[(string) ($doc['project_label'] ?? 'General / No specific project')][] = $doc;
    }
    $required = ado_cd_required_doc_rows($user_id);
    ob_start();
    echo '<div class="ado-page-head"><h2>Upload Site Docs</h2><p class="ado-page-sub">Share drawings, schedules, access notes, and compliance documents with the AutoDoor Experts team.</p></div>';
    echo '<div class="ado-docs-layout">';
    echo '<div>';
    echo '<article class="ado-card"><div style="padding:24px;">';
    echo '<form id="ado-site-doc-form" enctype="multipart/form-data">';
    echo '<div class="ado-upload-zone-lg"><div class="ado-uz-title">Drop files here to upload</div><div class="ado-uz-sub">PDF, DWG, DXF, JPG, PNG, DOC, DOCX, ZIP</div><input id="ado-site-doc-files" type="file" name="site_docs[]" multiple></div>';
    echo '<div class="ado-doc-type-tip">Helpful to include: hardware schedules, marked-up drawings, GC contacts, access instructions, and any AHJ requirements for operator work.</div>';
    echo '<div class="ado-doc-form-row">';
    echo '<label><span>Attach to Project</span><select name="order_id">';
    foreach (ado_cd_project_options($user_id) as $option) {
        echo '<option value="' . esc_attr((string) $option['id']) . '">' . esc_html((string) $option['label']) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span>Document Type</span><select name="doc_type"><option value="">Auto-detect</option><option value="hardware_schedule">Hardware schedule</option><option value="floor_plan">Floor plan / door schedule</option><option value="gc_contact">GC contact information</option><option value="ahj_requirements">AHJ requirements</option><option value="site_access">Site access instructions</option></select></label>';
    echo '</div><div style="margin-top:16px;"><button class="ado-btn primary" type="submit">Upload Files</button></div></form>';
    echo '</div></article>';

    echo '<article class="ado-card" style="margin-top:20px;"><div class="ado-card-head"><span class="ado-card-title">Uploaded Documents</span></div><div style="padding:16px 24px 20px;">';
    if (!$groups) {
        echo '<div class="ado-empty">No site documents uploaded yet.</div>';
    } else {
        foreach ($groups as $project_label => $group_docs) {
            echo '<div class="ado-doc-section-title">' . esc_html((string) $project_label) . '<span class="ado-tag tag-muted">' . esc_html((string) count($group_docs)) . ' file(s)</span></div>';
            echo '<div class="ado-doc-list">';
            foreach ($group_docs as $doc) {
                echo '<div class="ado-file-row">';
                echo '<div class="ado-file-icon ' . esc_attr(ado_cd_file_icon_class($doc)) . '">' . esc_html(ado_cd_file_icon_label($doc)) . '</div>';
                echo '<div class="ado-file-copy"><div class="ado-file-name">' . esc_html((string) ($doc['file_name'] ?? 'Document')) . '</div><div class="ado-file-meta">' . esc_html(trim((string) (($doc['size'] ?? '') . ' | Uploaded ' . wp_date('M j, Y', strtotime((string) ($doc['created_at'] ?? '')) ?: time())))) . '</div></div>';
                echo '<div class="ado-file-actions-row">';
                if (($doc['url'] ?? '') !== '') {
                    echo '<a class="ado-btn" target="_blank" rel="noopener" href="' . esc_url((string) $doc['url']) . '">View</a>';
                }
                echo '<button class="ado-btn ado-site-doc-delete" type="button" data-attachment-id="' . esc_attr((string) ($doc['attachment_id'] ?? 0)) . '">Delete</button>';
                echo '</div></div>';
            }
            echo '</div>';
        }
    }
    echo '</div></article>';
    echo '</div>';

    echo '<div class="ado-docs-side">';
    echo '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Required for Install</span></div><div class="ado-required-list">';
    foreach ($required as $row) {
        echo '<div class="ado-required-row"><div class="ado-required-copy"><div class="ado-row-title">' . esc_html((string) $row['label']) . '</div><div class="ado-row-sub">' . esc_html((string) ($row['project_label'] . ($row['present'] ? ' uploaded' : ' missing'))) . '</div></div><span class="ado-mini-tag ' . esc_attr($row['present'] ? 'completed' : 'warn') . '">' . esc_html($row['present'] ? 'Ready' : 'Missing') . '</span></div>';
    }
    echo '</div></article>';
    echo '<article class="ado-card" style="margin-top:16px;"><div class="ado-card-head"><span class="ado-card-title">Tips</span></div><div class="ado-doc-tip-stack"><div>Include GC contact details so technicians can coordinate access directly.</div><div>Marked-up door plans and hardware schedules speed up quoting and mobilization.</div><div>For regulated sites, upload inspection or AHJ requirements before install.</div></div></article>';
    echo '</div>';
    echo '</div>';
    return (string) ob_get_clean();
}

function ado_cd_render_view_content(string $view, int $user_id): string {
    switch ($view) {
        case 'new-quote':
            return ado_cd_render_quote_workspace_panel($user_id);
        case 'quotes':
            return ado_cd_render_quotes_workspace($user_id);
        case 'projects':
            return ado_cd_render_projects_workspace($user_id);
        case 'schedule':
            return ado_cd_render_schedule($user_id);
        case 'site-docs':
            return ado_cd_render_site_docs_workspace($user_id);
        case 'invoices':
            return ado_cd_render_invoices_workspace($user_id);
        case 'dashboard':
        default:
            return ado_cd_render_dashboard_overview($user_id);
    }
}

add_shortcode('ado_client_dashboard_app', static function (): string {
    if (!is_user_logged_in() || !ado_is_client()) {
        return '<p>Client access only.</p>';
    }

    wp_enqueue_script('jquery');

    $uid = (int) get_current_user_id();
    $counts = ado_cd_counts($uid);
    $view = sanitize_key((string) ($_GET['view'] ?? 'dashboard'));
    $view_titles = [
        'dashboard' => 'Dashboard',
        'new-quote' => 'New Quote',
        'quotes' => 'My Quotes',
        'projects' => 'My Projects',
        'schedule' => 'Schedule',
        'site-docs' => 'Upload Site Docs',
        'invoices' => 'Invoices',
    ];
    if (!isset($view_titles[$view])) {
        $view = 'dashboard';
    }
    $nonce = wp_create_nonce('ado_quote_nonce');
    $portal_nonce = wp_create_nonce('ado_client_portal_nonce');
    $account_name = ado_cd_account_name($uid);
    $account_initials = ado_cd_account_initials($uid);
    $primary_action = ['label' => 'New Quote', 'href' => ado_cd_view_url('new-quote')];
    if ($view === 'schedule') {
        $primary_action = ['label' => 'Request Visit', 'href' => '#ado-visit-request-form'];
    } elseif ($view === 'site-docs') {
        $primary_action = ['label' => 'Upload Files', 'href' => '#ado-site-doc-form'];
    } elseif ($view === 'new-quote') {
        $primary_action = ['label' => 'Open Quote Cart', 'href' => wc_get_cart_url()];
    } elseif ($view === 'invoices') {
        $primary_action = ['label' => 'Contact Billing', 'href' => 'mailto:' . ado_cd_support_email()];
    }

    ob_start();
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap');
    .ado-app{--bg:#f4f5f7;--surface:#fff;--surface-2:#f9fafb;--border:#e8eaed;--accent:#1a56db;--accent-soft:#eff4ff;--accent-2:#0e9f6e;--accent-2-soft:#f0fdf4;--warn:#e3a008;--warn-soft:#fffbeb;--danger:#e02424;--danger-soft:#fef2f2;--text-primary:#111928;--text-secondary:#6b7280;--text-muted:#9ca3af;--shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);--radius:14px;--radius-sm:8px;font-family:'DM Sans',sans-serif;background:var(--bg);display:flex;min-height:100vh;color:var(--text-primary)}.ado-app *{box-sizing:border-box}.ado-side{width:256px;min-height:100vh;background:var(--text-primary);display:flex;flex-direction:column;position:sticky;top:0}.ado-side-logo-wrap{padding:28px 24px 24px;border-bottom:1px solid rgba(255,255,255,.08)}.ado-logo-mark{display:flex;align-items:center;gap:10px}.ado-logo-icon{width:36px;height:36px;border-radius:9px;background:var(--accent);display:flex;align-items:center;justify-content:center;color:#fff;font-family:'Syne',sans-serif;font-weight:800}.ado-side-logo{font-family:'Syne',sans-serif;font-size:18px;font-weight:700;color:#fff}.ado-side-logo span{color:var(--accent)}.ado-nav{flex:1;padding:16px 12px;display:flex;flex-direction:column;gap:2px}.ado-nav-label{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:12px 12px 6px;margin-top:8px}.ado-nav a{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.55);font-size:14px;font-weight:500;text-decoration:none}.ado-nav a:hover{background:rgba(255,255,255,.07);color:#fff}.ado-nav a.active{background:var(--accent);color:#fff}.ado-nav-badge{background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:999px}.ado-side-user{padding:16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px}.ado-user-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#1a56db,#0e9f6e);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff}.ado-user-name{font-size:13px;font-weight:600;color:#fff}.ado-user-role{font-size:11px;color:rgba(255,255,255,.4)}.ado-main{flex:1;display:flex;flex-direction:column;min-width:0}.ado-top{background:var(--surface);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:20}.ado-top h1{margin:0;font-family:'Syne',sans-serif;font-size:20px;font-weight:700}.ado-top-right{display:flex;gap:12px}.ado-content{padding:32px;flex:1}.ado-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 18px;border-radius:var(--radius-sm);font-size:14px;font-weight:500;text-decoration:none;border:1px solid var(--border);background:transparent;color:var(--text-secondary);cursor:pointer}.ado-btn:hover{background:var(--surface-2);color:var(--text-primary)}.ado-btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.ado-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}.ado-card-head{padding:20px 24px 0;display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}.ado-card-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700}.ado-card-link,.ado-icon-link{font-size:13px;font-weight:600;color:var(--accent);text-decoration:none}.ado-card-link:hover,.ado-icon-link:hover{text-decoration:underline}.ado-empty{padding:24px;font-size:13px;color:var(--text-muted)}.ado-page-head{margin:0 0 24px}.ado-page-head h2{margin:0;font-family:'Syne',sans-serif;font-size:24px;font-weight:800}.ado-page-sub{margin:6px 0 0;font-size:14px;color:var(--text-muted)}.ado-row-title{font-weight:700}.ado-row-sub{font-size:12px;color:var(--text-muted)}.ado-flash{display:none;margin:0 0 20px;padding:12px 16px;border-radius:var(--radius-sm);font-size:13px}.ado-flash.ok{display:block;background:#ecfdf3;color:#027a48}.ado-flash.err{display:block;background:#fef2f2;color:#b42318}.ado-table,.ado-quote-door-table,.ado-door-table,.ado-inv-table{width:100%;border-collapse:collapse}.ado-table th,.ado-quote-door-table th,.ado-door-table th,.ado-inv-table th{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);padding:12px 24px;text-align:left;border-bottom:1px solid var(--border)}.ado-table td,.ado-quote-door-table td,.ado-door-table td,.ado-inv-table td{padding:14px 24px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}.ado-table tr:last-child td,.ado-quote-door-table tr:last-child td,.ado-door-table tr:last-child td,.ado-inv-table tr:last-child td{border-bottom:none}.ado-table tr:hover td,.ado-quote-door-table tr:hover td,.ado-door-table tr:hover td,.ado-inv-table tr:hover td{background:var(--surface-2)}.ado-status-chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px}.ado-status-chip::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}.ado-status-chip.in-progress{background:var(--accent-soft);color:var(--accent)}.ado-status-chip.pending{background:var(--warn-soft);color:#92400e}.ado-status-chip.completed{background:var(--accent-2-soft);color:#065f46}.ado-status-chip.draft{background:var(--bg);color:var(--text-muted);border:1px solid var(--border)}.ado-tag,.ado-mini-tag{display:inline-flex;align-items:center;border-radius:20px;font-weight:600}.ado-tag{font-size:11px;padding:3px 8px}.ado-tag.tag-blue{background:var(--accent-soft);color:var(--accent)}.ado-tag.tag-muted,.ado-mini-tag{background:var(--bg);color:var(--text-muted);border:1px solid var(--border)}.ado-mini-tag{font-size:10px;padding:2px 7px}.ado-mini-tag.warn{background:var(--warn-soft);color:#92400e}.ado-mini-tag.pending{background:var(--danger-soft);color:var(--danger)}.ado-mini-tag.completed{background:var(--accent-2-soft);color:#065f46}.ado-action-row{display:flex;gap:6px;flex-wrap:wrap}.ado-action-row .ado-btn{padding:6px 10px;font-size:12px}
    .ado-alert-banner{background:var(--danger-soft);border:1px solid #fca5a5;border-radius:var(--radius);padding:14px 20px;display:flex;gap:12px;align-items:center;margin-bottom:28px}.ado-alert-text{font-size:14px;color:#991b1b;flex:1}.ado-alert-action{font-size:13px;font-weight:600;color:var(--danger);text-decoration:none}.ado-welcome-bar{margin-bottom:18px}.ado-welcome-greeting{font-size:13px;color:var(--text-muted)}.ado-welcome-name{font-family:'Syne',sans-serif;font-size:24px;font-weight:800}.ado-stats-row,.ado-inv-summary-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px}.ado-inv-summary-row{grid-template-columns:repeat(4,1fr)}.ado-stat-card,.ado-inv-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;box-shadow:var(--shadow-sm)}.ado-inv-stat.danger{border-top:3px solid var(--danger)}.ado-inv-stat.warn{border-top:3px solid var(--warn)}.ado-inv-stat.success{border-top:3px solid var(--accent-2)}.ado-stat-label,.ado-inv-stat-label{font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);margin-bottom:10px}.ado-stat-value,.ado-inv-stat-val{font-family:'Syne',sans-serif;font-size:28px;font-weight:700;line-height:1;margin-bottom:6px}.ado-inv-stat-val{font-size:26px}.ado-stat-sub{font-size:12px;color:var(--text-muted)}.ado-stat-pill{display:inline-flex;margin-top:10px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px}.ado-stat-pill.danger{background:var(--danger-soft);color:var(--danger)}.ado-stat-pill.success{background:var(--accent-2-soft);color:#065f46}.ado-stat-pill.warn{background:var(--warn-soft);color:#92400e}.ado-main-grid,.ado-sched-layout,.ado-docs-layout,.ado-inv-layout,.ado-quote-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px}.ado-quote-layout{grid-template-columns:minmax(0,1fr) 360px}.ado-main-col,.ado-right-col,.ado-docs-side,.ado-inv-side{display:flex;flex-direction:column;gap:20px}.ado-quick-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:0 24px 24px}.ado-quick-action-btn{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:14px;border-radius:12px;background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);text-decoration:none;font-weight:600}.ado-quick-action-btn:hover{border-color:var(--accent);background:var(--accent-soft)}.ado-qa-icon{width:34px;height:34px;border-radius:10px;background:var(--border);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--text-muted)}.ado-qa-icon.active{background:var(--accent);color:#fff}.ado-note-item{display:flex;gap:12px;padding:14px 24px;border-bottom:1px solid var(--border)}.ado-note-item:last-child{border-bottom:none}.ado-note-flag{width:10px;height:10px;border-radius:50%;margin-top:6px;flex-shrink:0}.ado-note-flag.critical{background:var(--danger)}.ado-note-copy{flex:1}.ado-note-project{font-size:13px;font-weight:700;margin-bottom:4px}.ado-note-text{font-size:13px;color:var(--text-secondary);line-height:1.5}.ado-note-meta{font-size:11px;color:var(--text-muted);margin-top:6px}.ado-note-priority{font-size:10px;font-weight:700;text-transform:uppercase;padding:3px 8px;border-radius:20px;background:var(--danger-soft);color:var(--danger)}.ado-schedule-item,.ado-invoice-item{display:flex;gap:12px;padding:14px 24px;border-bottom:1px solid var(--border)}.ado-schedule-item:last-child,.ado-invoice-item:last-child{border-bottom:none}.ado-schedule-date-box,.ado-visit-date-box{width:52px;height:52px;border-radius:10px;background:var(--accent-soft);display:flex;flex-direction:column;align-items:center;justify-content:center;border:1px solid rgba(26,86,219,.2);flex-shrink:0}.ado-schedule-date-box.today,.ado-visit-date-box.today{background:var(--accent)}.ado-s-month,.ado-vdb-mo{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--accent)}.ado-schedule-date-box.today .ado-s-month,.ado-visit-date-box.today .ado-vdb-mo{color:rgba(255,255,255,.85)}.ado-s-day,.ado-vdb-day{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:var(--accent);line-height:1}.ado-schedule-date-box.today .ado-s-day,.ado-visit-date-box.today .ado-vdb-day{color:#fff}.ado-schedule-info,.ado-visit-info,.ado-file-copy,.ado-invoice-info{flex:1}.ado-schedule-title,.ado-visit-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:3px}.ado-schedule-detail,.ado-visit-meta,.ado-invoice-proj,.ado-file-meta{font-size:12px;color:var(--text-muted);margin-top:2px}.ado-schedule-tag{display:inline-flex;font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px}.ado-schedule-tag.pending{background:var(--warn-soft);color:#92400e}.ado-schedule-tag.in-progress{background:var(--accent-soft);color:var(--accent)}.ado-schedule-tag.completed{background:var(--accent-2-soft);color:#065f46}.ado-invoice-icon{width:12px;height:12px;border-radius:50%;background:var(--warn);margin-top:4px}.ado-invoice-icon.overdue{background:var(--danger)}.ado-invoice-icon.paid{background:var(--accent-2)}.ado-invoice-right{text-align:right}.ado-invoice-amount,.ado-qch-amount,.ado-project-value-amount,.ado-pay-highlight-value,.ado-inv-amount{font-family:'Syne',sans-serif;font-weight:800}.ado-invoice-due{font-size:11px}.ado-invoice-due.overdue,.ado-inv-amount.overdue{color:var(--danger)}.ado-invoice-due.pending,.ado-inv-amount.pending{color:#92400e}.ado-invoice-due.paid,.ado-inv-amount.paid{color:#065f46}
    .ado-quotes-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:24px}.ado-filter-chip{padding:7px 16px;border-radius:99px;font-size:13px;font-weight:500;border:1px solid var(--border);background:transparent;color:var(--text-muted)}.ado-filter-chip.active{background:var(--accent);border-color:var(--accent);color:#fff}.ado-quote-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:14px}.ado-quote-card.awaiting-action{border-left:4px solid var(--warn)}.ado-quote-card.approved{border-left:4px solid var(--accent-2)}.ado-quote-card.draft{border-left:4px solid var(--text-muted)}.ado-quote-card-header{padding:18px 24px;display:flex;gap:16px;align-items:center;border-bottom:1px solid var(--border)}.ado-qch-num{font-family:'Syne',sans-serif;font-size:14px;font-weight:700}.ado-qch-project,.ado-qch-date{font-size:13px;color:var(--text-muted)}.ado-qch-right{margin-left:auto;text-align:right}.ado-quote-card-body{padding:20px 24px}.ado-expiry-warn{background:var(--warn-soft);border:1px solid rgba(227,160,8,.3);border-radius:var(--radius-sm);padding:10px 14px;font-size:13px;color:#92400e;margin-bottom:16px}.ado-model-code{font-family:monospace;font-size:12px;background:var(--bg);padding:2px 6px;border-radius:4px;color:var(--text-secondary)}.ado-quote-actions{display:flex;gap:10px;flex-wrap:wrap;padding-top:16px;border-top:1px solid var(--border)}.ado-projects-grid{display:flex;flex-direction:column;gap:16px}.ado-project-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}.ado-project-card-top{padding:20px 24px;display:flex;gap:16px;align-items:flex-start;border-bottom:1px solid var(--border)}.ado-project-icon{width:44px;height:44px;border-radius:10px;background:var(--accent-soft)}.ado-project-icon.pending{background:var(--warn-soft)}.ado-project-icon.completed{background:var(--accent-2-soft)}.ado-project-header{flex:1}.ado-project-name{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;margin-bottom:3px}.ado-project-location{font-size:13px;color:var(--text-muted);margin-bottom:8px}.ado-project-meta-row{display:flex;gap:8px;flex-wrap:wrap}.ado-project-value{text-align:right;flex-shrink:0}.ado-project-value-label{font-size:11px;color:var(--text-muted);margin-top:3px}.ado-project-stats{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--border)}.ado-proj-stat{padding:14px 20px;border-right:1px solid var(--border)}.ado-proj-stat:last-child{border-right:none}.ado-proj-stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;color:var(--text-muted);margin-bottom:6px}.ado-proj-stat-val{font-family:'Syne',sans-serif;font-size:18px;font-weight:700}.ado-proj-stat-val.small{font-size:14px;font-family:'DM Sans',sans-serif}.ado-proj-stat-val.danger{color:var(--danger)}.ado-proj-stat-sub{font-size:11px;color:var(--text-muted)}.ado-door-num{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;background:var(--accent-soft);color:var(--accent);padding:3px 8px;border-radius:5px}.ado-door-num.done{background:var(--accent-2-soft);color:#065f46}.ado-door-num.blocked{background:var(--danger-soft);color:var(--danger)}.ado-project-footer{padding:14px 24px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:var(--surface-2);border-top:1px solid var(--border)}.ado-proj-note-preview{flex:1;font-size:12px;color:var(--text-muted)}.ado-proj-note-preview.critical{color:var(--danger);font-weight:600}.ado-calendar-card{padding:20px 24px}.ado-cal-header{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:16px}.ado-cal-month{font-family:'Syne',sans-serif;font-size:18px;font-weight:700}.ado-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}.ado-cal-day-label{text-align:center;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);padding-bottom:8px}.ado-cal-cell{aspect-ratio:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:6px 4px;border-radius:8px;font-size:13px;color:var(--text-secondary)}.ado-cal-cell.muted{color:var(--text-muted)}.ado-cal-cell.today{background:var(--accent);color:#fff;font-weight:700}.ado-cal-cell.has-visit{color:var(--text-primary);font-weight:600}.ado-cal-dot{width:5px;height:5px;border-radius:50%;margin-top:3px}.ado-cal-dot.blue{background:var(--accent)}.ado-cal-dot.warn{background:var(--warn)}.ado-visit-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:14px}.ado-visit-card-top{padding:18px 20px;display:flex;gap:14px;align-items:flex-start;border-bottom:1px solid var(--border)}.ado-visit-card-body{padding:14px 20px}.ado-visit-tags{display:flex;gap:6px;flex-wrap:wrap}.ado-visit-note{font-size:13px;color:var(--danger);font-weight:500}.ado-request-form label,.ado-doc-form-row label,.ado-quote-workspace-shell label{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block}.ado-request-form input,.ado-request-form select,.ado-request-form textarea,.ado-doc-form-row select,.ado-quote-workspace-shell input,.ado-quote-workspace-shell select{width:100%;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:13.5px;padding:10px 13px;outline:none;margin-top:7px}.ado-request-form textarea{resize:none;height:88px}.ado-history-row{padding:12px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:10px;font-size:13px}.ado-history-row:last-child{border-bottom:none}.ado-upload-zone-lg{border:2px dashed var(--border);border-radius:var(--radius);padding:52px 32px;text-align:center;background:var(--surface-2)}.ado-uz-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:700;margin-bottom:6px}.ado-uz-sub{font-size:13px;color:var(--text-muted)}.ado-upload-zone-lg input{margin-top:16px}.ado-doc-type-tip,.ado-info-box{background:var(--accent-soft);border:1px solid rgba(26,86,219,.2);border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;color:var(--accent);margin-top:18px}.ado-doc-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}.ado-doc-section-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}.ado-doc-list{display:flex;flex-direction:column;gap:7px;margin-bottom:24px}.ado-file-row{display:flex;gap:12px;align-items:center;padding:12px 16px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm)}.ado-file-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;background:var(--bg);color:var(--text-muted)}.ado-file-icon.pdf{background:var(--danger-soft);color:var(--danger)}.ado-file-icon.dwg{background:var(--accent-soft);color:var(--accent)}.ado-file-icon.img{background:var(--accent-2-soft);color:#065f46}.ado-file-icon.doc{background:var(--warn-soft);color:#92400e}.ado-file-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ado-file-actions-row{display:flex;gap:6px;flex-shrink:0}.ado-required-row{padding:12px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}.ado-required-row:last-child{border-bottom:none}.ado-doc-tip-stack{padding:12px 20px 16px;display:flex;flex-direction:column;gap:10px}.ado-doc-tip-stack div{font-size:13px;color:var(--text-secondary);line-height:1.6;padding:10px 12px;background:var(--surface-2);border-radius:8px}.ado-overdue-banner{background:var(--danger-soft);border:1px solid #fca5a5;border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;color:#991b1b;margin-bottom:20px}.ado-inv-table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}.ado-inv-num{font-weight:700}.ado-pay-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px;box-shadow:var(--shadow-sm)}.ado-pay-card-title,.ado-qs-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:16px}.ado-pay-highlight{background:var(--surface-2);border-radius:var(--radius-sm);padding:14px;text-align:center}.ado-pay-highlight-label{font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px}.ado-pay-highlight-sub{font-size:12px;color:var(--text-secondary);margin-top:4px}.ado-billing-lines{padding:12px 24px 20px;display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--text-secondary)}.ado-quote-steps{display:flex;align-items:center;gap:0;margin-bottom:24px}.ado-step{display:flex;align-items:center;gap:10px}.ado-step-num{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;background:var(--border);color:var(--text-muted)}.ado-step.active .ado-step-num{background:var(--accent);color:#fff}.ado-step.done .ado-step-num{background:var(--accent-2);color:#fff}.ado-step-label{font-size:13px;font-weight:600;color:var(--text-muted)}.ado-step.active .ado-step-label{color:var(--accent)}.ado-step.done .ado-step-label{color:#065f46}.ado-step-divider{flex:1;height:2px;background:var(--border);margin:0 12px;min-width:40px}.ado-step-divider.done{background:var(--accent-2)}.ado-quote-summary-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;position:sticky;top:88px}.ado-qs-row{display:flex;justify-content:space-between;gap:12px;font-size:13px;padding:8px 0;border-bottom:1px solid var(--border)}.ado-qs-label{color:var(--text-secondary)}.ado-qs-val{font-weight:600;text-align:right}.ado-qs-total{display:flex;justify-content:space-between;gap:12px;padding-top:12px;margin-top:4px;border-top:2px solid var(--border)}.ado-qs-note{font-size:11px;color:var(--text-muted);margin-top:8px;line-height:1.5}.ado-quote-workspace-shell .ado-card{margin-bottom:16px}.ado-quote-workspace-shell .button{display:inline-flex;align-items:center;justify-content:center;padding:9px 18px;border-radius:var(--radius-sm);font-size:14px;border:1px solid var(--border);background:transparent;color:var(--text-secondary);cursor:pointer;text-decoration:none}.ado-quote-workspace-shell .button.button-primary{background:var(--accent);border-color:var(--accent);color:#fff}
    @media (max-width:1100px){.ado-app{flex-direction:column}.ado-side{position:relative;width:100%;min-height:auto}.ado-main-grid,.ado-stats-row,.ado-inv-summary-row,.ado-project-stats,.ado-sched-layout,.ado-docs-layout,.ado-doc-form-row,.ado-inv-layout,.ado-quote-layout{grid-template-columns:1fr}.ado-quote-summary-card{position:relative;top:auto}}@media (max-width:800px){.ado-top{padding:16px 20px;flex-direction:column;align-items:flex-start;gap:12px}.ado-content{padding:20px}.ado-table,.ado-quote-door-table,.ado-door-table,.ado-inv-table{display:block;overflow:auto;white-space:nowrap}.ado-quick-actions{grid-template-columns:1fr 1fr}.ado-quote-card-header,.ado-project-card-top{flex-wrap:wrap}.ado-qch-right,.ado-project-value{margin-left:0;text-align:left}}@media (max-width:600px){.ado-quick-actions{grid-template-columns:1fr}.ado-top-right{flex-wrap:wrap}.ado-quote-actions,.ado-project-footer,.ado-action-row{flex-direction:column;align-items:stretch}.ado-btn{width:100%;justify-content:center}}
    </style>
    <div class="ado-app">
      <aside class="ado-side">
        <div class="ado-side-logo-wrap">
          <div class="ado-logo-mark">
            <div class="ado-logo-icon">A</div>
            <span class="ado-side-logo">Auto<span>Door</span></span>
          </div>
        </div>
        <nav class="ado-nav">
          <div class="ado-nav-label">Overview</div>
          <a class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('dashboard'); ?>"><span>Dashboard</span></a>
          <div class="ado-nav-label">Quotes &amp; Projects</div>
          <a class="<?php echo $view === 'new-quote' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('new-quote'); ?>"><span>New Quote</span></a>
          <a class="<?php echo $view === 'quotes' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('quotes'); ?>"><span>My Quotes</span><?php if ($counts['quotes_count'] > 0) { ?><span class="ado-nav-badge"><?php echo esc_html((string) $counts['quotes_count']); ?></span><?php } ?></a>
          <a class="<?php echo $view === 'projects' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('projects'); ?>"><span>My Projects</span></a>
          <div class="ado-nav-label">Scheduling</div>
          <a class="<?php echo $view === 'schedule' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('schedule'); ?>"><span>Schedule</span></a>
          <a class="<?php echo $view === 'site-docs' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('site-docs'); ?>"><span>Upload Site Docs</span></a>
          <div class="ado-nav-label">Billing</div>
          <a class="<?php echo $view === 'invoices' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('invoices'); ?>"><span>Invoices</span><?php if ($counts['overdue_count'] > 0) { ?><span class="ado-nav-badge"><?php echo esc_html((string) $counts['overdue_count']); ?></span><?php } ?></a>
        </nav>
        <div class="ado-side-user">
          <div class="ado-user-avatar"><?php echo esc_html($account_initials); ?></div>
          <div>
            <div class="ado-user-name"><?php echo esc_html($account_name); ?></div>
            <div class="ado-user-role">Client Account</div>
          </div>
        </div>
      </aside>
      <section class="ado-main">
        <header class="ado-top">
          <h1><?php echo esc_html((string) $view_titles[$view]); ?></h1>
          <div class="ado-top-right">
            <a class="ado-btn" href="mailto:<?php echo esc_attr(ado_cd_support_email()); ?>">Support</a>
            <a class="ado-btn primary" href="<?php echo esc_url((string) $primary_action['href']); ?>"><?php echo esc_html((string) $primary_action['label']); ?></a>
          </div>
        </header>
        <div class="ado-content">
          <div id="ado-dashboard-flash" class="ado-flash"></div>
          <?php echo ado_cd_render_view_content($view, $uid); ?>
        </div>
      </section>
    </div>
    <script>
    (function($){
      var quoteNonce = <?php echo wp_json_encode($nonce); ?>;
      var portalNonce = <?php echo wp_json_encode($portal_nonce); ?>;
      var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      var checkoutFallback = <?php echo wp_json_encode(wc_get_checkout_url()); ?>;
      function flash(msg, ok){
        var el = $('#ado-dashboard-flash');
        if (!el.length) { window.alert(msg || 'Update complete.'); return; }
        el.removeClass('ok err').addClass(ok ? 'ok' : 'err').text(msg || '');
      }
      function postQuote(action, data, cb){
        var payload = $.extend({action: action, nonce: quoteNonce}, data || {});
        $.post(ajaxUrl, payload).done(function(res){ cb(res || {success:false,data:{message:'Request failed'}}); }).fail(function(){ cb({success:false,data:{message:'Request failed'}}); });
      }
      function postPortal(action, data, cb){
        var payload = $.extend({action: action, nonce: portalNonce}, data || {});
        $.post(ajaxUrl, payload).done(function(res){ cb(res || {success:false,data:{message:'Request failed'}}); }).fail(function(){ cb({success:false,data:{message:'Request failed'}}); });
      }
      function showQuotePreview(html){
        var target = $('#ado-quote-preview-output');
        if (!target.length) { return; }
        target.html(html || '');
        if (html) {
          $('html, body').animate({scrollTop: target.offset().top - 20}, 200);
        }
      }
      $(document).on('click', '.ado-approve-quote', function(){
        var draftId = $(this).data('draft-id');
        if (!draftId) { return; }
        postQuote('ado_load_quote_draft', {draft_id: draftId}, function(res){
          if (!res.success) {
            flash((res.data && res.data.message) ? res.data.message : 'Could not load quote.', false);
            return;
          }
          var target = (res.data && res.data.checkout_url) ? res.data.checkout_url : checkoutFallback;
          window.location.href = target;
        });
      });
      $(document).on('click', '.ado-decline-quote', function(){
        var draftId = $(this).data('draft-id');
        if (!draftId) { return; }
        if (!window.confirm('Decline and remove this quote draft?')) { return; }
        postQuote('ado_delete_quote_draft', {draft_id: draftId}, function(res){
          if (!res.success) {
            flash((res.data && res.data.message) ? res.data.message : 'Could not remove quote.', false);
            return;
          }
          flash('Quote declined and removed.', true);
          window.setTimeout(function(){ window.location.reload(); }, 400);
        });
      });
      $(document).on('click', '.ado-request-quote-change', function(){
        var draftId = $(this).data('draft-id');
        var notes = window.prompt('What needs to change on this quote?');
        if (!draftId || !notes) { return; }
        postPortal('ado_cd_request_quote_change', {draft_id: draftId, notes: notes}, function(res){
          if (!res.success) {
            flash((res.data && res.data.message) ? res.data.message : 'Could not send change request.', false);
            return;
          }
          flash((res.data && res.data.message) ? res.data.message : 'Change request sent.', true);
        });
      });
      $(document).on('click', '.ado-preview-quote', function(){
        var draftId = $(this).data('draft-id');
        if (!draftId) { return; }
        postQuote('ado_show_quote_draft_output', {draft_id: draftId}, function(res){
          if (!res.success) {
            flash((res.data && res.data.message) ? res.data.message : 'Could not load quote output.', false);
            return;
          }
          showQuotePreview((res.data && res.data.result_html) ? res.data.result_html : '');
          flash((res.data && res.data.message) ? res.data.message : 'Quote output loaded.', true);
        });
      });
      $(document).on('submit', '#ado-site-doc-form', function(e){
        e.preventDefault();
        var fileInput = $('#ado-site-doc-files')[0];
        if (!fileInput || !fileInput.files || !fileInput.files.length) {
          flash('Choose at least one file to upload.', false);
          return;
        }
        var fd = new FormData(this);
        fd.append('action', 'ado_cd_upload_site_docs');
        fd.append('nonce', portalNonce);
        $.ajax({
          url: ajaxUrl,
          method: 'POST',
          data: fd,
          processData: false,
          contentType: false
        }).done(function(res){
          if (!res || !res.success) {
            flash(res && res.data && res.data.message ? res.data.message : 'Upload failed.', false);
            return;
          }
          flash(res.data && res.data.message ? res.data.message : 'Files uploaded.', true);
          window.location.reload();
        }).fail(function(){
          flash('Upload failed.', false);
        });
      });
      $(document).on('click', '.ado-site-doc-delete', function(){
        var attachmentId = $(this).data('attachment-id');
        if (!attachmentId || !window.confirm('Delete this document?')) { return; }
        $.post(ajaxUrl, {action: 'ado_cd_delete_site_doc', nonce: portalNonce, attachment_id: attachmentId})
          .done(function(res){
            if (!res || !res.success) {
              flash(res && res.data && res.data.message ? res.data.message : 'Could not delete document.', false);
              return;
            }
            flash(res.data && res.data.message ? res.data.message : 'Document removed.', true);
            window.location.reload();
          })
          .fail(function(){
            flash('Could not delete document.', false);
          });
      });
      $(document).on('submit', '#ado-visit-request-form', function(e){
        e.preventDefault();
        var payload = $(this).serializeArray().reduce(function(acc, item){
          acc[item.name] = item.value;
          return acc;
        }, {});
        postPortal('ado_cd_request_visit', payload, function(res){
          if (!res.success) {
            flash((res.data && res.data.message) ? res.data.message : 'Could not send visit request.', false);
            return;
          }
          $('#ado-visit-request-form')[0].reset();
          flash((res.data && res.data.message) ? res.data.message : 'Visit request sent.', true);
        });
      });
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
});
