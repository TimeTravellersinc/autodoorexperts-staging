<?php
// Lightweight client app shell: consistent UI across dashboard views + live backend actions.
if (defined('ADO_CLIENT_DASHBOARD_APP_LOADED')) { return; }
define('ADO_CLIENT_DASHBOARD_APP_LOADED', true);

function ado_cd_view_url(string $view, array $args = []): string {
    $map = [
        'dashboard' => home_url('/portal/'),
        'new-quote' => home_url('/portal/new-quote/'),
        'quotes' => home_url('/portal/quotes/'),
        'projects' => home_url('/portal/projects/'),
        'service-calls' => home_url('/portal/service-calls/'),
        'schedule' => home_url('/portal/schedule/'),
        'invoices' => home_url('/portal/invoices/'),
    ];
    if ($args) {
        return esc_url(add_query_arg(array_merge(['view' => $view], $args), home_url('/client-dashboard/')));
    }
    $base = isset($map[$view]) ? (string) $map[$view] : (string) add_query_arg(['view' => $view], home_url('/client-dashboard/'));
    return esc_url($base);
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

function ado_cd_order_name(WC_Order $order): string {
    if (function_exists('ado_order_project_name')) {
        $project_name = trim((string) ado_order_project_name($order));
        if ($project_name !== '') {
            return $project_name;
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
        $name = trim((string) $item->get_name());
        if ($name !== '') {
            return $name;
        }
    }
    return 'Project #' . (string) $order->get_id();
}

function ado_cd_order_door_count(WC_Order $order): int {
    $project_doors = $order->get_meta('_ado_project_doors');
    if (is_array($project_doors) && $project_doors) {
        $count = 0;
        foreach ($project_doors as $door) {
            if (!is_array($door)) {
                continue;
            }
            $door_id = trim((string) ($door['door_number'] ?? ($door['door_id'] ?? '')));
            if ($door_id === '') {
                continue;
            }
            $count++;
        }
        if ($count > 0) {
            return $count;
        }
    }

    $snapshot = (string) $order->get_meta('_ado_scoped_json_snapshot');
    if ($snapshot !== '') {
        $json = json_decode($snapshot, true);
        $door_count = (int) ($json['result']['door_count'] ?? 0);
        if ($door_count > 0) {
            return $door_count;
        }
    }

    $scope_path = (string) $order->get_meta('_ado_scoped_json_path');
    if ($scope_path !== '' && is_readable($scope_path)) {
        $json = json_decode((string) file_get_contents($scope_path), true);
        $door_count = (int) ($json['result']['door_count'] ?? 0);
        if ($door_count > 0) {
            return $door_count;
        }
    }

    $door_ids = [];
    foreach ($order->get_items() as $item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            continue;
        }
        $door_id = trim((string) $item->get_meta('_adq_door_number'));
        if ($door_id === '') {
            $door_id = trim((string) $item->get_meta('_adq_door_id'));
        }
        if ($door_id !== '') {
            $door_ids[$door_id] = true;
        }
    }
    return count($door_ids);
}

function ado_cd_order_stage_label(WC_Order $order): string {
    $status = strtolower(trim((string) $order->get_status()));
    $map = [
        'pending' => 'PO Received',
        'on-hold' => 'Scheduled',
        'processing' => 'In Progress',
        'completed' => 'Complete',
    ];
    if (isset($map[$status])) {
        return $map[$status];
    }
    if (function_exists('wc_get_order_status_name')) {
        $label = trim((string) wc_get_order_status_name($status));
        if ($label !== '') {
            return $label;
        }
    }
    return ucfirst($status !== '' ? $status : 'Unknown');
}

function ado_cd_project_nav_rows(array $orders): array {
    $rows = [];
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) {
            continue;
        }
        $next_visit_raw = trim((string) $order->get_meta('_ado_next_visit_date'));
        $next_visit_ts = $next_visit_raw !== '' ? strtotime($next_visit_raw) : false;
        $wave_status = strtolower(trim((string) $order->get_meta('_ado_wave_status')));
        $rows[] = [
            'order_id' => (int) $order->get_id(),
            'name' => ado_cd_order_name($order),
            'stage' => ado_cd_order_stage_label($order),
            'door_count' => ado_cd_order_door_count($order),
            'next_visit' => $next_visit_ts !== false ? wp_date('M j', (int) $next_visit_ts) : $next_visit_raw,
            'next_visit_ts' => $next_visit_ts !== false ? (int) $next_visit_ts : 0,
            'has_invoice_alert' => in_array($wave_status, ['pending', 'overdue', 'unpaid'], true),
            'invoice_alert_label' => $wave_status === 'overdue' ? 'Overdue invoice' : 'Invoice pending',
            'is_completed' => strtolower(trim((string) $order->get_status())) === 'completed',
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $a_completed = !empty($a['is_completed']);
        $b_completed = !empty($b['is_completed']);
        if ($a_completed !== $b_completed) {
            return $a_completed ? 1 : -1;
        }
        $a_visit = (int) ($a['next_visit_ts'] ?? 0);
        $b_visit = (int) ($b['next_visit_ts'] ?? 0);
        if ($a_visit > 0 && $b_visit > 0 && $a_visit !== $b_visit) {
            return $a_visit <=> $b_visit;
        }
        if ($a_visit > 0 && $b_visit <= 0) {
            return -1;
        }
        if ($b_visit > 0 && $a_visit <= 0) {
            return 1;
        }
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    return $rows;
}

function ado_cd_parse_technician_ids(string $raw): array {
    $ids = preg_split('/[\s,]+/', trim($raw)) ?: [];
    $rows = [];
    foreach ($ids as $id) {
        $value = (int) $id;
        if ($value > 0) {
            $rows[$value] = true;
        }
    }
    return array_values(array_map('intval', array_keys($rows)));
}

function ado_cd_schedule_slot_options(): array {
    return [
        'morning' => 'Morning (8:00 AM - 11:00 AM)',
        'midday' => 'Midday (11:00 AM - 2:00 PM)',
        'afternoon' => 'Afternoon (2:00 PM - 5:00 PM)',
    ];
}

function ado_cd_schedule_slot_windows(): array {
    return [
        'morning' => ['label' => 'Morning (8:00 AM - 11:00 AM)', 'start' => '08:00', 'end' => '11:00'],
        'midday' => ['label' => 'Midday (11:00 AM - 2:00 PM)', 'start' => '11:00', 'end' => '14:00'],
        'afternoon' => ['label' => 'Afternoon (2:00 PM - 5:00 PM)', 'start' => '14:00', 'end' => '17:00'],
    ];
}

function ado_cd_schedule_slot_key_for_visit(string $visit_raw): ?string {
    $visit_raw = trim($visit_raw);
    if ($visit_raw === '') {
        return null;
    }

    $timestamp = strtotime($visit_raw);
    if ($timestamp === false) {
        return null;
    }

    if (!preg_match('/\d{1,2}:\d{2}/', $visit_raw)) {
        return 'full_day';
    }

    $minutes = ((int) wp_date('G', $timestamp) * 60) + (int) wp_date('i', $timestamp);
    if ($minutes < 660) {
        return 'morning';
    }
    if ($minutes < 840) {
        return 'midday';
    }
    return 'afternoon';
}

function ado_cd_schedule_timezone(): DateTimeZone {
    $timezone_name = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
    if ($timezone_name === '') {
        $timezone_name = (string) get_option('timezone_string');
    }
    if ($timezone_name === '') {
        $timezone_name = 'UTC';
    }
    try {
        return new DateTimeZone($timezone_name);
    } catch (Exception $exception) {
        return new DateTimeZone('UTC');
    }
}

function ado_cd_schedule_timezone_name(): string {
    return ado_cd_schedule_timezone()->getName();
}

function ado_cd_schedule_business_day_rows(int $window_days = 14): array {
    $window_days = max(1, $window_days);
    $timezone = ado_cd_schedule_timezone();
    $rows = [];
    $cursor = new DateTimeImmutable('today', $timezone);

    while (count($rows) < $window_days) {
        if ((int) $cursor->format('N') < 6) {
            $rows[] = [
                'date_key' => $cursor->format('Y-m-d'),
                'date_label' => $cursor->format('D, M j'),
                'day_start' => $cursor->setTime(0, 0, 0),
                'day_end' => $cursor->setTime(23, 59, 59),
            ];
        }
        $cursor = $cursor->modify('+1 day');
    }

    return $rows;
}

function ado_cd_google_calendar_api_key(): string {
    $constants = [
        'ADO_GOOGLE_CALENDAR_API_KEY',
        'GOOGLE_CALENDAR_API_KEY',
        'ADO_GOOGLE_API_KEY',
        'GOOGLE_API_KEY',
    ];
    foreach ($constants as $constant_name) {
        if (defined($constant_name)) {
            $value = trim((string) constant($constant_name));
            if ($value !== '') {
                return trim((string) apply_filters('ado_cd_google_calendar_api_key', $value));
            }
        }
    }

    $env_keys = [
        'ADO_GOOGLE_CALENDAR_API_KEY',
        'GOOGLE_CALENDAR_API_KEY',
        'ADO_GOOGLE_API_KEY',
        'GOOGLE_API_KEY',
    ];
    foreach ($env_keys as $env_key) {
        $value = getenv($env_key);
        if ($value !== false) {
            $value = trim((string) $value);
            if ($value !== '') {
                return trim((string) apply_filters('ado_cd_google_calendar_api_key', $value));
            }
        }
    }

    $settings = get_option('adxp_portals_settings', []);
    if (is_array($settings)) {
        $value = trim((string) ($settings['gcal_api_key'] ?? ''));
        if ($value !== '') {
            return trim((string) apply_filters('ado_cd_google_calendar_api_key', $value));
        }
    }

    $option_keys = [
        'ado_google_calendar_api_key',
        'ado_google_api_key',
        'google_calendar_api_key',
        'gcal_api_key',
    ];
    foreach ($option_keys as $option_key) {
        $value = trim((string) get_option($option_key, ''));
        if ($value !== '') {
            return trim((string) apply_filters('ado_cd_google_calendar_api_key', $value));
        }
    }

    return trim((string) apply_filters('ado_cd_google_calendar_api_key', ''));
}

function ado_cd_google_calendar_mapping(array $assigned_technician_ids): array {
    $calendar_ids = [];
    $missing_technician_ids = [];

    foreach (array_values(array_unique(array_map('intval', $assigned_technician_ids))) as $technician_id) {
        if ($technician_id <= 0) {
            continue;
        }
        $calendar_id = trim((string) get_user_meta($technician_id, '_ado_google_calendar_id', true));
        $calendar_id = trim((string) apply_filters('ado_cd_google_calendar_id_for_technician', $calendar_id, $technician_id));
        if ($calendar_id === '') {
            $missing_technician_ids[] = $technician_id;
            continue;
        }
        $calendar_ids[$calendar_id] = true;
    }

    return [
        'calendar_ids' => array_values(array_map('strval', array_keys($calendar_ids))),
        'missing_technician_ids' => array_values(array_map('intval', $missing_technician_ids)),
    ];
}

function ado_cd_google_freebusy_busy_blocks(array $calendar_payloads): array {
    $busy_blocks = [];
    foreach ($calendar_payloads as $calendar_id => $calendar_payload) {
        foreach ((array) ($calendar_payload['busy'] ?? []) as $busy_row) {
            $start_raw = trim((string) ($busy_row['start'] ?? ''));
            $end_raw = trim((string) ($busy_row['end'] ?? ''));
            if ($start_raw === '' || $end_raw === '') {
                continue;
            }
            try {
                $start = new DateTimeImmutable($start_raw);
                $end = new DateTimeImmutable($end_raw);
            } catch (Exception $exception) {
                continue;
            }
            if ($end <= $start) {
                continue;
            }
            $busy_blocks[] = [
                'calendar_id' => (string) $calendar_id,
                'start_ts' => $start->getTimestamp(),
                'end_ts' => $end->getTimestamp(),
            ];
        }
    }
    return $busy_blocks;
}

function ado_cd_google_availability_finalize(array $result, WC_Order $order, int $window_days): array {
    $result['source'] = trim((string) ($result['source'] ?? 'google_freebusy'));
    if ($result['source'] === '') {
        $result['source'] = 'google_freebusy';
    }
    $result['state'] = sanitize_key((string) ($result['state'] ?? 'fetch_error'));
    if ($result['state'] === '') {
        $result['state'] = 'fetch_error';
    }
    $result['message'] = trim((string) ($result['message'] ?? ''));
    $result['fetched_at'] = trim((string) ($result['fetched_at'] ?? wp_date(DATE_ATOM)));
    if ($result['fetched_at'] === '') {
        $result['fetched_at'] = wp_date(DATE_ATOM);
    }
    $result['timezone'] = trim((string) ($result['timezone'] ?? ado_cd_schedule_timezone_name()));
    if ($result['timezone'] === '') {
        $result['timezone'] = ado_cd_schedule_timezone_name();
    }
    $result['window_days'] = max(1, (int) ($result['window_days'] ?? $window_days));
    $result['booking_enabled'] = false;
    $result['assigned_technician_ids'] = array_values(array_map('intval', (array) ($result['assigned_technician_ids'] ?? [])));
    $result['calendar_ids'] = array_values(array_map('strval', (array) ($result['calendar_ids'] ?? [])));
    $result['missing_technician_ids'] = array_values(array_map('intval', (array) ($result['missing_technician_ids'] ?? [])));
    $result['slots'] = array_values((array) ($result['slots'] ?? []));

    return apply_filters('ado_cd_google_availability_adapter_result', $result, $order, $window_days);
}

function ado_cd_google_availability_adapter(WC_Order $order, int $window_days = 14): array {
    $result = [
        'state' => 'fetch_error',
        'message' => 'Live Google availability could not be loaded right now.',
        'source' => 'google_freebusy',
        'fetched_at' => wp_date(DATE_ATOM),
        'timezone' => ado_cd_schedule_timezone_name(),
        'window_days' => max(1, $window_days),
        'booking_enabled' => false,
        'assigned_technician_ids' => [],
        'calendar_ids' => [],
        'missing_technician_ids' => [],
        'slots' => [],
    ];

    $assigned_technician_ids = ado_cd_parse_technician_ids((string) $order->get_meta('_ado_technician_ids'));
    $result['assigned_technician_ids'] = $assigned_technician_ids;
    if (!$assigned_technician_ids) {
        $result['state'] = 'no_assigned_technician';
        $result['message'] = 'Booking is not available yet because no technician is assigned to this project.';
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $mapping = ado_cd_google_calendar_mapping($assigned_technician_ids);
    $result['calendar_ids'] = (array) ($mapping['calendar_ids'] ?? []);
    $result['missing_technician_ids'] = (array) ($mapping['missing_technician_ids'] ?? []);
    if (!$result['calendar_ids'] || $result['missing_technician_ids']) {
        $result['state'] = 'no_calendar_mapping';
        $result['message'] = !$result['calendar_ids']
            ? 'Live Google availability is unavailable because the assigned technician calendar is not mapped yet.'
            : 'Live Google availability is unavailable because one or more assigned technician calendars are not mapped yet.';
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $api_key = ado_cd_google_calendar_api_key();
    if ($api_key === '') {
        $result['state'] = 'missing_credentials';
        $result['message'] = 'Live Google availability is currently unavailable because Google Calendar credentials are not configured.';
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $day_rows = ado_cd_schedule_business_day_rows($window_days);
    if (!$day_rows) {
        $result['state'] = 'fetch_error';
        $result['message'] = 'Live Google availability could not be normalized for the next business days.';
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $first_day = $day_rows[0];
    $last_day = $day_rows[count($day_rows) - 1];
    $response = wp_remote_post(
        'https://www.googleapis.com/calendar/v3/freeBusy?key=' . rawurlencode($api_key),
        [
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 20,
            'body' => wp_json_encode([
                'timeMin' => $first_day['day_start']->format(DateTimeInterface::RFC3339),
                'timeMax' => $last_day['day_end']->format(DateTimeInterface::RFC3339),
                'timeZone' => $result['timezone'],
                'items' => array_map(static function (string $calendar_id): array {
                    return ['id' => $calendar_id];
                }, $result['calendar_ids']),
            ]),
        ]
    );

    if (is_wp_error($response)) {
        $result['state'] = 'fetch_error';
        $result['message'] = 'Live Google availability could not be loaded right now: ' . $response->get_error_message();
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    $response_body = (string) wp_remote_retrieve_body($response);
    if ($response_code < 200 || $response_code >= 300) {
        $decoded_error = json_decode($response_body, true);
        $error_message = trim((string) ($decoded_error['error']['message'] ?? ''));
        $result['state'] = 'fetch_error';
        $result['message'] = 'Live Google availability request failed'
            . ($response_code > 0 ? ' (' . $response_code . ')' : '')
            . ($error_message !== '' ? ': ' . $error_message : '.');
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $decoded = json_decode($response_body, true);
    if (!is_array($decoded)) {
        $result['state'] = 'fetch_error';
        $result['message'] = 'Live Google availability returned an unreadable response.';
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $calendar_payloads = (array) ($decoded['calendars'] ?? []);
    if (!$calendar_payloads) {
        $result['state'] = 'fetch_error';
        $result['message'] = 'Live Google availability returned no calendar payload.';
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $calendar_errors = [];
    foreach ($result['calendar_ids'] as $calendar_id) {
        foreach ((array) (($calendar_payloads[$calendar_id] ?? [])['errors'] ?? []) as $error_row) {
            $reason = trim((string) ($error_row['reason'] ?? ''));
            if ($reason !== '') {
                $calendar_errors[] = $calendar_id . ': ' . $reason;
            }
        }
    }
    if ($calendar_errors) {
        $result['state'] = 'fetch_error';
        $result['message'] = 'Live Google availability could not read every assigned calendar: ' . implode('; ', $calendar_errors);
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $busy_blocks = ado_cd_google_freebusy_busy_blocks($calendar_payloads);
    $slot_windows = ado_cd_schedule_slot_windows();
    $timezone = ado_cd_schedule_timezone();
    $slots = [];

    foreach ($day_rows as $day_row) {
        foreach ($slot_windows as $slot_key => $slot_window) {
            try {
                $slot_start = new DateTimeImmutable($day_row['date_key'] . ' ' . (string) ($slot_window['start'] ?? '00:00'), $timezone);
                $slot_end = new DateTimeImmutable($day_row['date_key'] . ' ' . (string) ($slot_window['end'] ?? '23:59'), $timezone);
            } catch (Exception $exception) {
                continue;
            }
            if ($slot_end <= $slot_start) {
                continue;
            }

            $is_busy = false;
            foreach ($busy_blocks as $busy_block) {
                if ((int) ($busy_block['start_ts'] ?? 0) < $slot_end->getTimestamp() && (int) ($busy_block['end_ts'] ?? 0) > $slot_start->getTimestamp()) {
                    $is_busy = true;
                    break;
                }
            }
            if ($is_busy) {
                continue;
            }

            $slots[] = [
                'date_key' => (string) ($day_row['date_key'] ?? ''),
                'date_label' => (string) ($day_row['date_label'] ?? ''),
                'slot_key' => (string) $slot_key,
                'slot_label' => (string) ($slot_window['label'] ?? $slot_key),
                'slot_start' => $slot_start->format(DateTimeInterface::RFC3339),
                'slot_end' => $slot_end->format(DateTimeInterface::RFC3339),
                'timezone' => $result['timezone'],
                'source' => 'google_freebusy',
                'fetched_at' => $result['fetched_at'],
            ];
        }
    }

    $result['state'] = 'ok';
    $result['message'] = $slots
        ? 'Live availability is shown from Google Calendar free/busy.'
        : 'No live availability was returned from Google Calendar for the next 14 business days.';
    $result['slots'] = $slots;

    return ado_cd_google_availability_finalize($result, $order, $window_days);
}

function ado_cd_schedule_request_state(WC_Order $order): array {
    $status = strtolower(trim((string) $order->get_meta('_ado_schedule_request_status')));
    $date = trim((string) $order->get_meta('_ado_schedule_requested_date'));
    $time = trim((string) $order->get_meta('_ado_schedule_requested_time'));
    $slot_key = sanitize_key((string) $order->get_meta('_ado_schedule_requested_slot_key'));
    $requested_by = (int) $order->get_meta('_ado_schedule_requested_by_user');
    $requested_at = trim((string) $order->get_meta('_ado_schedule_requested_at'));
    $slots = ado_cd_schedule_slot_options();
    $slot_label = (string) ($slots[$slot_key] ?? ucwords(str_replace('-', ' ', $slot_key)));

    return [
        'status' => $status,
        'date' => $date,
        'time' => $time,
        'slot_key' => $slot_key,
        'slot_label' => $slot_label,
        'requested_by_user' => $requested_by,
        'requested_at' => $requested_at,
    ];
}

function ado_cd_scope_payload(WC_Order $order): array {
    $snapshot = (string) $order->get_meta('_ado_scoped_json_snapshot');
    if ($snapshot !== '') {
        $json = json_decode($snapshot, true);
        if (is_array($json)) {
            return $json;
        }
    }

    $scope_path = (string) $order->get_meta('_ado_scoped_json_path');
    if ($scope_path !== '' && is_readable($scope_path)) {
        $json = json_decode((string) file_get_contents($scope_path), true);
        if (is_array($json)) {
            return $json;
        }
    }

    return [];
}

function ado_cd_hardware_category_from_text(string $text): string {
    $text = strtolower(trim($text));
    $rules = [
        'Locks and Strikes' => ['strike', 'lock', 'latch', 'cylinder', 'thumbturn', 'deadbolt'],
        'Operators and Closers' => ['operator', 'closer'],
        'Access Control' => ['reader', 'key switch', 'card', 'relay', 'sensor', 'push', 'wire', 'control'],
        'Door Components' => ['plate', 'arm', 'spindle', 'bracket', 'mount', 'adapter'],
        'Hinges and Pivots' => ['hinge', 'pivot'],
    ];
    foreach ($rules as $label => $needles) {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($text, $needle) !== false) {
                return $label;
            }
        }
    }
    return 'Other Hardware';
}

function ado_cd_hardware_category_weight(string $label): int {
    $map = [
        'Locks and Strikes' => 10,
        'Operators and Closers' => 20,
        'Access Control' => 30,
        'Door Components' => 40,
        'Hinges and Pivots' => 50,
        'Other Hardware' => 90,
    ];
    return $map[$label] ?? 999;
}

function ado_cd_order_doors(WC_Order $order): array {
    $rows = [];
    $by_id = [];
    $normalize_items = static function ($items): array {
        $rows = [];
        $by_key = [];
        foreach ((array) $items as $item) {
            $label = '';
            $qty = 0;
            $category = '';
            if (is_array($item)) {
                $label = trim((string) ($item['catalog'] ?? $item['model'] ?? $item['desc'] ?? $item['name'] ?? ''));
                $qty = (int) ($item['qty'] ?? $item['quantity'] ?? 0);
                $source_text = trim(implode(' ', array_filter([
                    (string) ($item['category'] ?? ''),
                    (string) ($item['catalog'] ?? ''),
                    (string) ($item['model'] ?? ''),
                    (string) ($item['desc'] ?? ''),
                    (string) ($item['raw'] ?? ''),
                    (string) ($item['name'] ?? ''),
                ])));
                $category = trim((string) ($item['category'] ?? ''));
                if ($category === '') {
                    $category = ado_cd_hardware_category_from_text($source_text !== '' ? $source_text : $label);
                }
            } else {
                $label = trim((string) $item);
                $category = ado_cd_hardware_category_from_text($label);
            }
            if ($label === '') {
                continue;
            }
            $key = strtolower($label);
            if (isset($by_key[$key])) {
                $idx = (int) $by_key[$key];
                if ($qty > 0) {
                    $rows[$idx]['qty'] = max((int) ($rows[$idx]['qty'] ?? 0), $qty);
                }
                if (trim((string) ($rows[$idx]['category'] ?? '')) === '' && $category !== '') {
                    $rows[$idx]['category'] = $category;
                }
                continue;
            }
            $by_key[$key] = count($rows);
            $rows[] = [
                'label' => $label,
                'qty' => $qty > 0 ? $qty : 0,
                'category' => $category,
            ];
        }
        return $rows;
    };
    $merge_items = static function (array $base_items, array $incoming_items): array {
        if (!$incoming_items) {
            return $base_items;
        }
        $rows = [];
        $by_key = [];
        foreach (array_merge($base_items, $incoming_items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $qty = (int) ($item['qty'] ?? 0);
            $category = trim((string) ($item['category'] ?? ''));
            $key = strtolower($label);
            if (isset($by_key[$key])) {
                $idx = (int) $by_key[$key];
                if ($qty > 0) {
                    $rows[$idx]['qty'] = max((int) ($rows[$idx]['qty'] ?? 0), $qty);
                }
                if (trim((string) ($rows[$idx]['category'] ?? '')) === '' && $category !== '') {
                    $rows[$idx]['category'] = $category;
                }
                continue;
            }
            $by_key[$key] = count($rows);
            $rows[] = [
                'label' => $label,
                'qty' => $qty > 0 ? $qty : 0,
                'category' => $category,
            ];
        }
        return $rows;
    };
    $push_door = static function (array $door) use (&$rows, &$by_id, $merge_items): void {
        $door_id = trim((string) ($door['door_id'] ?? ''));
        if ($door_id === '') {
            return;
        }
        $key = strtolower($door_id);
        if (isset($by_id[$key])) {
            $idx = $by_id[$key];
            foreach (['door_label', 'door_number', 'model', 'location', 'door_type'] as $field) {
                if (trim((string) ($rows[$idx][$field] ?? '')) === '' && trim((string) ($door[$field] ?? '')) !== '') {
                    $rows[$idx][$field] = (string) $door[$field];
                }
            }
            if (!empty($door['items']) && is_array($door['items'])) {
                $rows[$idx]['items'] = $rows[$idx]['items'] ?? [];
                $rows[$idx]['items'] = $merge_items($rows[$idx]['items'], $door['items']);
            }
            return;
        }
        $by_id[$key] = count($rows);
        $rows[] = [
            'door_id' => $door_id,
            'door_number' => trim((string) ($door['door_number'] ?? $door_id)),
            'door_label' => trim((string) ($door['door_label'] ?? ('Door ' . $door_id))),
            'model' => trim((string) ($door['model'] ?? '')),
            'location' => trim((string) ($door['location'] ?? '')),
            'door_type' => trim((string) ($door['door_type'] ?? '')),
            'items' => !empty($door['items']) && is_array($door['items']) ? $door['items'] : [],
        ];
    };

    $payload = ado_cd_scope_payload($order);
    foreach ((array) ($payload['result']['doors'] ?? []) as $door) {
        if (!is_array($door)) {
            continue;
        }
        $door_id = trim((string) ($door['door_id'] ?? $door['door_number'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $model = '';
        foreach ((array) ($door['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $model = trim((string) ($item['catalog'] ?? $item['model'] ?? $item['desc'] ?? ''));
            if ($model !== '') {
                break;
            }
        }
        $door_label = trim((string) ($door['door_label'] ?? ''));
        $door_number = trim((string) ($door['door_number'] ?? $door_id));
        if ($door_label === '') {
            $door_label = 'Door ' . $door_number;
        }
        $push_door([
            'door_id' => $door_id,
            'door_number' => $door_number,
            'door_label' => $door_label,
            'model' => $model,
            'location' => trim((string) ($door['heading'] ?? '')),
            'door_type' => trim((string) ($door['door_type'] ?? '')),
            'items' => $normalize_items($door['items'] ?? []),
        ]);
    }

    $project_doors = $order->get_meta('_ado_project_doors');
    if (is_array($project_doors)) {
        foreach ($project_doors as $door) {
            if (!is_array($door)) {
                continue;
            }
            $door_id = trim((string) ($door['door_number'] ?? ($door['door_id'] ?? '')));
            if ($door_id === '') {
                continue;
            }
            $door_number = trim((string) ($door['door_number'] ?? $door_id));
            $door_label = trim((string) ($door['door_label'] ?? ''));
            if ($door_label === '') {
                $door_label = 'Door ' . $door_number;
            }
            $model = '';
            if (!empty($door['signals']) && is_array($door['signals'])) {
                $model = trim((string) reset($door['signals']));
            }
            if ($model === '' && !empty($door['items']) && is_array($door['items'])) {
                foreach ($door['items'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $model = trim((string) ($item['catalog'] ?? $item['model'] ?? $item['desc'] ?? ''));
                    if ($model !== '') {
                        break;
                    }
                }
            }
            $push_door([
                'door_id' => $door_id,
                'door_number' => $door_number,
                'door_label' => $door_label,
                'model' => $model,
                'location' => trim((string) ($door['heading'] ?? '')),
                'door_type' => trim((string) ($door['door_type'] ?? '')),
                'items' => $normalize_items($door['items'] ?? []),
            ]);
        }
    }

    foreach ($order->get_items() as $item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            continue;
        }
        $door_id = trim((string) $item->get_meta('_adq_door_number'));
        if ($door_id === '') {
            $door_id = trim((string) $item->get_meta('_adq_door_id'));
        }
        if ($door_id === '') {
            continue;
        }
        $push_door([
            'door_id' => $door_id,
            'door_number' => $door_id,
            'door_label' => 'Door ' . $door_id,
            'model' => trim((string) $item->get_meta('_adq_model')),
            'location' => '',
            'door_type' => '',
            'items' => $normalize_items([[
                'catalog' => trim((string) $item->get_meta('_adq_model')) !== '' ? (string) $item->get_meta('_adq_model') : (string) $item->get_name(),
                'qty' => (int) $item->get_quantity(),
            ]]),
        ]);
    }
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($a['door_number'] ?? ''), (string) ($b['door_number'] ?? ''));
    });

    return $rows;
}

function ado_cd_dom_id(string $prefix, string $value): string {
    $slug = strtolower(trim((string) $value));
    $slug = preg_replace('/[^a-z0-9\-_]+/', '-', $slug) ?: '';
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
        $slug = 'item';
    }
    return $prefix . $slug;
}

function ado_cd_client_door_feedback_defaults(): array {
    return [
        'readiness_confirmed' => false,
        'readiness_note' => '',
        'note' => '',
        'note_history' => [],
        'documents' => [],
        'updated_at' => '',
        'updated_by' => 0,
    ];
}

function ado_cd_client_door_feedback_map(WC_Order $order): array {
    $map = $order->get_meta('_ado_client_door_feedback');
    return is_array($map) ? $map : [];
}

function ado_cd_client_door_feedback_state(WC_Order $order, string $door_id): array {
    $map = ado_cd_client_door_feedback_map($order);
    $state = is_array($map[$door_id] ?? null) ? $map[$door_id] : [];
    $defaults = ado_cd_client_door_feedback_defaults();

    $defaults['readiness_confirmed'] = !empty($state['readiness_confirmed']);
    $defaults['readiness_note'] = trim((string) ($state['readiness_note'] ?? ''));
    $defaults['note'] = trim((string) ($state['note'] ?? ''));
    $defaults['updated_at'] = trim((string) ($state['updated_at'] ?? ''));
    $defaults['updated_by'] = (int) ($state['updated_by'] ?? 0);
    $note_history = [];
    foreach ((array) ($state['note_history'] ?? []) as $note_entry) {
        if (!is_array($note_entry)) {
            continue;
        }
        $note_text = trim((string) ($note_entry['note'] ?? ''));
        if ($note_text === '') {
            continue;
        }
        $note_history[] = [
            'note' => $note_text,
            'created_at' => trim((string) ($note_entry['created_at'] ?? '')),
            'created_by' => (int) ($note_entry['created_by'] ?? 0),
            'source' => trim((string) ($note_entry['source'] ?? 'project_manager')),
        ];
    }
    $defaults['note_history'] = $note_history;

    $documents = [];
    foreach ((array) ($state['documents'] ?? []) as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $url = trim((string) ($doc['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $documents[] = [
            'url' => $url,
            'name' => trim((string) ($doc['name'] ?? 'Document')),
            'uploaded_at' => trim((string) ($doc['uploaded_at'] ?? '')),
            'uploaded_by' => (int) ($doc['uploaded_by'] ?? 0),
        ];
    }
    $defaults['documents'] = $documents;

    return $defaults;
}

function ado_cd_project_door_workflow_defaults(): array {
    return [
        'site_preparation' => [
            'state' => 'yes',
            'comment' => '',
        ],
        'hardware_availability' => [
            'state' => 'yes',
            'comment' => '',
        ],
        'hardware_entries' => [],
        'testing' => [
            'note' => '',
            'complete' => false,
            'final_video' => [],
            'updated_at' => '',
        ],
    ];
}

function ado_cd_project_door_workflow_state(WC_Order $order, string $door_id): array {
    $workflow_map = $order->get_meta('_ado_tp_project_door_workflow');
    $workflow_map = is_array($workflow_map) ? $workflow_map : [];
    $door_state = is_array($workflow_map[$door_id] ?? null) ? $workflow_map[$door_id] : [];
    $defaults = ado_cd_project_door_workflow_defaults();

    foreach (['site_preparation', 'hardware_availability'] as $section) {
        $state_value = strtolower(trim((string) ($door_state[$section]['state'] ?? 'yes')));
        $defaults[$section]['state'] = in_array($state_value, ['no', '0', 'false', 'off'], true) ? 'no' : 'yes';
        $defaults[$section]['comment'] = trim((string) ($door_state[$section]['comment'] ?? ''));
    }

    $hardware_entries = $door_state['hardware_entries'] ?? [];
    $defaults['hardware_entries'] = is_array($hardware_entries) ? $hardware_entries : [];

    $testing = is_array($door_state['testing'] ?? null) ? $door_state['testing'] : [];
    $defaults['testing']['note'] = trim((string) ($testing['note'] ?? ''));
    $defaults['testing']['complete'] = !empty($testing['complete']);
    $defaults['testing']['final_video'] = is_array($testing['final_video'] ?? null) ? $testing['final_video'] : [];
    $defaults['testing']['updated_at'] = trim((string) ($testing['updated_at'] ?? ''));

    return $defaults;
}

function ado_cd_project_door_normalize_media_entries($entries): array {
    $rows = [];
    foreach ((array) $entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $url = trim((string) ($entry['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $rows[] = [
            'url' => $url,
            'name' => trim((string) ($entry['name'] ?? 'Media')),
            'type' => strtolower(trim((string) ($entry['type'] ?? 'file'))),
            'created_at' => trim((string) ($entry['created_at'] ?? '')),
            'section' => trim((string) ($entry['section'] ?? '')),
            'category_key' => trim((string) ($entry['category_key'] ?? '')),
            'model_key' => trim((string) ($entry['model_key'] ?? '')),
        ];
    }
    return $rows;
}

function ado_cd_project_door_hardware_progress(array $workflow_state): array {
    $hardware_entries = is_array($workflow_state['hardware_entries'] ?? null) ? (array) $workflow_state['hardware_entries'] : [];
    $total = 0;
    $installed = 0;
    foreach ($hardware_entries as $category) {
        if (!is_array($category)) {
            continue;
        }
        foreach ((array) ($category['models'] ?? []) as $model_entry) {
            if (!is_array($model_entry)) {
                continue;
            }
            $total++;
            if (!empty($model_entry['installed'])) {
                $installed++;
            }
        }
    }
    $percent = $total > 0 ? (int) round(($installed / $total) * 100) : 0;
    return [
        'total' => $total,
        'installed' => $installed,
        'percent' => max(0, min(100, $percent)),
    ];
}

function ado_cd_project_door_matches_hint(array $door, string $hint, string $note = ''): bool {
    $hint = strtoupper(trim($hint));
    $door_id = strtoupper(trim((string) ($door['door_id'] ?? '')));
    $door_number = strtoupper(trim((string) ($door['door_number'] ?? $door_id)));
    $candidates = array_values(array_filter([$door_id, $door_number]));
    if ($hint !== '' && in_array($hint, $candidates, true)) {
        return true;
    }
    if ($hint !== '') {
        return false;
    }
    $note_upper = strtoupper($note);
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && strpos($note_upper, $candidate) !== false) {
            return true;
        }
    }
    return false;
}

function ado_cd_project_door_media_rows(WC_Order $order, array $door, array $workflow_state): array {
    $rows = [];
    $seen = [];
    $push_media = static function (array $row) use (&$rows, &$seen): void {
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '' || isset($seen[$url])) {
            return;
        }
        $seen[$url] = true;
        $created_at = trim((string) ($row['created_at'] ?? ''));
        $rows[] = [
            'url' => $url,
            'name' => trim((string) ($row['name'] ?? 'Media')),
            'type' => strtolower(trim((string) ($row['type'] ?? 'file'))),
            'section' => trim((string) ($row['section'] ?? '')),
            'created_at' => $created_at,
            'ts' => (int) (strtotime($created_at) ?: 0),
        ];
    };

    foreach ((array) ($workflow_state['hardware_entries'] ?? []) as $category_entry) {
        if (!is_array($category_entry)) {
            continue;
        }
        $category_label = trim((string) ($category_entry['category_label'] ?? 'Hardware'));
        foreach ((array) ($category_entry['models'] ?? []) as $model_entry) {
            if (!is_array($model_entry)) {
                continue;
            }
            $model_label = trim((string) ($model_entry['model_label'] ?? 'Model'));
            foreach (ado_cd_project_door_normalize_media_entries($model_entry['media'] ?? []) as $media_entry) {
                if (!is_array($media_entry)) {
                    continue;
                }
                $media_entry['section'] = $category_label . ' - ' . $model_label;
                $push_media($media_entry);
            }
        }
    }

    foreach (ado_cd_project_door_normalize_media_entries($workflow_state['testing']['final_video'] ?? []) as $video_entry) {
        if (!is_array($video_entry)) {
            continue;
        }
        $video_entry['section'] = 'Final Test Video';
        $push_media($video_entry);
    }

    $logs = $order->get_meta('_ado_tech_logs');
    if (is_array($logs)) {
        foreach (array_reverse($logs) as $log) {
            if (!is_array($log)) {
                continue;
            }
            $url = trim((string) ($log['attachment_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $note = trim((string) ($log['note'] ?? $log['text'] ?? $log['message'] ?? ''));
            $door_hint = trim((string) ($log['door_hint'] ?? ''));
            if (!ado_cd_project_door_matches_hint($door, $door_hint, $note)) {
                continue;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            $name = trim((string) basename($path));
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $push_media([
                'url' => $url,
                'name' => $name !== '' ? $name : 'Field Media',
                'type' => $ext !== '' ? $ext : 'file',
                'section' => 'Technician Field Upload',
                'created_at' => trim((string) ($log['created_at'] ?? '')),
            ]);
        }
    }

    usort($rows, static fn(array $a, array $b): int => ((int) ($b['ts'] ?? 0)) <=> ((int) ($a['ts'] ?? 0)));
    return $rows;
}

function ado_cd_project_door_note_history(WC_Order $order, array $door, array $workflow_state, array $client_state): array {
    $rows = [];
    $push_note = static function (array $row) use (&$rows): void {
        $note = trim((string) ($row['note'] ?? ''));
        if ($note === '') {
            return;
        }
        $created_at = trim((string) ($row['created_at'] ?? ''));
        $rows[] = [
            'source' => trim((string) ($row['source'] ?? 'Update')),
            'section' => trim((string) ($row['section'] ?? '')),
            'author' => trim((string) ($row['author'] ?? '')),
            'note' => $note,
            'created_at' => $created_at,
            'ts' => (int) (strtotime($created_at) ?: 0),
        ];
    };

    $logs = $order->get_meta('_ado_tech_logs');
    if (is_array($logs)) {
        foreach (array_reverse($logs) as $log) {
            if (!is_array($log)) {
                continue;
            }
            $note = trim((string) ($log['note'] ?? $log['text'] ?? $log['message'] ?? ''));
            if ($note === '') {
                continue;
            }
            $door_hint = trim((string) ($log['door_hint'] ?? ''));
            if (!ado_cd_project_door_matches_hint($door, $door_hint, $note)) {
                continue;
            }
            $push_note([
                'source' => 'Technician',
                'section' => 'Field Note',
                'author' => trim((string) ($log['technician_name'] ?? '')),
                'note' => $note,
                'created_at' => trim((string) ($log['created_at'] ?? '')),
            ]);
        }
    }

    foreach ((array) ($workflow_state['hardware_entries'] ?? []) as $category_entry) {
        if (!is_array($category_entry)) {
            continue;
        }
        $category_label = trim((string) ($category_entry['category_label'] ?? 'Hardware'));
        foreach ((array) ($category_entry['models'] ?? []) as $model_entry) {
            if (!is_array($model_entry)) {
                continue;
            }
            $model_note = trim((string) ($model_entry['note'] ?? ''));
            if ($model_note === '') {
                continue;
            }
            $model_label = trim((string) ($model_entry['model_label'] ?? 'Model'));
            $push_note([
                'source' => 'Technician',
                'section' => $category_label . ' - ' . $model_label,
                'note' => $model_note,
                'created_at' => trim((string) ($model_entry['updated_at'] ?? '')),
            ]);
        }
    }

    $testing_note = trim((string) ($workflow_state['testing']['note'] ?? ''));
    if ($testing_note !== '') {
        $testing_timestamp = trim((string) ($workflow_state['testing']['updated_at'] ?? ''));
        if ($testing_timestamp === '') {
            foreach (ado_cd_project_door_normalize_media_entries($workflow_state['testing']['final_video'] ?? []) as $video) {
                if (!is_array($video)) {
                    continue;
                }
                $candidate = trim((string) ($video['created_at'] ?? ''));
                if ($candidate !== '') {
                    $testing_timestamp = $candidate;
                    break;
                }
            }
        }
        $push_note([
            'source' => 'Technician',
            'section' => 'Testing',
            'note' => $testing_note,
            'created_at' => $testing_timestamp,
        ]);
    }

    foreach ((array) ($client_state['note_history'] ?? []) as $client_note) {
        if (!is_array($client_note)) {
            continue;
        }
        $author_id = (int) ($client_note['created_by'] ?? 0);
        $author_label = '';
        if ($author_id > 0) {
            $author_user = get_userdata($author_id);
            if ($author_user instanceof WP_User) {
                $author_label = trim((string) ($author_user->display_name ?: $author_user->user_login));
            }
        }
        $push_note([
            'source' => 'Project Manager',
            'section' => 'PM Note',
            'author' => $author_label,
            'note' => trim((string) ($client_note['note'] ?? '')),
            'created_at' => trim((string) ($client_note['created_at'] ?? '')),
        ]);
    }

    usort($rows, static fn(array $a, array $b): int => ((int) ($b['ts'] ?? 0)) <=> ((int) ($a['ts'] ?? 0)));
    return $rows;
}

function ado_cd_render_project_door_panel(array $door, WC_Order $project): string {
    $door_id = trim((string) ($door['door_id'] ?? ''));
    if ($door_id === '') {
        return '<div class="ado-empty">Door details unavailable.</div>';
    }
    $state = ado_cd_client_door_feedback_state($project, $door_id);
    $workflow_state = ado_cd_project_door_workflow_state($project, $door_id);
    $hardware_progress = ado_cd_project_door_hardware_progress($workflow_state);
    $project_id = (int) $project->get_id();
    $schedule_visit = trim((string) $project->get_meta('_ado_next_visit_date'));
    $scheduling_confirmed = $schedule_visit !== '';
    $door_model = trim((string) ($door['model'] ?? ''));
    $next_visit_raw = trim((string) $project->get_meta('_ado_next_visit_date'));
    $door_label = trim((string) ($door['door_label'] ?? ('Door ' . $door_id)));
    $documents = is_array($state['documents'] ?? null) ? (array) $state['documents'] : [];
    $note_history = ado_cd_project_door_note_history($project, $door, $workflow_state, $state);
    $media_rows = ado_cd_project_door_media_rows($project, $door, $workflow_state);
    $installed_lookup = [];
    foreach ((array) ($workflow_state['hardware_entries'] ?? []) as $category_entry) {
        if (!is_array($category_entry)) {
            continue;
        }
        foreach ((array) ($category_entry['models'] ?? []) as $model_entry) {
            if (!is_array($model_entry)) {
                continue;
            }
            $model_label = preg_replace('/\s+/', ' ', strtolower(trim((string) ($model_entry['model_label'] ?? ''))));
            if ($model_label === '') {
                continue;
            }
            $installed_lookup[$model_label] = !empty($model_entry['installed']);
        }
    }
    $scope_lines = [];
    $scope_seen = [];
    foreach ((array) ($door['items'] ?? []) as $item) {
        if (is_array($item)) {
            $label = trim((string) ($item['label'] ?? $item['catalog'] ?? $item['model'] ?? $item['desc'] ?? $item['name'] ?? ''));
            $qty = (int) ($item['qty'] ?? $item['quantity'] ?? 0);
            $category = trim((string) ($item['category'] ?? ''));
        } else {
            $label = trim((string) $item);
            $qty = 0;
            $category = '';
        }
        if ($label === '') {
            continue;
        }
        $key = strtolower($label);
        if (isset($scope_seen[$key])) {
            $idx = (int) $scope_seen[$key];
            if ($qty > 0) {
                $scope_lines[$idx]['qty'] = max((int) ($scope_lines[$idx]['qty'] ?? 0), $qty);
            }
            if (trim((string) ($scope_lines[$idx]['category'] ?? '')) === '' && $category !== '') {
                $scope_lines[$idx]['category'] = $category;
            }
            continue;
        }
        $scope_seen[$key] = count($scope_lines);
        $scope_lines[] = [
            'label' => $label,
            'qty' => $qty > 0 ? $qty : 0,
            'category' => $category,
        ];
    }
    if (!$scope_lines && $door_model !== '') {
        $scope_lines[] = ['label' => $door_model, 'qty' => 0, 'category' => ado_cd_hardware_category_from_text($door_model)];
    }
    $hardware_groups = [];
    foreach ($scope_lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $line_label = trim((string) ($line['label'] ?? ''));
        if ($line_label === '') {
            continue;
        }
        $line_category = trim((string) ($line['category'] ?? ''));
        if ($line_category === '') {
            $line_category = ado_cd_hardware_category_from_text($line_label);
        }
        if (!isset($hardware_groups[$line_category])) {
            $hardware_groups[$line_category] = [];
        }
        $hardware_groups[$line_category][] = $line;
    }
    uksort($hardware_groups, static fn(string $a, string $b): int => ado_cd_hardware_category_weight($a) <=> ado_cd_hardware_category_weight($b));

    ob_start();
    ?>
    <div class="ado-client-door-panel">
      <div class="ado-client-door-status-grid ado-client-door-status-grid-3">
        <div class="ado-client-door-status-card <?php echo $scheduling_confirmed ? 'ok' : 'warn'; ?>">
          <strong>Site Readiness</strong>
          <small><?php echo esc_html($scheduling_confirmed ? 'Confirmed during scheduling' : 'Pending scheduling confirmation'); ?></small>
        </div>
        <div class="ado-client-door-status-card <?php echo $scheduling_confirmed ? 'ok' : 'warn'; ?>">
          <strong>Hardware Availability</strong>
          <small><?php echo esc_html($scheduling_confirmed ? 'Confirmed during scheduling' : 'Pending scheduling confirmation'); ?></small>
        </div>
        <div class="ado-client-door-status-card <?php echo $hardware_progress['percent'] >= 100 ? 'ok' : 'warn'; ?>">
          <strong>Completion</strong>
          <small>Progress reflects technician installation updates.</small>
          <div class="ado-client-door-progress-track"><span class="ado-client-door-progress-fill" style="width:<?php echo esc_attr((string) $hardware_progress['percent']); ?>%;"></span></div>
        </div>
      </div>
      <form class="ado-client-door-form" enctype="multipart/form-data">
        <input type="hidden" name="project_id" value="<?php echo esc_attr((string) $project_id); ?>">
        <input type="hidden" name="door_id" value="<?php echo esc_attr($door_id); ?>">
        <div class="ado-client-door-card">
          <div class="ado-client-door-section-head">
            <h4 class="ado-client-door-section-title">Hardware Tracking</h4>
            <button class="ado-btn ado-client-door-upload-toggle" type="button" aria-expanded="false">Add Files / Photos</button>
          </div>
          <div class="ado-client-door-upload-panel" hidden>
            <label class="ado-client-door-field">
              <span>Upload additional document or photo</span>
              <input type="file" name="door_document" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
            </label>
          </div>
          <?php if (!$hardware_groups) { ?>
          <div class="ado-empty ado-client-door-empty">No scoped hardware lines were found for this door yet.</div>
          <?php } else { ?>
          <div class="ado-client-door-scope-list">
            <?php foreach ($hardware_groups as $category_label => $group_lines) { ?>
            <div class="ado-client-door-hardware-category"><?php echo esc_html((string) $category_label); ?></div>
            <?php foreach ((array) $group_lines as $line) { if (!is_array($line)) { continue; } $label = trim((string) ($line['label'] ?? '')); if ($label === '') { continue; } $qty = (int) ($line['qty'] ?? 0); ?>
            <div class="ado-client-door-scope-item">
              <strong><?php echo esc_html($label); ?></strong>
              <div class="ado-client-door-scope-meta">
                <?php if ($qty > 1) { ?><span class="ado-client-door-scope-qty">x<?php echo esc_html((string) $qty); ?></span><?php } ?>
                <?php $scope_model_key = preg_replace('/\s+/', ' ', strtolower($label)); $model_installed = $installed_lookup[$scope_model_key] ?? null; ?>
                <span class="ado-client-door-scope-state <?php echo $model_installed === true ? 'ok' : 'warn'; ?>"><?php echo esc_html($model_installed === true ? 'Installed' : 'Pending'); ?></span>
              </div>
            </div>
            <?php } ?>
            <?php } ?>
          </div>
          <?php } ?>
        </div>
        <div class="ado-client-door-card">
          <h4 class="ado-client-door-section-title">Notes Timeline</h4>
          <?php if (!$note_history) { ?>
          <div class="ado-empty ado-client-door-empty">No technician or project-manager notes recorded for this door yet.</div>
          <?php } else { ?>
          <div class="ado-client-door-note-history">
            <?php foreach ($note_history as $history_row) { if (!is_array($history_row)) { continue; } ?>
            <div class="ado-client-door-note-item">
              <div class="ado-client-door-note-head">
                <strong><?php echo esc_html((string) ($history_row['source'] ?? 'Update')); ?></strong>
                <small><?php echo esc_html(trim((string) ($history_row['created_at'] ?? '')) !== '' ? (string) ($history_row['created_at'] ?? '') : 'Timestamp unavailable'); ?></small>
              </div>
              <?php if (!empty($history_row['section'])) { ?><div class="ado-row-sub"><?php echo esc_html('Section: ' . (string) $history_row['section']); ?></div><?php } ?>
              <?php if (!empty($history_row['author'])) { ?><div class="ado-row-sub"><?php echo esc_html('By: ' . (string) $history_row['author']); ?></div><?php } ?>
              <p><?php echo esc_html((string) ($history_row['note'] ?? '')); ?></p>
            </div>
            <?php } ?>
          </div>
          <?php } ?>
        </div>
        <div class="ado-client-door-card">
          <h4 class="ado-client-door-section-title">Photos And Videos</h4>
          <?php if (!$media_rows) { ?>
          <div class="ado-empty ado-client-door-empty">No technician photos/videos or final test video uploaded yet.</div>
          <?php } else { ?>
          <div class="ado-client-door-media-list">
            <?php foreach ($media_rows as $media_row) { if (!is_array($media_row)) { continue; } ?>
            <a class="ado-client-door-media-item" href="<?php echo esc_url((string) ($media_row['url'] ?? '#')); ?>" target="_blank" rel="noopener">
              <strong><?php echo esc_html((string) ($media_row['name'] ?? 'Media')); ?></strong>
              <small><?php echo esc_html(trim((string) ($media_row['section'] ?? '')) !== '' ? (string) ($media_row['section'] ?? '') : strtoupper((string) ($media_row['type'] ?? 'file'))); ?></small>
              <?php if (!empty($media_row['created_at'])) { ?><small><?php echo esc_html((string) $media_row['created_at']); ?></small><?php } ?>
            </a>
            <?php } ?>
          </div>
          <?php } ?>
          <div class="ado-client-door-doc-list">
            <strong>Client Uploads</strong>
            <?php if (empty($documents)) { ?>
            <p class="ado-row-sub">No door-specific files uploaded yet.</p>
            <?php } else { ?>
            <?php foreach ($documents as $doc) { ?>
            <a href="<?php echo esc_url((string) ($doc['url'] ?? '#')); ?>" target="_blank" rel="noopener">
              <span><?php echo esc_html((string) ($doc['name'] ?? 'Document')); ?></span>
              <small><?php echo esc_html((string) ($doc['uploaded_at'] ?? '')); ?></small>
            </a>
            <?php } ?>
            <?php } ?>
          </div>
        </div>
        <div class="ado-client-door-card ado-client-pm-note-panel" hidden>
          <h4 class="ado-client-door-section-title">Project Manager Note</h4>
          <label class="ado-client-door-field">
            <span>Project manager note</span>
            <textarea name="note" rows="4" placeholder="Add a project manager note for this door."><?php echo esc_textarea((string) ($state['note'] ?? '')); ?></textarea>
          </label>
        </div>
        <div class="ado-client-door-actions">
          <button class="ado-btn primary" type="submit">Save Door Update</button>
          <span class="ado-row-sub"><?php echo esc_html($next_visit_raw !== '' ? ('Next scheduled visit: ' . $next_visit_raw) : 'Schedule confirmation controls readiness and hardware availability status.'); ?></span>
        </div>
        <div class="ado-client-door-flash" aria-live="polite"></div>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function ado_cd_counts(int $user_id, ?array $orders = null): array {
    $quotes_count = 0;
    if (function_exists('ado_get_quote_drafts')) {
        $quotes_count = count((array) ado_get_quote_drafts($user_id));
    }

    $orders = is_array($orders) ? $orders : ado_cd_client_orders($user_id);
    $overdue_count = 0;
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $wave_status = strtolower(trim((string) $order->get_meta('_ado_wave_status')));
        if ($wave_status === 'overdue') { $overdue_count++; }
    }

    return [
        'quotes_count' => $quotes_count,
        'overdue_count' => $overdue_count,
    ];
}

function ado_cd_find_project_order(array $orders, int $project_id): ?WC_Order {
    if ($project_id <= 0) {
        return null;
    }
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) {
            continue;
        }
        if ((int) $order->get_id() === $project_id) {
            return $order;
        }
    }
    return null;
}

function ado_cd_wave_status_label(string $wave_status): string {
    $wave_status = strtolower(trim($wave_status));
    $map = [
        'pending' => 'Pending Approval',
        'unpaid' => 'Payment Due',
        'overdue' => 'Overdue',
        'paid' => 'Paid',
    ];
    return $map[$wave_status] ?? 'Not Invoiced';
}

function ado_cd_order_attention_items(WC_Order $order): array {
    $items = [];

    $wave_status = strtolower(trim((string) $order->get_meta('_ado_wave_status')));
    if ($wave_status === 'overdue') {
        $items[] = 'An invoice is overdue and requires payment.';
    } elseif (in_array($wave_status, ['pending', 'unpaid'], true)) {
        $items[] = 'An invoice is waiting for your review/payment.';
    }

    $next_visit = trim((string) $order->get_meta('_ado_next_visit_date'));
    if ($next_visit === '') {
        $items[] = 'No upcoming visit is scheduled yet.';
    }

    $status = strtolower(trim((string) $order->get_status()));
    if (in_array($status, ['pending', 'on-hold'], true)) {
        $items[] = 'Project schedule is still being coordinated.';
    }

    $critical = trim((string) $order->get_meta('_ado_critical_notes'));
    if ($critical !== '') {
        $items[] = 'There are priority field notes that may require your response.';
    }

    return array_values(array_unique($items));
}

function ado_cd_project_activity_rows(WC_Order $order, int $limit = 8): array {
    $logs = $order->get_meta('_ado_tech_logs');
    if (!is_array($logs) || !$logs) {
        return [];
    }

    $rows = [];
    foreach (array_reverse($logs) as $log) {
        if (!is_array($log)) {
            continue;
        }
        $note = trim((string) ($log['note'] ?? ''));
        $priority = strtolower(trim((string) ($log['priority'] ?? 'normal')));
        $created_at = trim((string) ($log['created_at'] ?? ''));
        $attachment_url = trim((string) ($log['attachment_url'] ?? ''));
        $hours = (float) ($log['hours'] ?? 0);
        $summary = $note !== '' ? $note : 'Update posted by technician.';
        $label = $attachment_url !== '' ? 'Photo/Attachment Added' : 'Technician Note Added';
        $rows[] = [
            'label' => $label,
            'summary' => $summary,
            'created_at' => $created_at,
            'priority' => in_array($priority, ['normal', 'high', 'critical'], true) ? $priority : 'normal',
            'hours' => $hours,
            'attachment_url' => $attachment_url,
        ];
        if (count($rows) >= max(1, $limit)) {
            break;
        }
    }

    return $rows;
}

function ado_cd_project_file_rows(WC_Order $order): array {
    $rows = [];

    $po_url = trim((string) $order->get_meta('_ado_po_file_url'));
    if ($po_url !== '') {
        $rows[] = [
            'name' => 'Purchase Order Document',
            'meta' => 'Uploaded PO',
            'url' => $po_url,
        ];
    }

    $invoice_url = trim((string) $order->get_meta('_ado_wave_invoice_url'));
    if ($invoice_url !== '') {
        $rows[] = [
            'name' => 'Invoice',
            'meta' => 'Billing',
            'url' => $invoice_url,
        ];
    }

    $logs = $order->get_meta('_ado_tech_logs');
    $seen = [];
    if (is_array($logs)) {
        foreach (array_reverse($logs) as $log) {
            if (!is_array($log)) {
                continue;
            }
            $url = trim((string) ($log['attachment_url'] ?? ''));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $created = trim((string) ($log['created_at'] ?? ''));
            $rows[] = [
                'name' => 'Field Attachment',
                'meta' => $created !== '' ? ('Added ' . $created) : 'Technician upload',
                'url' => $url,
            ];
        }
    }

    return $rows;
}

function ado_cd_render_project_workspace(array $orders, int $selected_project_id): string {
    if (!$orders) {
        return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Project Tracking</span></div><div class="ado-empty">No projects are available for your account.</div></article>';
    }

    if ($selected_project_id <= 0 && isset($orders[0]) && $orders[0] instanceof WC_Order) {
        $selected_project_id = (int) $orders[0]->get_id();
    }

    $project = ado_cd_find_project_order($orders, $selected_project_id);
    if (!($project instanceof WC_Order)) {
        return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Project Tracking</span></div><div class="ado-empty">Project not found or unavailable for your account.</div></article>';
    }

    $project_id = (int) $project->get_id();
    $project_name = ado_cd_order_name($project);
    $project_address = function_exists('ado_order_project_address') ? trim((string) ado_order_project_address($project)) : '';
    $stage = ado_cd_order_stage_label($project);
    $door_count = ado_cd_order_door_count($project);
    $next_visit_raw = trim((string) $project->get_meta('_ado_next_visit_date'));
    $next_visit_ts = $next_visit_raw !== '' ? strtotime($next_visit_raw) : false;
    $next_visit = $next_visit_ts !== false ? wp_date('M j, Y', (int) $next_visit_ts) : ($next_visit_raw !== '' ? $next_visit_raw : 'Not scheduled');
    $wave_status = strtolower(trim((string) $project->get_meta('_ado_wave_status')));
    $invoice_label = ado_cd_wave_status_label($wave_status);
    $invoice_url = trim((string) $project->get_meta('_ado_wave_invoice_url'));
    $amount_due = (float) $project->get_meta('_ado_wave_amount_due');
    if ($amount_due <= 0 && in_array($wave_status, ['pending', 'overdue', 'unpaid'], true)) {
        $amount_due = (float) $project->get_total();
    }
    $attention_items = ado_cd_order_attention_items($project);
    $activity_rows = ado_cd_project_activity_rows($project, 8);
    $file_rows = ado_cd_project_file_rows($project);
    $critical_notes = trim((string) $project->get_meta('_ado_critical_notes'));
    $po_number = trim((string) $project->get_meta('_ado_po_number'));
    $project_doors = ado_cd_order_doors($project);
    $selected_door_id = sanitize_text_field((string) ($_GET['door_id'] ?? ''));
    $selected_door = null;
    foreach ($project_doors as $door) {
        if ((string) ($door['door_id'] ?? '') === $selected_door_id) {
            $selected_door = $door;
            break;
        }
    }
    $door_drawer_message = '';
    if ($selected_door_id !== '' && !is_array($selected_door)) {
        $door_drawer_message = 'Door not found in this project.';
    }
    $initial_tab = $selected_door_id !== '' ? 'doors' : 'overview';
    $has_confirmed_visit = $next_visit_raw !== '';
    $availability = ado_cd_google_availability_adapter($project, 14);
    $availability_state = (string) ($availability['state'] ?? 'fetch_error');
    $availability_slots = (array) ($availability['slots'] ?? []);
    $availability_source = trim((string) ($availability['source'] ?? ''));
    $availability_fetched_at = trim((string) ($availability['fetched_at'] ?? ''));
    $availability_message = trim((string) ($availability['message'] ?? ''));

    ob_start();
    ?>
    <div class="ado-project-shell">
      <article class="ado-card ado-project-header-card">
        <div class="ado-project-header">
            <div>
              <div class="ado-project-title"><?php echo esc_html($project_name); ?></div>
              <div class="ado-project-sub">Project #<?php echo esc_html((string) $project_id); ?> | <?php echo esc_html($stage); ?><?php if ($project_address !== '') { ?> | <?php echo esc_html($project_address); ?><?php } ?></div>
              <div class="ado-project-meta-row">
              <span class="ado-project-pill"><?php echo esc_html($door_count); ?> <?php echo esc_html($door_count === 1 ? 'door' : 'doors'); ?></span>
              <span class="ado-project-pill"><?php echo esc_html('Next Visit: ' . $next_visit); ?></span>
              <span class="ado-project-pill <?php echo esc_attr($wave_status === 'overdue' ? 'danger' : (in_array($wave_status, ['pending', 'unpaid'], true) ? 'warn' : 'ok')); ?>"><?php echo esc_html('Invoice: ' . $invoice_label); ?></span>
              <?php if ($po_number !== '') { ?><span class="ado-project-pill">PO: <?php echo esc_html($po_number); ?></span><?php } ?>
            </div>
          </div>
          <div class="ado-project-actions">
            <a class="ado-btn" href="<?php echo esc_url(ado_cd_view_url('schedule')); ?>">Schedule</a>
            <a class="ado-btn" href="<?php echo esc_url(ado_cd_view_url('invoices')); ?>">Invoices</a>
            <?php if ($invoice_url !== '') { ?><a class="ado-btn primary" href="<?php echo esc_url($invoice_url); ?>" target="_blank" rel="noopener">Open Invoice</a><?php } ?>
          </div>
        </div>
      </article>

      <div class="ado-project-tab-strip" data-project-tabs>
        <button class="ado-project-tab <?php echo $initial_tab === 'overview' ? 'active' : ''; ?>" type="button" data-tab-target="overview">Overview</button>
        <span class="ado-project-tab-dot" aria-hidden="true">&bull;</span>
        <button class="ado-project-tab <?php echo $initial_tab === 'doors' ? 'active' : ''; ?>" type="button" data-tab-target="doors">Doors</button>
        <span class="ado-project-tab-dot" aria-hidden="true">&bull;</span>
        <button class="ado-project-tab <?php echo $initial_tab === 'activity' ? 'active' : ''; ?>" type="button" data-tab-target="activity">Activity</button>
        <span class="ado-project-tab-dot" aria-hidden="true">&bull;</span>
        <button class="ado-project-tab <?php echo $initial_tab === 'files' ? 'active' : ''; ?>" type="button" data-tab-target="files">Files / Photos</button>
      </div>

      <div class="ado-project-tab-panel <?php echo $initial_tab === 'overview' ? 'is-active' : ''; ?>" data-tab-panel="overview" <?php echo $initial_tab === 'overview' ? '' : 'hidden'; ?>>
      <div class="ado-project-grid">
        <article class="ado-card">
          <div class="ado-card-head"><span class="ado-card-title">Project Summary</span></div>
          <div class="ado-project-summary-grid">
            <div class="ado-project-kv"><strong>Stage</strong><span><?php echo esc_html($stage); ?></span></div>
            <div class="ado-project-kv"><strong>Doors</strong><span><?php echo esc_html((string) $door_count); ?></span></div>
            <div class="ado-project-kv"><strong>Next Visit</strong><span><?php echo esc_html($next_visit); ?></span></div>
            <div class="ado-project-kv"><strong>Invoice Status</strong><span><?php echo esc_html($invoice_label); ?><?php if ($amount_due > 0) { echo esc_html(' (' . ado_cd_currency($amount_due) . ')'); } ?></span></div>
          </div>
          <?php if ($critical_notes !== '') { ?><div class="ado-project-note"><?php echo esc_html($critical_notes); ?></div><?php } ?>
        </article>

        <article class="ado-card">
          <div class="ado-card-head"><span class="ado-card-title">Needs Attention</span></div>
          <?php if (!$attention_items) { ?>
          <div class="ado-empty">No immediate actions required.</div>
          <?php } else { ?>
          <ul class="ado-project-attention-list">
            <?php foreach ($attention_items as $item) { ?>
            <li><?php echo esc_html((string) $item); ?></li>
            <?php } ?>
          </ul>
          <?php } ?>
        </article>
      </div>
      <article class="ado-card ado-schedule-request-card">
        <div class="ado-card-head"><span class="ado-card-title">Book Technician Visit</span></div>
        <div class="ado-schedule-request-body">
          <?php if ($has_confirmed_visit) { ?>
          <div class="ado-schedule-confirmed"><strong>Confirmed visit:</strong> <?php echo esc_html($next_visit); ?></div>
          <?php } else { ?>
          <div class="ado-schedule-confirmed"><strong>Confirmed visit:</strong> Not scheduled</div>
          <?php } ?>

          <?php if ($availability_state !== 'ok') { ?>
          <div class="ado-schedule-note"><?php echo esc_html($availability_message !== '' ? $availability_message : 'Live Google availability is unavailable right now.'); ?></div>
          <?php } elseif (!$availability_slots) { ?>
          <div class="ado-schedule-note"><?php echo esc_html($availability_message !== '' ? $availability_message : 'No live availability was returned from Google Calendar for the next 14 business days.'); ?></div>
          <?php } else { ?>
          <div class="ado-schedule-note">Read-only live availability is shown below from the assigned technician Google Calendar mapping.</div>
          <fieldset class="ado-schedule-slot-list" disabled aria-disabled="true">
            <?php foreach ($availability_slots as $slot_row) { ?>
            <label class="ado-schedule-slot-option">
              <input type="radio" name="bookable_slot_<?php echo esc_attr((string) $project_id); ?>" disabled>
              <span class="ado-schedule-slot-copy">
                <strong><?php echo esc_html((string) ($slot_row['date_label'] ?? '')); ?></strong>
                <small><?php echo esc_html((string) ($slot_row['slot_label'] ?? '')); ?></small>
              </span>
            </label>
            <?php } ?>
          </fieldset>
          <div class="ado-schedule-actions">
            <button class="ado-btn primary" type="button" disabled aria-disabled="true">Booking Disabled In Slice 1b</button>
            <span class="ado-row-sub">Availability source: <?php echo esc_html($availability_source !== '' ? $availability_source : 'google_freebusy'); ?><?php if ($availability_fetched_at !== '') { ?> | Fetched: <?php echo esc_html($availability_fetched_at); ?><?php } ?>. Booking submission is not active in this slice.</span>
          </div>
          <?php } ?>
          <?php if ($availability_source !== '' || $availability_fetched_at !== '') { ?>
          <div class="ado-row-sub">Availability state: <?php echo esc_html($availability_state); ?><?php if ($availability_source !== '') { ?> | Source: <?php echo esc_html($availability_source); ?><?php } ?><?php if ($availability_fetched_at !== '') { ?> | Fetched: <?php echo esc_html($availability_fetched_at); ?><?php } ?></div>
          <?php } ?>
        </div>
      </article>
      </div>

      <article class="ado-card ado-project-tab-panel <?php echo $initial_tab === 'activity' ? 'is-active' : ''; ?>" data-tab-panel="activity" <?php echo $initial_tab === 'activity' ? '' : 'hidden'; ?>>
        <div class="ado-card-head"><span class="ado-card-title">Recent Activity</span></div>
        <?php if (!$activity_rows) { ?>
        <div class="ado-empty">No activity logged yet for this project.</div>
        <?php } else { ?>
        <ul class="ado-project-activity-list">
          <?php foreach ($activity_rows as $activity) { ?>
          <li>
            <div class="ado-project-activity-head">
              <strong><?php echo esc_html((string) ($activity['label'] ?? 'Update')); ?></strong>
              <?php if (!empty($activity['created_at'])) { ?><small><?php echo esc_html((string) $activity['created_at']); ?></small><?php } ?>
            </div>
            <div class="ado-row-sub"><?php echo esc_html((string) ($activity['summary'] ?? '')); ?></div>
            <div class="ado-project-activity-meta">
              <span class="ado-pill <?php echo esc_attr(($activity['priority'] ?? 'normal') === 'critical' ? 'critical' : (($activity['priority'] ?? 'normal') === 'high' ? 'high' : 'ok')); ?>"><?php echo esc_html(strtoupper((string) ($activity['priority'] ?? 'normal'))); ?></span>
              <?php if (!empty($activity['hours']) && (float) $activity['hours'] > 0) { ?><span class="ado-row-sub"><?php echo esc_html(number_format((float) $activity['hours'], 2)); ?>h</span><?php } ?>
              <?php if (!empty($activity['attachment_url'])) { ?><a class="ado-btn" style="padding:4px 10px;font-size:11px;" href="<?php echo esc_url((string) $activity['attachment_url']); ?>" target="_blank" rel="noopener">Open Attachment</a><?php } ?>
            </div>
          </li>
          <?php } ?>
        </ul>
        <?php } ?>
      </article>

      <div class="ado-project-tab-panel ado-client-door-workspace <?php echo $initial_tab === 'doors' ? 'is-active' : ''; ?>" data-tab-panel="doors" data-project-id="<?php echo esc_attr((string) $project_id); ?>" data-selected-door="<?php echo esc_attr($selected_door_id); ?>" <?php echo $initial_tab === 'doors' ? '' : 'hidden'; ?>>
        <article class="ado-card">
          <?php if (empty($project_doors)) { ?>
          <div class="ado-empty">No doors are available for this project yet.</div>
          <?php } else { ?>
          <div class="ado-project-door-list">
            <?php foreach ($project_doors as $door) { if (!is_array($door)) { continue; } $door_id = trim((string) ($door['door_id'] ?? '')); if ($door_id === '') { continue; } $door_label = trim((string) ($door['door_label'] ?? ('Door ' . $door_id))); $door_meta = trim(implode(' | ', array_filter([trim((string) ($door['model'] ?? '')), trim((string) ($door['location'] ?? ''))]))); $template_id = ado_cd_dom_id('ado-client-door-template-', $door_id); $door_url = ado_cd_view_url('projects', ['project_id' => $project_id, 'door_id' => $door_id]); ?>
            <a class="ado-project-door-trigger <?php echo $selected_door_id === $door_id ? 'active' : ''; ?>" href="<?php echo esc_url($door_url); ?>" data-door-id="<?php echo esc_attr($door_id); ?>" data-door-template="<?php echo esc_attr($template_id); ?>" data-door-label="<?php echo esc_attr($door_label); ?>" data-door-meta="<?php echo esc_attr($door_meta); ?>">
              <strong><?php echo esc_html($door_label); ?></strong>
              <small><?php echo esc_html($door_meta !== '' ? $door_meta : 'Door status and updates'); ?></small>
            </a>
            <?php } ?>
          </div>
          <?php } ?>
        </article>

        <div class="ado-client-door-backdrop <?php echo ($selected_door || $door_drawer_message !== '') ? 'is-open' : ''; ?>" <?php echo ($selected_door || $door_drawer_message !== '') ? '' : 'hidden'; ?>></div>
        <aside class="ado-client-door-drawer <?php echo ($selected_door || $door_drawer_message !== '') ? 'is-open' : ''; ?>" <?php echo ($selected_door || $door_drawer_message !== '') ? '' : 'hidden'; ?>>
          <div class="ado-client-door-drawer-head">
            <div>
              <div class="ado-client-door-drawer-kicker">Project Door</div>
              <div class="ado-client-door-drawer-title"><?php echo esc_html($selected_door ? (string) ($selected_door['door_label'] ?? 'Door details') : 'Door details'); ?></div>
              <div class="ado-client-door-drawer-sub"><?php echo esc_html($selected_door ? trim(implode(' | ', array_filter([(string) ($selected_door['model'] ?? ''), (string) ($selected_door['location'] ?? '')]))) : ($door_drawer_message !== '' ? $door_drawer_message : 'Select a door for progress, notes history, and media visibility.')); ?></div>
            </div>
            <div class="ado-client-door-head-actions">
              <button class="ado-btn ado-client-door-notes-toggle" type="button" aria-expanded="false">Project Manager Note</button>
              <button class="ado-btn ado-client-door-close" type="button">Close</button>
            </div>
          </div>
          <div class="ado-client-door-drawer-body"><?php if ($selected_door && is_array($selected_door)) { echo ado_cd_render_project_door_panel($selected_door, $project); } elseif ($door_drawer_message !== '') { ?><div class="ado-empty"><?php echo esc_html($door_drawer_message); ?></div><?php } else { ?><div class="ado-empty">Select a door to manage readiness and updates.</div><?php } ?></div>
        </aside>
        <?php foreach ($project_doors as $door) { if (!is_array($door)) { continue; } $door_id = trim((string) ($door['door_id'] ?? '')); if ($door_id === '') { continue; } $template_id = ado_cd_dom_id('ado-client-door-template-', $door_id); ?><template id="<?php echo esc_attr($template_id); ?>"><?php echo ado_cd_render_project_door_panel($door, $project); ?></template><?php } ?>
      </div>

      <article class="ado-card ado-project-tab-panel <?php echo $initial_tab === 'files' ? 'is-active' : ''; ?>" data-tab-panel="files" <?php echo $initial_tab === 'files' ? '' : 'hidden'; ?>>
        <div class="ado-card-head"><span class="ado-card-title">Files / Photos</span></div>
        <?php if (!$file_rows) { ?>
        <div class="ado-empty">No files uploaded yet for this project.</div>
        <?php } else { ?>
        <div class="ado-project-file-list">
          <?php foreach ($file_rows as $file) { ?>
          <a class="ado-project-file-link" href="<?php echo esc_url((string) ($file['url'] ?? '#')); ?>" target="_blank" rel="noopener">
            <strong><?php echo esc_html((string) ($file['name'] ?? 'File')); ?></strong>
            <small><?php echo esc_html((string) ($file['meta'] ?? '')); ?></small>
          </a>
          <?php } ?>
        </div>
        <?php } ?>
      </article>
    </div>
    <?php

    return (string) ob_get_clean();
}

function ado_cd_render_quotes_queue(int $user_id): string {
    if (!function_exists('ado_render_quote_drafts_html')) {
        return '<div class="ado-empty">Quotes module is unavailable.</div>';
    }
    return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Saved Quotes</span></div><div style="padding:16px 18px;">' . ado_render_quote_drafts_html($user_id) . '</div></article>';
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
            'project' => ado_cd_order_name($order),
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
    $orders = ado_cd_client_orders($user_id);
    $rows = [];
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $visit = trim((string) $order->get_meta('_ado_next_visit_date'));
        if ($visit === '') { continue; }
        $ts = strtotime($visit);
        if ($ts === false) { continue; }
        $rows[] = [
            'name' => ado_cd_order_name($order),
            'date' => wp_date('M j, Y', (int) $ts),
            'status' => in_array($order->get_status(), ['pending', 'on-hold'], true) ? 'Confirm' : 'Booked',
        ];
    }
    usort($rows, static fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
    ob_start();
    echo '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Upcoming Visits</span></div>';
    if (!$rows) {
        echo '<div class="ado-empty">No upcoming visits scheduled yet.</div>';
    } else {
        echo '<ul class="ado-list">';
        foreach ($rows as $row) {
            echo '<li><div class="ado-row-title">' . esc_html($row['name']) . '</div><div class="ado-row-sub">' . esc_html($row['date']) . '</div><span class="ado-pill ' . ($row['status'] === 'Confirm' ? 'high' : 'ok') . '">' . esc_html($row['status']) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</article>';
    echo '<article class="ado-card" style="margin-top:16px;"><div class="ado-card-head"><span class="ado-card-title">Live Availability Source</span></div><div style="padding:16px 18px;"><p class="ado-row-sub">Read-only technician availability is shown inside each project using the assigned technician Google Calendar mapping. Booking stays disabled in this slice.</p></div></article>';
    return (string) ob_get_clean();
}

function ado_cd_render_view_content(string $view, int $user_id, array $orders = [], int $selected_project_id = 0): string {
    switch ($view) {
        case 'new-quote':
            return do_shortcode('[ado_client_quote_dashboard]');
        case 'quotes':
            return do_shortcode('[ado_client_quote_dashboard]');
        case 'projects':
            return ado_cd_render_project_workspace($orders ?: ado_cd_client_orders($user_id), $selected_project_id);
        case 'service-calls':
            return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">My Service Calls</span></div><div style="padding:16px 18px;"><p class="ado-row-sub">We are preparing this section. Please contact support if you need help with a service call.</p></div></article>';
        case 'schedule':
            return ado_cd_render_schedule($user_id);
        case 'invoices':
            return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Outstanding Invoices</span></div>' . ado_cd_render_invoices($user_id) . '</article>';
        case 'dashboard':
        default:
            return do_shortcode('[ado_client_dashboard]');
    }
}

add_shortcode('ado_client_dashboard_app', static function (): string {
    if (!is_user_logged_in() || !ado_is_client()) {
        return '<p>Client access only.</p>';
    }

    $uid = (int) get_current_user_id();
    $orders = ado_cd_client_orders($uid);
    $counts = ado_cd_counts($uid, $orders);
    $project_nav_rows = ado_cd_project_nav_rows($orders);
    $selected_project_id = (int) ($_GET['project_id'] ?? 0);
    $view = sanitize_key((string) ($_GET['view'] ?? 'dashboard'));
    $client_nonce = wp_create_nonce('ado_client_portal_nonce');
    $view_titles = [
        'dashboard' => 'Dashboard',
        'new-quote' => 'New Quote',
        'quotes' => 'My Quotes',
        'projects' => 'My Projects',
        'service-calls' => 'My Service Calls',
        'schedule' => 'Schedule',
        'invoices' => 'Invoices',
    ];
    if (!isset($view_titles[$view])) {
        $view = 'dashboard';
    }
    if ($view === 'projects' && $selected_project_id <= 0 && !empty($project_nav_rows[0]['order_id'])) {
        $selected_project_id = (int) $project_nav_rows[0]['order_id'];
    }
    $page_title = (string) $view_titles[$view];
    if ($view === 'projects' && $selected_project_id > 0) {
        foreach ($project_nav_rows as $project_nav_row) {
            if ((int) ($project_nav_row['order_id'] ?? 0) === $selected_project_id) {
                $page_title = (string) ($project_nav_row['name'] ?? $page_title);
                break;
            }
        }
    }
    ob_start();
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap');
    .ado-app{--bg:#f4f5f7;--surface:#fff;--surface-2:#f9fafb;--border:#e8eaed;--accent:#1a56db;--warn:#e3a008;--danger:#e02424;--text-primary:#111928;--text-secondary:#6b7280;--text-muted:#9ca3af;--shadow-sm:0 1px 3px rgba(0,0,0,0.06),0 1px 2px rgba(0,0,0,0.04);--radius:14px;--radius-sm:8px;font-family:'DM Sans',sans-serif;background:var(--bg);display:flex;min-height:100vh;color:var(--text-primary)}
    .ado-app *{box-sizing:border-box}.ado-side{width:256px;background:var(--text-primary);min-height:100vh;position:sticky;top:0}.ado-side-logo{padding:28px 24px 24px;border-bottom:1px solid rgba(255,255,255,.08);font-family:'Syne',sans-serif;font-weight:800;font-size:20px;color:#fff}.ado-side-logo span{color:var(--accent)}.ado-nav{padding:16px 12px;display:flex;flex-direction:column;gap:2px}.ado-nav-label{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:12px 12px 6px;margin-top:8px}.ado-nav a{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.6);font-size:14px;font-weight:500;text-decoration:none}.ado-nav a:hover{background:rgba(255,255,255,.07);color:#fff}.ado-nav a.active{background:var(--accent);color:#fff}.ado-nav-badge{font-size:10px;font-weight:700;background:var(--danger);padding:2px 6px;border-radius:999px;color:#fff}.ado-nav-project{align-items:flex-start;gap:8px}.ado-nav-project-copy{display:block;min-width:0;flex:1}.ado-nav-project-name{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ado-nav-project-meta{display:block;margin-top:2px;font-size:11px;line-height:1.3;color:rgba(255,255,255,.42);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ado-nav-project.active .ado-nav-project-meta{color:rgba(255,255,255,.85)}
    .ado-main{flex:1;display:flex;flex-direction:column}.ado-top{background:var(--surface);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:20}.ado-top h1{margin:0;font-family:'Syne',sans-serif;font-size:20px}.ado-top-right{display:flex;gap:10px}.ado-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 16px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;cursor:pointer}.ado-btn.primary{background:var(--accent);border-color:transparent;color:#fff}.ado-content{padding:28px}
    .ado-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm)}.ado-card-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}.ado-card-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700}.ado-card-link{font-size:12px;font-weight:600;text-decoration:none;color:var(--accent)}.ado-empty{padding:24px 18px;font-size:13px;color:var(--text-muted)}.ado-table{width:100%;border-collapse:collapse}.ado-table th{padding:10px 18px;text-align:left;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;color:var(--text-muted)}.ado-table td{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px}
    .ado-list{list-style:none;margin:0;padding:0}.ado-list li{padding:14px 18px;border-bottom:1px solid var(--border)}.ado-row-title{font-weight:700}.ado-row-sub{font-size:12px;color:var(--text-muted)}.ado-pill{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:999px}.ado-pill.high{background:#fffbeb;color:#92400e}.ado-pill.ok{background:#f0fdf4;color:#065f46}.ado-pill.critical{background:#fef2f2;color:#e02424}.ado-action-row{display:flex;gap:6px}.ado-action-row .ado-btn{padding:6px 10px;font-size:12px}
    .ado-flash{display:none;margin:10px 18px 16px;padding:10px 12px;border-radius:8px;font-size:12px}.ado-flash.ok{display:block;background:#ecfdf3;color:#027a48}.ado-flash.err{display:block;background:#fef2f2;color:#b42318}
    .ado-project-shell{display:flex;flex-direction:column;gap:12px}.ado-project-header-card{padding:16px 18px}.ado-project-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}.ado-project-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800}.ado-project-sub{margin-top:4px;font-size:13px;color:var(--text-secondary)}.ado-project-meta-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.ado-project-pill{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;background:var(--surface-2);border:1px solid var(--border);color:var(--text-secondary)}.ado-project-pill.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}.ado-project-pill.danger{background:#fef2f2;border-color:#fecaca;color:#b91c1c}.ado-project-pill.ok{background:#ecfdf3;border-color:#bbf7d0;color:#166534}.ado-project-actions{display:flex;gap:8px;flex-wrap:wrap}.ado-project-tab-strip{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ado-project-tab{display:inline-flex;padding:6px 12px;border-radius:999px;border:1px solid var(--border);background:var(--surface);color:var(--text-secondary);font-size:12px;font-weight:600;text-decoration:none;appearance:none;cursor:pointer}.ado-project-tab.active{background:#e8eefc;color:var(--accent);border-color:#bfd0f5}.ado-project-tab-dot{font-size:14px;line-height:1;color:#9ca3af}.ado-project-tab-panel{display:block}.ado-project-tab-panel[hidden]{display:none!important}.ado-project-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:12px}.ado-project-summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:14px}.ado-project-kv{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2)}.ado-project-kv strong{display:block;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}.ado-project-kv span{display:block;margin-top:4px;font-size:13px;color:var(--text-primary)}.ado-project-note{margin:0 14px 14px;padding:10px 12px;border-radius:10px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e;font-size:12px;line-height:1.45}.ado-project-attention-list{list-style:none;margin:0;padding:12px 14px;display:flex;flex-direction:column;gap:8px}.ado-project-attention-list li{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:13px;color:var(--text-primary)}.ado-project-activity-list{list-style:none;margin:0;padding:0}.ado-project-activity-list li{padding:14px 16px;border-bottom:1px solid var(--border)}.ado-project-activity-list li:last-child{border-bottom:none}.ado-project-activity-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.ado-project-activity-head small{font-size:11px;color:var(--text-muted)}.ado-project-activity-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px}.ado-project-file-list{display:flex;flex-direction:column;gap:8px;padding:14px}.ado-project-file-link{display:block;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);text-decoration:none}.ado-project-file-link strong{display:block;color:var(--text-primary)}.ado-project-file-link small{display:block;margin-top:3px;color:var(--text-muted);font-size:12px}.ado-project-door-list{display:flex;flex-direction:column;gap:8px;padding:14px}.ado-project-door-trigger{display:block;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);text-decoration:none;color:var(--text-primary)}.ado-project-door-trigger strong{display:block;font-size:14px}.ado-project-door-trigger small{display:block;margin-top:3px;color:var(--text-muted)}.ado-project-door-trigger.active{border-color:#bfd0f5;background:#e8eefc}.ado-client-door-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.55);opacity:0;pointer-events:none;transition:opacity .16s ease;z-index:99970}.ado-client-door-backdrop.is-open{opacity:1;pointer-events:auto}.ado-client-door-drawer{position:fixed;top:0;right:0;bottom:0;width:46vw;background:var(--surface);border-left:1px solid var(--border);box-shadow:-18px 0 42px rgba(2,6,23,.18);z-index:99971;transform:translateX(100%);transition:transform .2s ease;display:flex;flex-direction:column}.ado-client-door-drawer.is-open{transform:translateX(0)}body.admin-bar .ado-client-door-backdrop{top:32px}body.admin-bar .ado-client-door-drawer{top:32px}body.ado-client-door-open{overflow:hidden}.ado-client-door-drawer-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ado-client-door-drawer-kicker{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#9ca3af}.ado-client-door-drawer-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-top:2px}.ado-client-door-drawer-sub{font-size:12px;color:var(--text-muted);margin-top:3px}.ado-client-door-close{padding:6px 10px;font-size:12px}.ado-client-door-drawer-body{padding:14px;overflow:auto;display:flex;flex-direction:column;gap:12px;flex:1}.ado-client-door-panel{display:flex;flex-direction:column;gap:12px}.ado-client-door-panel-head{display:flex;flex-direction:column;gap:4px}.ado-client-door-panel-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800}.ado-client-door-panel-sub{font-size:12px;color:var(--text-muted)}.ado-client-door-form{display:flex;flex-direction:column;gap:10px}.ado-client-door-check{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:13px}.ado-client-door-check input{margin:0}.ado-client-door-field{display:block}.ado-client-door-field span{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px}.ado-client-door-field textarea,.ado-client-door-field input[type="file"]{width:100%;background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:9px 10px;font-size:13px}.ado-client-door-field textarea{resize:vertical;min-height:90px}.ado-client-door-doc-list{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2)}.ado-client-door-doc-list strong{display:block;font-size:12px;margin-bottom:8px}.ado-client-door-doc-list a{display:block;padding:7px 8px;border:1px solid var(--border);border-radius:8px;text-decoration:none;background:#fff;margin-bottom:6px}.ado-client-door-doc-list a:last-child{margin-bottom:0}.ado-client-door-doc-list span{display:block;color:var(--text-primary);font-size:13px}.ado-client-door-doc-list small{display:block;color:var(--text-muted);font-size:11px;margin-top:2px}.ado-client-door-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ado-client-door-flash{display:none;padding:8px 10px;border-radius:8px;font-size:12px}.ado-client-door-flash.ok{display:block;background:#ecfdf3;color:#027a48}.ado-client-door-flash.err{display:block;background:#fef2f2;color:#b42318}
    .ado-schedule-request-body{padding:14px;display:flex;flex-direction:column;gap:12px}.ado-schedule-confirmed{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:13px;color:var(--text-primary)}.ado-schedule-note{padding:10px;border:1px solid #fde68a;border-radius:10px;background:#fffbeb;color:#92400e;font-size:13px}.ado-schedule-slot-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ado-schedule-slot-option{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);opacity:.72;cursor:not-allowed}.ado-schedule-slot-option input{margin-top:2px}.ado-schedule-slot-copy{display:flex;flex-direction:column;gap:4px}.ado-schedule-slot-copy strong{font-size:13px;color:var(--text-primary)}.ado-schedule-slot-copy small{font-size:12px;color:var(--text-secondary)}.ado-schedule-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ado-schedule-actions .ado-btn[disabled]{opacity:.55;cursor:not-allowed}
    .ado-client-door-drawer{background:#1a1d27;border-left:1px solid rgba(255,255,255,.08);box-shadow:-18px 0 42px rgba(2,6,23,.32)}
    .ado-client-door-drawer-head{border-bottom:1px solid rgba(255,255,255,.08)}
    .ado-client-door-drawer-kicker{color:#64748b;letter-spacing:.12em}
    .ado-client-door-drawer-title{color:#f1f5f9}
    .ado-client-door-drawer-sub{color:#94a3b8;line-height:1.4}
    .ado-client-door-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .ado-client-door-notes-toggle[hidden]{display:none}
    .ado-client-door-close.ado-btn{border-color:rgba(148,163,184,.3);color:#cbd5e1}
    .ado-client-door-drawer-body{gap:14px}
    .ado-client-door-status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .ado-client-door-status-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
    .ado-client-door-status-card{padding:12px;border:1px solid rgba(148,163,184,.25);border-radius:10px;background:rgba(255,255,255,.03)}
    .ado-client-door-status-card strong{display:block;font-size:12px;color:#e2e8f0}
    .ado-client-door-status-card small{display:block;margin-top:4px;font-size:11px;color:#94a3b8}
    .ado-client-door-status-card.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.1)}
    .ado-client-door-status-card.warn{border-color:rgba(249,115,22,.45);background:rgba(249,115,22,.12)}
    .ado-client-door-progress-track{margin-top:8px;height:7px;border-radius:999px;background:rgba(148,163,184,.2);overflow:hidden}
    .ado-client-door-progress-fill{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#60a5fa,#22c55e)}
    .ado-client-door-card{border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px;background:rgba(255,255,255,.02)}
    .ado-client-door-section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
    .ado-client-door-section-title{font-family:'Syne',sans-serif;font-size:13px;margin:0 0 10px;color:#f1f5f9}
    .ado-client-door-section-head .ado-client-door-section-title{margin:0}
    .ado-client-door-upload-toggle{padding:6px 10px;font-size:11px;border-color:rgba(96,165,250,.45);color:#bfdbfe;background:rgba(59,130,246,.14)}
    .ado-client-door-upload-toggle:hover{background:rgba(59,130,246,.2)}
    .ado-client-door-upload-panel{margin-bottom:10px;padding:10px;border:1px solid rgba(148,163,184,.24);border-radius:10px;background:rgba(15,23,42,.35)}
    .ado-client-door-accordion{display:block}
    .ado-client-door-accordion-summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer}
    .ado-client-door-accordion-summary::-webkit-details-marker{display:none}
    .ado-client-door-accordion-icon{color:#94a3b8;font-size:12px;line-height:1;transition:transform .16s ease}
    .ado-client-door-accordion[open] .ado-client-door-accordion-icon{transform:rotate(180deg)}
    .ado-client-door-accordion-body{margin-top:10px}
    .ado-client-door-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .ado-client-door-kv{padding:8px 10px;border:1px solid rgba(148,163,184,.22);border-radius:8px;background:rgba(255,255,255,.03)}
    .ado-client-door-kv strong{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:4px}
    .ado-client-door-kv small{display:block;color:#e2e8f0;font-size:12px}
    .ado-client-door-check{background:rgba(255,255,255,.03);border-color:rgba(148,163,184,.22);color:#e2e8f0}
    .ado-client-door-check input{accent-color:#3b82f6}
    .ado-client-door-field span{color:#94a3b8;letter-spacing:.1em;font-size:10px}
    .ado-client-door-field textarea,.ado-client-door-field input[type="file"]{background:rgba(255,255,255,.05);border-color:rgba(148,163,184,.25);color:#f1f5f9}
    .ado-client-door-field textarea::placeholder{color:#94a3b8;opacity:1}
    .ado-client-door-scope-list{display:flex;flex-direction:column;gap:8px}
    .ado-client-door-hardware-category{margin-top:10px;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#64748b}
    .ado-client-door-hardware-category:first-child{margin-top:2px}
    .ado-client-door-scope-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid rgba(148,163,184,.22);border-radius:8px;background:rgba(255,255,255,.03)}
    .ado-client-door-scope-item strong{font-size:13px;color:#f1f5f9}
    .ado-client-door-scope-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .ado-client-door-scope-qty{font-size:10px;padding:2px 7px;border-radius:999px;background:rgba(59,130,246,.16);border:1px solid rgba(59,130,246,.35);color:#93c5fd}
    .ado-client-door-scope-state{font-size:10px;letter-spacing:.04em;text-transform:uppercase;padding:2px 8px;border-radius:999px;border:1px solid rgba(148,163,184,.3);color:#cbd5e1}
    .ado-client-door-scope-state.ok{background:rgba(34,197,94,.16);border-color:rgba(34,197,94,.4);color:#86efac}
    .ado-client-door-scope-state.warn{background:rgba(249,115,22,.14);border-color:rgba(249,115,22,.45);color:#fdba74}
    .ado-client-door-doc-list{margin-top:10px;background:rgba(255,255,255,.03);border-color:rgba(148,163,184,.22)}
    .ado-client-door-doc-list strong{color:#e2e8f0}
    .ado-client-door-doc-list .ado-row-sub{color:#94a3b8;margin:0}
    .ado-client-door-doc-list a{background:rgba(15,23,42,.55);border-color:rgba(148,163,184,.22)}
    .ado-client-door-doc-list span{color:#f1f5f9}
    .ado-client-door-doc-list small{color:#94a3b8}
    .ado-client-door-note-history{display:flex;flex-direction:column;gap:8px}
    .ado-client-door-note-item{padding:10px;border:1px solid rgba(148,163,184,.22);border-radius:8px;background:rgba(255,255,255,.03)}
    .ado-client-door-note-item p{margin:8px 0 0;color:#e2e8f0;font-size:13px;line-height:1.45}
    .ado-client-door-note-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .ado-client-door-note-head strong{font-size:12px;color:#f1f5f9}
    .ado-client-door-note-head small{font-size:11px;color:#94a3b8}
    .ado-client-door-media-list{display:flex;flex-direction:column;gap:8px}
    .ado-client-door-media-item{display:block;padding:10px;border:1px solid rgba(148,163,184,.22);border-radius:8px;background:rgba(15,23,42,.55);text-decoration:none}
    .ado-client-door-media-item strong{display:block;color:#f1f5f9;font-size:13px}
    .ado-client-door-media-item small{display:block;color:#94a3b8;font-size:11px;margin-top:3px}
    .ado-client-pm-note-panel.is-open{outline:1px solid rgba(59,130,246,.35)}
    .ado-client-door-actions .ado-row-sub{color:#94a3b8}
    .ado-client-door-flash.ok{background:rgba(34,197,94,.16);color:#86efac}
    .ado-client-door-flash.err{background:rgba(239,68,68,.15);color:#fecaca}
    .ado-client-door-empty{padding:10px;border:1px dashed rgba(148,163,184,.25);border-radius:8px;color:#94a3b8}
    @media (max-width:900px){.ado-client-door-status-grid,.ado-client-door-status-grid-3,.ado-client-door-meta-grid{grid-template-columns:1fr}.ado-client-door-head-actions{justify-content:flex-start}}
    @media (max-width:1100px){.ado-app{flex-direction:column}.ado-side{position:relative;width:100%;min-height:auto}.ado-project-grid{grid-template-columns:1fr}.ado-project-summary-grid{grid-template-columns:1fr}.ado-schedule-slot-list{grid-template-columns:1fr}}@media (max-width:840px){.ado-client-door-drawer{width:100vw}body.admin-bar .ado-client-door-backdrop{top:46px}body.admin-bar .ado-client-door-drawer{top:46px}}
    </style>
    <div class="ado-app">
      <aside class="ado-side">
        <div class="ado-side-logo">Auto<span>Door</span></div>
        <nav class="ado-nav">
          <div class="ado-nav-label">Overview</div>
          <a class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('dashboard'); ?>"><span>Dashboard</span></a>
          <a class="<?php echo $view === 'quotes' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('quotes'); ?>"><span>My Quotes</span><?php if ($counts['quotes_count'] > 0) { ?><span class="ado-nav-badge"><?php echo esc_html((string) $counts['quotes_count']); ?></span><?php } ?></a>
          <a class="<?php echo $view === 'service-calls' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('service-calls'); ?>"><span>My Service Calls</span></a>
          <div class="ado-nav-label">Projects</div>
          <?php if (!$project_nav_rows) { ?>
          <a class="<?php echo $view === 'projects' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('projects'); ?>"><span>My Projects</span></a>
          <?php } else { foreach ($project_nav_rows as $project_nav_row) { $project_id = (int) ($project_nav_row['order_id'] ?? 0); if ($project_id <= 0) { continue; } $project_active = $view === 'projects' && $selected_project_id > 0 && $selected_project_id === $project_id; $project_meta = [trim((string) ($project_nav_row['stage'] ?? 'In Progress'))]; $project_door_count = (int) ($project_nav_row['door_count'] ?? 0); if ($project_door_count > 0) { $project_meta[] = $project_door_count . ' ' . ($project_door_count === 1 ? 'door' : 'doors'); } $project_next_visit = trim((string) ($project_nav_row['next_visit'] ?? '')); if ($project_next_visit !== '') { $project_meta[] = 'Next visit ' . $project_next_visit; } ?><a class="ado-nav-project <?php echo $project_active ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('projects', ['project_id' => $project_id]); ?>"><span class="ado-nav-project-copy"><span class="ado-nav-project-name"><?php echo esc_html((string) ($project_nav_row['name'] ?? ('Project #' . $project_id))); ?></span><span class="ado-nav-project-meta"><?php echo esc_html(implode(' | ', array_filter($project_meta))); ?></span></span><?php if (!empty($project_nav_row['has_invoice_alert'])) { ?><span class="ado-nav-badge" title="<?php echo esc_attr((string) ($project_nav_row['invoice_alert_label'] ?? 'Invoice alert')); ?>">!</span><?php } ?></a><?php } } ?>
          <div class="ado-nav-label">Schedule</div>
          <a class="<?php echo $view === 'schedule' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('schedule'); ?>"><span>Schedule</span></a>
          <div class="ado-nav-label">Invoices</div>
          <a class="<?php echo $view === 'invoices' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('invoices'); ?>"><span>Invoices</span><?php if ($counts['overdue_count'] > 0) { ?><span class="ado-nav-badge"><?php echo esc_html((string) $counts['overdue_count']); ?></span><?php } ?></a>
        </nav>
      </aside>
      <section class="ado-main">
        <header class="ado-top">
          <h1><?php echo esc_html($page_title); ?></h1>
          <div class="ado-top-right">
            <a class="ado-btn" href="mailto:info@autodoorexperts.ca">Support</a>
            <a id="ado-client-new-quote-trigger" class="ado-btn primary" href="<?php echo ado_cd_view_url('new-quote'); ?>">New Quote</a>
          </div>
        </header>
        <div class="ado-content"><?php echo ado_cd_render_view_content($view, $uid, $orders, $selected_project_id); ?></div>
      </section>
    </div>
    <script>
    (function(){
      var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      var nonce = <?php echo wp_json_encode($client_nonce); ?>;
      var appRoot = document.querySelector('.ado-app');
      if (!appRoot) {
        return;
      }

      var tabs = appRoot.querySelectorAll('.ado-project-tab[data-tab-target]');
      var panels = appRoot.querySelectorAll('.ado-project-tab-panel[data-tab-panel]');
      function activateTab(tabName){
        if (!tabName) {
          return;
        }
        tabs.forEach(function(tab){
          var isActive = String(tab.getAttribute('data-tab-target') || '') === String(tabName);
          tab.classList.toggle('active', isActive);
        });
        panels.forEach(function(panel){
          var isActive = String(panel.getAttribute('data-tab-panel') || '') === String(tabName);
          panel.hidden = !isActive;
          panel.classList.toggle('is-active', isActive);
        });
      }
      tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
          var target = String(tab.getAttribute('data-tab-target') || 'overview');
          activateTab(target);
          if (target !== 'doors') {
            closeDoorDrawer();
          }
        });
      });

      var doorWorkspace = appRoot.querySelector('.ado-client-door-workspace');
      if (!doorWorkspace) {
        return;
      }
      var projectId = parseInt(doorWorkspace.getAttribute('data-project-id') || '0', 10) || 0;
      var doorDrawer = doorWorkspace.querySelector('.ado-client-door-drawer');
      var doorBackdrop = doorWorkspace.querySelector('.ado-client-door-backdrop');
      var notesToggle = doorWorkspace.querySelector('.ado-client-door-notes-toggle');

      function setDoorUrl(doorId){
        var url = new URL(window.location.href);
        url.searchParams.set('view', 'projects');
        if (projectId > 0) {
          url.searchParams.set('project_id', String(projectId));
        }
        if (doorId) {
          url.searchParams.set('door_id', String(doorId));
        } else {
          url.searchParams.delete('door_id');
        }
        window.history.replaceState({}, '', url.toString());
      }
      function findDoorTrigger(doorId){
        if (!doorId) {
          return null;
        }
        var triggers = doorWorkspace.querySelectorAll('.ado-project-door-trigger');
        for (var i = 0; i < triggers.length; i++) {
          if (String(triggers[i].getAttribute('data-door-id') || '') === String(doorId)) {
            return triggers[i];
          }
        }
        return null;
      }
      function showDoorDrawer(){
        if (!doorDrawer || !doorBackdrop) {
          return;
        }
        doorDrawer.hidden = false;
        doorBackdrop.hidden = false;
        window.requestAnimationFrame(function(){
          doorDrawer.classList.add('is-open');
          doorBackdrop.classList.add('is-open');
          document.body.classList.add('ado-client-door-open');
        });
      }
      function hideDoorDrawer(){
        if (!doorDrawer || !doorBackdrop) {
          return;
        }
        doorDrawer.classList.remove('is-open');
        doorBackdrop.classList.remove('is-open');
        document.body.classList.remove('ado-client-door-open');
        window.setTimeout(function(){
          if (!doorDrawer.classList.contains('is-open')) {
            doorDrawer.hidden = true;
          }
          if (!doorBackdrop.classList.contains('is-open')) {
            doorBackdrop.hidden = true;
          }
        }, 180);
      }
      function setPmNotePanelOpen(open){
        if (!doorWorkspace || !notesToggle) {
          return;
        }
        var panel = doorWorkspace.querySelector('.ado-client-pm-note-panel');
        if (!panel) {
          notesToggle.hidden = true;
          notesToggle.setAttribute('aria-expanded', 'false');
          return;
        }
        notesToggle.hidden = false;
        panel.hidden = !open;
        panel.classList.toggle('is-open', !!open);
        notesToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        notesToggle.textContent = open ? 'Hide PM Note' : 'Project Manager Note';
      }
      function setDoorUploadPanelOpen(open){
        if (!doorWorkspace) {
          return;
        }
        var uploadPanel = doorWorkspace.querySelector('.ado-client-door-upload-panel');
        var uploadToggle = doorWorkspace.querySelector('.ado-client-door-upload-toggle');
        if (!uploadPanel || !uploadToggle) {
          return;
        }
        uploadPanel.hidden = !open;
        uploadToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        uploadToggle.textContent = open ? 'Hide Files / Photos' : 'Add Files / Photos';
      }
      function loadDoorDrawer(trigger){
        if (!doorWorkspace || !doorDrawer || !trigger) {
          return false;
        }
        var templateId = trigger.getAttribute('data-door-template') || '';
        var template = templateId ? document.getElementById(templateId) : null;
        var drawerBody = doorWorkspace.querySelector('.ado-client-door-drawer-body');
        var drawerTitle = doorWorkspace.querySelector('.ado-client-door-drawer-title');
        var drawerSub = doorWorkspace.querySelector('.ado-client-door-drawer-sub');
        if (!drawerBody || !drawerTitle || !drawerSub) {
          return false;
        }
        if (template && template.content) {
          drawerBody.innerHTML = '';
          drawerBody.appendChild(template.content.cloneNode(true));
        } else {
          drawerBody.innerHTML = '<div class="ado-empty">Door details are unavailable.</div>';
        }
        drawerTitle.textContent = trigger.getAttribute('data-door-label') || 'Door details';
        drawerSub.textContent = trigger.getAttribute('data-door-meta') || 'Select a door for progress, notes history, and media visibility.';
        setPmNotePanelOpen(false);
        setDoorUploadPanelOpen(false);
        return true;
      }
      function setDoorFormMessage(form, message, isOk){
        if (!form) {
          return;
        }
        var flash = form.querySelector('.ado-client-door-flash');
        if (!flash) {
          return;
        }
        flash.className = 'ado-client-door-flash ' + (isOk ? 'ok' : 'err');
        flash.textContent = String(message || '');
      }
      function openDoorDrawer(trigger){
        if (!trigger || !loadDoorDrawer(trigger)) {
          return;
        }
        activateTab('doors');
        doorWorkspace.querySelectorAll('.ado-project-door-trigger.active').forEach(function(node){ node.classList.remove('active'); });
        trigger.classList.add('active');
        setDoorUrl(trigger.getAttribute('data-door-id') || '');
        setPmNotePanelOpen(false);
        showDoorDrawer();
      }
      function closeDoorDrawer(){
        if (!doorWorkspace) {
          return;
        }
        doorWorkspace.querySelectorAll('.ado-project-door-trigger.active').forEach(function(node){ node.classList.remove('active'); });
        setDoorUrl('');
        setPmNotePanelOpen(false);
        setDoorUploadPanelOpen(false);
        hideDoorDrawer();
      }
      async function submitDoorForm(form){
        if (!form) {
          return;
        }
        var button = form.querySelector('button[type="submit"]');
        if (button) {
          button.disabled = true;
        }
        var fd = new FormData(form);
        fd.append('action', 'ado_save_client_door_feedback');
        fd.append('nonce', nonce);
        try {
          var res = await fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' });
          var json = await res.json();
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) ? json.data.message : 'Failed to save door update.');
          }
          setDoorFormMessage(form, (json.data && json.data.message) ? json.data.message : 'Door update saved.', true);
          window.setTimeout(function(){ window.location.reload(); }, 420);
        } catch (err) {
          setDoorFormMessage(form, (err && err.message) ? err.message : 'Failed to save door update.', false);
        } finally {
          if (button) {
            button.disabled = false;
          }
        }
      }

      var initialDoorId = doorWorkspace.getAttribute('data-selected-door') || '';
      if (initialDoorId) {
        var initialTrigger = findDoorTrigger(initialDoorId);
        if (initialTrigger) {
          initialTrigger.classList.add('active');
          activateTab('doors');
        }
      }
      setPmNotePanelOpen(false);
      setDoorUploadPanelOpen(false);
      doorWorkspace.addEventListener('click', function(ev){
        var doorTrigger = ev.target.closest('.ado-project-door-trigger');
        if (doorTrigger) {
          ev.preventDefault();
          openDoorDrawer(doorTrigger);
          return;
        }
        var uploadToggleBtn = ev.target.closest('.ado-client-door-upload-toggle');
        if (uploadToggleBtn) {
          ev.preventDefault();
          var uploadPanel = doorWorkspace.querySelector('.ado-client-door-upload-panel');
          if (uploadPanel) {
            setDoorUploadPanelOpen(!!uploadPanel.hidden);
          }
          return;
        }
        var noteToggleBtn = ev.target.closest('.ado-client-door-notes-toggle');
        if (noteToggleBtn) {
          ev.preventDefault();
          var notePanel = doorWorkspace.querySelector('.ado-client-pm-note-panel');
          if (notePanel) {
            setPmNotePanelOpen(!!notePanel.hidden);
          }
          return;
        }
        var closeBtn = ev.target.closest('.ado-client-door-close');
        if (closeBtn) {
          ev.preventDefault();
          closeDoorDrawer();
        }
      });
      doorWorkspace.addEventListener('submit', function(ev){
        var form = ev.target.closest('.ado-client-door-form');
        if (!form) {
          return;
        }
        ev.preventDefault();
        submitDoorForm(form);
      });
      if (doorBackdrop) {
        doorBackdrop.addEventListener('click', function(){
          closeDoorDrawer();
        });
      }
      document.addEventListener('keydown', function(ev){
        if (ev.key !== 'Escape') {
          return;
        }
        if (doorDrawer && doorDrawer.classList.contains('is-open')) {
          closeDoorDrawer();
        }
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});

add_action('wp_ajax_ado_submit_client_schedule_request', static function (): void {
    wp_send_json_error([
        'message' => 'Direct booking activation is not available in this slice yet.',
        'code' => 'booking_not_active',
    ], 409);
});

add_action('wp_ajax_ado_save_client_door_feedback', static function (): void {
    if (!is_user_logged_in() || !ado_is_client()) {
        wp_send_json_error(['message' => 'Client access only.'], 403);
    }
    check_ajax_referer('ado_client_portal_nonce', 'nonce');

    $project_id = (int) ($_POST['project_id'] ?? 0);
    $door_id = sanitize_text_field((string) ($_POST['door_id'] ?? ''));
    if ($project_id <= 0 || $door_id === '') {
        wp_send_json_error(['message' => 'Project and door are required.'], 400);
    }

    $user_id = (int) get_current_user_id();
    $order = ado_cd_find_project_order(ado_cd_client_orders($user_id), $project_id);
    if (!($order instanceof WC_Order)) {
        wp_send_json_error(['message' => 'Project not found.'], 404);
    }

    $door_exists = false;
    foreach (ado_cd_order_doors($order) as $door) {
        if ((string) ($door['door_id'] ?? '') === $door_id) {
            $door_exists = true;
            break;
        }
    }
    if (!$door_exists) {
        wp_send_json_error(['message' => 'Door not found on this project.'], 404);
    }

    $feedback_map = ado_cd_client_door_feedback_map($order);
    $state = is_array($feedback_map[$door_id] ?? null) ? $feedback_map[$door_id] : ado_cd_client_door_feedback_defaults();
    if (array_key_exists('readiness_confirmed', $_POST)) {
        $state['readiness_confirmed'] = !empty($_POST['readiness_confirmed']);
    }
    if (array_key_exists('readiness_note', $_POST)) {
        $state['readiness_note'] = sanitize_textarea_field((string) ($_POST['readiness_note'] ?? ''));
    }
    $incoming_note = array_key_exists('note', $_POST) ? sanitize_textarea_field((string) ($_POST['note'] ?? '')) : null;
    if ($incoming_note !== null) {
        $previous_note = trim((string) ($state['note'] ?? ''));
        $state['note'] = $incoming_note;
        $note_history = [];
        foreach ((array) ($state['note_history'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $note_text = trim((string) ($entry['note'] ?? ''));
            if ($note_text === '') {
                continue;
            }
            $note_history[] = [
                'note' => $note_text,
                'created_at' => trim((string) ($entry['created_at'] ?? '')),
                'created_by' => (int) ($entry['created_by'] ?? 0),
                'source' => trim((string) ($entry['source'] ?? 'project_manager')),
            ];
        }
        if ($incoming_note !== '' && $incoming_note !== $previous_note) {
            $note_history[] = [
                'note' => $incoming_note,
                'created_at' => wp_date('Y-m-d H:i'),
                'created_by' => $user_id,
                'source' => 'project_manager',
            ];
        }
        $state['note_history'] = $note_history;
    }
    $state['updated_at'] = wp_date('Y-m-d H:i');
    $state['updated_by'] = $user_id;

    $documents = [];
    foreach ((array) ($state['documents'] ?? []) as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $url = trim((string) ($doc['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $documents[] = [
            'url' => $url,
            'name' => trim((string) ($doc['name'] ?? 'Document')),
            'uploaded_at' => trim((string) ($doc['uploaded_at'] ?? '')),
            'uploaded_by' => (int) ($doc['uploaded_by'] ?? 0),
        ];
    }

    if (!empty($_FILES['door_document']['tmp_name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($_FILES['door_document'], ['test_form' => false]);
        if (!empty($upload['error'])) {
            wp_send_json_error(['message' => (string) $upload['error']], 400);
        }
        if (empty($upload['url'])) {
            wp_send_json_error(['message' => 'Failed to upload file.'], 400);
        }
        $documents[] = [
            'url' => esc_url_raw((string) $upload['url']),
            'name' => sanitize_text_field((string) ($_FILES['door_document']['name'] ?? 'Document')),
            'uploaded_at' => wp_date('Y-m-d H:i'),
            'uploaded_by' => $user_id,
        ];
    }

    $state['documents'] = $documents;
    $feedback_map[$door_id] = $state;
    $order->update_meta_data('_ado_client_door_feedback', $feedback_map);
    $order->save();

    wp_send_json_success([
        'message' => 'Door update saved.',
        'document_count' => count($documents),
    ]);
});
