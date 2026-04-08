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

function ado_cd_order_project_address(WC_Order $order): string {
    if (function_exists('ado_order_project_address')) {
        $project_address = trim((string) ado_order_project_address($order));
        if ($project_address !== '') {
            return $project_address;
        }
    }

    $build_address = static function (array $parts): string {
        $filtered = array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $parts), static function (string $value): bool {
            return $value !== '';
        }));
        return trim(implode(', ', $filtered));
    };

    $shipping_address = $build_address([
        $order->get_shipping_address_1(),
        $order->get_shipping_address_2(),
        $order->get_shipping_city(),
        $order->get_shipping_state(),
        $order->get_shipping_postcode(),
        $order->get_shipping_country(),
    ]);
    if ($shipping_address !== '') {
        return $shipping_address;
    }

    $billing_address = $build_address([
        $order->get_billing_address_1(),
        $order->get_billing_address_2(),
        $order->get_billing_city(),
        $order->get_billing_state(),
        $order->get_billing_postcode(),
        $order->get_billing_country(),
    ]);
    return $billing_address;
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

function ado_cd_forced_technician_id(): int {
    $forced_id = 2;
    $constant_keys = [
        'ADO_SCHEDULING_TECHNICIAN_ID',
        'ADO_DEFAULT_TECHNICIAN_ID',
    ];
    foreach ($constant_keys as $constant_key) {
        if (defined($constant_key)) {
            $candidate = (int) constant($constant_key);
            if ($candidate > 0) {
                $forced_id = $candidate;
                break;
            }
        }
    }
    $env_keys = [
        'ADO_SCHEDULING_TECHNICIAN_ID',
        'ADO_DEFAULT_TECHNICIAN_ID',
    ];
    foreach ($env_keys as $env_key) {
        $value = getenv($env_key);
        if ($value === false) {
            continue;
        }
        $candidate = (int) $value;
        if ($candidate > 0) {
            $forced_id = $candidate;
            break;
        }
    }
    return (int) apply_filters('ado_cd_forced_technician_id', $forced_id);
}

function ado_cd_assigned_technician_ids(WC_Order $order): array {
    // Launch override: route all projects to one technician until dispatcher rules are introduced.
    $forced_id = ado_cd_forced_technician_id();
    $assigned = array_map('intval', (array) apply_filters('ado_cd_assigned_technician_ids', [$forced_id], $order));
    $assigned = array_values(array_unique(array_filter($assigned, static function (int $technician_id): bool {
        return $technician_id > 0;
    })));
    return array_values(array_map('intval', $assigned));
}

function ado_cd_schedule_slot_options(): array {
    return [
        'morning' => 'Morning (9:00 AM - 11:00 AM)',
        'midday' => 'Midday (11:00 AM - 1:00 PM)',
        'afternoon' => 'Afternoon (1:00 PM - 4:00 PM)',
    ];
}

function ado_cd_schedule_slot_windows(): array {
    return [
        'morning' => ['label' => 'Morning (9:00 AM - 11:00 AM)', 'start' => '09:00', 'end' => '11:00'],
        'midday' => ['label' => 'Midday (11:00 AM - 1:00 PM)', 'start' => '11:00', 'end' => '13:00'],
        'afternoon' => ['label' => 'Afternoon (1:00 PM - 4:00 PM)', 'start' => '13:00', 'end' => '16:00'],
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
    if ($minutes < 780) {
        return 'midday';
    }
    return 'afternoon';
}

function ado_cd_schedule_timezone(): DateTimeZone {
    $timezone_name = '';
    $constant_keys = [
        'ADO_SCHEDULING_TIMEZONE',
        'ADO_GOOGLE_SCHEDULING_TIMEZONE',
    ];
    foreach ($constant_keys as $constant_key) {
        if (defined($constant_key)) {
            $value = trim((string) constant($constant_key));
            if ($value !== '') {
                $timezone_name = $value;
                break;
            }
        }
    }
    if ($timezone_name === '') {
        $env_keys = [
            'ADO_SCHEDULING_TIMEZONE',
            'ADO_GOOGLE_SCHEDULING_TIMEZONE',
            'TZ',
        ];
        foreach ($env_keys as $env_key) {
            $value = getenv($env_key);
            if ($value === false) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $timezone_name = $value;
                break;
            }
        }
    }
    if ($timezone_name === '') {
        $timezone_name = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
    }
    if ($timezone_name === '') {
        $timezone_name = (string) get_option('timezone_string');
    }
    if ($timezone_name === '') {
        $timezone_name = 'America/Toronto';
    }
    $timezone_name = trim((string) apply_filters('ado_cd_schedule_timezone_name', $timezone_name));
    try {
        return new DateTimeZone($timezone_name);
    } catch (Exception $exception) {
        return new DateTimeZone('America/Toronto');
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

function ado_cd_schedule_availability_window_days(): int {
    $window_days = 60;
    $constant_keys = [
        'ADO_SCHEDULING_WINDOW_DAYS',
        'ADO_GOOGLE_AVAILABILITY_WINDOW_DAYS',
    ];
    foreach ($constant_keys as $constant_key) {
        if (!defined($constant_key)) {
            continue;
        }
        $candidate = (int) constant($constant_key);
        if ($candidate > 0) {
            $window_days = $candidate;
            break;
        }
    }
    $env_keys = [
        'ADO_SCHEDULING_WINDOW_DAYS',
        'ADO_GOOGLE_AVAILABILITY_WINDOW_DAYS',
    ];
    foreach ($env_keys as $env_key) {
        $value = getenv($env_key);
        if ($value === false) {
            continue;
        }
        $candidate = (int) $value;
        if ($candidate > 0) {
            $window_days = $candidate;
            break;
        }
    }
    return max(30, min(60, (int) apply_filters('ado_cd_schedule_availability_window_days', $window_days)));
}

function ado_cd_google_default_calendar_id_for_technician(int $technician_id): string {
    if ($technician_id !== ado_cd_forced_technician_id()) {
        return '';
    }
    $default_calendar_id = 'info.ttincorporated@gmail.com';
    $constant_keys = [
        'ADO_SCHEDULING_CALENDAR_ID',
        'ADO_GOOGLE_SCHEDULING_CALENDAR_ID',
    ];
    foreach ($constant_keys as $constant_key) {
        if (defined($constant_key)) {
            $value = trim((string) constant($constant_key));
            if ($value !== '') {
                $default_calendar_id = $value;
                break;
            }
        }
    }
    $env_keys = [
        'ADO_SCHEDULING_CALENDAR_ID',
        'ADO_GOOGLE_SCHEDULING_CALENDAR_ID',
    ];
    foreach ($env_keys as $env_key) {
        $value = getenv($env_key);
        if ($value === false) {
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '') {
            $default_calendar_id = $value;
            break;
        }
    }
    return trim((string) apply_filters('ado_cd_default_google_calendar_id_for_technician', $default_calendar_id, $technician_id));
}

function ado_cd_google_service_account_raw_json(): string {
    $constant_keys = [
        'ADO_GOOGLE_SERVICE_ACCOUNT_JSON',
        'GOOGLE_SERVICE_ACCOUNT_JSON',
    ];
    foreach ($constant_keys as $constant_key) {
        if (!defined($constant_key)) {
            continue;
        }
        $value = trim((string) constant($constant_key));
        if ($value !== '') {
            return $value;
        }
    }
    $env_json_keys = [
        'ADO_GOOGLE_SERVICE_ACCOUNT_JSON',
        'GOOGLE_SERVICE_ACCOUNT_JSON',
    ];
    foreach ($env_json_keys as $env_key) {
        $value = getenv($env_key);
        if ($value === false) {
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }
    $constant_path_keys = [
        'ADO_GOOGLE_SERVICE_ACCOUNT_FILE',
        'GOOGLE_APPLICATION_CREDENTIALS',
    ];
    foreach ($constant_path_keys as $constant_key) {
        if (!defined($constant_key)) {
            continue;
        }
        $path = trim((string) constant($constant_key));
        if ($path !== '' && is_readable($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && trim($raw) !== '') {
                return $raw;
            }
        }
    }
    $env_path_keys = [
        'ADO_GOOGLE_SERVICE_ACCOUNT_FILE',
        'GOOGLE_APPLICATION_CREDENTIALS',
    ];
    foreach ($env_path_keys as $env_key) {
        $path = getenv($env_key);
        if ($path === false) {
            continue;
        }
        $path = trim((string) $path);
        if ($path === '' || !is_readable($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if (is_string($raw) && trim($raw) !== '') {
            return $raw;
        }
    }
    return '';
}

function ado_cd_google_service_account_credentials(): array {
    $raw = ado_cd_google_service_account_raw_json();
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $client_email = trim((string) ($decoded['client_email'] ?? ''));
    $private_key = trim((string) ($decoded['private_key'] ?? ''));
    $token_uri = trim((string) ($decoded['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
    if ($client_email === '' || $private_key === '' || $token_uri === '') {
        return [];
    }
    return [
        'client_email' => $client_email,
        'private_key' => $private_key,
        'token_uri' => $token_uri,
        'project_id' => trim((string) ($decoded['project_id'] ?? '')),
    ];
}

function ado_cd_google_expected_service_account_email(): string {
    $email = 'scheduler@booking-calendar-491901.iam.gserviceaccount.com';
    $constant_keys = [
        'ADO_GOOGLE_SERVICE_ACCOUNT_EMAIL',
        'GOOGLE_SERVICE_ACCOUNT_EMAIL',
    ];
    foreach ($constant_keys as $constant_key) {
        if (!defined($constant_key)) {
            continue;
        }
        $value = trim((string) constant($constant_key));
        if ($value !== '') {
            $email = $value;
            break;
        }
    }
    $env_keys = [
        'ADO_GOOGLE_SERVICE_ACCOUNT_EMAIL',
        'GOOGLE_SERVICE_ACCOUNT_EMAIL',
    ];
    foreach ($env_keys as $env_key) {
        $value = getenv($env_key);
        if ($value === false) {
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '') {
            $email = $value;
            break;
        }
    }
    return strtolower($email);
}

function ado_cd_google_base64url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ado_cd_google_service_account_access_token(array $credentials, string $scope = 'https://www.googleapis.com/auth/calendar'): array {
    $client_email = trim((string) ($credentials['client_email'] ?? ''));
    $private_key = trim((string) ($credentials['private_key'] ?? ''));
    $token_uri = trim((string) ($credentials['token_uri'] ?? ''));
    $scope = trim($scope);
    if ($scope === '') {
        $scope = 'https://www.googleapis.com/auth/calendar';
    }
    if ($client_email === '' || $private_key === '' || $token_uri === '') {
        return ['access_token' => '', 'error' => 'Google service account credentials are missing required fields.'];
    }

    $cache_key = 'ado_cd_google_sa_token_' . md5($client_email . '|' . $token_uri . '|' . $scope);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        $cached_token = trim((string) ($cached['access_token'] ?? ''));
        $cached_expires_at = (int) ($cached['expires_at'] ?? 0);
        if ($cached_token !== '' && $cached_expires_at > (time() + 60)) {
            return ['access_token' => $cached_token, 'error' => ''];
        }
    }

    if (!function_exists('openssl_sign')) {
        return ['access_token' => '', 'error' => 'OpenSSL is not available for Google service account authentication.'];
    }

    $now = time();
    $jwt_header = wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $jwt_payload = wp_json_encode([
        'iss' => $client_email,
        'scope' => $scope,
        'aud' => $token_uri,
        'iat' => $now,
        'exp' => $now + 3600,
    ]);
    if (!is_string($jwt_header) || !is_string($jwt_payload) || $jwt_header === '' || $jwt_payload === '') {
        return ['access_token' => '', 'error' => 'Google service account JWT payload encoding failed.'];
    }

    $signing_input = ado_cd_google_base64url($jwt_header) . '.' . ado_cd_google_base64url($jwt_payload);
    $signature = '';
    $signed = @openssl_sign($signing_input, $signature, $private_key, 'sha256WithRSAEncryption');
    if (!$signed || !is_string($signature) || $signature === '') {
        return ['access_token' => '', 'error' => 'Google service account JWT signing failed.'];
    }

    $assertion = $signing_input . '.' . ado_cd_google_base64url($signature);
    $response = wp_remote_post($token_uri, [
        'timeout' => 20,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ],
    ]);
    if (is_wp_error($response)) {
        return ['access_token' => '', 'error' => 'Google service account token fetch failed: ' . $response->get_error_message()];
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    $response_body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($response_body, true);
    if ($response_code < 200 || $response_code >= 300) {
        $error_message = trim((string) ($decoded['error_description'] ?? ($decoded['error']['message'] ?? ($decoded['error'] ?? 'Token endpoint returned a non-success response.'))));
        return ['access_token' => '', 'error' => 'Google service account token fetch failed' . ($response_code > 0 ? ' (' . $response_code . ')' : '') . ': ' . $error_message];
    }

    $access_token = trim((string) ($decoded['access_token'] ?? ''));
    if ($access_token === '') {
        return ['access_token' => '', 'error' => 'Google service account token response did not include an access token.'];
    }

    $expires_in = max(300, (int) ($decoded['expires_in'] ?? 3600));
    set_transient($cache_key, [
        'access_token' => $access_token,
        'expires_at' => (time() + $expires_in),
    ], max(60, $expires_in - 60));

    return ['access_token' => $access_token, 'error' => ''];
}

function ado_cd_google_freebusy_access_token(): array {
    $credentials = ado_cd_google_service_account_credentials();
    if (!$credentials) {
        return [
            'status' => 'missing_credentials',
            'access_token' => '',
            'message' => 'Live Google availability is currently unavailable because service account credentials are not configured (use environment JSON or a secure server file path).',
        ];
    }
    $expected_email = ado_cd_google_expected_service_account_email();
    $credentials_email = strtolower(trim((string) ($credentials['client_email'] ?? '')));
    if ($expected_email !== '' && $credentials_email !== '' && $credentials_email !== $expected_email) {
        return [
            'status' => 'fetch_error',
            'access_token' => '',
            'message' => 'Live Google availability credentials loaded a different service account email than expected.',
        ];
    }
    $token_result = ado_cd_google_service_account_access_token($credentials);
    $access_token = trim((string) ($token_result['access_token'] ?? ''));
    if ($access_token === '') {
        return [
            'status' => 'fetch_error',
            'access_token' => '',
            'message' => 'Live Google availability could not authenticate with the Google service account: ' . trim((string) ($token_result['error'] ?? 'unknown error')),
        ];
    }
    return [
        'status' => 'ok',
        'access_token' => $access_token,
        'message' => '',
    ];
}

function ado_cd_google_booking_write_context(WC_Order $order): array {
    $assigned_technician_ids = ado_cd_assigned_technician_ids($order);
    if (!$assigned_technician_ids) {
        return [
            'ok' => false,
            'message' => 'No assigned technician is available for Google calendar booking.',
            'calendar_id' => '',
            'access_token' => '',
        ];
    }
    $mapping = ado_cd_google_calendar_mapping($assigned_technician_ids);
    $calendar_ids = array_values(array_filter(array_map('strval', (array) ($mapping['calendar_ids'] ?? []))));
    if (!$calendar_ids || !empty($mapping['missing_technician_ids'])) {
        return [
            'ok' => false,
            'message' => 'Assigned technician Google calendar mapping is missing.',
            'calendar_id' => '',
            'access_token' => '',
        ];
    }
    $auth = ado_cd_google_freebusy_access_token();
    if (($auth['status'] ?? 'fetch_error') !== 'ok') {
        return [
            'ok' => false,
            'message' => trim((string) ($auth['message'] ?? 'Google authentication failed.')),
            'calendar_id' => '',
            'access_token' => '',
        ];
    }
    return [
        'ok' => true,
        'message' => '',
        'calendar_id' => trim((string) $calendar_ids[0]),
        'access_token' => trim((string) ($auth['access_token'] ?? '')),
    ];
}

function ado_cd_google_booking_event_payload(WC_Order $order, string $schedule_date, array $door_labels, string $note = ''): array {
    $timezone_name = ado_cd_schedule_timezone_name();
    $start_dt = $schedule_date . 'T09:00:00';
    $end_dt = $schedule_date . 'T16:00:00';
    $door_labels = array_values(array_filter(array_map('strval', $door_labels)));
    $door_line = $door_labels ? implode(', ', $door_labels) : 'Door booking';
    $summary = trim((string) ado_cd_order_name($order));
    if ($summary === '') {
        $summary = 'Project #' . (string) $order->get_id();
    }
    if (strlen($summary) > 255) {
        $summary = substr($summary, 0, 252) . '...';
    }
    $description = $door_line;
    $project_address = trim((string) ado_cd_order_project_address($order));
    $payload = [
        'summary' => $summary,
        'description' => $description,
        'start' => [
            'dateTime' => $start_dt,
            'timeZone' => $timezone_name,
        ],
        'end' => [
            'dateTime' => $end_dt,
            'timeZone' => $timezone_name,
        ],
    ];
    if ($project_address !== '') {
        $payload['location'] = $project_address;
    }
    return $payload;
}

function ado_cd_google_create_booking_event(WC_Order $order, string $schedule_date, array $door_labels, string $note = ''): array {
    $context = ado_cd_google_booking_write_context($order);
    if (empty($context['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($context['message'] ?? 'Google booking context is unavailable.'),
            'calendar_id' => '',
            'event_id' => '',
            'event_link' => '',
        ];
    }

    $calendar_id = trim((string) ($context['calendar_id'] ?? ''));
    $access_token = trim((string) ($context['access_token'] ?? ''));
    if ($calendar_id === '' || $access_token === '') {
        return [
            'ok' => false,
            'message' => 'Google booking context is missing calendar or access token.',
            'calendar_id' => '',
            'event_id' => '',
            'event_link' => '',
        ];
    }

    $payload = ado_cd_google_booking_event_payload($order, $schedule_date, $door_labels, $note);
    $response = wp_remote_post(
        'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events?sendUpdates=none',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 20,
            'body' => wp_json_encode($payload),
        ]
    );
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'message' => 'Google calendar booking creation failed: ' . $response->get_error_message(),
            'calendar_id' => $calendar_id,
            'event_id' => '',
            'event_link' => '',
        ];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded)) {
        $error_message = trim((string) ($decoded['error']['message'] ?? 'Google calendar returned a non-success response.'));
        return [
            'ok' => false,
            'message' => 'Google calendar booking creation failed' . ($code > 0 ? ' (' . $code . ')' : '') . ': ' . $error_message,
            'calendar_id' => $calendar_id,
            'event_id' => '',
            'event_link' => '',
        ];
    }
    return [
        'ok' => true,
        'message' => '',
        'calendar_id' => $calendar_id,
        'event_id' => trim((string) ($decoded['id'] ?? '')),
        'event_link' => trim((string) ($decoded['htmlLink'] ?? '')),
    ];
}

function ado_cd_google_update_booking_event(WC_Order $order, string $calendar_id, string $event_id, string $schedule_date, array $door_labels, string $note = ''): array {
    $calendar_id = trim($calendar_id);
    $event_id = trim($event_id);
    if ($calendar_id === '' || $event_id === '') {
        return [
            'ok' => false,
            'message' => 'Google calendar booking reference is missing for update.',
            'calendar_id' => $calendar_id,
            'event_id' => $event_id,
            'event_link' => '',
        ];
    }
    $context = ado_cd_google_booking_write_context($order);
    if (empty($context['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($context['message'] ?? 'Google booking context is unavailable.'),
            'calendar_id' => $calendar_id,
            'event_id' => $event_id,
            'event_link' => '',
        ];
    }
    $access_token = trim((string) ($context['access_token'] ?? ''));
    if ($access_token === '') {
        return [
            'ok' => false,
            'message' => 'Google booking context is missing access token.',
            'calendar_id' => $calendar_id,
            'event_id' => $event_id,
            'event_link' => '',
        ];
    }
    $payload = ado_cd_google_booking_event_payload($order, $schedule_date, $door_labels, $note);
    $response = wp_remote_request(
        'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events/' . rawurlencode($event_id) . '?sendUpdates=none',
        [
            'method' => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 20,
            'body' => wp_json_encode($payload),
        ]
    );
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'message' => 'Google calendar booking update failed: ' . $response->get_error_message(),
            'calendar_id' => $calendar_id,
            'event_id' => $event_id,
            'event_link' => '',
        ];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded)) {
        $error_message = trim((string) ($decoded['error']['message'] ?? 'Google calendar returned a non-success response.'));
        return [
            'ok' => false,
            'message' => 'Google calendar booking update failed' . ($code > 0 ? ' (' . $code . ')' : '') . ': ' . $error_message,
            'calendar_id' => $calendar_id,
            'event_id' => $event_id,
            'event_link' => '',
        ];
    }
    return [
        'ok' => true,
        'message' => '',
        'calendar_id' => $calendar_id,
        'event_id' => trim((string) ($decoded['id'] ?? $event_id)),
        'event_link' => trim((string) ($decoded['htmlLink'] ?? '')),
    ];
}

function ado_cd_google_cancel_booking_event(WC_Order $order, string $calendar_id, string $event_id): array {
    $calendar_id = trim($calendar_id);
    $event_id = trim($event_id);
    if ($calendar_id === '' || $event_id === '') {
        return [
            'ok' => false,
            'message' => 'Google calendar booking reference is missing.',
        ];
    }
    $context = ado_cd_google_booking_write_context($order);
    if (empty($context['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($context['message'] ?? 'Google booking context is unavailable.'),
        ];
    }
    $access_token = trim((string) ($context['access_token'] ?? ''));
    if ($access_token === '') {
        return [
            'ok' => false,
            'message' => 'Google calendar cancellation failed: access token is missing.',
        ];
    }
    $response = wp_remote_request(
        'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events/' . rawurlencode($event_id) . '?sendUpdates=none',
        [
            'method' => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 20,
        ]
    );
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'message' => 'Google calendar cancellation failed: ' . $response->get_error_message(),
        ];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 404 || $code === 410) {
        return ['ok' => true, 'message' => ''];
    }
    if ($code < 200 || $code >= 300) {
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $error_message = trim((string) ($decoded['error']['message'] ?? 'Google calendar returned a non-success response.'));
        return [
            'ok' => false,
            'message' => 'Google calendar cancellation failed' . ($code > 0 ? ' (' . $code . ')' : '') . ': ' . $error_message,
        ];
    }
    return ['ok' => true, 'message' => ''];
}

function ado_cd_google_calendar_mapping(array $assigned_technician_ids): array {
    $calendar_ids = [];
    $missing_technician_ids = [];

    foreach (array_values(array_unique(array_map('intval', $assigned_technician_ids))) as $technician_id) {
        if ($technician_id <= 0) {
            continue;
        }
        $calendar_id = trim((string) get_user_meta($technician_id, '_ado_google_calendar_id', true));
        if ($calendar_id === '') {
            $calendar_id = ado_cd_google_default_calendar_id_for_technician($technician_id);
        }
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

function ado_cd_google_time_range_label(int $start_ts, int $end_ts, DateTimeZone $timezone): string {
    if ($end_ts <= $start_ts) {
        return '';
    }
    try {
        $start = (new DateTimeImmutable('@' . $start_ts))->setTimezone($timezone);
        $end = (new DateTimeImmutable('@' . $end_ts))->setTimezone($timezone);
    } catch (Exception $exception) {
        return '';
    }
    return $start->format('g:i A') . ' - ' . $end->format('g:i A');
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

function ado_cd_google_availability_adapter(WC_Order $order, int $window_days = 60): array {
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

    $assigned_technician_ids = ado_cd_assigned_technician_ids($order);
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

    $auth = ado_cd_google_freebusy_access_token();
    if (($auth['status'] ?? 'fetch_error') !== 'ok') {
        $result['state'] = sanitize_key((string) ($auth['status'] ?? 'fetch_error'));
        $result['message'] = trim((string) ($auth['message'] ?? 'Live Google availability is currently unavailable.'));
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }
    $access_token = trim((string) ($auth['access_token'] ?? ''));

    $day_rows = ado_cd_schedule_business_day_rows($window_days);
    if (!$day_rows) {
        $result['state'] = 'fetch_error';
        $result['message'] = 'Live Google availability could not be normalized for the next business days.';
        return ado_cd_google_availability_finalize($result, $order, $window_days);
    }

    $first_day = $day_rows[0];
    $last_day = $day_rows[count($day_rows) - 1];
    $time_min_raw = $first_day['day_start']->format(DateTimeInterface::RFC3339);
    $time_max_raw = $last_day['day_end']->format(DateTimeInterface::RFC3339);
    $timezone = ado_cd_schedule_timezone();
    $slots = [];
    $dedupe_map = [];
    foreach ($result['calendar_ids'] as $calendar_id) {
        $page_token = '';
        $page_guard = 0;
        do {
            $events_url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode((string) $calendar_id) . '/events'
                . '?singleEvents=true'
                . '&orderBy=startTime'
                . '&maxResults=2500'
                . '&timeMin=' . rawurlencode($time_min_raw)
                . '&timeMax=' . rawurlencode($time_max_raw)
                . '&timeZone=' . rawurlencode((string) $result['timezone']);
            if ($page_token !== '') {
                $events_url .= '&pageToken=' . rawurlencode($page_token);
            }
            $events_response = wp_remote_get($events_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                ],
                'timeout' => 20,
            ]);
            if (is_wp_error($events_response)) {
                $result['state'] = 'fetch_error';
                $result['message'] = 'Live Google calendar events could not be loaded right now: ' . $events_response->get_error_message();
                return ado_cd_google_availability_finalize($result, $order, $window_days);
            }
            $events_response_code = (int) wp_remote_retrieve_response_code($events_response);
            $events_response_body = (string) wp_remote_retrieve_body($events_response);
            $events_decoded = json_decode($events_response_body, true);
            if ($events_response_code < 200 || $events_response_code >= 300 || !is_array($events_decoded)) {
                $error_message = trim((string) ($events_decoded['error']['message'] ?? ''));
                $result['state'] = 'fetch_error';
                $result['message'] = 'Live Google calendar events request failed'
                    . ($events_response_code > 0 ? ' (' . $events_response_code . ')' : '')
                    . ($error_message !== '' ? ': ' . $error_message : '.');
                return ado_cd_google_availability_finalize($result, $order, $window_days);
            }

            foreach ((array) ($events_decoded['items'] ?? []) as $event_row) {
                if (!is_array($event_row)) {
                    continue;
                }
                if (strtolower(trim((string) ($event_row['status'] ?? ''))) === 'cancelled') {
                    continue;
                }
                $start_dt_raw = trim((string) (($event_row['start']['dateTime'] ?? '')));
                $end_dt_raw = trim((string) (($event_row['end']['dateTime'] ?? '')));
                $start_date_raw = trim((string) (($event_row['start']['date'] ?? '')));
                $is_all_day = $start_dt_raw === '' && $start_date_raw !== '';
                try {
                    if ($is_all_day) {
                        $event_start = new DateTimeImmutable($start_date_raw . ' 00:00:00', $timezone);
                        $end_date_raw = trim((string) (($event_row['end']['date'] ?? '')));
                        $event_end = $end_date_raw !== ''
                            ? new DateTimeImmutable($end_date_raw . ' 00:00:00', $timezone)
                            : $event_start->modify('+1 day');
                    } else {
                        if ($start_dt_raw === '') {
                            continue;
                        }
                        $event_start = (new DateTimeImmutable($start_dt_raw))->setTimezone($timezone);
                        $event_end = $end_dt_raw !== ''
                            ? (new DateTimeImmutable($end_dt_raw))->setTimezone($timezone)
                            : $event_start;
                    }
                } catch (Exception $exception) {
                    continue;
                }
                if ($event_end <= $event_start) {
                    continue;
                }

                $event_start_ts = $event_start->getTimestamp();
                $event_end_ts = $event_end->getTimestamp();
                $event_key = trim((string) ($event_row['id'] ?? '')) . '|' . $event_start_ts . '|' . $event_end_ts . '|' . (string) $calendar_id;
                if (isset($dedupe_map[$event_key])) {
                    continue;
                }
                $dedupe_map[$event_key] = true;

                $event_title = trim((string) ($event_row['summary'] ?? ''));
                if ($event_title === '') {
                    $event_title = 'Untitled Event';
                }
                $slots[] = [
                    'date_key' => $event_start->format('Y-m-d'),
                    'date_label' => $event_start->format('D, M j'),
                    'slot_key' => 'event-' . md5($event_key),
                    'slot_label' => $event_title,
                    'slot_time_range' => $is_all_day ? 'All day' : ado_cd_google_time_range_label($event_start_ts, $event_end_ts, $timezone),
                    'slot_start' => $event_start->format(DateTimeInterface::RFC3339),
                    'slot_end' => $event_end->format(DateTimeInterface::RFC3339),
                    'timezone' => $result['timezone'],
                    'source' => 'google_calendar_events',
                    'fetched_at' => $result['fetched_at'],
                ];
            }

            $page_token = trim((string) ($events_decoded['nextPageToken'] ?? ''));
            $page_guard++;
        } while ($page_token !== '' && $page_guard < 20);
        if ($page_token !== '') {
            $result['state'] = 'fetch_error';
            $result['message'] = 'Live Google calendar events pagination exceeded safe limits.';
            return ado_cd_google_availability_finalize($result, $order, $window_days);
        }
    }
    usort($slots, static function (array $left, array $right): int {
        return strcmp((string) ($left['slot_start'] ?? ''), (string) ($right['slot_start'] ?? ''));
    });

    $result['state'] = 'ok';
    $result['message'] = $slots
        ? 'Existing Google Calendar events are shown below by title and time range.'
        : 'No Google Calendar events were found for the current booking window.';
    $result['slots'] = $slots;
    $result['source'] = 'google_calendar_events';

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

function ado_cd_client_schedule_bookings(WC_Order $order): array {
    $rows = $order->get_meta('_ado_client_schedule_bookings');
    if (!is_array($rows)) {
        return [];
    }
    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $booking_id = trim((string) ($row['booking_id'] ?? ''));
        if ($booking_id === '') {
            $booking_id = 'ado_booking_' . wp_generate_uuid4();
        }
        $schedule_date = trim((string) ($row['schedule_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedule_date)) {
            continue;
        }
        $door_ids = [];
        foreach ((array) ($row['door_ids'] ?? []) as $door_id) {
            $door_id = sanitize_text_field((string) $door_id);
            if ($door_id === '') {
                continue;
            }
            $door_ids[$door_id] = true;
        }
        $status = strtolower(trim((string) ($row['status'] ?? 'active')));
        if (!in_array($status, ['active', 'cancelled'], true)) {
            $status = 'active';
        }
        $normalized[] = [
            'booking_id' => $booking_id,
            'schedule_date' => $schedule_date,
            'door_ids' => array_values(array_keys($door_ids)),
            'status' => $status,
            'note' => sanitize_textarea_field((string) ($row['note'] ?? '')),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'created_by' => (int) ($row['created_by'] ?? 0),
            'created_by_client_id' => (int) ($row['created_by_client_id'] ?? ($row['created_by'] ?? 0)),
            'created_by_name' => sanitize_text_field((string) ($row['created_by_name'] ?? '')),
            'created_by_email' => sanitize_email((string) ($row['created_by_email'] ?? '')),
            'cancelled_at' => trim((string) ($row['cancelled_at'] ?? '')),
            'cancelled_by' => (int) ($row['cancelled_by'] ?? 0),
            'calendar_id' => trim((string) ($row['calendar_id'] ?? '')),
            'google_event_id' => trim((string) ($row['google_event_id'] ?? '')),
            'google_event_link' => trim((string) ($row['google_event_link'] ?? '')),
        ];
    }
    return $normalized;
}

function ado_cd_booking_is_owned_by_client(array $booking_row, int $client_user_id, ?WP_User $client_user = null): bool {
    if ($client_user_id <= 0) {
        return false;
    }
    $owner_ids = [
        (int) ($booking_row['created_by_client_id'] ?? 0),
        (int) ($booking_row['created_by'] ?? 0),
    ];
    foreach ($owner_ids as $owner_id) {
        if ($owner_id > 0 && $owner_id === $client_user_id) {
            return true;
        }
    }
    $booking_email = strtolower(trim((string) ($booking_row['created_by_email'] ?? '')));
    $client_email = '';
    if ($client_user instanceof WP_User) {
        $client_email = strtolower(trim((string) $client_user->user_email));
    }
    return $booking_email !== '' && $client_email !== '' && $booking_email === $client_email;
}

function ado_cd_client_schedule_active_bookings(WC_Order $order): array {
    $rows = [];
    foreach (ado_cd_client_schedule_bookings($order) as $booking) {
        if ((string) ($booking['status'] ?? '') !== 'active') {
            continue;
        }
        $rows[] = $booking;
    }
    return $rows;
}

function ado_cd_client_schedule_active_booked_door_ids(WC_Order $order): array {
    $rows = [];
    foreach (ado_cd_client_schedule_active_bookings($order) as $booking) {
        foreach ((array) ($booking['door_ids'] ?? []) as $door_id) {
            $door_id = sanitize_text_field((string) $door_id);
            if ($door_id === '') {
                continue;
            }
            $rows[$door_id] = true;
        }
    }
    return array_values(array_keys($rows));
}

function ado_cd_client_booking_reference(array $orders, string $booking_id): ?array {
    $booking_id = trim($booking_id);
    if ($booking_id === '') {
        return null;
    }
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) {
            continue;
        }
        $bookings = ado_cd_client_schedule_bookings($order);
        foreach ($bookings as $index => $booking_row) {
            if (!is_array($booking_row)) {
                continue;
            }
            if ((string) ($booking_row['booking_id'] ?? '') !== $booking_id) {
                continue;
            }
            return [
                'order' => $order,
                'order_id' => (int) $order->get_id(),
                'bookings' => $bookings,
                'index' => (int) $index,
                'booking' => $booking_row,
            ];
        }
    }
    return null;
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

function ado_cd_site_readiness_sections(): array {
    return [
        'opening_ready' => [
            'title' => 'Opening is fully ready',
            'purpose' => 'Confirm the technician is arriving to a finished, installable opening, not an active rough opening. Jambs and doors are by others/customer, which means the opening itself must already be in place.',
            'items' => [
                'door_id_matches_drawings' => 'Door number / opening ID is confirmed and matches drawings/submittal.',
                'handing_confirmed' => 'Correct handing is confirmed: LH, RH, LHR, RHR, single, pair, in-swing, out-swing.',
                'opening_size_matches_drawings' => 'Opening width and height match approved shop drawings.',
                'header_height_clearance_ok' => 'Header height and clearances match operator requirements.',
                'jambs_installed_and_anchored' => 'Jambs are installed, anchored, and not loose.',
                'frame_plumb_square_true' => 'Frame is plumb, square, and true.',
                'header_level_and_structural' => 'Header is level and structurally sound.',
                'mounting_surface_complete' => 'Mounting surface is complete and free of drywall gaps, voids, or unfinished blocking.',
                'reinforcement_installed' => 'Required reinforcement/backing is installed where operator and peripherals mount.',
                'no_pending_opening_work' => 'No pending glazing, framing, or aluminum storefront work remains at the opening.',
                'finish_surfaces_complete' => 'Finish surfaces that affect mounting locations are complete.',
                'ceiling_access_clear' => 'Ceiling/soffit condition above the opening will not block cover removal or service access.',
            ],
        ],
        'manual_operation_ready' => [
            'title' => 'Door and hardware operate correctly manually',
            'purpose' => 'The operator should automate a properly functioning door, not compensate for a bad door installation. The technician assumes the installed door assembly is ready for setup.',
            'items' => [
                'door_leaf_installed' => 'Door leaf is installed and secured.',
                'hinges_aligned' => 'Hinges/pivots are correct, tight, and properly aligned.',
                'full_manual_swing' => 'Door swings freely through full travel by hand.',
                'no_binding' => 'Door does not bind at head, jamb, threshold, floor, or weatherstrip.',
                'door_closes_consistently' => 'Door closes fully and consistently without sticking.',
                'reveal_gaps_consistent' => 'Reveal gaps are consistent.',
                'threshold_no_interference' => 'Threshold is installed and does not interfere with swing.',
                'bottom_pivot_area_complete' => 'Bottom pivot/floor closer area is complete where applicable.',
                'manual_closer_coordinated' => 'Existing manual closer, if any, has been coordinated with the operator scope.',
                'lockset_manual_operation_ok' => 'Lockset/latch engages and releases correctly by hand.',
                'seals_not_obstructing' => 'Weatherstripping, sweeps, astragals, coordinators, and seals do not obstruct operation.',
                'pair_doors_aligned' => 'Pair doors are aligned and meeting edges are correct.',
            ],
        ],
        'electrical_power_ready' => [
            'title' => 'Electrical power is roughed in and available',
            'purpose' => 'The technician should not arrive to discover there is no power, wrong voltage, or no way to energize the operator. Technicians expect to coordinate automatic door operators connections to power supplies and related devices.',
            'items' => [
                'power_requirements_confirmed' => 'Correct power requirement has been confirmed from approved submittal or manufacturer cut sheet.',
                'dedicated_branch_installed' => 'Dedicated branch circuit is installed where required.',
                'correct_voltage_present' => 'Correct voltage is present at the operator location.',
                'disconnect_known' => 'Disconnecting means / breaker identification is known.',
                'junction_location_correct' => 'Junction box or power stub-out is at the correct location.',
                'conductors_ready' => 'Conductors are pulled, labeled, and terminated or ready for termination.',
                'circuit_live_tested' => 'Circuit has been tested live by electrician.',
                'no_temporary_power' => 'No temporary power is being relied on for final install.',
                'ceiling_access_for_power' => 'Ceiling closure or millwork will not block access to the power connection.',
                'related_device_power_ready' => 'Power for related devices/power supplies is also available if part of the system.',
            ],
        ],
        'low_voltage_ready' => [
            'title' => 'Low-voltage pathways and device rough-in are complete',
            'purpose' => 'Push plates, sensors, key switches, program switches, annunciators, and other accessories need conduit, boxes, and cable paths ready before install. Technicians expect the right wires or pull strings and device boxes at the right locations.',
            'items' => [
                'all_accessories_identified' => 'Every accessory in scope has been identified from approved submittal.',
                'device_boxes_installed' => 'Device box locations are installed for push plates / switches / controls.',
                'conduit_paths_installed' => 'Conduit pathways are installed from operator to each field device where required.',
                'cable_or_pullstring_pulled' => 'Pull string or low-voltage cable has been pulled as specified.',
                'cable_type_matches_requirement' => 'Pull string or cable type matches the project requirement.',
                'spare_capacity_present' => 'Spare conductors or extra capacity is present if required by project standard.',
                'wires_labeled' => 'Wires are labeled at both ends.',
                'pathways_complete' => 'Pathways are complete through walls, frames, ceiling spaces, and mullions.',
                'no_system_conflicts' => 'No pathway conflicts remain with security, fire alarm, or glazing.',
                'surface_raceway_approved' => 'Surface raceway requirements have been approved if conduit is not concealed.',
                'mounting_heights_coordinated' => 'Device mounting heights and accessibility locations have been coordinated.',
            ],
        ],
        'locks_and_sequencing' => [
            'title' => 'Locks, electrified hardware, and release sequencing are coordinated',
            'purpose' => 'This is one of the biggest sources of failed trips. The lock system must be coordinated and, where required for proper operation, a time-delay relay must be provided so the automatic operator activates only after the electric lock releases.',
            'items' => [
                'electrified_lock_reviewed' => 'Opening has been reviewed for maglock, electric strike, ELR, shear lock, latch retraction, or other electrified locking.',
                'lock_model_known' => 'Lock hardware model and function are known.',
                'sequence_defined_in_writing' => 'Sequence of operation has been defined in writing.',
                'operator_signal_known' => 'Operator signal source is known.',
                'lock_release_signal_known' => 'Lock release signal source is known.',
                'time_delay_provided' => 'Time delay relay is provided if required.',
                'access_control_interface_coordinated' => 'Card reader / access control interface has been coordinated.',
                'monitoring_contacts_coordinated' => 'Request-to-exit / door position / latch monitoring contacts are coordinated where required.',
                'fail_safe_mode_confirmed' => 'Fail-safe vs fail-secure behavior has been confirmed.',
                'fire_alarm_requirements_known' => 'Fire alarm interface requirements are known.',
                'shared_written_sequence' => 'Security integrator and automatic door installer have the same written sequence.',
                'timing_test_ready' => 'Any special unlock-before-open timing has been tested or is ready to be tested.',
            ],
        ],
        'systems_integration_defined' => [
            'title' => 'Other systems integration is defined',
            'purpose' => 'The operator may need to interface with fire alarm, building security, access control, intercom, or emergency egress logic. Integration with other systems may be required for a complete working installation depending on scope.',
            'items' => [
                'fire_alarm_interaction_known' => 'Fire alarm interaction requirement is known.',
                'access_control_interaction_known' => 'Access control interaction requirement is known.',
                'intercom_interaction_known' => 'Intercom or nurse call interaction requirement is known if applicable.',
                'no_unfinished_trade_dependency' => 'Automatic operator is not being expected to solve another trade\'s unfinished integration.',
                'interface_owners_assigned' => 'Responsible trade for each interface is assigned.',
                'io_points_identified' => 'Required dry contacts / outputs / inputs have been identified.',
                'integration_drawings_available' => 'Integration drawings or riser details are available on site.',
                'test_participants_identified' => 'Functional test participants are identified: electrician, security, fire alarm, door tech, PM.',
                'startup_owner_assigned' => 'Final startup/testing responsibility is assigned.',
                'after_hours_window_scheduled' => 'After-hours access/testing windows are scheduled if needed.',
                'owner_rep_alignment' => 'Building owner rep knows which systems must be live for testing.',
            ],
        ],
        'peripheral_locations_finalized' => [
            'title' => 'Peripheral mounting locations are finalized and field-approved',
            'purpose' => 'Safety devices and activation devices cannot be placed casually. AAADM notes ANSI/BHMA standards define installation, sensing-device, and safety requirements, so device placement must align with approved design and safe operation.',
            'items' => [
                'push_plate_locations_marked' => 'Push plate locations are decided and marked.',
                'push_plate_heights_compliant' => 'Push plate heights comply with project accessibility requirements.',
                'sensor_locations_clear' => 'Safety sensor locations are decided and unobstructed.',
                'approach_and_swing_reviewed' => 'Approach side and swing side conditions have been reviewed.',
                'program_controls_accessible' => 'Program switch / key switch / reset controls are located and accessible.',
                'no_coverage_conflicts' => 'Bollards, walls, columns, furniture, and planters do not conflict with device coverage.',
                'no_nuisance_activation_risk' => 'Device locations do not create nuisance activations from adjacent pedestrian traffic.',
                'guide_rails_provided' => 'Guide rails or barriers are provided where called for.',
                'mounting_surfaces_final' => 'Mounting surfaces are final and suitable for the device type.',
                'signage_locations_reserved' => 'Signage locations are reserved and visible.',
                'owner_preferences_documented' => 'Owner/user preference items are documented before install day.',
            ],
        ],
        'flooring_and_elevations_complete' => [
            'title' => 'Flooring, thresholds, and finished elevations are complete',
            'purpose' => 'Final floor conditions affect clearances, bottom pivots, sensor geometry, and true operation of the opening. Bottom-pivot preparation and door hanging dependencies rely on completed opening/floor conditions.',
            'items' => [
                'final_flooring_installed' => 'Final flooring is installed at both sides of the opening.',
                'transitions_complete' => 'Floor height transitions are complete.',
                'threshold_installed' => 'Threshold is installed and secured.',
                'no_temp_floor_masking' => 'No temporary floor protection is masking final elevations.',
                'pivot_work_complete' => 'Floor closer pocket / bottom pivot work is complete where applicable.',
                'no_floor_interference' => 'Carpet, tile, mat wells, ramps, and reducers do not interfere with swing or sensor zones.',
                'floor_level_within_tolerance' => 'Floor is level across the door path within acceptable project tolerances.',
                'floor_accessory_locations_final' => 'Any floor-mounted accessory locations are finalized.',
                'ffe_matches_submittal' => 'Finished floor elevation matches what the submittal assumed.',
                'no_future_floor_change_planned' => 'No future flooring change is planned after install.',
            ],
        ],
        'signage_and_compliance_planned' => [
            'title' => 'Signage, labels, and compliance items are planned',
            'purpose' => 'Automatic doors need required labels/signage and a compliant final setup. ANSI/BHMA standards and manufacturer requirements govern installation, safety labels, and final setup.',
            'items' => [
                'door_and_operator_type_identified' => 'Correct door type and operator type have been identified for compliance review.',
                'safety_labels_in_scope' => 'Required safety decals/labels are included in the project scope.',
                'signage_owner_assigned' => 'Signage responsibility is assigned: door vendor, GC, or owner.',
                'program_modes_known' => 'Program mode requirements are known: off / hold open / auto / one-way, etc.',
                'accessibility_method_understood' => 'Accessibility requirements for activation method are understood.',
                'glass_markings_complete' => 'Glass/vision panel markings required by other codes are complete.',
                'graphics_wont_interfere' => 'No branding film, frosting, or graphics will interfere with required labels or sensors.',
                'inspection_requirements_known' => 'Final inspection requirement is known: internal QA, AAADM inspection, owner witness test, AHJ, or all applicable parties.',
                'om_and_training_assigned' => 'O&M manuals and training expectations are assigned.',
                'warranty_closeout_defined' => 'Warranty closeout expectations are defined.',
            ],
        ],
        'safe_access_available' => [
            'title' => 'Safe, unobstructed access for installation and testing is available',
            'purpose' => 'Even when the opening is technically ready, the visit fails if the technician cannot safely work, access power, open covers, test the door path, or use ladders/lifts. This is basic site-readiness, not just product-readiness.',
            'items' => [
                'opening_released_for_work' => 'Opening is released for work on the scheduled day.',
                'no_trade_conflicts' => 'No conflicting trades will occupy the area.',
                'safe_access_equipment_available' => 'Safe access equipment is available if needed: ladder, lift, scaffold.',
                'lighting_adequate' => 'Work area lighting is adequate.',
                'ceiling_access_unobstructed' => 'Ceiling/header access is unobstructed.',
                'path_clear_of_storage' => 'Door swing path and approach areas are free of material storage.',
                'test_area_control_available' => 'Area can be temporarily controlled for testing.',
                'hazardous_adjacent_work_isolated' => 'Dusty, wet, or hazardous adjacent work is complete or isolated.',
                'power_available_for_testing' => 'Power can remain on for startup and testing.',
                'access_and_escort_arranged' => 'Building access / lockout / escort requirements are arranged.',
                'site_contact_available' => 'PM or site contact will be available during the visit.',
                'required_permissions_scheduled' => 'Required shutdowns or security permissions are scheduled.',
            ],
        ],
    ];
}

function ado_cd_site_readiness_submission_counts(array $definition, array $sections): array {
    $total_items = 0;
    $checked_items = 0;
    foreach ($definition as $section_key => $section_row) {
        $section_state = is_array($sections[$section_key] ?? null) ? (array) $sections[$section_key] : [];
        $section_items = is_array($section_state['items'] ?? null) ? (array) $section_state['items'] : [];
        foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
            $total_items++;
            if (!empty($section_items[$item_key])) {
                $checked_items++;
            }
        }
    }
    return [
        'total_items' => $total_items,
        'checked_items' => $checked_items,
    ];
}

function ado_cd_site_readiness_submissions(WC_Order $order): array {
    $definition = ado_cd_site_readiness_sections();
    $stored = $order->get_meta('_ado_site_readiness_checklist');
    $stored = is_array($stored) ? $stored : [];
    $available_door_ids = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $available_door_ids[$door_id] = true;
    }

    $normalize_submission = static function (array $submission_row, string $fallback_id) use ($definition, $available_door_ids): array {
        $submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($submission_row['submission_id'] ?? '')));
        if (!is_string($submission_id)) {
            $submission_id = '';
        }
        if ($submission_id === '') {
            $submission_id = $fallback_id;
        }
        $submission_door_lookup = [];
        foreach ((array) ($submission_row['door_ids'] ?? []) as $submission_door_id_raw) {
            $submission_door_id = sanitize_text_field((string) $submission_door_id_raw);
            if ($submission_door_id === '' || !isset($available_door_ids[$submission_door_id])) {
                continue;
            }
            $submission_door_lookup[$submission_door_id] = true;
        }
        $submission_sections = is_array($submission_row['sections'] ?? null) ? (array) $submission_row['sections'] : [];
        $normalized_sections = [];
        foreach ($definition as $section_key => $section_row) {
            $input_section = is_array($submission_sections[$section_key] ?? null) ? (array) $submission_sections[$section_key] : [];
            $input_items = is_array($input_section['items'] ?? null) ? (array) $input_section['items'] : [];
            $normalized_items = [];
            foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
                $normalized_items[$item_key] = !empty($input_items[$item_key]);
            }
            $normalized_sections[$section_key] = [
                'items' => $normalized_items,
                'note' => sanitize_textarea_field((string) ($input_section['note'] ?? '')),
            ];
        }
        return [
            'submission_id' => $submission_id,
            'door_ids' => array_values(array_keys($submission_door_lookup)),
            'sections' => $normalized_sections,
            'updated_at' => trim((string) ($submission_row['updated_at'] ?? '')),
            'updated_by' => (int) ($submission_row['updated_by'] ?? 0),
        ];
    };

    $normalized_submissions = [];
    $stored_submissions = is_array($stored['submissions'] ?? null) ? (array) $stored['submissions'] : [];
    if ($stored_submissions) {
        foreach ($stored_submissions as $submission_index => $submission_row) {
            if (!is_array($submission_row)) {
                continue;
            }
            $fallback_id = 'sr_' . (string) ($submission_index + 1);
            $normalized_submissions[] = $normalize_submission($submission_row, $fallback_id);
        }
    } elseif (is_array($stored['sections'] ?? null) || is_array($stored['door_ids'] ?? null)) {
        $legacy_fallback_id = 'sr_legacy_1';
        $legacy_submission = [
            'submission_id' => trim((string) ($stored['submission_id'] ?? $legacy_fallback_id)),
            'door_ids' => (array) ($stored['door_ids'] ?? []),
            'sections' => (array) ($stored['sections'] ?? []),
            'updated_at' => trim((string) ($stored['updated_at'] ?? '')),
            'updated_by' => (int) ($stored['updated_by'] ?? 0),
        ];
        $normalized_submissions[] = $normalize_submission($legacy_submission, $legacy_fallback_id);
    }

    return array_values($normalized_submissions);
}

function ado_cd_site_readiness_state(WC_Order $order, string $requested_submission_id = ''): array {
    $definition = ado_cd_site_readiness_sections();
    $stored = $order->get_meta('_ado_site_readiness_checklist');
    $stored = is_array($stored) ? $stored : [];
    $active_submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($stored['active_submission_id'] ?? '')));
    if (!is_string($active_submission_id)) {
        $active_submission_id = '';
    }
    $requested_submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field($requested_submission_id));
    if (!is_string($requested_submission_id)) {
        $requested_submission_id = '';
    }
    $submissions = ado_cd_site_readiness_submissions($order);
    $door_label_lookup = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $door_label = trim((string) ($door_row['door_label'] ?? ('Door ' . $door_id)));
        if ($door_label === '') {
            $door_label = 'Door ' . $door_id;
        }
        $door_label_lookup[$door_id] = $door_label;
    }
    $selected_submission = null;
    foreach ([$requested_submission_id, $active_submission_id] as $candidate_submission_id) {
        $candidate_submission_id = trim((string) $candidate_submission_id);
        if ($candidate_submission_id === '') {
            continue;
        }
        foreach ($submissions as $submission_row) {
            if ((string) ($submission_row['submission_id'] ?? '') !== $candidate_submission_id) {
                continue;
            }
            $selected_submission = $submission_row;
            break 2;
        }
    }
    if (!is_array($selected_submission) && $submissions) {
        $selected_submission = (array) $submissions[0];
    }
    if (!is_array($selected_submission)) {
        $empty_sections = [];
        foreach ($definition as $section_key => $section_row) {
            $empty_items = [];
            foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
                $empty_items[$item_key] = false;
            }
            $empty_sections[$section_key] = [
                'items' => $empty_items,
                'note' => '',
            ];
        }
        $selected_submission = [
            'submission_id' => '',
            'door_ids' => [],
            'sections' => $empty_sections,
            'updated_at' => '',
            'updated_by' => 0,
        ];
    }
    $selected_sections = is_array($selected_submission['sections'] ?? null) ? (array) $selected_submission['sections'] : [];
    $selected_counts = ado_cd_site_readiness_submission_counts($definition, $selected_sections);
    $submission_summaries = [];
    foreach ($submissions as $submission_index => $submission_row) {
        if (!is_array($submission_row)) {
            continue;
        }
        $summary_sections = is_array($submission_row['sections'] ?? null) ? (array) $submission_row['sections'] : [];
        $summary_counts = ado_cd_site_readiness_submission_counts($definition, $summary_sections);
        $summary_door_labels_map = [];
        foreach ((array) ($submission_row['door_ids'] ?? []) as $summary_door_id_raw) {
            $summary_door_id = sanitize_text_field((string) $summary_door_id_raw);
            if ($summary_door_id === '' || isset($summary_door_labels_map[$summary_door_id])) {
                continue;
            }
            $summary_door_labels_map[$summary_door_id] = (string) ($door_label_lookup[$summary_door_id] ?? ('Door ' . $summary_door_id));
        }
        $summary_door_labels = array_values($summary_door_labels_map);
        $submission_summaries[] = [
            'submission_id' => trim((string) ($submission_row['submission_id'] ?? '')),
            'updated_at' => trim((string) ($submission_row['updated_at'] ?? '')),
            'updated_by' => (int) ($submission_row['updated_by'] ?? 0),
            'door_count' => count($summary_door_labels),
            'door_labels' => $summary_door_labels,
            'door_list' => implode(', ', $summary_door_labels),
            'checked_items' => (int) ($summary_counts['checked_items'] ?? 0),
            'total_items' => (int) ($summary_counts['total_items'] ?? 0),
            'index' => (int) $submission_index,
        ];
    }

    return [
        'submission_id' => trim((string) ($selected_submission['submission_id'] ?? '')),
        'door_ids' => array_values((array) ($selected_submission['door_ids'] ?? [])),
        'sections' => $selected_sections,
        'updated_at' => trim((string) ($selected_submission['updated_at'] ?? '')),
        'updated_by' => (int) ($selected_submission['updated_by'] ?? 0),
        'checked_items' => (int) ($selected_counts['checked_items'] ?? 0),
        'total_items' => (int) ($selected_counts['total_items'] ?? 0),
        'submissions' => $submission_summaries,
        'all_submissions' => $submissions,
    ];
}

function ado_cd_site_readiness_reopened_door_lookup(WC_Order $order): array {
    $workflow_map = $order->get_meta('_ado_tp_project_door_workflow');
    $workflow_map = is_array($workflow_map) ? $workflow_map : [];
    $workflow_map_by_key = [];
    foreach ($workflow_map as $workflow_door_id => $workflow_row) {
        if (!is_array($workflow_row)) {
            continue;
        }
        $workflow_key = strtolower(trim((string) $workflow_door_id));
        if ($workflow_key === '' || isset($workflow_map_by_key[$workflow_key])) {
            continue;
        }
        $workflow_map_by_key[$workflow_key] = (array) $workflow_row;
    }
    $reopened_lookup = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $door_state = [];
        if (is_array($workflow_map[$door_id] ?? null)) {
            $door_state = (array) $workflow_map[$door_id];
        } else {
            $door_key = strtolower(trim($door_id));
            if ($door_key !== '' && is_array($workflow_map_by_key[$door_key] ?? null)) {
                $door_state = (array) $workflow_map_by_key[$door_key];
            }
        }
        $site_preparation = is_array($door_state['site_preparation'] ?? null) ? (array) $door_state['site_preparation'] : [];
        $site_preparation_state = strtolower(trim((string) ($site_preparation['state'] ?? 'yes')));
        if (in_array($site_preparation_state, ['no', '0', 'false', 'off'], true)) {
            $reopened_lookup[$door_id] = true;
        }
    }
    return $reopened_lookup;
}

function ado_cd_site_readiness_booking_gate(WC_Order $order): array {
    $definition = ado_cd_site_readiness_sections();
    $submissions = ado_cd_site_readiness_submissions($order);
    $reopened_door_lookup = ado_cd_site_readiness_reopened_door_lookup($order);
    $ready_door_lookup = [];
    foreach ($submissions as $submission_row) {
        if (!is_array($submission_row)) {
            continue;
        }
        $submission_sections = is_array($submission_row['sections'] ?? null) ? (array) $submission_row['sections'] : [];
        $submission_counts = ado_cd_site_readiness_submission_counts($definition, $submission_sections);
        $is_saved = trim((string) ($submission_row['updated_at'] ?? '')) !== '';
        $is_complete = (int) ($submission_counts['total_items'] ?? 0) > 0
            && (int) ($submission_counts['checked_items'] ?? 0) >= (int) ($submission_counts['total_items'] ?? 0);
        if (!$is_saved || !$is_complete) {
            continue;
        }
        foreach ((array) ($submission_row['door_ids'] ?? []) as $door_id_raw) {
            $door_id = sanitize_text_field((string) $door_id_raw);
            if ($door_id === '') {
                continue;
            }
            if (isset($reopened_door_lookup[$door_id])) {
                continue;
            }
            $ready_door_lookup[$door_id] = true;
        }
    }
    return [
        'door_lookup' => $ready_door_lookup,
        'door_ids' => array_values(array_keys($ready_door_lookup)),
        'reopened_door_lookup' => $reopened_door_lookup,
        'reopened_door_ids' => array_values(array_keys($reopened_door_lookup)),
        'is_ready_for_booking' => count($ready_door_lookup) > 0,
        'submission_count' => count($submissions),
    ];
}

function ado_cd_hardware_availability_sections(): array {
    return [
        'scope_and_quantities_verified' => [
            'title' => 'Hardware scope and quantities are verified',
            'purpose' => 'Confirm all hardware required for the selected doors is identified and matched to approved scope before shipment acceptance.',
            'items' => [
                'door_scope_matches_submittal' => 'Door IDs selected for this submittal match approved hardware scope and schedule.',
                'operator_models_confirmed' => 'Operator and closer models are confirmed against approved submittal.',
                'locks_and_strikes_confirmed' => 'Locks, strikes, latches, and electrified hardware models are confirmed.',
                'activation_and_safety_devices_confirmed' => 'Push plates, sensors, switches, and safety devices are included as required.',
                'mounting_hardware_confirmed' => 'Required brackets, arm kits, fasteners, and mounting accessories are included.',
                'power_and_control_components_confirmed' => 'Power supplies, relays, interfaces, and control modules are included where required.',
                'quantities_match_selected_doors' => 'Quantities are verified and sufficient for all selected doors.',
                'special_finish_or_handing_confirmed' => 'Special handing, finish, and configuration requirements are confirmed.',
            ],
        ],
        'shipment_and_delivery_confirmed' => [
            'title' => 'Shipment and delivery status are confirmed',
            'purpose' => 'Confirm the required hardware is not only ordered, but has shipped and arrived at the project site in time for the technician visit.',
            'items' => [
                'shipment_references_recorded' => 'Shipment references (PO, packing list, or tracking) are documented.',
                'all_required_packages_shipped' => 'All required packages for selected doors are marked as shipped.',
                'delivery_date_confirmed' => 'Delivery date to site is confirmed and aligned with visit plan.',
                'partial_shipments_flagged' => 'Partial shipments are identified and missing items are explicitly tracked.',
                'backorders_accounted_for' => 'Backordered components are resolved or rebooked with clear ETA.',
                'carrier_or_vendor_contacts_known' => 'Carrier/vendor contact details are available for exception handling.',
            ],
        ],
        'on_site_receipt_and_condition_verified' => [
            'title' => 'On-site receipt and condition are verified',
            'purpose' => 'Confirm hardware has physically arrived on site, is complete, and is in installable condition.',
            'items' => [
                'hardware_received_on_site' => 'Hardware for selected doors has been physically received on site.',
                'boxes_labeled_to_doors' => 'Packages are labeled or mapped to the correct door/opening IDs.',
                'received_quantities_verified' => 'Received quantities were checked against packing lists.',
                'damage_or_shortages_logged' => 'Visible damage, shortages, or mismatches are documented and resolved.',
                'critical_components_present' => 'Critical components needed to complete installation are present.',
                'replacement_items_if_needed_arranged' => 'Any replacement items required have confirmed delivery or are already on site.',
            ],
        ],
        'staging_and_install_readiness_confirmed' => [
            'title' => 'Staging and install-day access are confirmed',
            'purpose' => 'Confirm received hardware is accessible, protected, and ready for technician use on install day.',
            'items' => [
                'staging_location_known' => 'Staging/storage location on site is known to PM and technician.',
                'materials_accessible_for_visit' => 'Hardware can be accessed during the scheduled work window.',
                'materials_protected_from_damage' => 'Stored materials are protected from weather, damage, or loss.',
                'door_specific_kits_grouped' => 'Door-specific kits are grouped to reduce install-day delays.',
                'required_site_access_arranged' => 'Building/site access needed to retrieve staged hardware is arranged.',
                'install_day_point_of_contact_confirmed' => 'A site contact can release materials and answer scope questions during visit.',
            ],
        ],
        'documentation_and_handoff_complete' => [
            'title' => 'Documentation and handoff are complete',
            'purpose' => 'Provide enough detail so the technician and PM can quickly verify hardware readiness without ambiguity.',
            'items' => [
                'readiness_notes_saved' => 'Readiness notes clearly identify what is confirmed for selected doors.',
                'exceptions_documented' => 'Known exceptions or constraints are documented before visit day.',
                'supporting_photos_or_docs_uploaded' => 'Supporting photos/documents were uploaded when required by project standards.',
                'technician_handoff_note_ready' => 'A concise handoff note is available for technician review.',
                'pm_acknowledges_install_ready' => 'Project manager confirms selected doors are hardware-ready for installation.',
            ],
        ],
    ];
}

function ado_cd_hardware_availability_submission_counts(array $definition, array $sections): array {
    $total_items = 0;
    $checked_items = 0;
    foreach ($definition as $section_key => $section_row) {
        $section_state = is_array($sections[$section_key] ?? null) ? (array) $sections[$section_key] : [];
        $section_items = is_array($section_state['items'] ?? null) ? (array) $section_state['items'] : [];
        foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
            $total_items++;
            if (!empty($section_items[$item_key])) {
                $checked_items++;
            }
        }
    }
    return [
        'total_items' => $total_items,
        'checked_items' => $checked_items,
    ];
}

function ado_cd_hardware_availability_submissions(WC_Order $order): array {
    $definition = ado_cd_hardware_availability_sections();
    $stored = $order->get_meta('_ado_hardware_availability_checklist');
    $stored = is_array($stored) ? $stored : [];
    $available_door_ids = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $available_door_ids[$door_id] = true;
    }

    $normalize_submission = static function (array $submission_row, string $fallback_id) use ($definition, $available_door_ids): array {
        $submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($submission_row['submission_id'] ?? '')));
        if (!is_string($submission_id)) {
            $submission_id = '';
        }
        if ($submission_id === '') {
            $submission_id = $fallback_id;
        }
        $submission_door_lookup = [];
        foreach ((array) ($submission_row['door_ids'] ?? []) as $submission_door_id_raw) {
            $submission_door_id = sanitize_text_field((string) $submission_door_id_raw);
            if ($submission_door_id === '' || !isset($available_door_ids[$submission_door_id])) {
                continue;
            }
            $submission_door_lookup[$submission_door_id] = true;
        }
        $submission_sections = is_array($submission_row['sections'] ?? null) ? (array) $submission_row['sections'] : [];
        $normalized_sections = [];
        foreach ($definition as $section_key => $section_row) {
            $input_section = is_array($submission_sections[$section_key] ?? null) ? (array) $submission_sections[$section_key] : [];
            $input_items = is_array($input_section['items'] ?? null) ? (array) $input_section['items'] : [];
            $normalized_items = [];
            foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
                $normalized_items[$item_key] = !empty($input_items[$item_key]);
            }
            $normalized_sections[$section_key] = [
                'items' => $normalized_items,
                'note' => sanitize_textarea_field((string) ($input_section['note'] ?? '')),
            ];
        }
        return [
            'submission_id' => $submission_id,
            'door_ids' => array_values(array_keys($submission_door_lookup)),
            'sections' => $normalized_sections,
            'updated_at' => trim((string) ($submission_row['updated_at'] ?? '')),
            'updated_by' => (int) ($submission_row['updated_by'] ?? 0),
        ];
    };

    $normalized_submissions = [];
    $stored_submissions = is_array($stored['submissions'] ?? null) ? (array) $stored['submissions'] : [];
    if ($stored_submissions) {
        foreach ($stored_submissions as $submission_index => $submission_row) {
            if (!is_array($submission_row)) {
                continue;
            }
            $fallback_id = 'ha_' . (string) ($submission_index + 1);
            $normalized_submissions[] = $normalize_submission($submission_row, $fallback_id);
        }
    } elseif (is_array($stored['sections'] ?? null) || is_array($stored['door_ids'] ?? null)) {
        $legacy_fallback_id = 'ha_legacy_1';
        $legacy_submission = [
            'submission_id' => trim((string) ($stored['submission_id'] ?? $legacy_fallback_id)),
            'door_ids' => (array) ($stored['door_ids'] ?? []),
            'sections' => (array) ($stored['sections'] ?? []),
            'updated_at' => trim((string) ($stored['updated_at'] ?? '')),
            'updated_by' => (int) ($stored['updated_by'] ?? 0),
        ];
        $normalized_submissions[] = $normalize_submission($legacy_submission, $legacy_fallback_id);
    }

    return array_values($normalized_submissions);
}

function ado_cd_hardware_availability_state(WC_Order $order, string $requested_submission_id = ''): array {
    $definition = ado_cd_hardware_availability_sections();
    $stored = $order->get_meta('_ado_hardware_availability_checklist');
    $stored = is_array($stored) ? $stored : [];
    $active_submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($stored['active_submission_id'] ?? '')));
    if (!is_string($active_submission_id)) {
        $active_submission_id = '';
    }
    $requested_submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field($requested_submission_id));
    if (!is_string($requested_submission_id)) {
        $requested_submission_id = '';
    }
    $submissions = ado_cd_hardware_availability_submissions($order);
    $door_label_lookup = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $door_label = trim((string) ($door_row['door_label'] ?? ('Door ' . $door_id)));
        if ($door_label === '') {
            $door_label = 'Door ' . $door_id;
        }
        $door_label_lookup[$door_id] = $door_label;
    }
    $selected_submission = null;
    foreach ([$requested_submission_id, $active_submission_id] as $candidate_submission_id) {
        $candidate_submission_id = trim((string) $candidate_submission_id);
        if ($candidate_submission_id === '') {
            continue;
        }
        foreach ($submissions as $submission_row) {
            if ((string) ($submission_row['submission_id'] ?? '') !== $candidate_submission_id) {
                continue;
            }
            $selected_submission = $submission_row;
            break 2;
        }
    }
    if (!is_array($selected_submission) && $submissions) {
        $selected_submission = (array) $submissions[0];
    }
    if (!is_array($selected_submission)) {
        $empty_sections = [];
        foreach ($definition as $section_key => $section_row) {
            $empty_items = [];
            foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
                $empty_items[$item_key] = false;
            }
            $empty_sections[$section_key] = [
                'items' => $empty_items,
                'note' => '',
            ];
        }
        $selected_submission = [
            'submission_id' => '',
            'door_ids' => [],
            'sections' => $empty_sections,
            'updated_at' => '',
            'updated_by' => 0,
        ];
    }
    $selected_sections = is_array($selected_submission['sections'] ?? null) ? (array) $selected_submission['sections'] : [];
    $selected_counts = ado_cd_hardware_availability_submission_counts($definition, $selected_sections);
    $submission_summaries = [];
    foreach ($submissions as $submission_index => $submission_row) {
        if (!is_array($submission_row)) {
            continue;
        }
        $summary_sections = is_array($submission_row['sections'] ?? null) ? (array) $submission_row['sections'] : [];
        $summary_counts = ado_cd_hardware_availability_submission_counts($definition, $summary_sections);
        $summary_door_labels_map = [];
        foreach ((array) ($submission_row['door_ids'] ?? []) as $summary_door_id_raw) {
            $summary_door_id = sanitize_text_field((string) $summary_door_id_raw);
            if ($summary_door_id === '' || isset($summary_door_labels_map[$summary_door_id])) {
                continue;
            }
            $summary_door_labels_map[$summary_door_id] = (string) ($door_label_lookup[$summary_door_id] ?? ('Door ' . $summary_door_id));
        }
        $summary_door_labels = array_values($summary_door_labels_map);
        $submission_summaries[] = [
            'submission_id' => trim((string) ($submission_row['submission_id'] ?? '')),
            'updated_at' => trim((string) ($submission_row['updated_at'] ?? '')),
            'updated_by' => (int) ($submission_row['updated_by'] ?? 0),
            'door_count' => count($summary_door_labels),
            'door_labels' => $summary_door_labels,
            'door_list' => implode(', ', $summary_door_labels),
            'checked_items' => (int) ($summary_counts['checked_items'] ?? 0),
            'total_items' => (int) ($summary_counts['total_items'] ?? 0),
            'index' => (int) $submission_index,
        ];
    }

    return [
        'submission_id' => trim((string) ($selected_submission['submission_id'] ?? '')),
        'door_ids' => array_values((array) ($selected_submission['door_ids'] ?? [])),
        'sections' => $selected_sections,
        'updated_at' => trim((string) ($selected_submission['updated_at'] ?? '')),
        'updated_by' => (int) ($selected_submission['updated_by'] ?? 0),
        'checked_items' => (int) ($selected_counts['checked_items'] ?? 0),
        'total_items' => (int) ($selected_counts['total_items'] ?? 0),
        'submissions' => $submission_summaries,
        'all_submissions' => $submissions,
    ];
}

function ado_cd_hardware_availability_reopened_door_lookup(WC_Order $order): array {
    $workflow_map = $order->get_meta('_ado_tp_project_door_workflow');
    $workflow_map = is_array($workflow_map) ? $workflow_map : [];
    $workflow_map_by_key = [];
    foreach ($workflow_map as $workflow_door_id => $workflow_row) {
        if (!is_array($workflow_row)) {
            continue;
        }
        $workflow_key = strtolower(trim((string) $workflow_door_id));
        if ($workflow_key === '' || isset($workflow_map_by_key[$workflow_key])) {
            continue;
        }
        $workflow_map_by_key[$workflow_key] = (array) $workflow_row;
    }
    $reopened_lookup = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $door_state = [];
        if (is_array($workflow_map[$door_id] ?? null)) {
            $door_state = (array) $workflow_map[$door_id];
        } else {
            $door_key = strtolower(trim($door_id));
            if ($door_key !== '' && is_array($workflow_map_by_key[$door_key] ?? null)) {
                $door_state = (array) $workflow_map_by_key[$door_key];
            }
        }
        $hardware_availability = is_array($door_state['hardware_availability'] ?? null) ? (array) $door_state['hardware_availability'] : [];
        $hardware_state = strtolower(trim((string) ($hardware_availability['state'] ?? 'yes')));
        if (in_array($hardware_state, ['no', '0', 'false', 'off'], true)) {
            $reopened_lookup[$door_id] = true;
        }
    }
    return $reopened_lookup;
}

function ado_cd_hardware_availability_booking_gate(WC_Order $order): array {
    $definition = ado_cd_hardware_availability_sections();
    $submissions = ado_cd_hardware_availability_submissions($order);
    $reopened_door_lookup = ado_cd_hardware_availability_reopened_door_lookup($order);
    $ready_door_lookup = [];
    foreach ($submissions as $submission_row) {
        if (!is_array($submission_row)) {
            continue;
        }
        $submission_sections = is_array($submission_row['sections'] ?? null) ? (array) $submission_row['sections'] : [];
        $submission_counts = ado_cd_hardware_availability_submission_counts($definition, $submission_sections);
        $is_saved = trim((string) ($submission_row['updated_at'] ?? '')) !== '';
        $is_complete = (int) ($submission_counts['total_items'] ?? 0) > 0
            && (int) ($submission_counts['checked_items'] ?? 0) >= (int) ($submission_counts['total_items'] ?? 0);
        if (!$is_saved || !$is_complete) {
            continue;
        }
        foreach ((array) ($submission_row['door_ids'] ?? []) as $door_id_raw) {
            $door_id = sanitize_text_field((string) $door_id_raw);
            if ($door_id === '') {
                continue;
            }
            if (isset($reopened_door_lookup[$door_id])) {
                continue;
            }
            $ready_door_lookup[$door_id] = true;
        }
    }
    return [
        'door_lookup' => $ready_door_lookup,
        'door_ids' => array_values(array_keys($ready_door_lookup)),
        'reopened_door_lookup' => $reopened_door_lookup,
        'reopened_door_ids' => array_values(array_keys($reopened_door_lookup)),
        'is_ready_for_booking' => count($ready_door_lookup) > 0,
        'submission_count' => count($submissions),
    ];
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
    $door_state = [];
    if (is_array($workflow_map[$door_id] ?? null)) {
        $door_state = (array) $workflow_map[$door_id];
    } else {
        $door_key = strtolower(trim($door_id));
        if ($door_key !== '') {
            foreach ($workflow_map as $workflow_door_id => $workflow_row) {
                if (!is_array($workflow_row)) {
                    continue;
                }
                if (strtolower(trim((string) $workflow_door_id)) !== $door_key) {
                    continue;
                }
                $door_state = (array) $workflow_row;
                break;
            }
        }
    }
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
    $site_readiness_gate = ado_cd_site_readiness_booking_gate($project);
    $site_readiness_lookup = is_array($site_readiness_gate['door_lookup'] ?? null)
        ? (array) $site_readiness_gate['door_lookup']
        : [];
    $hardware_availability_gate = ado_cd_hardware_availability_booking_gate($project);
    $hardware_availability_lookup = is_array($hardware_availability_gate['door_lookup'] ?? null)
        ? (array) $hardware_availability_gate['door_lookup']
        : [];
    $door_id_key = strtolower(trim($door_id));
    $door_exists_in_lookup = static function (array $lookup, string $door_id, string $door_id_key): bool {
        if (isset($lookup[$door_id])) {
            return true;
        }
        if ($door_id_key === '') {
            return false;
        }
        foreach (array_keys($lookup) as $lookup_door_id) {
            if (strtolower(trim((string) $lookup_door_id)) === $door_id_key) {
                return true;
            }
        }
        return false;
    };
    $site_readiness_confirmed = $door_exists_in_lookup($site_readiness_lookup, $door_id, $door_id_key);
    $hardware_availability_confirmed = $door_exists_in_lookup($hardware_availability_lookup, $door_id, $door_id_key);
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
        <div class="ado-client-door-status-card <?php echo $site_readiness_confirmed ? 'ok' : 'warn'; ?>">
          <strong>Site Readiness</strong>
          <small><?php echo esc_html($site_readiness_confirmed ? 'Confirmed by saved site readiness submission' : 'Pending site readiness confirmation'); ?></small>
        </div>
        <div class="ado-client-door-status-card <?php echo $hardware_availability_confirmed ? 'ok' : 'warn'; ?>">
          <strong>Hardware Availability</strong>
          <small><?php echo esc_html($hardware_availability_confirmed ? 'Confirmed by saved hardware availability submission' : 'Pending hardware availability confirmation'); ?></small>
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
    if (function_exists('ado_project_timeline_rows')) {
        return ado_project_timeline_rows($order, $limit);
    }
    return [];
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
    $current_client_user_id = (int) get_current_user_id();
    $current_client_user = wp_get_current_user();
    $project_name = ado_cd_order_name($project);
    $project_address = ado_cd_order_project_address($project);
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
    $activity_rows = ado_cd_project_activity_rows($project, 240);
    $file_rows = ado_cd_project_file_rows($project);
    $critical_notes = trim((string) $project->get_meta('_ado_critical_notes'));
    $po_number = trim((string) $project->get_meta('_ado_po_number'));
    $project_doors = ado_cd_order_doors($project);
    $project_door_lookup = [];
    foreach ($project_doors as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = trim((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $project_door_lookup[$door_id] = [
            'door_label' => trim((string) ($door_row['door_label'] ?? ('Door ' . $door_id))),
            'door_meta' => trim(implode(' | ', array_filter([
                trim((string) ($door_row['model'] ?? '')),
                trim((string) ($door_row['location'] ?? '')),
            ]))),
        ];
    }
    $project_door_total = count($project_door_lookup) > 0 ? count($project_door_lookup) : max(0, $door_count);
    $site_readiness_sections = ado_cd_site_readiness_sections();
    $requested_site_readiness_submission_id = sanitize_text_field((string) ($_GET['site_readiness_submission_id'] ?? ''));
    $site_readiness_state = ado_cd_site_readiness_state($project, $requested_site_readiness_submission_id);
    $site_readiness_total_count = (int) ($site_readiness_state['total_items'] ?? 0);
    $site_readiness_confirmed_count = (int) ($site_readiness_state['checked_items'] ?? 0);
    $site_readiness_section_count = count($site_readiness_sections);
    $site_readiness_updated_at = trim((string) ($site_readiness_state['updated_at'] ?? ''));
    $site_readiness_submission_id = trim((string) ($site_readiness_state['submission_id'] ?? ''));
    $site_readiness_submission_rows = is_array($site_readiness_state['submissions'] ?? null) ? (array) $site_readiness_state['submissions'] : [];
    $site_readiness_submission_payload = [];
    foreach ((array) ($site_readiness_state['all_submissions'] ?? []) as $site_readiness_submission_row) {
        if (!is_array($site_readiness_submission_row)) {
            continue;
        }
        $site_readiness_submission_row_id = sanitize_text_field((string) ($site_readiness_submission_row['submission_id'] ?? ''));
        if ($site_readiness_submission_row_id === '') {
            continue;
        }
        $site_readiness_submission_payload[$site_readiness_submission_row_id] = [
            'submission_id' => $site_readiness_submission_row_id,
            'door_ids' => array_values((array) ($site_readiness_submission_row['door_ids'] ?? [])),
            'sections' => is_array($site_readiness_submission_row['sections'] ?? null) ? (array) $site_readiness_submission_row['sections'] : [],
            'updated_at' => trim((string) ($site_readiness_submission_row['updated_at'] ?? '')),
            'updated_by' => (int) ($site_readiness_submission_row['updated_by'] ?? 0),
        ];
    }
    $site_readiness_selected_door_ids = [];
    foreach ((array) ($site_readiness_state['door_ids'] ?? []) as $site_readiness_selected_door_id) {
        $site_readiness_selected_door_id = sanitize_text_field((string) $site_readiness_selected_door_id);
        if ($site_readiness_selected_door_id === '' || !isset($project_door_lookup[$site_readiness_selected_door_id])) {
            continue;
        }
        $site_readiness_selected_door_ids[$site_readiness_selected_door_id] = true;
    }
    $site_readiness_selected_door_lookup = array_fill_keys(array_values(array_keys($site_readiness_selected_door_ids)), true);
    $site_readiness_reopened_door_lookup = ado_cd_site_readiness_reopened_door_lookup($project);
    $site_readiness_door_picker_rows = [];
    foreach ($project_door_lookup as $project_door_id => $project_door_row) {
        $door_label = trim((string) ($project_door_row['door_label'] ?? ('Door ' . $project_door_id)));
        $door_meta = trim((string) ($project_door_row['door_meta'] ?? ''));
        $site_readiness_door_picker_rows[] = [
            'door_id' => (string) $project_door_id,
            'door_label' => $door_label,
            'door_meta' => $door_meta,
            'door_search' => strtolower(trim((string) $project_door_id . ' ' . $door_label . ' ' . $door_meta)),
            'is_selected' => isset($site_readiness_selected_door_lookup[(string) $project_door_id]),
            'is_reopened' => isset($site_readiness_reopened_door_lookup[(string) $project_door_id]),
        ];
    }
    $site_readiness_selected_door_count = count($site_readiness_selected_door_lookup);
    $site_readiness_total_doors = count($site_readiness_door_picker_rows);
    $hardware_availability_sections = ado_cd_hardware_availability_sections();
    $requested_hardware_availability_submission_id = sanitize_text_field((string) ($_GET['hardware_availability_submission_id'] ?? ''));
    $hardware_availability_state = ado_cd_hardware_availability_state($project, $requested_hardware_availability_submission_id);
    $hardware_availability_total_count = (int) ($hardware_availability_state['total_items'] ?? 0);
    $hardware_availability_confirmed_count = (int) ($hardware_availability_state['checked_items'] ?? 0);
    $hardware_availability_section_count = count($hardware_availability_sections);
    $hardware_availability_updated_at = trim((string) ($hardware_availability_state['updated_at'] ?? ''));
    $hardware_availability_submission_id = trim((string) ($hardware_availability_state['submission_id'] ?? ''));
    $hardware_availability_submission_rows = is_array($hardware_availability_state['submissions'] ?? null) ? (array) $hardware_availability_state['submissions'] : [];
    $hardware_availability_submission_payload = [];
    foreach ((array) ($hardware_availability_state['all_submissions'] ?? []) as $hardware_availability_submission_row) {
        if (!is_array($hardware_availability_submission_row)) {
            continue;
        }
        $hardware_availability_submission_row_id = sanitize_text_field((string) ($hardware_availability_submission_row['submission_id'] ?? ''));
        if ($hardware_availability_submission_row_id === '') {
            continue;
        }
        $hardware_availability_submission_payload[$hardware_availability_submission_row_id] = [
            'submission_id' => $hardware_availability_submission_row_id,
            'door_ids' => array_values((array) ($hardware_availability_submission_row['door_ids'] ?? [])),
            'sections' => is_array($hardware_availability_submission_row['sections'] ?? null) ? (array) $hardware_availability_submission_row['sections'] : [],
            'updated_at' => trim((string) ($hardware_availability_submission_row['updated_at'] ?? '')),
            'updated_by' => (int) ($hardware_availability_submission_row['updated_by'] ?? 0),
        ];
    }
    $hardware_availability_selected_door_ids = [];
    foreach ((array) ($hardware_availability_state['door_ids'] ?? []) as $hardware_availability_selected_door_id) {
        $hardware_availability_selected_door_id = sanitize_text_field((string) $hardware_availability_selected_door_id);
        if ($hardware_availability_selected_door_id === '' || !isset($project_door_lookup[$hardware_availability_selected_door_id])) {
            continue;
        }
        $hardware_availability_selected_door_ids[$hardware_availability_selected_door_id] = true;
    }
    $hardware_availability_selected_door_lookup = array_fill_keys(array_values(array_keys($hardware_availability_selected_door_ids)), true);
    $hardware_availability_reopened_door_lookup = ado_cd_hardware_availability_reopened_door_lookup($project);
    $hardware_availability_door_picker_rows = [];
    foreach ($project_door_lookup as $project_door_id => $project_door_row) {
        $door_label = trim((string) ($project_door_row['door_label'] ?? ('Door ' . $project_door_id)));
        $door_meta = trim((string) ($project_door_row['door_meta'] ?? ''));
        $hardware_availability_door_picker_rows[] = [
            'door_id' => (string) $project_door_id,
            'door_label' => $door_label,
            'door_meta' => $door_meta,
            'door_search' => strtolower(trim((string) $project_door_id . ' ' . $door_label . ' ' . $door_meta)),
            'is_selected' => isset($hardware_availability_selected_door_lookup[(string) $project_door_id]),
            'is_reopened' => isset($hardware_availability_reopened_door_lookup[(string) $project_door_id]),
        ];
    }
    $hardware_availability_selected_door_count = count($hardware_availability_selected_door_lookup);
    $hardware_availability_total_doors = count($hardware_availability_door_picker_rows);
    $schedule_timezone = ado_cd_schedule_timezone();
    $schedule_today_key = wp_date('Y-m-d', null, $schedule_timezone);
    $active_schedule_bookings = ado_cd_client_schedule_active_bookings($project);
    $active_schedule_bookings_by_date = [];
    $active_booked_door_ids_map = [];
    foreach ($active_schedule_bookings as $booking_row) {
        $booking_date = trim((string) ($booking_row['schedule_date'] ?? ''));
        if ($booking_date === '') {
            continue;
        }
        $booking_door_ids = [];
        foreach ((array) ($booking_row['door_ids'] ?? []) as $booking_door_id) {
            $booking_door_id = sanitize_text_field((string) $booking_door_id);
            if ($booking_door_id === '' || !isset($project_door_lookup[$booking_door_id])) {
                continue;
            }
            $booking_door_ids[] = $booking_door_id;
            $active_booked_door_ids_map[$booking_door_id] = true;
        }
        if (!$booking_door_ids) {
            continue;
        }
        $active_schedule_bookings_by_date[$booking_date][] = [
            'booking_id' => trim((string) ($booking_row['booking_id'] ?? '')),
            'schedule_date' => $booking_date,
            'door_ids' => $booking_door_ids,
            'note' => trim((string) ($booking_row['note'] ?? '')),
            'created_at' => trim((string) ($booking_row['created_at'] ?? '')),
            'created_by_client_id' => (int) ($booking_row['created_by_client_id'] ?? ($booking_row['created_by'] ?? 0)),
            'created_by_name' => trim((string) ($booking_row['created_by_name'] ?? '')),
            'created_by_email' => trim((string) ($booking_row['created_by_email'] ?? '')),
            'calendar_id' => trim((string) ($booking_row['calendar_id'] ?? '')),
            'google_event_id' => trim((string) ($booking_row['google_event_id'] ?? '')),
            'google_event_link' => trim((string) ($booking_row['google_event_link'] ?? '')),
        ];
    }
    $booking_picker_doors = [];
    $booking_picker_seen = [];
    $client_verified_bookings_by_date = [];
    $client_verified_booking_seen = [];
    foreach ($orders as $client_order_row) {
        if (!($client_order_row instanceof WC_Order)) {
            continue;
        }
        $source_project_id = (int) $client_order_row->get_id();
        $source_project_name = ado_cd_order_name($client_order_row);
        $source_project_door_lookup = [];
        $source_active_booked_door_ids_map = array_fill_keys(ado_cd_client_schedule_active_booked_door_ids($client_order_row), true);
        $source_site_readiness_gate = ado_cd_site_readiness_booking_gate($client_order_row);
        $source_site_readiness_door_lookup = is_array($source_site_readiness_gate['door_lookup'] ?? null)
            ? (array) $source_site_readiness_gate['door_lookup']
            : [];
        $source_hardware_availability_gate = ado_cd_hardware_availability_booking_gate($client_order_row);
        $source_hardware_availability_door_lookup = is_array($source_hardware_availability_gate['door_lookup'] ?? null)
            ? (array) $source_hardware_availability_gate['door_lookup']
            : [];
        $source_site_ready_for_booking = !empty($source_site_readiness_gate['is_ready_for_booking']);
        $source_hardware_ready_for_booking = !empty($source_hardware_availability_gate['is_ready_for_booking']);
        foreach (ado_cd_order_doors($client_order_row) as $source_door_row) {
            if (!is_array($source_door_row)) {
                continue;
            }
            $source_door_id = sanitize_text_field((string) ($source_door_row['door_id'] ?? ''));
            if ($source_door_id === '') {
                continue;
            }
            $source_door_label = trim((string) ($source_door_row['door_label'] ?? ('Door ' . $source_door_id)));
            $source_door_meta = trim(implode(' | ', array_filter([
                trim((string) ($source_door_row['model'] ?? '')),
                trim((string) ($source_door_row['location'] ?? '')),
            ])));
            $source_project_door_lookup[$source_door_id] = [
                'door_label' => $source_door_label,
                'door_meta' => $source_door_meta,
            ];
            $picker_key = $source_project_id . '::' . $source_door_id;
            if (!isset($booking_picker_seen[$picker_key])) {
                $booking_picker_seen[$picker_key] = true;
                $booking_picker_doors[] = [
                    'project_id' => $source_project_id,
                    'project_name' => $source_project_name,
                    'door_id' => $source_door_id,
                    'door_label' => $source_door_label,
                    'door_meta' => $source_door_meta,
                    'is_booked' => isset($source_active_booked_door_ids_map[$source_door_id]),
                    'is_site_ready_for_booking' => $source_site_ready_for_booking && isset($source_site_readiness_door_lookup[$source_door_id]),
                    'is_hardware_ready_for_booking' => $source_hardware_ready_for_booking && isset($source_hardware_availability_door_lookup[$source_door_id]),
                ];
            }
        }
        foreach (ado_cd_client_schedule_active_bookings($client_order_row) as $verified_booking_row) {
            if (!is_array($verified_booking_row)) {
                continue;
            }
            if (!ado_cd_booking_is_owned_by_client($verified_booking_row, $current_client_user_id, $current_client_user instanceof WP_User ? $current_client_user : null)) {
                continue;
            }
            $verified_booking_date = trim((string) ($verified_booking_row['schedule_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $verified_booking_date)) {
                continue;
            }
            $verified_booking_id = trim((string) ($verified_booking_row['booking_id'] ?? ''));
            $verified_door_ids = [];
            $verified_door_labels = [];
            foreach ((array) ($verified_booking_row['door_ids'] ?? []) as $verified_door_id) {
                $verified_door_id = sanitize_text_field((string) $verified_door_id);
                if ($verified_door_id === '') {
                    continue;
                }
                $verified_door_ids[] = $verified_door_id;
                $verified_door_labels[] = (string) (($source_project_door_lookup[$verified_door_id]['door_label'] ?? '') !== '' ? $source_project_door_lookup[$verified_door_id]['door_label'] : ('Door ' . $verified_door_id));
            }
            if (!$verified_door_labels) {
                continue;
            }
            $dedupe_key = $verified_booking_id !== ''
                ? ('booking:' . $verified_booking_id)
                : ('project:' . $source_project_id . '|date:' . $verified_booking_date . '|doors:' . implode(',', $verified_door_ids));
            if (isset($client_verified_booking_seen[$dedupe_key])) {
                continue;
            }
            $client_verified_booking_seen[$dedupe_key] = true;
            $client_verified_bookings_by_date[$verified_booking_date][] = [
                'booking_id' => $verified_booking_id,
                'project_id' => $source_project_id,
                'project_name' => $source_project_name,
                'door_summary' => trim(implode(', ', array_filter($verified_door_labels))),
                'is_editable_booking' => true,
            ];
        }
    }
    $booking_day_rows_for_js = [];
    $booking_day_rows_seen = [];
    foreach ($active_schedule_bookings_by_date as $booking_date => $booking_rows) {
        $booking_day_rows_for_js[$booking_date] = [];
        foreach ((array) $booking_rows as $booking_row) {
            if (!is_array($booking_row)) {
                continue;
            }
            $door_labels = [];
            foreach ((array) ($booking_row['door_ids'] ?? []) as $booking_door_id) {
                $booking_door_id = sanitize_text_field((string) $booking_door_id);
                if ($booking_door_id === '') {
                    continue;
                }
                $door_labels[] = (string) ($project_door_lookup[$booking_door_id]['door_label'] ?? ('Door ' . $booking_door_id));
            }
            if (!$door_labels) {
                continue;
            }
            $booking_id = trim((string) ($booking_row['booking_id'] ?? ''));
            $booking_dedupe_key = $booking_id !== ''
                ? ('booking:' . $booking_id)
                : ('project:' . $project_id . '|date:' . $booking_date . '|doors:' . implode(',', array_values((array) ($booking_row['door_ids'] ?? []))));
            if (isset($booking_day_rows_seen[$booking_dedupe_key])) {
                continue;
            }
            $booking_day_rows_seen[$booking_dedupe_key] = true;
            $booking_owner_id = (int) ($booking_row['created_by_client_id'] ?? 0);
            $booking_day_rows_for_js[$booking_date][] = [
                'booking_id' => $booking_id,
                'schedule_date' => trim((string) ($booking_row['schedule_date'] ?? $booking_date)),
                'door_ids' => array_values((array) ($booking_row['door_ids'] ?? [])),
                'door_labels' => $door_labels,
                'note' => trim((string) ($booking_row['note'] ?? '')),
                'created_at' => trim((string) ($booking_row['created_at'] ?? '')),
                'created_by_client_id' => $booking_owner_id,
                'created_by_name' => trim((string) ($booking_row['created_by_name'] ?? '')),
                'created_by_email' => trim((string) ($booking_row['created_by_email'] ?? '')),
                'calendar_id' => trim((string) ($booking_row['calendar_id'] ?? '')),
                'google_event_id' => trim((string) ($booking_row['google_event_id'] ?? '')),
                'google_event_link' => trim((string) ($booking_row['google_event_link'] ?? '')),
                'is_own_booking' => $booking_owner_id > 0 && $booking_owner_id === $current_client_user_id,
                'project_id' => $project_id,
                'project_name' => $project_name,
            ];
        }
    }
    foreach ($orders as $client_order_row) {
        if (!($client_order_row instanceof WC_Order)) {
            continue;
        }
        $source_project_id = (int) $client_order_row->get_id();
        $source_project_name = ado_cd_order_name($client_order_row);
        $source_project_door_lookup = [];
        foreach (ado_cd_order_doors($client_order_row) as $source_door_row) {
            if (!is_array($source_door_row)) {
                continue;
            }
            $source_door_id = sanitize_text_field((string) ($source_door_row['door_id'] ?? ''));
            if ($source_door_id === '') {
                continue;
            }
            $source_project_door_lookup[$source_door_id] = trim((string) ($source_door_row['door_label'] ?? ('Door ' . $source_door_id)));
        }
        foreach (ado_cd_client_schedule_active_bookings($client_order_row) as $booking_row) {
            if (!is_array($booking_row)) {
                continue;
            }
            if (!ado_cd_booking_is_owned_by_client($booking_row, $current_client_user_id, $current_client_user instanceof WP_User ? $current_client_user : null)) {
                continue;
            }
            $booking_date = trim((string) ($booking_row['schedule_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
                continue;
            }
            $door_ids = [];
            $door_labels = [];
            foreach ((array) ($booking_row['door_ids'] ?? []) as $booking_door_id) {
                $booking_door_id = sanitize_text_field((string) $booking_door_id);
                if ($booking_door_id === '') {
                    continue;
                }
                $door_ids[] = $booking_door_id;
                $door_labels[] = (string) ($source_project_door_lookup[$booking_door_id] ?? ('Door ' . $booking_door_id));
            }
            if (!$door_ids || !$door_labels) {
                continue;
            }
            $booking_id = trim((string) ($booking_row['booking_id'] ?? ''));
            $booking_dedupe_key = $booking_id !== ''
                ? ('booking:' . $booking_id)
                : ('project:' . $source_project_id . '|date:' . $booking_date . '|doors:' . implode(',', $door_ids));
            if (isset($booking_day_rows_seen[$booking_dedupe_key])) {
                continue;
            }
            $booking_day_rows_seen[$booking_dedupe_key] = true;
            if (!isset($booking_day_rows_for_js[$booking_date]) || !is_array($booking_day_rows_for_js[$booking_date])) {
                $booking_day_rows_for_js[$booking_date] = [];
            }
            $booking_day_rows_for_js[$booking_date][] = [
                'booking_id' => $booking_id,
                'schedule_date' => $booking_date,
                'door_ids' => $door_ids,
                'door_labels' => $door_labels,
                'note' => trim((string) ($booking_row['note'] ?? '')),
                'created_at' => trim((string) ($booking_row['created_at'] ?? '')),
                'created_by_client_id' => (int) ($booking_row['created_by_client_id'] ?? ($booking_row['created_by'] ?? 0)),
                'created_by_name' => trim((string) ($booking_row['created_by_name'] ?? '')),
                'created_by_email' => trim((string) ($booking_row['created_by_email'] ?? '')),
                'calendar_id' => trim((string) ($booking_row['calendar_id'] ?? '')),
                'google_event_id' => trim((string) ($booking_row['google_event_id'] ?? '')),
                'google_event_link' => trim((string) ($booking_row['google_event_link'] ?? '')),
                'is_own_booking' => true,
                'project_id' => $source_project_id,
                'project_name' => $source_project_name,
            ];
        }
    }
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
    $availability_window_days = ado_cd_schedule_availability_window_days();
    $availability = ado_cd_google_availability_adapter($project, $availability_window_days);
    $availability_state = (string) ($availability['state'] ?? 'fetch_error');
    $availability_slots = (array) ($availability['slots'] ?? []);
    $availability_source = trim((string) ($availability['source'] ?? ''));
    $availability_fetched_at = trim((string) ($availability['fetched_at'] ?? ''));
    $availability_message = trim((string) ($availability['message'] ?? ''));
    $availability_slots_by_date = [];
    foreach ($availability_slots as $slot_row) {
        if (!is_array($slot_row)) {
            continue;
        }
        $date_key = trim((string) ($slot_row['date_key'] ?? ''));
        if ($date_key === '') {
            $slot_start_ts = strtotime(trim((string) ($slot_row['slot_start'] ?? '')));
            if ($slot_start_ts !== false) {
                $date_key = wp_date('Y-m-d', (int) $slot_start_ts);
            }
        }
        if ($date_key === '') {
            continue;
        }
        $slot_label_raw = trim((string) ($slot_row['slot_label'] ?? ($slot_row['slot_key'] ?? '')));
        $slot_label = trim((string) preg_replace('/\s*\(.+\)$/', '', $slot_label_raw));
        $slot_time = trim((string) ($slot_row['slot_time_range'] ?? ''));
        if ($slot_time === '' && preg_match('/\(([^)]+)\)/', $slot_label_raw, $matches)) {
            $slot_time = trim((string) ($matches[1] ?? ''));
        }
        if ($slot_time === '') {
            $slot_time = trim((string) ($slot_row['slot_start'] ?? ''));
        }
        if (strtolower($slot_label) === 'available') {
            $slot_label = '';
        }
        $availability_slots_by_date[$date_key][] = [
            'label' => $slot_label,
            'time' => $slot_time,
            'slot_start' => trim((string) ($slot_row['slot_start'] ?? '')),
        ];
    }
    ksort($availability_slots_by_date);
    foreach ($availability_slots_by_date as $date_key => $slot_rows) {
        usort($slot_rows, static function (array $left, array $right): int {
            return strcmp((string) ($left['slot_start'] ?? ''), (string) ($right['slot_start'] ?? ''));
        });
        $availability_slots_by_date[$date_key] = $slot_rows;
    }
    foreach ($client_verified_bookings_by_date as $booking_date => $verified_booking_rows) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $booking_date)) {
            continue;
        }
        if (!(is_array($verified_booking_rows) && $verified_booking_rows)) {
            continue;
        }
        $own_booking_slots = [];
        foreach ($verified_booking_rows as $verified_booking_row) {
            if (!is_array($verified_booking_row)) {
                continue;
            }
            $slot_project_name = trim((string) ($verified_booking_row['project_name'] ?? ''));
            if ($slot_project_name === '') {
                $slot_project_name = 'Project #' . (string) ((int) ($verified_booking_row['project_id'] ?? 0));
            }
            $door_summary = trim((string) ($verified_booking_row['door_summary'] ?? ''));
            $booking_id = trim((string) ($verified_booking_row['booking_id'] ?? ''));
            $own_booking_slots[] = [
                'label' => $slot_project_name,
                'time' => '9:00 AM - 4:00 PM',
                'slot_start' => $booking_date . 'T09:00:00',
                'slot_key' => 'client-booking-own-' . ($booking_id !== '' ? $booking_id : md5($booking_date . '|' . $slot_project_name . '|' . $door_summary)),
                'source' => 'client_schedule_booking',
                'is_own_booking' => true,
                'is_editable_booking' => !empty($verified_booking_row['is_editable_booking']),
                'door_summary' => $door_summary,
            ];
        }
        if ($own_booking_slots) {
            $availability_slots_by_date[$booking_date] = $own_booking_slots;
        }
    }
    foreach ($active_schedule_bookings_by_date as $booking_date => $booking_rows) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $booking_date)) {
            continue;
        }
        if (!(is_array($booking_rows) && $booking_rows)) {
            continue;
        }
        if (isset($availability_slots_by_date[$booking_date]) && (array) $availability_slots_by_date[$booking_date]) {
            continue;
        }
        $availability_slots_by_date[$booking_date][] = [
            'label' => 'Booked',
            'time' => '9:00 AM - 4:00 PM',
            'slot_start' => $booking_date . 'T09:00:00',
            'slot_key' => 'client-booking-' . md5($booking_date),
            'source' => 'client_schedule_booking',
            'is_own_booking' => false,
        ];
    }
    foreach ($availability_slots_by_date as $date_key => $slot_rows) {
        usort($slot_rows, static function (array $left, array $right): int {
            return strcmp((string) ($left['slot_start'] ?? ''), (string) ($right['slot_start'] ?? ''));
        });
        $availability_slots_by_date[$date_key] = $slot_rows;
    }
    $schedule_timezone = ado_cd_schedule_timezone();
    $schedule_today_key = wp_date('Y-m-d', null, $schedule_timezone);
    $availability_calendar_months = [];
    if ($availability_slots_by_date) {
        $availability_day_keys = array_keys($availability_slots_by_date);
        sort($availability_day_keys);
        $start_month_ts = strtotime((string) reset($availability_day_keys));
        $end_month_ts = strtotime((string) end($availability_day_keys));
        if ($start_month_ts !== false && $end_month_ts !== false) {
            $month_cursor = strtotime(wp_date('Y-m-01', (int) $start_month_ts));
            $month_end = strtotime(wp_date('Y-m-01', (int) $end_month_ts));
            while ($month_cursor !== false && $month_end !== false && $month_cursor <= $month_end) {
                $month_key = wp_date('Y-m', (int) $month_cursor);
                $days_in_month = (int) wp_date('t', (int) $month_cursor);
                $first_weekday = (int) wp_date('w', (int) $month_cursor);
                $cells = [];
                for ($weekday = 0; $weekday < $first_weekday; $weekday++) {
                    $cells[] = null;
                }
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $day_key = $month_key . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                    $day_ts = strtotime($day_key . ' 12:00:00');
                    $day_is_weekday = $day_ts !== false ? ((int) wp_date('N', (int) $day_ts) <= 5) : true;
                    $day_is_past = strcmp($day_key, $schedule_today_key) < 0;
                    $cells[] = [
                        'day_number' => $day,
                        'day_key' => $day_key,
                        'is_weekday' => $day_is_weekday,
                        'is_past' => $day_is_past,
                        'slots' => (array) ($availability_slots_by_date[$day_key] ?? []),
                    ];
                }
                while (count($cells) % 7 !== 0) {
                    $cells[] = null;
                }
                $availability_calendar_months[] = [
                    'month_label' => wp_date('F Y', (int) $month_cursor),
                    'weeks' => array_chunk($cells, 7),
                ];
                $month_cursor = strtotime('+1 month', (int) $month_cursor);
            }
        }
    }

    $project_site_readiness_gate = ado_cd_site_readiness_booking_gate($project);
    $project_site_ready_lookup = is_array($project_site_readiness_gate['door_lookup'] ?? null)
        ? (array) $project_site_readiness_gate['door_lookup']
        : [];
    $project_site_reopened_lookup = is_array($project_site_readiness_gate['reopened_door_lookup'] ?? null)
        ? (array) $project_site_readiness_gate['reopened_door_lookup']
        : [];
    $normalize_project_door_lookup = static function (array $lookup): array {
        $normalized = [];
        foreach ($lookup as $lookup_key => $lookup_value) {
            $raw_id = sanitize_text_field((string) $lookup_key);
            if ($raw_id === '' && is_string($lookup_value)) {
                $raw_id = sanitize_text_field($lookup_value);
            }
            $normalized_key = strtolower(trim($raw_id));
            if ($normalized_key === '') {
                continue;
            }
            $normalized[$normalized_key] = true;
        }
        return $normalized;
    };
    $project_site_ready_lookup_normalized = $normalize_project_door_lookup($project_site_ready_lookup);
    $project_site_reopened_lookup_normalized = $normalize_project_door_lookup($project_site_reopened_lookup);
    $project_total_doors = count($project_door_lookup);
    $project_site_ready_count = 0;
    $project_site_reopened_count = 0;
    $project_completed_doors = 0;
    foreach (array_keys($project_door_lookup) as $project_door_id) {
        $project_door_key = strtolower(trim((string) $project_door_id));
        if (isset($project_site_ready_lookup[$project_door_id]) || ($project_door_key !== '' && isset($project_site_ready_lookup_normalized[$project_door_key]))) {
            $project_site_ready_count++;
        }
        if (isset($project_site_reopened_lookup[$project_door_id]) || ($project_door_key !== '' && isset($project_site_reopened_lookup_normalized[$project_door_key]))) {
            $project_site_reopened_count++;
        }
        $project_door_workflow_state = ado_cd_project_door_workflow_state($project, (string) $project_door_id);
        if (!empty($project_door_workflow_state['testing']['complete'])) {
            $project_completed_doors++;
        }
    }
    $project_site_pending_count = max(0, $project_total_doors - $project_site_ready_count);
    $project_install_progress_pct = $project_total_doors > 0
        ? (int) round(($project_completed_doors / $project_total_doors) * 100)
        : 0;
    $project_hardware_availability_gate = ado_cd_hardware_availability_booking_gate($project);
    $project_hardware_confirmed_lookup = is_array($project_hardware_availability_gate['door_lookup'] ?? null)
        ? (array) $project_hardware_availability_gate['door_lookup']
        : [];
    $project_hardware_reopened_lookup = is_array($project_hardware_availability_gate['reopened_door_lookup'] ?? null)
        ? (array) $project_hardware_availability_gate['reopened_door_lookup']
        : [];
    $project_hardware_confirmed_lookup_normalized = $normalize_project_door_lookup($project_hardware_confirmed_lookup);
    $project_hardware_reopened_lookup_normalized = $normalize_project_door_lookup($project_hardware_reopened_lookup);
    $project_hardware_confirmed_count = 0;
    $project_hardware_reopened_count = 0;
    $project_reopened_site_only_count = 0;
    $project_reopened_hardware_only_count = 0;
    $project_reopened_both_count = 0;
    foreach (array_keys($project_door_lookup) as $project_door_id) {
        $project_door_key = strtolower(trim((string) $project_door_id));
        if (isset($project_hardware_confirmed_lookup[$project_door_id]) || ($project_door_key !== '' && isset($project_hardware_confirmed_lookup_normalized[$project_door_key]))) {
            $project_hardware_confirmed_count++;
        }
        $is_site_reopened = isset($project_site_reopened_lookup[$project_door_id]) || ($project_door_key !== '' && isset($project_site_reopened_lookup_normalized[$project_door_key]));
        $is_hardware_reopened = isset($project_hardware_reopened_lookup[$project_door_id]) || ($project_door_key !== '' && isset($project_hardware_reopened_lookup_normalized[$project_door_key]));
        if ($is_hardware_reopened) {
            $project_hardware_reopened_count++;
        }
        if ($is_site_reopened && $is_hardware_reopened) {
            $project_reopened_both_count++;
        } elseif ($is_site_reopened) {
            $project_reopened_site_only_count++;
        } elseif ($is_hardware_reopened) {
            $project_reopened_hardware_only_count++;
        }
    }
    $project_hardware_pending_count = max(0, $project_total_doors - $project_hardware_confirmed_count);
    $project_reopened_total_count = $project_reopened_site_only_count + $project_reopened_hardware_only_count + $project_reopened_both_count;
    $project_upcoming_booking_count = 0;
    $project_upcoming_booked_door_lookup = [];
    foreach ($active_schedule_bookings_by_date as $booking_day_key => $booking_rows) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $booking_day_key)) {
            continue;
        }
        if (strcmp((string) $booking_day_key, $schedule_today_key) < 0) {
            continue;
        }
        foreach ((array) $booking_rows as $booking_row) {
            if (!is_array($booking_row)) {
                continue;
            }
            $project_upcoming_booking_count++;
            foreach ((array) ($booking_row['door_ids'] ?? []) as $booking_door_id_raw) {
                $booking_door_id = sanitize_text_field((string) $booking_door_id_raw);
                if ($booking_door_id !== '') {
                    $project_upcoming_booked_door_lookup[$booking_door_id] = true;
                }
            }
        }
    }
    $project_upcoming_booked_door_count = count($project_upcoming_booked_door_lookup);
    $project_next_booking_key = '';
    $booking_day_keys = array_keys($active_schedule_bookings_by_date);
    sort($booking_day_keys);
    foreach ($booking_day_keys as $booking_day_key) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $booking_day_key)) {
            continue;
        }
        if (strcmp((string) $booking_day_key, $schedule_today_key) >= 0) {
            $project_next_booking_key = (string) $booking_day_key;
            break;
        }
    }
    $project_next_booking_label = 'Not scheduled';
    if ($project_next_booking_key !== '') {
        $project_next_booking_ts = strtotime($project_next_booking_key);
        $project_next_booking_label = $project_next_booking_ts !== false
            ? wp_date('M j, Y', (int) $project_next_booking_ts)
            : $project_next_booking_key;
    }
    $project_technician_day_lookup = [];
    $project_technician_event_count = 0;
    foreach ($activity_rows as $activity_row) {
        if (!is_array($activity_row)) {
            continue;
        }
        if (sanitize_key((string) ($activity_row['actor_role'] ?? '')) !== 'technician') {
            continue;
        }
        $project_technician_event_count++;
        $activity_event_ts = (int) ($activity_row['event_ts'] ?? 0);
        if ($activity_event_ts <= 0) {
            $activity_event_ts = (int) strtotime((string) ($activity_row['occurred_at'] ?? ($activity_row['created_at'] ?? '')));
        }
        if ($activity_event_ts <= 0) {
            continue;
        }
        $project_technician_day_lookup[wp_date('Y-m-d', $activity_event_ts, $schedule_timezone)] = true;
    }
    $project_technician_days_count = count($project_technician_day_lookup);
    $project_next_availability_key = '';
    $project_next_availability_label = 'Unavailable';
    $project_next_availability_detail = $availability_message !== ''
        ? $availability_message
        : 'Live Google availability is unavailable right now.';
    if ($availability_state === 'ok') {
        $availability_window_business_days = ado_cd_schedule_business_day_rows(max(1, (int) ($availability['window_days'] ?? $availability_window_days)));
        foreach ($availability_window_business_days as $availability_window_day_row) {
            if (!is_array($availability_window_day_row)) {
                continue;
            }
            $availability_window_day_key = trim((string) ($availability_window_day_row['date_key'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $availability_window_day_key)) {
                continue;
            }
            if (strcmp($availability_window_day_key, $schedule_today_key) < 0) {
                continue;
            }
            $availability_window_day_slots = is_array($availability_slots_by_date[$availability_window_day_key] ?? null)
                ? (array) $availability_slots_by_date[$availability_window_day_key]
                : [];
            if ($availability_window_day_slots) {
                continue;
            }
            $project_next_availability_key = $availability_window_day_key;
            break;
        }
        if ($project_next_availability_key !== '') {
            $project_next_availability_ts = strtotime($project_next_availability_key);
            $project_next_availability_label = $project_next_availability_ts !== false
                ? wp_date('M j, Y', (int) $project_next_availability_ts)
                : $project_next_availability_key;
            $project_next_availability_detail = 'First weekday in the booking window that is free for booking.';
        } else {
            $project_next_availability_label = 'No open weekdays';
            $project_next_availability_detail = 'All business days in the current window already have calendar activity.';
        }
    }
    $project_health_tone = 'ok';
    $project_health_label = 'On Track';
    if ($wave_status === 'overdue' || $project_reopened_total_count > 0) {
        $project_health_tone = 'risk';
        $project_health_label = 'Needs Immediate Attention';
    } elseif ($project_site_pending_count > 0 || ($project_next_booking_key === '' && $project_completed_doors < $project_total_doors)) {
        $project_health_tone = 'warn';
        $project_health_label = 'Action Needed';
    }
    $project_priority_rows = [];
    if ($project_reopened_total_count > 0) {
        $project_reopened_scope_label = 'across site readiness and hardware availability';
        if ($project_reopened_site_only_count > 0 && $project_reopened_hardware_only_count <= 0 && $project_reopened_both_count <= 0) {
            $project_reopened_scope_label = 'for site readiness';
        } elseif ($project_reopened_hardware_only_count > 0 && $project_reopened_site_only_count <= 0 && $project_reopened_both_count <= 0) {
            $project_reopened_scope_label = 'for hardware availability';
        } elseif ($project_reopened_both_count > 0 && $project_reopened_site_only_count <= 0 && $project_reopened_hardware_only_count <= 0) {
            $project_reopened_scope_label = 'for both site readiness and hardware availability';
        }
        $project_reopened_breakdown_parts = [];
        if ($project_reopened_site_only_count > 0) {
            $project_reopened_breakdown_parts[] = $project_reopened_site_only_count . ' site readiness only';
        }
        if ($project_reopened_hardware_only_count > 0) {
            $project_reopened_breakdown_parts[] = $project_reopened_hardware_only_count . ' hardware availability only';
        }
        if ($project_reopened_both_count > 0) {
            $project_reopened_breakdown_parts[] = $project_reopened_both_count . ' both confirmations';
        }
        $project_reopened_detail = 'Open Doors to review notes and update confirmations before the next visit.';
        if ($project_reopened_breakdown_parts) {
            $project_reopened_detail = 'Breakdown: ' . implode(' | ', $project_reopened_breakdown_parts) . '. ' . $project_reopened_detail;
        }
        $project_priority_rows[] = [
            'tone' => 'risk',
            'title' => $project_reopened_total_count . ' door' . ($project_reopened_total_count === 1 ? '' : 's') . ' ' . ($project_reopened_total_count === 1 ? 'was' : 'were') . ' unconfirmed by the technician ' . $project_reopened_scope_label . '.',
            'detail' => $project_reopened_detail,
            'tab' => 'doors',
            'button_label' => 'Open Doors',
            'dismissible' => true,
            'dismiss_key' => 'tech-unconfirmed-' . $project_id . '-' . $project_reopened_site_only_count . '-' . $project_reopened_hardware_only_count . '-' . $project_reopened_both_count,
        ];
    }
    if ($project_site_pending_count > 0) {
        $project_priority_rows[] = [
            'tone' => 'warn',
            'title' => $project_site_pending_count . ' door' . ($project_site_pending_count === 1 ? '' : 's') . ' still need saved site readiness confirmation.',
            'detail' => 'Use Confirm Site Readiness to complete and save all checklist items.',
            'action' => 'open_site_readiness',
            'button_label' => 'Open Site Readiness',
        ];
    }
    if ($project_hardware_pending_count > 0) {
        $project_priority_rows[] = [
            'tone' => 'warn',
            'title' => $project_hardware_pending_count . ' door' . ($project_hardware_pending_count === 1 ? '' : 's') . ' still need saved hardware availability confirmation.',
            'detail' => 'Use Confirm Hardware Availability to confirm shipped, received, and install-ready hardware for each selected door.',
            'action' => 'open_hardware_availability',
            'button_label' => 'Open Hardware Availability',
        ];
    }
    if ($project_next_booking_key === '' && $project_completed_doors < $project_total_doors) {
        $project_priority_rows[] = [
            'tone' => 'warn',
            'title' => 'No upcoming technician visit is currently booked.',
            'detail' => 'Choose a date in Book Technician Visit to keep installation moving.',
            'action' => 'scroll_booking',
            'button_label' => 'Go to Booking',
        ];
    }
    if ($wave_status === 'overdue') {
        $project_priority_rows[] = [
            'tone' => 'risk',
            'title' => 'Invoice is overdue.',
            'detail' => 'Resolve billing to avoid installation delays.',
            'tab' => '',
        ];
    } elseif (in_array($wave_status, ['pending', 'unpaid'], true)) {
        $project_priority_rows[] = [
            'tone' => 'warn',
            'title' => 'Invoice is awaiting review/payment.',
            'detail' => 'Open Invoices to confirm billing status.',
            'tab' => '',
        ];
    }
    if (!$project_priority_rows) {
        $project_priority_rows[] = [
            'tone' => 'ok',
            'title' => 'No blockers detected.',
            'detail' => 'Continue with bookings and door progress updates as work advances.',
            'tab' => '',
        ];
    }
    $project_priority_seen = [];
    foreach ($project_priority_rows as $project_priority_row) {
        if (!is_array($project_priority_row)) {
            continue;
        }
        $project_priority_title_key = strtolower(trim((string) ($project_priority_row['title'] ?? '')));
        if ($project_priority_title_key !== '') {
            $project_priority_seen[$project_priority_title_key] = true;
        }
    }
    $project_recent_events = array_slice($activity_rows, 0, 3);

    ob_start();
    ?>
    <div class="ado-project-shell" data-project-id="<?php echo esc_attr((string) $project_id); ?>">
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
      <div class="ado-project-overview-metrics">
        <div class="ado-project-overview-metric">
          <strong>Site Readiness</strong>
          <span><?php echo esc_html($project_site_ready_count . ' / ' . $project_total_doors . ' doors confirmed'); ?></span>
          <small><?php echo esc_html($project_site_pending_count > 0 ? ($project_site_pending_count . ' pending') : 'All scoped doors confirmed'); ?></small>
        </div>
        <div class="ado-project-overview-metric">
          <strong>Hardware Availability</strong>
          <span><?php echo esc_html($project_hardware_confirmed_count . ' / ' . $project_total_doors . ' doors confirmed'); ?></span>
          <small><?php echo esc_html($project_hardware_pending_count > 0 ? ($project_hardware_pending_count . ' pending client confirmation') : 'All scoped doors have client hardware confirmation'); ?></small>
        </div>
        <div class="ado-project-overview-metric">
          <strong>Door Completion</strong>
          <span><?php echo esc_html($project_completed_doors . ' / ' . $project_total_doors . ' complete'); ?></span>
          <small><?php echo esc_html($project_install_progress_pct . '% project completion'); ?></small>
        </div>
        <div class="ado-project-overview-metric">
          <strong>Project days</strong>
          <span><?php echo esc_html((string) $project_technician_days_count); ?> day<?php echo esc_html($project_technician_days_count === 1 ? '' : 's'); ?></span>
          <small><?php echo esc_html($project_technician_event_count > 0 ? ($project_technician_event_count . ' technician event' . ($project_technician_event_count === 1 ? '' : 's') . ' logged') : 'No technician activity logged yet'); ?></small>
        </div>
        <div class="ado-project-overview-metric">
          <strong>Next visit</strong>
          <span><?php echo esc_html($project_next_booking_label); ?></span>
          <small><?php echo esc_html($project_upcoming_booking_count > 0 ? ($project_upcoming_booking_count . ' upcoming booking' . ($project_upcoming_booking_count === 1 ? '' : 's') . ' | ' . $project_upcoming_booked_door_count . ' door' . ($project_upcoming_booked_door_count === 1 ? '' : 's') . ' queued') : 'No upcoming technician day is booked for this project.'); ?></small>
        </div>
        <div class="ado-project-overview-metric">
          <strong>Next availability</strong>
          <span><?php echo esc_html($project_next_availability_label); ?></span>
          <small><?php echo esc_html($project_next_availability_detail); ?></small>
        </div>
      </div>
      <div class="ado-project-overview-layout">
        <article class="ado-card ado-project-overview-main ado-project-submittals-card">
          <div class="ado-card-head">
            <span class="ado-card-title">Readiness Submittals</span>
          </div>
          <div class="ado-project-submittals-body">
            <div class="ado-project-submittal-group">
              <div class="ado-project-submittal-group-head">
                <strong>Site Readiness</strong>
                <button class="ado-btn primary" type="button" data-site-readiness-confirm style="white-space:nowrap;">Confirm Site Readiness</button>
              </div>
              <div class="ado-site-readiness-submittal-row">
                <div class="ado-site-readiness-submittal-strip" data-site-readiness-submittal-strip>
                  <?php if ($site_readiness_submission_rows) { ?>
                      <?php foreach ($site_readiness_submission_rows as $site_readiness_submission_row) { ?>
                          <?php
                          $submission_row_id = sanitize_text_field((string) ($site_readiness_submission_row['submission_id'] ?? ''));
                          if ($submission_row_id === '') {
                              continue;
                          }
                          $submission_row_index = (int) ($site_readiness_submission_row['index'] ?? 0);
                          $submission_row_checked_items = (int) ($site_readiness_submission_row['checked_items'] ?? 0);
                          $submission_row_total_items = (int) ($site_readiness_submission_row['total_items'] ?? 0);
                          $submission_row_door_list = trim((string) ($site_readiness_submission_row['door_list'] ?? ''));
                          if ($submission_row_door_list === '') {
                              $submission_row_door_list = 'No doors selected';
                          }
                          $submission_row_updated_at = trim((string) ($site_readiness_submission_row['updated_at'] ?? ''));
                          $submission_row_label = 'Submittal ' . (string) ($submission_row_index + 1);
                          $submission_row_meta = $submission_row_checked_items . '/' . $submission_row_total_items . ' checked | ' . $submission_row_door_list;
                          if ($submission_row_updated_at !== '') {
                              $submission_row_meta .= ' | ' . $submission_row_updated_at;
                          }
                          $submission_row_is_active = $submission_row_id === $site_readiness_submission_id;
                          ?>
                  <button class="ado-site-readiness-submittal-btn <?php echo $submission_row_is_active ? 'is-active' : ''; ?>" type="button" data-site-readiness-open-submission="<?php echo esc_attr($submission_row_id); ?>">
                    <strong><?php echo esc_html($submission_row_label); ?></strong>
                    <small><?php echo esc_html($submission_row_meta); ?></small>
                  </button>
                      <?php } ?>
                  <?php } else { ?>
                  <div class="ado-site-readiness-submittal-empty">No site readiness submittals saved yet.</div>
                  <?php } ?>
                </div>
              </div>
              <script type="application/json" data-site-readiness-submissions-json><?php echo wp_json_encode($site_readiness_submission_payload); ?></script>
            </div>

            <div class="ado-project-submittal-group">
              <div class="ado-project-submittal-group-head">
                <strong>Hardware Availability</strong>
                <button class="ado-btn" type="button" data-hardware-availability-confirm style="white-space:nowrap;">Confirm Hardware Availability</button>
              </div>
              <div class="ado-site-readiness-submittal-row">
                <div class="ado-site-readiness-submittal-strip" data-hardware-availability-submittal-strip>
                  <?php if ($hardware_availability_submission_rows) { ?>
                      <?php foreach ($hardware_availability_submission_rows as $hardware_submission_row) { ?>
                          <?php
                          $hardware_submission_row_id = sanitize_text_field((string) ($hardware_submission_row['submission_id'] ?? ''));
                          if ($hardware_submission_row_id === '') {
                              continue;
                          }
                          $hardware_submission_row_index = (int) ($hardware_submission_row['index'] ?? 0);
                          $hardware_submission_checked_items = (int) ($hardware_submission_row['checked_items'] ?? 0);
                          $hardware_submission_total_items = (int) ($hardware_submission_row['total_items'] ?? 0);
                          $hardware_submission_door_list = trim((string) ($hardware_submission_row['door_list'] ?? ''));
                          if ($hardware_submission_door_list === '') {
                              $hardware_submission_door_list = 'No doors selected';
                          }
                          $hardware_submission_updated_at = trim((string) ($hardware_submission_row['updated_at'] ?? ''));
                          $hardware_submission_label = 'Hardware Submittal ' . (string) ($hardware_submission_row_index + 1);
                          $hardware_submission_meta = $hardware_submission_checked_items . '/' . $hardware_submission_total_items . ' checked | ' . $hardware_submission_door_list;
                          if ($hardware_submission_updated_at !== '') {
                              $hardware_submission_meta .= ' | ' . $hardware_submission_updated_at;
                          }
                          $hardware_submission_is_active = $hardware_submission_row_id === $hardware_availability_submission_id;
                          ?>
                  <button class="ado-site-readiness-submittal-btn <?php echo $hardware_submission_is_active ? 'is-active' : ''; ?>" type="button" data-hardware-availability-open-submission="<?php echo esc_attr($hardware_submission_row_id); ?>">
                    <strong><?php echo esc_html($hardware_submission_label); ?></strong>
                    <small><?php echo esc_html($hardware_submission_meta); ?></small>
                  </button>
                      <?php } ?>
                  <?php } else { ?>
                  <div class="ado-site-readiness-submittal-empty">No hardware availability submittals saved yet.</div>
                  <?php } ?>
                </div>
              </div>
              <script type="application/json" data-hardware-availability-submissions-json><?php echo wp_json_encode($hardware_availability_submission_payload); ?></script>
            </div>
          </div>
          <?php if ($critical_notes !== '') { ?><div class="ado-project-note"><?php echo esc_html($critical_notes); ?></div><?php } ?>
        </article>

        <article class="ado-card ado-project-overview-side">
          <div class="ado-card-head">
            <span class="ado-card-title">Priority Queue</span>
            <span class="ado-project-health-pill is-<?php echo esc_attr($project_health_tone); ?>"><?php echo esc_html($project_health_label); ?></span>
          </div>
          <ul class="ado-project-priority-list">
            <?php foreach ($project_priority_rows as $priority_row) { ?>
            <?php
            $priority_tone = sanitize_key((string) ($priority_row['tone'] ?? 'warn'));
            if (!in_array($priority_tone, ['ok', 'warn', 'risk'], true)) {
                $priority_tone = 'warn';
            }
            $priority_tab = sanitize_key((string) ($priority_row['tab'] ?? ''));
            $priority_action = sanitize_key((string) ($priority_row['action'] ?? ''));
            $priority_button_label = trim((string) ($priority_row['button_label'] ?? ''));
            $priority_dismissible = !empty($priority_row['dismissible']);
            $priority_dismiss_key = trim((string) ($priority_row['dismiss_key'] ?? ''));
            if ($priority_dismissible && $priority_dismiss_key === '') {
                $priority_dismissible = false;
            }
            if ($priority_button_label === '') {
                if ($priority_action === 'open_site_readiness') {
                    $priority_button_label = 'Open Site Readiness';
                } elseif ($priority_action === 'open_hardware_availability') {
                    $priority_button_label = 'Open Hardware Availability';
                } elseif ($priority_action === 'scroll_booking') {
                    $priority_button_label = 'Go to Booking';
                } elseif ($priority_tab !== '') {
                    $priority_button_label = 'Open ' . ucfirst($priority_tab);
                }
            }
            ?>
            <li class="is-<?php echo esc_attr($priority_tone); ?> <?php echo $priority_dismissible ? 'is-dismissible' : ''; ?>" <?php if ($priority_dismissible) { ?>data-priority-dismiss-key="<?php echo esc_attr($priority_dismiss_key); ?>"<?php } ?>>
              <?php if ($priority_dismissible) { ?>
              <button class="ado-priority-dismiss" type="button" data-priority-dismiss="<?php echo esc_attr($priority_dismiss_key); ?>" aria-label="Dismiss alert">&times;</button>
              <?php } ?>
              <strong><?php echo esc_html((string) ($priority_row['title'] ?? 'Action needed')); ?></strong>
              <small><?php echo esc_html((string) ($priority_row['detail'] ?? '')); ?></small>
              <?php if ($priority_action === 'open_site_readiness') { ?>
              <button class="ado-btn" type="button" data-overview-open-site-readiness><?php echo esc_html($priority_button_label); ?></button>
              <?php } elseif ($priority_action === 'open_hardware_availability') { ?>
              <button class="ado-btn" type="button" data-overview-open-hardware-availability><?php echo esc_html($priority_button_label); ?></button>
              <?php } elseif ($priority_action === 'scroll_booking') { ?>
              <button class="ado-btn" type="button" data-overview-scroll-booking><?php echo esc_html($priority_button_label); ?></button>
              <?php } elseif ($priority_tab !== '') { ?>
              <button class="ado-btn" type="button" data-overview-jump-tab="<?php echo esc_attr($priority_tab); ?>"><?php echo esc_html($priority_button_label); ?></button>
              <?php } ?>
            </li>
            <?php } ?>
            <?php foreach ($attention_items as $attention_item) { ?>
            <?php $attention_item_key = strtolower(trim((string) $attention_item)); if ($attention_item_key !== '' && isset($project_priority_seen[$attention_item_key])) { continue; } ?>
            <li class="is-warn">
              <strong><?php echo esc_html((string) $attention_item); ?></strong>
            </li>
            <?php } ?>
          </ul>
          <div class="ado-project-overview-side-block">
            <strong>Latest Activity</strong>
            <?php if (!$project_recent_events) { ?>
            <div class="ado-empty">No project events logged yet.</div>
            <?php } else { ?>
            <div class="ado-project-overview-event-list">
              <?php foreach ($project_recent_events as $recent_event) { ?>
              <div class="ado-project-overview-event">
                <strong><?php echo esc_html((string) ($recent_event['title'] ?? 'Update')); ?></strong>
                <small><?php echo esc_html((string) ($recent_event['created_at'] ?? '')); ?></small>
              </div>
              <?php } ?>
            </div>
            <?php } ?>
          </div>
        </article>
      </div>
      <article class="ado-card ado-schedule-request-card" data-overview-booking-card>
        <div class="ado-card-head">
          <span class="ado-card-title">Book Technician Visit</span>
        </div>
        <div class="ado-schedule-request-body">
          <?php if ($has_confirmed_visit) { ?>
          <div class="ado-schedule-confirmed"><strong>Confirmed visit:</strong> <?php echo esc_html($next_visit); ?></div>
          <?php } else { ?>
          <div class="ado-schedule-confirmed"><strong>Confirmed visit:</strong> Not scheduled</div>
          <?php } ?>

          <?php if ($availability_state !== 'ok') { ?>
          <div class="ado-schedule-note"><?php echo esc_html($availability_message !== '' ? $availability_message : 'Live Google availability is unavailable right now.'); ?></div>
          <?php } elseif (!$availability_slots) { ?>
          <div class="ado-schedule-note"><?php echo esc_html($availability_message !== '' ? $availability_message : 'No live availability was returned from Google Calendar for the current booking window.'); ?></div>
          <?php } else { ?>
          <div class="ado-schedule-note">Google Calendar events and booked doors are shown below from the assigned technician calendar mapping.</div>
          <div class="ado-schedule-calendar-wrap" data-schedule-calendar aria-hidden="true">
            <?php if (!$availability_calendar_months) { ?>
            <div class="ado-schedule-day-empty">Live slots were returned but could not be grouped into a monthly calendar view yet.</div>
            <?php } ?>
            <?php if ($availability_calendar_months) { ?>
            <div class="ado-schedule-calendar-head">
              <div class="ado-schedule-calendar-nav">
                <button class="ado-schedule-month-nav" type="button" data-calendar-nav="prev" aria-label="Previous month">&#8249;</button>
                <strong class="ado-schedule-month-title" data-calendar-current-label><?php echo esc_html((string) ($availability_calendar_months[0]['month_label'] ?? '')); ?></strong>
                <button class="ado-schedule-month-nav" type="button" data-calendar-nav="next" aria-label="Next month">&#8250;</button>
              </div>
              <span class="ado-schedule-month-sub">Google-style Month View (Read Only)</span>
            </div>
            <?php } ?>
            <?php foreach ($availability_calendar_months as $month_index => $month_row) { ?>
            <section class="ado-schedule-month" data-calendar-month data-month-label="<?php echo esc_attr((string) ($month_row['month_label'] ?? '')); ?>" <?php echo $month_index === 0 ? '' : 'hidden'; ?>>
              <div class="ado-schedule-month-scroll">
                <div class="ado-schedule-month-track">
                  <div class="ado-schedule-weekdays">
                    <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                  </div>
                  <div class="ado-schedule-month-grid">
                    <?php foreach ((array) ($month_row['weeks'] ?? []) as $week_row) { ?>
                        <?php foreach ((array) $week_row as $day_row) { ?>
                            <?php if (!is_array($day_row)) { ?>
                    <div class="ado-schedule-day is-empty" aria-hidden="true"></div>
                            <?php } else { ?>
                                <?php $day_slots = (array) ($day_row['slots'] ?? []); ?>
                                <?php
                                $day_key = trim((string) ($day_row['day_key'] ?? ''));
                                $day_is_past = !empty($day_row['is_past']);
                                $day_has_own_booking = false;
                                foreach ($day_slots as $day_slot_probe) {
                                    if (!empty($day_slot_probe['is_own_booking'])) {
                                        $day_has_own_booking = true;
                                    }
                                    if ($day_has_own_booking) {
                                        break;
                                    }
                                }
                                $day_state = $day_is_past ? 'past' : ($day_has_own_booking ? 'own-booked' : ($day_slots ? 'booked' : 'available'));
                                $day_css_state = $day_is_past ? 'is-past' : ($day_has_own_booking ? 'is-own-booked' : ($day_slots ? 'is-booked' : 'is-available'));
                                $day_is_interactive = !$day_is_past && ($day_state === 'available' || $day_has_own_booking);
                                $day_label = '';
                                if ($day_key !== '') {
                                    $day_ts = strtotime($day_key . ' 12:00:00');
                                    if ($day_ts !== false) {
                                        $day_label = wp_date('D, M j, Y', (int) $day_ts);
                                    }
                                }
                                $day_aria_label = 'Open booking panel';
                                if ($day_label !== '') {
                                    if ($day_is_past) {
                                        $day_aria_label = $day_label . ' is in the past and not bookable';
                                    } elseif ($day_state === 'booked') {
                                        $day_aria_label = $day_label . ' is booked and unavailable';
                                    } else {
                                        $day_aria_label = 'Open booking for ' . $day_label;
                                    }
                                }
                                ?>
                    <div class="ado-schedule-day <?php echo esc_attr($day_css_state); ?>" data-calendar-day data-day-key="<?php echo esc_attr($day_key); ?>" data-day-state="<?php echo esc_attr($day_state); ?>" data-day-editable="<?php echo $day_has_own_booking ? '1' : '0'; ?>" data-day-label="<?php echo esc_attr($day_label); ?>" <?php if (!$day_is_interactive) { ?>aria-disabled="true"<?php } else { ?>role="button" tabindex="0"<?php } ?> aria-label="<?php echo esc_attr($day_aria_label); ?>">
                      <div class="ado-schedule-day-number">
                        <span><?php echo esc_html((string) ($day_row['day_number'] ?? '')); ?></span>
                                <?php if ($day_slots) { ?><span class="ado-schedule-day-badge"><?php echo esc_html((string) count($day_slots)); ?></span><?php } ?>
                      </div>
                                <?php if ($day_slots) { ?>
                      <div class="ado-schedule-day-slots">
                                    <?php foreach ($day_slots as $day_slot) { ?>
                        <span class="ado-schedule-slot-chip">
                                        <?php
                                        $is_own_booking_slot = !empty($day_slot['is_own_booking']);
                                        if ($is_own_booking_slot) {
                                            $slot_title = trim((string) ($day_slot['label'] ?? ''));
                                            $door_summary = trim((string) ($day_slot['door_summary'] ?? ''));
                                            echo esc_html($slot_title !== '' ? $slot_title : $project_name);
                                            if ($door_summary !== '') {
                                                echo '<small>' . esc_html($door_summary) . '</small>';
                                            } else {
                                                echo '<small>' . esc_html__('Booked doors', 'ado-modern') . '</small>';
                                            }
                                        } else {
                                            echo esc_html__('Booked', 'ado-modern');
                                        }
                                        ?>
                        </span>
                                    <?php } ?>
                      </div>
                                <?php } else { ?>
                      <div class="ado-schedule-day-empty"><?php echo esc_html($day_is_past ? 'Past date' : 'Available to book'); ?></div>
                                <?php } ?>
                    </div>
                            <?php } ?>
                        <?php } ?>
                    <?php } ?>
                  </div>
                </div>
              </div>
            </section>
            <?php } ?>
          </div>
          <div class="ado-schedule-booking-backdrop" data-booking-backdrop hidden></div>
          <aside class="ado-schedule-booking-drawer" data-booking-drawer hidden aria-hidden="true">
            <div class="ado-schedule-booking-head">
              <div>
                <div class="ado-schedule-booking-kicker">Book Technician Visit</div>
                <div class="ado-schedule-booking-title" data-booking-day-label>Pick a day</div>
                <div class="ado-schedule-booking-sub" data-booking-day-state>Select a day in the calendar to start booking.</div>
              </div>
              <button class="ado-btn ado-schedule-booking-close" type="button" data-booking-close>Close</button>
            </div>
            <div class="ado-schedule-booking-body">
              <form class="ado-schedule-booking-form" data-booking-form>
                <input type="hidden" name="project_id" value="<?php echo esc_attr((string) $project_id); ?>">
                <input type="hidden" name="schedule_date" data-booking-date-input value="">
                <label class="ado-schedule-booking-field"><span>Selected Day</span><input type="text" data-booking-date-display value="" readonly></label>
                <div class="ado-schedule-booking-field">
                  <span>Select Doors (max 2)</span>
                  <div class="ado-schedule-door-count" data-booking-door-count>Choose up to two doors for this day.</div>
                  <div class="ado-schedule-door-toolbar">
                    <input class="ado-schedule-door-search" type="search" data-booking-door-search placeholder="Search door number, model, or location">
                    <button class="ado-btn" type="button" data-booking-door-clear hidden>Clear</button>
                  </div>
                  <div class="ado-schedule-door-selected" data-booking-door-selected hidden></div>
                  <div class="ado-schedule-door-picker-wrap">
                  <div class="ado-schedule-door-picker" data-booking-door-picker>
                    <?php foreach ($booking_picker_doors as $booking_door) { ?>
                        <?php
                        $door_option_project_id = (int) ($booking_door['project_id'] ?? $project_id);
                        $door_option_project_name = trim((string) ($booking_door['project_name'] ?? ''));
                        $door_option_id = trim((string) ($booking_door['door_id'] ?? ''));
                        if ($door_option_id === '') {
                            continue;
                        }
                        $door_option_label = trim((string) ($booking_door['door_label'] ?? ('Door ' . $door_option_id)));
                        $door_option_meta = trim((string) ($booking_door['door_meta'] ?? ''));
                        $door_option_booked = !empty($booking_door['is_booked']);
                        $door_option_site_ready = !empty($booking_door['is_site_ready_for_booking']);
                        $door_option_hardware_ready = !empty($booking_door['is_hardware_ready_for_booking']);
                        $door_option_confirmed_for_booking = $door_option_site_ready && $door_option_hardware_ready;
                        $door_option_is_current_project = $door_option_project_id === $project_id;
                        $door_option_search = strtolower(trim($door_option_id . ' ' . $door_option_label . ' ' . $door_option_meta . ' ' . $door_option_project_name));
                        $door_option_hidden = !$door_option_is_current_project || $door_option_booked;
                        $door_option_disabled = $door_option_hidden || !$door_option_confirmed_for_booking;
                        $door_option_meta_label = trim(implode(' | ', array_filter([
                            $door_option_meta,
                            $door_option_project_name !== '' ? $door_option_project_name : '',
                        ])));
                        ?>
                    <label class="ado-schedule-door-option <?php echo $door_option_hidden ? 'is-hidden ' : ''; ?><?php echo $door_option_booked ? 'is-booked ' : ''; ?><?php echo !$door_option_confirmed_for_booking ? 'is-readiness-pending' : ''; ?>" data-booking-door-option data-door-id="<?php echo esc_attr($door_option_id); ?>" data-door-project-id="<?php echo esc_attr((string) $door_option_project_id); ?>" data-door-booked="<?php echo $door_option_booked ? '1' : '0'; ?>" data-door-readiness-ready="<?php echo $door_option_site_ready ? '1' : '0'; ?>" data-door-hardware-ready="<?php echo $door_option_hardware_ready ? '1' : '0'; ?>" data-door-label="<?php echo esc_attr($door_option_label); ?>" data-door-search="<?php echo esc_attr($door_option_search); ?>">
                      <input type="checkbox" name="door_ids[]" value="<?php echo esc_attr($door_option_id); ?>" data-booking-door-input <?php echo $door_option_disabled ? 'disabled' : ''; ?>>
                      <span class="ado-schedule-door-option-copy">
                        <strong><?php echo esc_html($door_option_label); ?></strong>
                        <small><?php echo esc_html($door_option_meta_label !== '' ? $door_option_meta_label : 'Project door'); ?></small>
                      </span>
                    </label>
                    <?php } ?>
                  </div>
                  </div>
                  <div class="ado-schedule-door-empty" data-booking-door-empty <?php echo $booking_picker_doors ? 'hidden' : ''; ?>>No project doors are available to book.</div>
                </div>
                <label class="ado-schedule-booking-field" data-booking-note-field><span>Booking Note</span><textarea name="booking_note" rows="4" placeholder="Add context for the technician (optional)"></textarea></label>
                <div class="ado-schedule-booking-existing" data-booking-existing-wrap hidden>
                  <div class="ado-schedule-booking-existing-title" data-booking-existing-title>Booked doors for this day</div>
                  <div class="ado-schedule-booking-existing-list" data-booking-existing-list></div>
                </div>
                <script type="application/json" data-booking-existing-json><?php echo wp_json_encode($booking_day_rows_for_js); ?></script>
                <div class="ado-schedule-booking-actions">
                  <button class="ado-btn primary" type="submit" data-booking-submit>Book Selected Doors</button>
                  <button class="ado-btn" type="button" data-booking-close data-booking-close-action>Cancel</button>
                  <button class="ado-btn" type="button" data-booking-cancel-day hidden>Cancel Booking</button>
                </div>
                <div class="ado-schedule-booking-flash" data-booking-flash></div>
                <div class="ado-row-sub">Select up to 2 doors per day. Booked doors are hidden from future bookings until cancelled.</div>
              </form>
            </div>
          </aside>
          <div class="ado-site-readiness-backdrop" data-site-readiness-backdrop hidden></div>
          <aside class="ado-site-readiness-drawer" data-site-readiness-drawer hidden aria-hidden="true">
            <div class="ado-site-readiness-head">
              <div>
                <div class="ado-site-readiness-kicker">Site Readiness</div>
                <div class="ado-site-readiness-title">Confirm Site Readiness</div>
                <div class="ado-site-readiness-sub">Review site conditions and confirm readiness before final scheduling.</div>
              </div>
              <button class="ado-btn" type="button" data-site-readiness-close>Close</button>
            </div>
            <div class="ado-site-readiness-body">
              <form class="ado-site-readiness-form" data-site-readiness-form>
                <input type="hidden" name="project_id" value="<?php echo esc_attr((string) $project_id); ?>" data-site-readiness-project-id>
                <input type="hidden" name="submission_id" value="<?php echo esc_attr($site_readiness_submission_id); ?>" data-site-readiness-submission-id>
                <div class="ado-site-readiness-summary" data-site-readiness-summary>
                  <?php if ($site_readiness_total_count > 0) { ?>
                  <?php echo esc_html($site_readiness_confirmed_count . ' of ' . $site_readiness_total_count . ' checklist items confirmed.'); ?>
                  <?php } else { ?>
                  No site-readiness checklist items are configured yet.
                  <?php } ?>
                </div>
                <?php if ($site_readiness_updated_at !== '') { ?><div class="ado-row-sub">Last saved: <?php echo esc_html($site_readiness_updated_at); ?></div><?php } ?>
                <section class="ado-site-readiness-door-scope">
                  <div class="ado-site-readiness-step-head">
                    <div class="ado-site-readiness-step-kicker">Door Scope</div>
                    <h3>Doors This Checklist Aligns With</h3>
                    <p>Select one or more project doors. This uses the same picker pattern as the booking calendar door selector.</p>
                  </div>
                  <div class="ado-schedule-door-count" data-site-readiness-door-count>
                    <?php if ($site_readiness_total_doors > 0) { ?>
                    <?php echo esc_html($site_readiness_selected_door_count . ' of ' . $site_readiness_total_doors . ' doors selected.'); ?>
                    <?php } else { ?>
                    No project doors are available.
                    <?php } ?>
                  </div>
                  <div class="ado-schedule-door-toolbar">
                    <input class="ado-schedule-door-search" type="search" data-site-readiness-door-search placeholder="Search door number, model, or location">
                    <button class="ado-btn" type="button" data-site-readiness-door-clear hidden>Clear</button>
                  </div>
                  <div class="ado-schedule-door-selected" data-site-readiness-door-selected <?php echo $site_readiness_selected_door_count > 0 ? '' : 'hidden'; ?>></div>
                  <div class="ado-schedule-door-picker-wrap">
                    <div class="ado-schedule-door-picker" data-site-readiness-door-picker>
                      <?php foreach ($site_readiness_door_picker_rows as $site_readiness_door_picker_row) { ?>
                          <?php
                          $scope_door_id = sanitize_text_field((string) ($site_readiness_door_picker_row['door_id'] ?? ''));
                          if ($scope_door_id === '') {
                              continue;
                          }
                          $scope_door_label = trim((string) ($site_readiness_door_picker_row['door_label'] ?? ('Door ' . $scope_door_id)));
                          $scope_door_meta = trim((string) ($site_readiness_door_picker_row['door_meta'] ?? ''));
                          $scope_door_search = trim((string) ($site_readiness_door_picker_row['door_search'] ?? ''));
                          $scope_door_selected = !empty($site_readiness_door_picker_row['is_selected']);
                          $scope_door_reopened = !empty($site_readiness_door_picker_row['is_reopened']);
                          ?>
                      <label class="ado-schedule-door-option <?php echo $scope_door_selected ? 'is-selected' : ''; ?>" data-site-readiness-door-option data-door-id="<?php echo esc_attr($scope_door_id); ?>" data-door-reopened="<?php echo $scope_door_reopened ? '1' : '0'; ?>" data-door-label="<?php echo esc_attr($scope_door_label); ?>" data-door-search="<?php echo esc_attr($scope_door_search); ?>">
                        <input type="checkbox" data-site-readiness-door-input value="<?php echo esc_attr($scope_door_id); ?>" <?php checked($scope_door_selected); ?>>
                        <span class="ado-schedule-door-option-copy">
                          <strong><?php echo esc_html($scope_door_label); ?></strong>
                          <small><?php echo esc_html($scope_door_meta !== '' ? $scope_door_meta : 'Project door'); ?></small>
                        </span>
                      </label>
                      <?php } ?>
                    </div>
                  </div>
                  <div class="ado-schedule-door-empty" data-site-readiness-door-empty <?php echo $site_readiness_total_doors > 0 ? 'hidden' : ''; ?>>No project doors are available to scope this checklist.</div>
                </section>
                <?php if (!$site_readiness_sections) { ?>
                <div class="ado-empty">No site-readiness sections are configured yet.</div>
                <?php } else { ?>
                <div class="ado-site-readiness-progress" data-site-readiness-progress></div>
                <div class="ado-site-readiness-bulk-actions">
                  <button class="ado-btn" type="button" data-site-readiness-check-all>Check All Items</button>
                </div>
                <div class="ado-site-readiness-tabs">
                  <?php $readiness_step_index = 0; foreach ($site_readiness_sections as $section_key => $section_row) { $readiness_step_index++; ?>
                  <div class="ado-site-readiness-tab-row">
                    <button class="ado-site-readiness-tab <?php echo $readiness_step_index === 1 ? 'is-active' : ''; ?>" type="button" data-site-readiness-step-button data-step-index="<?php echo esc_attr((string) ($readiness_step_index - 1)); ?>">
                      <span><?php echo esc_html((string) $readiness_step_index); ?></span>
                      <small><?php echo esc_html(trim((string) ($section_row['title'] ?? ('Section ' . $readiness_step_index)))); ?></small>
                    </button>
                    <label class="ado-site-readiness-tab-toggle" title="Check or uncheck all items in this section">
                      <input type="checkbox" data-site-readiness-section-toggle data-step-index="<?php echo esc_attr((string) ($readiness_step_index - 1)); ?>">
                      <span>All</span>
                    </label>
                  </div>
                  <?php } ?>
                </div>
                <div class="ado-site-readiness-steps">
                  <?php $readiness_step_index = 0; foreach ($site_readiness_sections as $section_key => $section_row) { $readiness_step_index++; ?>
                      <?php
                      $section_title = trim((string) ($section_row['title'] ?? ('Section ' . $readiness_step_index)));
                      $section_purpose = trim((string) ($section_row['purpose'] ?? ''));
                      $section_items = is_array($section_row['items'] ?? null) ? (array) $section_row['items'] : [];
                      $section_state = is_array($site_readiness_state['sections'][$section_key] ?? null) ? (array) $site_readiness_state['sections'][$section_key] : [];
                      $section_item_state = is_array($section_state['items'] ?? null) ? (array) $section_state['items'] : [];
                      $section_note = trim((string) ($section_state['note'] ?? ''));
                      ?>
                  <section class="ado-site-readiness-step" data-site-readiness-step data-section-key="<?php echo esc_attr((string) $section_key); ?>" <?php echo $readiness_step_index === 1 ? '' : 'hidden'; ?>>
                    <div class="ado-site-readiness-step-head">
                      <div class="ado-site-readiness-step-kicker">Section <?php echo esc_html((string) $readiness_step_index); ?> of <?php echo esc_html((string) $site_readiness_section_count); ?></div>
                      <h3><?php echo esc_html($section_title); ?></h3>
                      <?php if ($section_purpose !== '') { ?><p><?php echo esc_html($section_purpose); ?></p><?php } ?>
                    </div>
                    <div class="ado-site-readiness-checklist">
                      <?php foreach ($section_items as $item_key => $item_label) { ?>
                      <label class="ado-site-readiness-checklist-item">
                        <input type="checkbox" data-site-readiness-item data-item-key="<?php echo esc_attr((string) $item_key); ?>" <?php checked(!empty($section_item_state[$item_key])); ?>>
                        <span><?php echo esc_html((string) $item_label); ?></span>
                      </label>
                      <?php } ?>
                    </div>
                    <label class="ado-site-readiness-field">
                      <span>Section Note (Optional)</span>
                      <textarea rows="3" data-site-readiness-section-note placeholder="Add notes for this checklist section."><?php echo esc_textarea($section_note); ?></textarea>
                    </label>
                  </section>
                  <?php } ?>
                </div>
                <div class="ado-site-readiness-nav">
                  <button class="ado-btn" type="button" data-site-readiness-prev>Previous Section</button>
                  <button class="ado-btn" type="button" data-site-readiness-next>Next Section</button>
                </div>
                <?php } ?>
                <div class="ado-site-readiness-actions">
                  <button class="ado-btn primary" type="submit" data-site-readiness-save>Save Site Readiness</button>
                  <button class="ado-btn" type="button" data-site-readiness-close>Close</button>
                </div>
                <div class="ado-site-readiness-flash" data-site-readiness-flash aria-live="polite"></div>
                <div class="ado-row-sub">Complete each section checklist, then save to persist this project readiness review.</div>
              </form>
            </div>
          </aside>
          <div class="ado-site-readiness-backdrop" data-hardware-availability-backdrop hidden></div>
          <aside class="ado-site-readiness-drawer" data-hardware-availability-drawer hidden aria-hidden="true">
            <div class="ado-site-readiness-head">
              <div>
                <div class="ado-site-readiness-kicker">Hardware Availability</div>
                <div class="ado-site-readiness-title">Confirm Hardware Availability</div>
                <div class="ado-site-readiness-sub">Confirm all hardware for selected doors has shipped, arrived on site, and is ready for technician installation.</div>
              </div>
              <button class="ado-btn" type="button" data-hardware-availability-close>Close</button>
            </div>
            <div class="ado-site-readiness-body">
              <form class="ado-site-readiness-form" data-hardware-availability-form>
                <input type="hidden" name="project_id" value="<?php echo esc_attr((string) $project_id); ?>" data-hardware-availability-project-id>
                <input type="hidden" name="submission_id" value="<?php echo esc_attr($hardware_availability_submission_id); ?>" data-hardware-availability-submission-id>
                <div class="ado-site-readiness-summary" data-hardware-availability-summary>
                  <?php if ($hardware_availability_total_count > 0) { ?>
                  <?php echo esc_html($hardware_availability_confirmed_count . ' of ' . $hardware_availability_total_count . ' checklist items confirmed.'); ?>
                  <?php } else { ?>
                  No hardware-availability checklist items are configured yet.
                  <?php } ?>
                </div>
                <?php if ($hardware_availability_updated_at !== '') { ?><div class="ado-row-sub">Last saved: <?php echo esc_html($hardware_availability_updated_at); ?></div><?php } ?>
                <section class="ado-site-readiness-door-scope">
                  <div class="ado-site-readiness-step-head">
                    <div class="ado-site-readiness-step-kicker">Door Scope</div>
                    <h3>Doors This Hardware Confirmation Aligns With</h3>
                    <p>Select one or more project doors. This uses the same picker pattern as the booking calendar door selector.</p>
                  </div>
                  <div class="ado-schedule-door-count" data-hardware-availability-door-count>
                    <?php if ($hardware_availability_total_doors > 0) { ?>
                    <?php echo esc_html($hardware_availability_selected_door_count . ' of ' . $hardware_availability_total_doors . ' doors selected.'); ?>
                    <?php } else { ?>
                    No project doors are available.
                    <?php } ?>
                  </div>
                  <div class="ado-schedule-door-toolbar">
                    <input class="ado-schedule-door-search" type="search" data-hardware-availability-door-search placeholder="Search door number, model, or location">
                    <button class="ado-btn" type="button" data-hardware-availability-door-clear hidden>Clear</button>
                  </div>
                  <div class="ado-schedule-door-selected" data-hardware-availability-door-selected <?php echo $hardware_availability_selected_door_count > 0 ? '' : 'hidden'; ?>></div>
                  <div class="ado-schedule-door-picker-wrap">
                    <div class="ado-schedule-door-picker" data-hardware-availability-door-picker>
                      <?php foreach ($hardware_availability_door_picker_rows as $hardware_door_picker_row) { ?>
                          <?php
                          $hardware_scope_door_id = sanitize_text_field((string) ($hardware_door_picker_row['door_id'] ?? ''));
                          if ($hardware_scope_door_id === '') {
                              continue;
                          }
                          $hardware_scope_door_label = trim((string) ($hardware_door_picker_row['door_label'] ?? ('Door ' . $hardware_scope_door_id)));
                          $hardware_scope_door_meta = trim((string) ($hardware_door_picker_row['door_meta'] ?? ''));
                          $hardware_scope_door_search = trim((string) ($hardware_door_picker_row['door_search'] ?? ''));
                          $hardware_scope_door_selected = !empty($hardware_door_picker_row['is_selected']);
                          $hardware_scope_door_reopened = !empty($hardware_door_picker_row['is_reopened']);
                          ?>
                      <label class="ado-schedule-door-option <?php echo $hardware_scope_door_selected ? 'is-selected' : ''; ?>" data-hardware-availability-door-option data-door-id="<?php echo esc_attr($hardware_scope_door_id); ?>" data-door-reopened="<?php echo $hardware_scope_door_reopened ? '1' : '0'; ?>" data-door-label="<?php echo esc_attr($hardware_scope_door_label); ?>" data-door-search="<?php echo esc_attr($hardware_scope_door_search); ?>">
                        <input type="checkbox" data-hardware-availability-door-input value="<?php echo esc_attr($hardware_scope_door_id); ?>" <?php checked($hardware_scope_door_selected); ?>>
                        <span class="ado-schedule-door-option-copy">
                          <strong><?php echo esc_html($hardware_scope_door_label); ?></strong>
                          <small><?php echo esc_html($hardware_scope_door_meta !== '' ? $hardware_scope_door_meta : 'Project door'); ?></small>
                        </span>
                      </label>
                      <?php } ?>
                    </div>
                  </div>
                  <div class="ado-schedule-door-empty" data-hardware-availability-door-empty <?php echo $hardware_availability_total_doors > 0 ? 'hidden' : ''; ?>>No project doors are available to scope this checklist.</div>
                </section>
                <?php if (!$hardware_availability_sections) { ?>
                <div class="ado-empty">No hardware-availability sections are configured yet.</div>
                <?php } else { ?>
                <div class="ado-site-readiness-progress" data-hardware-availability-progress></div>
                <div class="ado-site-readiness-bulk-actions">
                  <button class="ado-btn" type="button" data-hardware-availability-check-all>Check All Items</button>
                </div>
                <div class="ado-site-readiness-tabs">
                  <?php $hardware_step_index = 0; foreach ($hardware_availability_sections as $hardware_section_key => $hardware_section_row) { $hardware_step_index++; ?>
                  <div class="ado-site-readiness-tab-row">
                    <button class="ado-site-readiness-tab <?php echo $hardware_step_index === 1 ? 'is-active' : ''; ?>" type="button" data-hardware-availability-step-button data-step-index="<?php echo esc_attr((string) ($hardware_step_index - 1)); ?>">
                      <span><?php echo esc_html((string) $hardware_step_index); ?></span>
                      <small><?php echo esc_html(trim((string) ($hardware_section_row['title'] ?? ('Section ' . $hardware_step_index)))); ?></small>
                    </button>
                    <label class="ado-site-readiness-tab-toggle" title="Check or uncheck all items in this section">
                      <input type="checkbox" data-hardware-availability-section-toggle data-step-index="<?php echo esc_attr((string) ($hardware_step_index - 1)); ?>">
                      <span>All</span>
                    </label>
                  </div>
                  <?php } ?>
                </div>
                <div class="ado-site-readiness-steps">
                  <?php $hardware_step_index = 0; foreach ($hardware_availability_sections as $hardware_section_key => $hardware_section_row) { $hardware_step_index++; ?>
                      <?php
                      $hardware_section_title = trim((string) ($hardware_section_row['title'] ?? ('Section ' . $hardware_step_index)));
                      $hardware_section_purpose = trim((string) ($hardware_section_row['purpose'] ?? ''));
                      $hardware_section_items = is_array($hardware_section_row['items'] ?? null) ? (array) $hardware_section_row['items'] : [];
                      $hardware_section_state = is_array($hardware_availability_state['sections'][$hardware_section_key] ?? null) ? (array) $hardware_availability_state['sections'][$hardware_section_key] : [];
                      $hardware_section_item_state = is_array($hardware_section_state['items'] ?? null) ? (array) $hardware_section_state['items'] : [];
                      $hardware_section_note = trim((string) ($hardware_section_state['note'] ?? ''));
                      ?>
                  <section class="ado-site-readiness-step" data-hardware-availability-step data-section-key="<?php echo esc_attr((string) $hardware_section_key); ?>" <?php echo $hardware_step_index === 1 ? '' : 'hidden'; ?>>
                    <div class="ado-site-readiness-step-head">
                      <div class="ado-site-readiness-step-kicker">Section <?php echo esc_html((string) $hardware_step_index); ?> of <?php echo esc_html((string) $hardware_availability_section_count); ?></div>
                      <h3><?php echo esc_html($hardware_section_title); ?></h3>
                      <?php if ($hardware_section_purpose !== '') { ?><p><?php echo esc_html($hardware_section_purpose); ?></p><?php } ?>
                    </div>
                    <div class="ado-site-readiness-checklist">
                      <?php foreach ($hardware_section_items as $hardware_item_key => $hardware_item_label) { ?>
                      <label class="ado-site-readiness-checklist-item">
                        <input type="checkbox" data-hardware-availability-item data-item-key="<?php echo esc_attr((string) $hardware_item_key); ?>" <?php checked(!empty($hardware_section_item_state[$hardware_item_key])); ?>>
                        <span><?php echo esc_html((string) $hardware_item_label); ?></span>
                      </label>
                      <?php } ?>
                    </div>
                    <label class="ado-site-readiness-field">
                      <span>Section Note (Optional)</span>
                      <textarea rows="3" data-hardware-availability-section-note placeholder="Add notes for this checklist section."><?php echo esc_textarea($hardware_section_note); ?></textarea>
                    </label>
                  </section>
                  <?php } ?>
                </div>
                <div class="ado-site-readiness-nav">
                  <button class="ado-btn" type="button" data-hardware-availability-prev>Previous Section</button>
                  <button class="ado-btn" type="button" data-hardware-availability-next>Next Section</button>
                </div>
                <?php } ?>
                <div class="ado-site-readiness-actions">
                  <button class="ado-btn primary" type="submit" data-hardware-availability-save>Save Hardware Availability</button>
                  <button class="ado-btn" type="button" data-hardware-availability-close>Close</button>
                </div>
                <div class="ado-site-readiness-flash" data-hardware-availability-flash aria-live="polite"></div>
                <div class="ado-row-sub">Complete each section checklist, then save to confirm hardware for selected doors is on site and ready for technician installation.</div>
              </form>
            </div>
          </aside>
          <div class="ado-schedule-actions">
            <span class="ado-row-sub">Click any day in the calendar to open the booking card for that date. Gray days are already booked.</span>
            <span class="ado-row-sub">Availability source: <?php echo esc_html($availability_source !== '' ? $availability_source : 'google_freebusy'); ?><?php if ($availability_fetched_at !== '') { ?> | Fetched: <?php echo esc_html($availability_fetched_at); ?><?php } ?></span>
          </div>
          <?php } ?>
          <?php if ($availability_source !== '' || $availability_fetched_at !== '') { ?>
          <div class="ado-row-sub">Availability state: <?php echo esc_html($availability_state); ?><?php if ($availability_source !== '') { ?> | Source: <?php echo esc_html($availability_source); ?><?php } ?><?php if ($availability_fetched_at !== '') { ?> | Fetched: <?php echo esc_html($availability_fetched_at); ?><?php } ?></div>
          <?php } ?>
        </div>
      </article>
      </div>

      <article class="ado-card ado-project-tab-panel <?php echo $initial_tab === 'activity' ? 'is-active' : ''; ?>" data-tab-panel="activity" <?php echo $initial_tab === 'activity' ? '' : 'hidden'; ?>>
        <div class="ado-card-head"><span class="ado-card-title">Project Timeline</span></div>
        <?php if (!$activity_rows) { ?>
        <div class="ado-empty">No project events have been logged yet.</div>
        <?php } else { ?>
        <ol class="ado-project-timeline">
          <?php foreach ($activity_rows as $activity) { ?>
          <?php
          $activity_category = sanitize_key((string) ($activity['category'] ?? 'general'));
          $activity_category_label = trim((string) ($activity['category_label'] ?? ucwords(str_replace('_', ' ', $activity_category))));
          if ($activity_category_label === '') {
              $activity_category_label = 'Update';
          }
          $activity_action_label = trim((string) ($activity['action_label'] ?? ''));
          $activity_actor = trim((string) ($activity['actor_name'] ?? ''));
          $activity_door_labels = is_array($activity['door_labels'] ?? null) ? array_values(array_filter(array_map('strval', (array) $activity['door_labels']))) : [];
          $activity_details = is_array($activity['details'] ?? null) ? array_values(array_filter(array_map('strval', (array) $activity['details']))) : [];
          ?>
          <li class="ado-project-timeline-item is-<?php echo esc_attr($activity_category); ?>">
            <span class="ado-project-timeline-dot" aria-hidden="true"></span>
            <article class="ado-project-timeline-card">
              <div class="ado-project-timeline-head">
                <strong><?php echo esc_html((string) ($activity['title'] ?? 'Update')); ?></strong>
                <?php if (!empty($activity['created_at'])) { ?><small><?php echo esc_html((string) $activity['created_at']); ?></small><?php } ?>
              </div>
              <?php if (!empty($activity['summary'])) { ?><p class="ado-row-sub"><?php echo esc_html((string) $activity['summary']); ?></p><?php } ?>
              <div class="ado-project-timeline-meta">
                <span class="ado-pill ok"><?php echo esc_html($activity_category_label); ?></span>
                <?php if ($activity_action_label !== '') { ?><span class="ado-row-sub"><?php echo esc_html($activity_action_label); ?></span><?php } ?>
                <?php if ($activity_actor !== '') { ?><span class="ado-row-sub">By <?php echo esc_html($activity_actor); ?></span><?php } ?>
              </div>
              <?php if ($activity_door_labels) { ?>
              <div class="ado-row-sub">Doors: <?php echo esc_html(implode(', ', $activity_door_labels)); ?></div>
              <?php } ?>
              <?php if ($activity_details) { ?>
              <ul class="ado-project-timeline-detail-list">
                <?php foreach ($activity_details as $activity_detail) { ?>
                <li><?php echo esc_html($activity_detail); ?></li>
                <?php } ?>
              </ul>
              <?php } ?>
            </article>
          </li>
          <?php } ?>
        </ol>
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
    echo '<div class="ado-table-wrap"><table class="ado-table"><thead><tr><th>Invoice</th><th>Project</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>';
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
    echo '</tbody></table></div>';
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
    $show_top_header = $view !== 'projects';
    ob_start();
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap');
    .ado-app{--bg:#f4f5f7;--surface:#fff;--surface-2:#f9fafb;--border:#e8eaed;--accent:#1a56db;--warn:#e3a008;--danger:#e02424;--text-primary:#111928;--text-secondary:#6b7280;--text-muted:#9ca3af;--shadow-sm:0 1px 3px rgba(0,0,0,0.06),0 1px 2px rgba(0,0,0,0.04);--radius:14px;--radius-sm:8px;font-family:'DM Sans',sans-serif;background:var(--bg);display:flex;min-height:100vh;color:var(--text-primary)}
    .ado-app [hidden]{display:none!important}
    .ado-app *{box-sizing:border-box}.ado-side{width:256px;background:var(--text-primary);min-height:100vh;position:sticky;top:0}.ado-side-logo{padding:28px 24px 24px;border-bottom:1px solid rgba(255,255,255,.08);font-family:'Syne',sans-serif;font-weight:800;font-size:20px;color:#fff}.ado-side-logo span{color:var(--accent)}.ado-nav{padding:16px 12px;display:flex;flex-direction:column;gap:2px}.ado-nav-label{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:12px 12px 6px;margin-top:8px}.ado-nav a{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.6);font-size:14px;font-weight:500;text-decoration:none}.ado-nav a:hover{background:rgba(255,255,255,.07);color:#fff}.ado-nav a.active{background:var(--accent);color:#fff}.ado-nav-badge{font-size:10px;font-weight:700;background:var(--danger);padding:2px 6px;border-radius:999px;color:#fff}.ado-nav-project{align-items:flex-start;gap:8px}.ado-nav-project-copy{display:block;min-width:0;flex:1}.ado-nav-project-name{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ado-nav-project-meta{display:block;margin-top:2px;font-size:11px;line-height:1.3;color:rgba(255,255,255,.42);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ado-nav-project.active .ado-nav-project-meta{color:rgba(255,255,255,.85)}
    .ado-main{flex:1;display:flex;flex-direction:column}.ado-top{background:var(--surface);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:20}.ado-top h1{margin:0;font-family:'Syne',sans-serif;font-size:20px}.ado-top-right{display:flex;gap:10px}.ado-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 16px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;cursor:pointer}.ado-btn.primary{background:var(--accent);border-color:transparent;color:#fff}.ado-content{padding:28px}
    .ado-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm)}.ado-card-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}.ado-card-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700}.ado-card-link{font-size:12px;font-weight:600;text-decoration:none;color:var(--accent)}.ado-empty{padding:24px 18px;font-size:13px;color:var(--text-muted)}.ado-table{width:100%;border-collapse:collapse}.ado-table th{padding:10px 18px;text-align:left;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;color:var(--text-muted)}.ado-table td{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px}
    .ado-list{list-style:none;margin:0;padding:0}.ado-list li{padding:14px 18px;border-bottom:1px solid var(--border)}.ado-row-title{font-weight:700}.ado-row-sub{font-size:12px;color:var(--text-muted)}.ado-pill{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:999px}.ado-pill.high{background:#fffbeb;color:#92400e}.ado-pill.ok{background:#f0fdf4;color:#065f46}.ado-pill.critical{background:#fef2f2;color:#e02424}.ado-action-row{display:flex;gap:6px}.ado-action-row .ado-btn{padding:6px 10px;font-size:12px}
    .ado-flash{display:none;margin:10px 18px 16px;padding:10px 12px;border-radius:8px;font-size:12px}.ado-flash.ok{display:block;background:#ecfdf3;color:#027a48}.ado-flash.err{display:block;background:#fef2f2;color:#b42318}
    .ado-project-shell{display:flex;flex-direction:column;gap:12px}.ado-project-header-card{padding:16px 18px}.ado-project-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}.ado-project-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800}.ado-project-sub{margin-top:4px;font-size:13px;color:var(--text-secondary)}.ado-project-meta-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.ado-project-pill{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;background:var(--surface-2);border:1px solid var(--border);color:var(--text-secondary)}.ado-project-pill.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}.ado-project-pill.danger{background:#fef2f2;border-color:#fecaca;color:#b91c1c}.ado-project-pill.ok{background:#ecfdf3;border-color:#bbf7d0;color:#166534}.ado-project-actions{display:flex;gap:8px;flex-wrap:wrap}.ado-project-tab-strip{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ado-project-tab{display:inline-flex;padding:6px 12px;border-radius:999px;border:1px solid var(--border);background:var(--surface);color:var(--text-secondary);font-size:12px;font-weight:600;text-decoration:none;appearance:none;cursor:pointer}.ado-project-tab.active{background:#e8eefc;color:var(--accent);border-color:#bfd0f5}.ado-project-tab-dot{font-size:14px;line-height:1;color:#9ca3af}.ado-project-tab-panel{display:block}.ado-project-tab-panel[hidden]{display:none!important}.ado-project-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:12px}.ado-project-summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:14px}.ado-project-kv{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2)}.ado-project-kv strong{display:block;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted)}.ado-project-kv span{display:block;margin-top:4px;font-size:13px;color:var(--text-primary)}.ado-project-note{margin:0 14px 14px;padding:10px 12px;border-radius:10px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e;font-size:12px;line-height:1.45}.ado-project-attention-list{list-style:none;margin:0;padding:12px 14px;display:flex;flex-direction:column;gap:8px}.ado-project-attention-list li{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:13px;color:var(--text-primary)}.ado-project-activity-list{list-style:none;margin:0;padding:0}.ado-project-activity-list li{padding:14px 16px;border-bottom:1px solid var(--border)}.ado-project-activity-list li:last-child{border-bottom:none}.ado-project-activity-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.ado-project-activity-head small{font-size:11px;color:var(--text-muted)}.ado-project-activity-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px}.ado-project-file-list{display:flex;flex-direction:column;gap:8px;padding:14px}.ado-project-file-link{display:block;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);text-decoration:none}.ado-project-file-link strong{display:block;color:var(--text-primary)}.ado-project-file-link small{display:block;margin-top:3px;color:var(--text-muted);font-size:12px}.ado-project-door-list{display:flex;flex-direction:column;gap:8px;padding:14px}.ado-project-door-trigger{display:block;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);text-decoration:none;color:var(--text-primary)}.ado-project-door-trigger strong{display:block;font-size:14px}.ado-project-door-trigger small{display:block;margin-top:3px;color:var(--text-muted)}.ado-project-door-trigger.active{border-color:#bfd0f5;background:#e8eefc}.ado-client-door-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.55);opacity:0;pointer-events:none;transition:opacity .16s ease;z-index:99970}.ado-client-door-backdrop.is-open{opacity:1;pointer-events:auto}.ado-client-door-drawer{position:fixed;top:0;right:0;bottom:0;width:46vw;background:var(--surface);border-left:1px solid var(--border);box-shadow:-18px 0 42px rgba(2,6,23,.18);z-index:99971;transform:translateX(100%);transition:transform .2s ease;display:flex;flex-direction:column}.ado-client-door-drawer.is-open{transform:translateX(0)}body.admin-bar .ado-client-door-backdrop{top:32px}body.admin-bar .ado-client-door-drawer{top:32px}body.ado-client-door-open{overflow:hidden}.ado-client-door-drawer-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ado-client-door-drawer-kicker{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#9ca3af}.ado-client-door-drawer-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-top:2px}.ado-client-door-drawer-sub{font-size:12px;color:var(--text-muted);margin-top:3px}.ado-client-door-close{padding:6px 10px;font-size:12px}.ado-client-door-drawer-body{padding:14px;overflow:auto;display:flex;flex-direction:column;gap:12px;flex:1}.ado-client-door-panel{display:flex;flex-direction:column;gap:12px}.ado-client-door-panel-head{display:flex;flex-direction:column;gap:4px}.ado-client-door-panel-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800}.ado-client-door-panel-sub{font-size:12px;color:var(--text-muted)}.ado-client-door-form{display:flex;flex-direction:column;gap:10px}.ado-client-door-check{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:13px}.ado-client-door-check input{margin:0}.ado-client-door-field{display:block}.ado-client-door-field span{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px}.ado-client-door-field textarea,.ado-client-door-field input[type="file"]{width:100%;background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:9px 10px;font-size:13px}.ado-client-door-field textarea{resize:vertical;min-height:90px}.ado-client-door-doc-list{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2)}.ado-client-door-doc-list strong{display:block;font-size:12px;margin-bottom:8px}.ado-client-door-doc-list a{display:block;padding:7px 8px;border:1px solid var(--border);border-radius:8px;text-decoration:none;background:#fff;margin-bottom:6px}.ado-client-door-doc-list a:last-child{margin-bottom:0}.ado-client-door-doc-list span{display:block;color:var(--text-primary);font-size:13px}.ado-client-door-doc-list small{display:block;color:var(--text-muted);font-size:11px;margin-top:2px}.ado-client-door-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ado-client-door-flash{display:none;padding:8px 10px;border-radius:8px;font-size:12px}.ado-client-door-flash.ok{display:block;background:#ecfdf3;color:#027a48}.ado-client-door-flash.err{display:block;background:#fef2f2;color:#b42318}
    .ado-schedule-request-body{padding:14px;display:flex;flex-direction:column;gap:12px}.ado-schedule-confirmed{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:13px;color:var(--text-primary)}.ado-schedule-note{padding:10px;border:1px solid #fde68a;border-radius:10px;background:#fffbeb;color:#92400e;font-size:13px}.ado-schedule-slot-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ado-schedule-slot-option{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);opacity:.72;cursor:not-allowed}.ado-schedule-slot-option input{margin-top:2px}.ado-schedule-slot-copy{display:flex;flex-direction:column;gap:4px}.ado-schedule-slot-copy strong{font-size:13px;color:var(--text-primary)}.ado-schedule-slot-copy small{font-size:12px;color:var(--text-secondary)}.ado-schedule-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ado-schedule-actions .ado-btn[disabled]{opacity:.55;cursor:not-allowed}
    .ado-schedule-calendar-wrap{display:flex;flex-direction:column;gap:12px}.ado-schedule-calendar-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:2px 2px 0}.ado-schedule-calendar-nav{display:flex;align-items:center;gap:8px}.ado-schedule-month-nav{width:28px;height:28px;border:1px solid var(--border);border-radius:999px;background:#fff;color:#334155;font-size:18px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.ado-schedule-month-nav[disabled]{opacity:.4;cursor:not-allowed}.ado-schedule-month-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;min-width:120px}.ado-schedule-month-sub{font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted)}.ado-schedule-month{border:1px solid var(--border);border-radius:12px;background:#fff;overflow:hidden}.ado-schedule-month-scroll{overflow-x:auto}.ado-schedule-month-track{min-width:720px}.ado-schedule-weekdays,.ado-schedule-month-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}.ado-schedule-weekdays div{padding:7px 8px;border-bottom:1px solid var(--border);background:#fbfcff;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted)}.ado-schedule-day{min-height:108px;padding:8px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);background:#fff;display:flex;flex-direction:column;gap:7px}.ado-schedule-day:nth-child(7n){border-right:none}.ado-schedule-day.is-empty{background:#fafbfc}.ado-schedule-day.is-past{background:#f3f4f6}.ado-schedule-day.is-booked{background:#f1f5f9}.ado-schedule-day.is-own-booked{background:#eef6ff}.ado-schedule-day.is-available{background:#fff}.ado-schedule-day.is-past .ado-schedule-day-empty{color:#94a3b8}.ado-schedule-day-number{display:flex;align-items:center;justify-content:space-between;font-size:12px;font-weight:700;color:var(--text-secondary)}.ado-schedule-day-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#e8f0fe;color:#1a56db;font-size:10px;font-weight:700}.ado-schedule-day-slots{display:flex;flex-direction:column;gap:4px}.ado-schedule-slot-chip{display:block;padding:4px 6px;border-radius:7px;background:#dbeafe;color:#1e3a8a;font-size:11px;line-height:1.2;font-weight:700}.ado-schedule-slot-chip small{display:block;margin-top:1px;color:#1d4ed8;font-size:10px;font-weight:600;opacity:.88}.ado-schedule-day-empty{margin-top:auto;font-size:11px;color:#9ca3af}.ado-schedule-day[data-calendar-day]{cursor:pointer;transition:box-shadow .16s ease,transform .16s ease}.ado-schedule-day.is-past[data-calendar-day],.ado-schedule-day.is-booked[data-calendar-day]{cursor:not-allowed}.ado-schedule-day[data-calendar-day]:hover:not(.is-past):not(.is-booked){box-shadow:inset 0 0 0 1px #bfdbfe}.ado-schedule-day[data-calendar-day]:focus-visible{outline:2px solid #2563eb;outline-offset:-2px}.ado-schedule-day.is-selected{box-shadow:inset 0 0 0 2px #2563eb}.ado-schedule-booking-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .16s ease;z-index:99960}.ado-schedule-booking-backdrop.is-open{opacity:1;pointer-events:auto}.ado-schedule-booking-drawer{position:fixed;top:0;right:0;bottom:0;width:min(420px,100vw);background:#fff;border-left:1px solid var(--border);box-shadow:-18px 0 42px rgba(2,6,23,.18);z-index:99961;transform:translateX(100%);transition:transform .2s ease;display:flex;flex-direction:column}.ado-schedule-booking-drawer.is-open{transform:translateX(0)}body.admin-bar .ado-schedule-booking-backdrop{top:32px}body.admin-bar .ado-schedule-booking-drawer{top:32px}body.ado-schedule-booking-open{overflow:hidden}.ado-schedule-booking-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ado-schedule-booking-kicker{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#9ca3af}.ado-schedule-booking-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;margin-top:2px}.ado-schedule-booking-sub{font-size:12px;color:var(--text-muted);margin-top:3px}.ado-schedule-booking-body{padding:14px;overflow:auto;display:flex;flex-direction:column;gap:12px;flex:1}.ado-schedule-booking-form{display:flex;flex-direction:column;gap:10px}.ado-schedule-booking-field{display:block}.ado-schedule-booking-field span{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px}.ado-schedule-booking-field input,.ado-schedule-booking-field select,.ado-schedule-booking-field textarea{width:100%;background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:9px 10px;font-size:13px}.ado-schedule-booking-field input[readonly]{background:#f8fafc;color:var(--text-secondary)}.ado-schedule-booking-field textarea{resize:vertical;min-height:90px}.ado-schedule-door-count{font-size:12px;color:var(--text-muted);margin-bottom:6px}.ado-schedule-door-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:8px}.ado-schedule-door-search{flex:1;min-width:0;background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:8px 10px;font-size:13px}.ado-schedule-door-search:focus{outline:none;border-color:#93c5fd;box-shadow:0 0 0 2px rgba(37,99,235,.14)}.ado-schedule-door-selected{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}.ado-schedule-door-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:11px;font-weight:700}.ado-schedule-door-chip button{all:unset;cursor:pointer;line-height:1;font-size:12px;color:#1d4ed8}.ado-schedule-door-picker-wrap{border:1px solid var(--border);border-radius:10px;background:#fff;max-height:260px;overflow:auto;padding:8px}.ado-schedule-door-picker{display:flex;flex-direction:column;gap:6px}.ado-schedule-door-option{display:grid;grid-template-columns:16px minmax(0,1fr);align-items:start;gap:10px;padding:8px;border:1px solid #eef2f7;border-radius:8px;background:#fff;cursor:pointer;transition:border-color .14s ease,box-shadow .14s ease;text-align:left}.ado-schedule-door-option:hover{border-color:#dbeafe}.ado-schedule-door-option input{margin:2px 0 0 0}.ado-schedule-door-option-copy{display:flex;flex-direction:column;gap:2px;min-width:0}.ado-schedule-door-option-copy strong{font-size:12px;color:var(--text-primary);line-height:1.35;word-break:break-word}.ado-schedule-door-option-copy small{font-size:11px;color:var(--text-muted);line-height:1.35;word-break:break-word}.ado-schedule-door-option.is-selected{border-color:#93c5fd;box-shadow:0 0 0 2px rgba(37,99,235,.14);background:#f8fbff}.ado-schedule-door-option.is-booked{opacity:.6}.ado-schedule-door-option.is-hidden{display:none}.ado-schedule-door-option.is-filtered{display:none}.ado-schedule-door-empty{padding:10px;border:1px dashed var(--border);border-radius:10px;background:var(--surface-2);font-size:12px;color:var(--text-muted)}.ado-schedule-booking-existing{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2)}.ado-schedule-booking-existing-title{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px}.ado-schedule-booking-existing-list{display:flex;flex-direction:column;gap:8px}.ado-schedule-booking-existing-item{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:8px;border:1px solid var(--border);border-radius:8px;background:#fff}.ado-schedule-booking-existing-copy{display:flex;flex-direction:column;gap:2px}.ado-schedule-booking-existing-copy strong{font-size:12px;color:var(--text-primary)}.ado-schedule-booking-existing-copy small{font-size:11px;color:var(--text-muted)}.ado-schedule-booking-existing-item .ado-btn{padding:5px 9px;font-size:11px}.ado-schedule-booking-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ado-schedule-booking-flash{display:none;padding:8px 10px;border-radius:8px;font-size:12px}.ado-schedule-booking-flash.ok{display:block;background:#ecfdf3;color:#027a48}.ado-schedule-booking-flash.err{display:block;background:#fef2f2;color:#b42318}.ado-site-readiness-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .16s ease;z-index:99962}.ado-site-readiness-backdrop.is-open{opacity:1;pointer-events:auto}.ado-site-readiness-drawer{position:fixed;top:0;right:0;bottom:0;width:100vw;background:#fff;border-left:1px solid var(--border);box-shadow:-18px 0 42px rgba(2,6,23,.18);z-index:99963;transform:translateX(100%);transition:transform .2s ease;display:flex;flex-direction:column}.ado-site-readiness-drawer.is-open{transform:translateX(0)}body.admin-bar .ado-site-readiness-backdrop{top:32px}body.admin-bar .ado-site-readiness-drawer{top:32px}body.ado-site-readiness-open{overflow:hidden}.ado-site-readiness-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ado-site-readiness-kicker{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#9ca3af}.ado-site-readiness-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-top:2px}.ado-site-readiness-sub{font-size:12px;color:var(--text-muted);margin-top:3px}.ado-site-readiness-body{padding:18px;overflow:auto;display:flex;flex-direction:column;gap:12px;flex:1}
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
    @media (max-width:1100px){.ado-app{flex-direction:column}.ado-side{position:relative;width:100%;min-height:auto}.ado-project-grid{grid-template-columns:1fr}.ado-project-summary-grid{grid-template-columns:1fr}.ado-schedule-slot-list{grid-template-columns:1fr}.ado-schedule-day{min-height:96px}}@media (max-width:840px){.ado-client-door-drawer{width:100vw}.ado-schedule-booking-drawer{width:100vw}body.admin-bar .ado-client-door-backdrop{top:46px}body.admin-bar .ado-client-door-drawer{top:46px}body.admin-bar .ado-schedule-booking-backdrop{top:46px}body.admin-bar .ado-schedule-booking-drawer{top:46px}body.admin-bar .ado-site-readiness-backdrop{top:46px}body.admin-bar .ado-site-readiness-drawer{top:46px}.ado-schedule-month-track{min-width:680px}.ado-schedule-day{min-height:90px}}
    </style>
    <style>
    .ado-site-readiness-form{display:flex;flex-direction:column;gap:12px}
    .ado-app{position:relative;--ado-side-open-width:256px}
    .ado-side{flex:0 0 var(--ado-side-open-width);max-width:var(--ado-side-open-width);transition:flex-basis .22s ease,max-width .22s ease,opacity .18s ease;overflow:hidden}
    .ado-app.is-side-collapsed .ado-side{flex-basis:0;max-width:0;opacity:0;pointer-events:none}
    .ado-side-backdrop{display:none}
    .ado-side-toggle-global{position:absolute;left:calc(var(--ado-side-open-width) - 48px);top:14px;z-index:120;background:rgba(15,23,42,.82);border:1px solid rgba(148,163,184,.45);color:#e2e8f0;border-radius:9px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 20px rgba(2,6,23,.24);padding:0;transition:left .22s ease,background .15s ease,border-color .15s ease,color .15s ease}
    .ado-side-toggle-global:hover{background:rgba(15,23,42,.94);border-color:rgba(148,163,184,.7)}
    .ado-side-toggle-icon-menu{display:none;flex-direction:column;align-items:center;justify-content:center;gap:4px}
    .ado-side-toggle-icon-menu span{display:block;width:14px;height:1.5px;background:currentColor;border-radius:999px}
    .ado-side-toggle-icon-close{font-size:18px;line-height:1}
    .ado-app.is-side-collapsed .ado-side-toggle-global{left:14px;background:#fff;border-color:#cbd5e1;color:#334155;box-shadow:0 8px 20px rgba(15,23,42,.14)}
    .ado-app.is-side-collapsed .ado-side-toggle-global:hover{background:#f8fafc;border-color:#94a3b8}
    .ado-app.is-side-collapsed .ado-side-toggle-icon-menu{display:inline-flex}
    .ado-app.is-side-collapsed .ado-side-toggle-icon-close{display:none}
    .ado-app.is-side-collapsed .ado-top{padding-left:84px}
    .ado-app.is-side-collapsed .ado-main.no-top-header .ado-content{padding-top:64px}
    .ado-site-readiness-summary{padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:12px;color:var(--text-secondary)}
    .ado-project-timeline{list-style:none;margin:0;padding:16px 18px 20px;display:flex;flex-direction:column;gap:10px;max-height:620px;overflow:auto}
    .ado-project-timeline-item{position:relative;padding-left:28px}
    .ado-project-timeline-item:before{content:'';position:absolute;left:9px;top:18px;bottom:-12px;width:2px;background:#e2e8f0}
    .ado-project-timeline-item:last-child:before{display:none}
    .ado-project-timeline-dot{position:absolute;left:3px;top:8px;width:14px;height:14px;border-radius:999px;border:2px solid #93c5fd;background:#fff}
    .ado-project-timeline-card{padding:10px 12px;border:1px solid #dbe4f0;border-radius:12px;background:#fff;display:flex;flex-direction:column;gap:6px;box-shadow:0 4px 10px rgba(15,23,42,.05)}
    .ado-project-timeline-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .ado-project-timeline-head strong{font-size:13px;color:var(--text-primary);line-height:1.35}
    .ado-project-timeline-head small{font-size:11px;color:var(--text-muted);white-space:nowrap}
    .ado-project-timeline-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .ado-project-timeline-detail-list{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:4px}
    .ado-project-timeline-detail-list li{font-size:12px;line-height:1.4;color:var(--text-secondary)}
    .ado-project-timeline-item.is-quote .ado-project-timeline-dot{border-color:#2563eb;background:#dbeafe}
    .ado-project-timeline-item.is-booking .ado-project-timeline-dot{border-color:#0ea5e9;background:#e0f2fe}
    .ado-project-timeline-item.is-site_readiness .ado-project-timeline-dot{border-color:#22c55e;background:#dcfce7}
    .ado-project-timeline-item.is-door_update .ado-project-timeline-dot{border-color:#f59e0b;background:#fef3c7}
    .ado-project-timeline-item.is-door_workflow .ado-project-timeline-dot{border-color:#8b5cf6;background:#ede9fe}
    .ado-project-timeline-item.is-note .ado-project-timeline-dot{border-color:#14b8a6;background:#ccfbf1}
    .ado-project-timeline-item.is-media .ado-project-timeline-dot{border-color:#ef4444;background:#fee2e2}
    .ado-project-timeline-item.is-project_data .ado-project-timeline-dot{border-color:#64748b;background:#e2e8f0}
    .ado-project-tab-panel[data-tab-panel="overview"]{display:flex;flex-direction:column;gap:12px}
    .ado-project-overview-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:12px}
    .ado-project-overview-main,.ado-project-overview-side{overflow:hidden}
    .ado-project-health-pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.02em;border:1px solid transparent}
    .ado-project-health-pill.is-ok{background:#ecfdf3;border-color:#bbf7d0;color:#166534}
    .ado-project-health-pill.is-warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
    .ado-project-health-pill.is-risk{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
    .ado-project-overview-metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
    .ado-project-overview-metric{padding:12px;border:1px solid #e3e9f2;border-radius:11px;background:#f8fafc;display:flex;flex-direction:column;gap:4px}
    .ado-project-overview-metric strong{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#64748b}
    .ado-project-overview-metric span{font-size:14px;font-weight:700;line-height:1.35;color:#0f172a}
    .ado-project-overview-metric small{font-size:12px;line-height:1.4;color:#475569}
    .ado-project-submittals-body{padding:14px;display:flex;flex-direction:column;gap:10px}
    .ado-project-submittal-group{padding:10px;border:1px solid #e3e9f2;border-radius:11px;background:#f8fafc;display:flex;flex-direction:column;gap:8px}
    .ado-project-submittal-group-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
    .ado-project-submittal-group-head strong{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#64748b}
    .ado-project-priority-list{list-style:none;margin:0;padding:12px;display:flex;flex-direction:column;gap:8px}
    .ado-project-priority-list li{position:relative;padding:10px;border:1px solid #dbe4f0;border-radius:10px;background:#f8fafc;display:flex;flex-direction:column;gap:4px}
    .ado-project-priority-list li.is-dismissible{padding-right:34px}
    .ado-project-priority-list li.is-ok{background:#f0fdf4;border-color:#bbf7d0}
    .ado-project-priority-list li.is-warn{background:#fffbeb;border-color:#fde68a}
    .ado-project-priority-list li.is-risk{background:#fef2f2;border-color:#fecaca}
    .ado-project-priority-list strong{font-size:13px;line-height:1.35;color:#0f172a}
    .ado-project-priority-list small{font-size:12px;line-height:1.4;color:#475569}
    .ado-project-priority-list .ado-btn{align-self:flex-start;padding:5px 10px;font-size:11px}
    .ado-priority-dismiss{position:absolute;top:8px;right:8px;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(148,163,184,.35);border-radius:999px;background:#fff;color:#64748b;font-size:12px;line-height:1;cursor:pointer;padding:0}
    .ado-priority-dismiss:hover{border-color:#94a3b8;background:#f8fafc;color:#334155}
    .ado-project-overview-side-block{margin:0 12px 12px;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2)}
    .ado-project-overview-side-block>strong{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px}
    .ado-project-overview-event-list{display:flex;flex-direction:column;gap:7px}
    .ado-project-overview-event{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
    .ado-project-overview-event strong{font-size:12px;line-height:1.35;color:#0f172a}
    .ado-project-overview-event small{font-size:11px;color:#64748b;white-space:nowrap}
    @media (max-width:1600px){
      .ado-project-overview-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}
    }
    @media (max-width:980px){
      .ado-project-overview-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
    }
    @media (max-width:640px){
      .ado-project-overview-metrics{grid-template-columns:1fr}
    }
    @media (max-width:1180px){
      .ado-project-overview-layout{grid-template-columns:1fr}
    }
    @media (max-width:900px){
      .ado-content{padding:16px}
      .ado-top{padding:12px 14px;gap:10px;flex-wrap:wrap}
      .ado-top h1{font-size:18px;line-height:1.25}
      .ado-top-right{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
      .ado-top-right .ado-btn{width:100%;padding:8px 10px}
      .ado-card-head{padding:12px 14px;gap:8px;flex-wrap:wrap}
      .ado-project-header-card{padding:14px}
      .ado-project-title{font-size:19px;line-height:1.2}
      .ado-project-sub{font-size:12px}
      .ado-project-actions{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
      .ado-project-actions .ado-btn{width:100%}
      .ado-project-tab-strip{flex-wrap:nowrap;overflow-x:auto;padding-bottom:2px}
      .ado-project-tab{flex:0 0 auto;white-space:nowrap}
      .ado-project-tab-dot{display:none}
      .ado-project-submittals-body{padding:10px}
      .ado-project-submittal-group{padding:8px}
      .ado-project-submittal-group-head .ado-btn{width:100%}
      .ado-site-readiness-submittal-row{flex-direction:column}
      .ado-site-readiness-submittal-strip{flex:0 0 auto;width:100%;grid-template-columns:1fr;min-height:44px}
      .ado-project-priority-list{padding:10px}
      .ado-project-timeline{padding:12px}
      .ado-project-file-list,.ado-project-door-list{padding:10px}
      .ado-client-door-drawer-head{padding:12px}
      .ado-client-door-head-actions{width:100%;justify-content:flex-start}
      .ado-client-door-head-actions .ado-btn{flex:1 1 auto}
      .ado-client-door-drawer-body{padding:12px}
      .ado-site-readiness-head{padding:12px}
      .ado-site-readiness-body{padding:12px}
      .ado-site-readiness-nav,.ado-site-readiness-actions,.ado-site-readiness-bulk-actions{justify-content:flex-start}
      .ado-schedule-booking-head{padding:12px}
      .ado-schedule-booking-body{padding:12px}
      .ado-schedule-door-toolbar{flex-wrap:wrap}
      .ado-schedule-door-toolbar .ado-btn{width:100%}
      .ado-schedule-month-track{min-width:560px}
      .ado-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
      .ado-table-wrap .ado-table{min-width:640px}
    }
    @media (max-width:640px){
      .ado-content{padding:12px}
      .ado-top{padding:10px 12px}
      .ado-top h1{font-size:17px}
      .ado-top-right{grid-template-columns:1fr}
      .ado-project-actions{grid-template-columns:1fr}
      .ado-project-meta-row{gap:6px}
      .ado-project-pill{font-size:10px}
      .ado-project-overview-metric{padding:10px}
      .ado-project-overview-metric span{font-size:13px}
      .ado-project-overview-metric small{font-size:11px}
      .ado-project-priority-list strong{font-size:12px}
      .ado-project-priority-list small{font-size:11px}
      .ado-project-timeline-head{flex-direction:column;gap:4px}
      .ado-project-timeline-head small{white-space:normal}
      .ado-client-door-status-grid-3{grid-template-columns:1fr}
      .ado-client-door-head-actions .ado-btn{width:100%}
      .ado-site-readiness-tabs{grid-template-columns:1fr}
      .ado-site-readiness-tab-row{grid-template-columns:1fr}
      .ado-site-readiness-nav,.ado-site-readiness-actions,.ado-site-readiness-bulk-actions{flex-direction:column;align-items:stretch}
      .ado-site-readiness-nav .ado-btn,.ado-site-readiness-actions .ado-btn,.ado-site-readiness-bulk-actions .ado-btn{width:100%}
    }
    @media (max-width:1100px){
      .ado-app{display:flex;flex-direction:row}
      .ado-side-backdrop{display:block;position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:110}
      body.admin-bar .ado-side-backdrop{top:32px}
      .ado-side{position:fixed;top:0;left:0;bottom:0;flex:0 0 0;max-width:0;width:min(320px,84vw);min-height:100vh;transform:translateX(-100%);transition:transform .22s ease;opacity:1;pointer-events:none;z-index:111}
      body.admin-bar .ado-side{top:32px;min-height:calc(100vh - 32px)}
      .ado-side-toggle-global{display:inline-flex;left:14px;top:12px;z-index:112}
      body.admin-bar .ado-side-toggle-global{top:44px}
      .ado-side-toggle-icon-menu{display:inline-flex}
      .ado-side-toggle-icon-close{display:none}
      .ado-top{padding-left:64px}
      .ado-main.no-top-header .ado-content{padding-top:64px}
      .ado-app.is-mobile-nav-open .ado-side-backdrop{opacity:1;pointer-events:auto}
      .ado-app.is-mobile-nav-open .ado-side{max-width:min(320px,84vw);transform:translateX(0);pointer-events:auto}
      .ado-app.is-mobile-nav-open .ado-side-toggle-global{left:calc(min(320px,84vw) - 48px);top:12px;background:rgba(15,23,42,.82);border-color:rgba(148,163,184,.45);color:#e2e8f0}
      body.admin-bar .ado-app.is-mobile-nav-open .ado-side-toggle-global{top:44px}
      .ado-app.is-mobile-nav-open .ado-side-toggle-icon-menu{display:none}
      .ado-app.is-mobile-nav-open .ado-side-toggle-icon-close{display:inline}
      .ado-app.is-side-collapsed .ado-top{padding-left:64px}
      .ado-app.is-side-collapsed .ado-main.no-top-header .ado-content{padding-top:64px}
    }
    @media screen and (max-width:782px){
      body.admin-bar .ado-side-backdrop{top:46px}
      body.admin-bar .ado-side{top:46px;min-height:calc(100vh - 46px)}
      body.admin-bar .ado-side-toggle-global{top:58px}
      body.admin-bar .ado-app.is-mobile-nav-open .ado-side-toggle-global{top:58px}
    }
    @media (min-width:981px){
      .ado-site-readiness-backdrop{background:rgba(15,23,42,.34);backdrop-filter:blur(2px)}
      .ado-site-readiness-drawer{
        top:18px;
        right:18px;
        bottom:18px;
        width:min(1360px,calc(100vw - 36px));
        border:1px solid #dbe4f0;
        border-radius:18px;
        box-shadow:0 24px 70px rgba(15,23,42,.28),0 4px 14px rgba(15,23,42,.16);
        transform:translateX(calc(100% + 24px));
        overflow:hidden
      }
      body.admin-bar .ado-site-readiness-drawer{top:50px}
    }
    .ado-schedule-door-option.is-readiness-pending{border-color:#fed7aa;background:#fff7ed}
    .ado-schedule-door-option.is-readiness-pending:hover{border-color:#fdba74}
    .ado-schedule-door-option.is-readiness-pending .ado-schedule-door-option-copy strong{color:#9a3412}
    .ado-schedule-door-option.is-readiness-pending .ado-schedule-door-option-copy small{color:#c2410c}
    .ado-site-readiness-submittal-row{display:flex;align-items:stretch;gap:8px;flex-wrap:wrap}
    .ado-site-readiness-submittal-strip{flex:1 1 360px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));border:1px solid var(--border);border-radius:10px;background:#fff;overflow:hidden;min-height:44px}
    .ado-site-readiness-submittal-btn{all:unset;box-sizing:border-box;display:flex;flex-direction:column;gap:2px;padding:8px 10px;background:#f8fafc;cursor:pointer;border-right:1px solid var(--border)}
    .ado-site-readiness-submittal-btn:last-child{border-right:none}
    .ado-site-readiness-submittal-btn strong{font-size:12px;color:var(--text-primary);line-height:1.35}
    .ado-site-readiness-submittal-btn small{font-size:10px;color:var(--text-muted);line-height:1.35;white-space:normal;overflow-wrap:anywhere}
    .ado-site-readiness-submittal-btn:hover{background:#f1f5f9}
    .ado-site-readiness-submittal-btn.is-active{background:#eff6ff;box-shadow:inset 0 0 0 1px #93c5fd}
    .ado-site-readiness-submittal-empty{display:flex;align-items:center;padding:10px 12px;font-size:12px;color:var(--text-muted)}
    .ado-site-readiness-door-scope{padding:12px;border:1px solid var(--border);border-radius:10px;background:#fff;display:flex;flex-direction:column;gap:10px}
    .ado-site-readiness-progress{padding:8px 10px;border:1px dashed var(--border);border-radius:8px;background:var(--surface-2);font-size:12px;color:var(--text-secondary)}
    .ado-site-readiness-bulk-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}
    .ado-site-readiness-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .ado-site-readiness-tab-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:6px}
    .ado-site-readiness-tab{width:100%;display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:#fff;color:var(--text-secondary);cursor:pointer;text-align:left}
    .ado-site-readiness-tab span{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:var(--surface-2);font-size:11px;font-weight:700;color:var(--text-muted);flex:0 0 18px}
    .ado-site-readiness-tab small{display:block;font-size:11px;line-height:1.35;color:var(--text-secondary)}
    .ado-site-readiness-tab-toggle{display:inline-flex;align-items:center;gap:4px;font-size:10px;color:var(--text-muted);white-space:nowrap}
    .ado-site-readiness-tab-toggle input{margin:0}
    .ado-site-readiness-tab.is-active{border-color:#93c5fd;background:#eff6ff}
    .ado-site-readiness-tab.is-active span{background:#dbeafe;color:#1d4ed8}
    .ado-site-readiness-tab.is-active small{color:#1e3a8a}
    .ado-site-readiness-tab.is-complete{border-color:#86efac;background:#f0fdf4}
    .ado-site-readiness-tab.is-complete span{background:#dcfce7;color:#15803d}
    .ado-site-readiness-tab.is-complete small{color:#166534}
    .ado-site-readiness-tab.is-active.is-complete{border-color:#4ade80;background:#ecfdf3}
    .ado-site-readiness-steps{display:flex;flex-direction:column;gap:10px}
    .ado-site-readiness-step{padding:12px;border:1px solid var(--border);border-radius:10px;background:#fff;display:flex;flex-direction:column;gap:10px}
    .ado-site-readiness-step-head h3{margin:0;font-family:'Syne',sans-serif;font-size:18px;line-height:1.2;color:var(--text-primary)}
    .ado-site-readiness-step-head p{margin:6px 0 0;font-size:12px;line-height:1.45;color:var(--text-secondary)}
    .ado-site-readiness-step-kicker{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px}
    .ado-site-readiness-checklist{display:flex;flex-direction:column;gap:6px}
    .ado-site-readiness-checklist-item{display:flex;align-items:flex-start;gap:8px;padding:9px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface-2);font-size:12px;line-height:1.45;color:var(--text-primary)}
    .ado-site-readiness-checklist-item input{margin-top:2px}
    .ado-site-readiness-field span{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px}
    .ado-site-readiness-field textarea{width:100%;background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:9px 10px;font-size:13px;resize:vertical;min-height:84px}
    .ado-site-readiness-nav{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
    .ado-site-readiness-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .ado-site-readiness-flash{display:none;padding:8px 10px;border-radius:8px;font-size:12px}
    .ado-site-readiness-flash.ok{display:block;background:#ecfdf3;color:#027a48}
    .ado-site-readiness-flash.err{display:block;background:#fef2f2;color:#b42318}
    @media (min-width:980px){.ado-site-readiness-tabs{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media (min-width:1280px){.ado-site-readiness-tabs{grid-template-columns:repeat(5,minmax(0,1fr))}}
    @media (max-width:900px){
      .ado-client-door-backdrop,.ado-schedule-booking-backdrop,.ado-site-readiness-backdrop{backdrop-filter:blur(1.5px)}
      .ado-client-door-drawer,.ado-schedule-booking-drawer,.ado-site-readiness-drawer{
        top:8px;
        right:8px;
        bottom:8px;
        left:auto;
        width:min(720px,calc(100vw - 16px));
        border:1px solid var(--border);
        border-radius:14px;
        box-shadow:0 18px 52px rgba(15,23,42,.3),0 4px 14px rgba(15,23,42,.18);
        transform:translateX(calc(100% + 18px));
        overflow:hidden
      }
      .ado-client-door-drawer-head,.ado-schedule-booking-head,.ado-site-readiness-head{padding:12px}
      .ado-client-door-drawer-body,.ado-schedule-booking-body,.ado-site-readiness-body{padding:12px}
      .ado-client-door-close,.ado-schedule-booking-close,.ado-site-readiness-actions .ado-btn,.ado-site-readiness-bulk-actions .ado-btn,.ado-site-readiness-nav .ado-btn,.ado-schedule-booking-actions .ado-btn{min-height:38px}
      body.admin-bar .ado-client-door-backdrop,body.admin-bar .ado-schedule-booking-backdrop,body.admin-bar .ado-site-readiness-backdrop{top:32px}
      body.admin-bar .ado-client-door-drawer,body.admin-bar .ado-schedule-booking-drawer,body.admin-bar .ado-site-readiness-drawer{
        top:40px;
        bottom:8px
      }
    }
    @media (max-width:640px){
      .ado-client-door-drawer,.ado-schedule-booking-drawer,.ado-site-readiness-drawer{
        top:auto;
        right:0;
        bottom:0;
        left:0;
        width:100vw;
        max-height:min(94vh,calc(100vh - 20px));
        border-left:none;
        border-right:none;
        border-bottom:none;
        border-radius:16px 16px 0 0;
        transform:translateY(calc(100% + 16px))
      }
      .ado-client-door-drawer.is-open,.ado-schedule-booking-drawer.is-open,.ado-site-readiness-drawer.is-open{transform:translateY(0)}
      .ado-client-door-drawer-head,.ado-schedule-booking-head,.ado-site-readiness-head{padding:10px 12px}
      .ado-client-door-drawer-title,.ado-schedule-booking-title,.ado-site-readiness-title{font-size:18px}
      .ado-client-door-drawer-sub,.ado-schedule-booking-sub,.ado-site-readiness-sub{font-size:11px}
      .ado-client-door-drawer-body,.ado-schedule-booking-body,.ado-site-readiness-body{padding:10px 12px}
      .ado-site-readiness-step{padding:10px}
      .ado-site-readiness-checklist-item{padding:8px 9px}
      .ado-site-readiness-field textarea,.ado-schedule-booking-field textarea{min-height:78px}
      .ado-schedule-door-picker-wrap{max-height:220px}
      .ado-schedule-booking-existing-item{flex-direction:column;align-items:stretch}
      .ado-schedule-booking-existing-item .ado-btn{width:100%}
      body.admin-bar .ado-client-door-drawer,body.admin-bar .ado-schedule-booking-drawer,body.admin-bar .ado-site-readiness-drawer{
        max-height:calc(100vh - 46px);
        bottom:0
      }
    }
    </style>
    <div class="ado-app">
      <button class="ado-side-toggle-global" type="button" data-side-toggle aria-label="Collapse menu" aria-expanded="true">
        <span class="ado-side-toggle-icon-menu" aria-hidden="true"><span></span><span></span><span></span></span>
        <span class="ado-side-toggle-icon-close" aria-hidden="true">&times;</span>
      </button>
      <div class="ado-side-backdrop" data-side-backdrop></div>
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
      <section class="ado-main<?php echo $show_top_header ? '' : ' no-top-header'; ?>">
        <?php if ($show_top_header) { ?>
        <header class="ado-top">
          <h1><?php echo esc_html($page_title); ?></h1>
          <div class="ado-top-right">
            <a class="ado-btn" href="mailto:info@autodoorexperts.ca">Support</a>
            <a id="ado-client-new-quote-trigger" class="ado-btn primary" href="<?php echo ado_cd_view_url('new-quote'); ?>">New Quote</a>
          </div>
        </header>
        <?php } ?>
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
      var sideToggleButtons = Array.prototype.slice.call(appRoot.querySelectorAll('[data-side-toggle]'));
      var sideBackdrop = appRoot.querySelector('[data-side-backdrop]');
      var sideNavLinks = Array.prototype.slice.call(appRoot.querySelectorAll('.ado-side .ado-nav a'));
      var sideModeMediaQuery = window.matchMedia('(max-width:1100px)');
      var sideCollapsedStorageKey = 'ado-client-sidebar-collapsed';
      function isMobileSideMode(){
        return !!sideModeMediaQuery.matches;
      }
      function updateSideToggleA11y(){
        var isExpanded = true;
        var label = 'Collapse menu';
        if (isMobileSideMode()) {
          isExpanded = appRoot.classList.contains('is-mobile-nav-open');
          label = isExpanded ? 'Close menu' : 'Open menu';
        } else {
          var isCollapsed = appRoot.classList.contains('is-side-collapsed');
          isExpanded = !isCollapsed;
          label = isCollapsed ? 'Open menu' : 'Collapse menu';
        }
        sideToggleButtons.forEach(function(button){
          button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
          button.setAttribute('aria-label', label);
        });
      }
      function setMobileSideOpen(open){
        appRoot.classList.toggle('is-mobile-nav-open', !!open);
        updateSideToggleA11y();
      }
      function setSideCollapsed(collapsed, options){
        collapsed = !!collapsed;
        appRoot.classList.toggle('is-side-collapsed', collapsed);
        persistedSideCollapsed = collapsed;
        updateSideToggleA11y();
        if (options && options.skipPersist) {
          return;
        }
        if (!window.localStorage) {
          return;
        }
        try {
          window.localStorage.setItem(sideCollapsedStorageKey, collapsed ? '1' : '0');
        } catch (err) {}
      }
      var persistedSideCollapsed = false;
      if (window.localStorage) {
        try {
          persistedSideCollapsed = window.localStorage.getItem(sideCollapsedStorageKey) === '1';
        } catch (err) {}
      }
      function syncSideMode(){
        if (isMobileSideMode()) {
          appRoot.classList.remove('is-side-collapsed');
          setMobileSideOpen(false);
          return;
        }
        appRoot.classList.remove('is-mobile-nav-open');
        setSideCollapsed(persistedSideCollapsed, {skipPersist: true});
      }
      syncSideMode();
      if (sideToggleButtons.length) {
        sideToggleButtons.forEach(function(button){
          button.addEventListener('click', function(ev){
            ev.preventDefault();
            if (isMobileSideMode()) {
              setMobileSideOpen(!appRoot.classList.contains('is-mobile-nav-open'));
              return;
            }
            setSideCollapsed(!appRoot.classList.contains('is-side-collapsed'));
          });
        });
      }
      if (sideBackdrop) {
        sideBackdrop.addEventListener('click', function(ev){
          ev.preventDefault();
          if (isMobileSideMode()) {
            setMobileSideOpen(false);
          }
        });
      }
      if (sideNavLinks.length) {
        sideNavLinks.forEach(function(link){
          link.addEventListener('click', function(){
            if (isMobileSideMode()) {
              setMobileSideOpen(false);
            }
          });
        });
      }
      document.addEventListener('keydown', function(ev){
        if (ev.key === 'Escape' && isMobileSideMode() && appRoot.classList.contains('is-mobile-nav-open')) {
          setMobileSideOpen(false);
        }
      });
      window.addEventListener('resize', function(){
        syncSideMode();
      });
      var scheduleBookingDrawer = appRoot.querySelector('[data-booking-drawer]');
      var scheduleBookingBackdrop = appRoot.querySelector('[data-booking-backdrop]');
      var scheduleBookingForm = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-form]') : null;
      var scheduleBookingDayLabel = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-day-label]') : null;
      var scheduleBookingDayState = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-day-state]') : null;
      var scheduleBookingDateInput = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-date-input]') : null;
      var scheduleBookingDateDisplay = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-date-display]') : null;
      var scheduleBookingSubmit = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-submit]') : null;
      var scheduleBookingNotes = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('textarea[name=\"booking_note\"]') : null;
      var scheduleBookingNotesField = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-note-field]') : null;
      var scheduleBookingCloseAction = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-close-action]') : null;
      var scheduleBookingCancelDayButton = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-cancel-day]') : null;
      var scheduleBookingFlash = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-flash]') : null;
      var scheduleBookingDoorOptions = scheduleBookingDrawer ? Array.prototype.slice.call(scheduleBookingDrawer.querySelectorAll('[data-booking-door-option]')) : [];
      var scheduleBookingDoorInputs = scheduleBookingDrawer ? Array.prototype.slice.call(scheduleBookingDrawer.querySelectorAll('[data-booking-door-input]')) : [];
      var scheduleBookingDoorCount = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-door-count]') : null;
      var scheduleBookingDoorSearch = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-door-search]') : null;
      var scheduleBookingDoorClear = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-door-clear]') : null;
      var scheduleBookingDoorSelected = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-door-selected]') : null;
      var scheduleBookingDoorEmpty = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-door-empty]') : null;
      var scheduleBookingExistingWrap = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-existing-wrap]') : null;
      var scheduleBookingExistingTitle = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-existing-title]') : null;
      var scheduleBookingExistingList = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-existing-list]') : null;
      var scheduleBookingExistingJsonNode = scheduleBookingDrawer ? scheduleBookingDrawer.querySelector('[data-booking-existing-json]') : null;
      var scheduleBookingExistingByDate = {};
      var scheduleBookingOwnDayBookingIds = [];
      var scheduleBookingOwnDayBookingProjectIds = {};
      var scheduleBookingOwnDoorBaselineMap = {};
      var scheduleBookingProjectField = scheduleBookingForm ? scheduleBookingForm.querySelector('input[name=\"project_id\"]') : null;
      var scheduleBookingDefaultProjectId = scheduleBookingProjectField ? String(scheduleBookingProjectField.value || '').trim() : '';
      var siteReadinessTrigger = appRoot.querySelector('[data-site-readiness-confirm]');
      var siteReadinessDrawer = appRoot.querySelector('[data-site-readiness-drawer]');
      var siteReadinessBackdrop = appRoot.querySelector('[data-site-readiness-backdrop]');
      var siteReadinessForm = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-form]') : null;
      var siteReadinessProjectField = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-project-id]') : null;
      var siteReadinessSubmissionField = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-submission-id]') : null;
      var siteReadinessSubmissionButtons = Array.prototype.slice.call(appRoot.querySelectorAll('[data-site-readiness-open-submission]'));
      var siteReadinessSubmissionsJsonNode = appRoot.querySelector('[data-site-readiness-submissions-json]');
      var siteReadinessSubmissionsById = {};
      var siteReadinessSummary = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-summary]') : null;
      var siteReadinessDoorOptions = siteReadinessDrawer ? Array.prototype.slice.call(siteReadinessDrawer.querySelectorAll('[data-site-readiness-door-option]')) : [];
      var siteReadinessDoorInputs = siteReadinessDrawer ? Array.prototype.slice.call(siteReadinessDrawer.querySelectorAll('[data-site-readiness-door-input]')) : [];
      var siteReadinessDoorSearch = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-door-search]') : null;
      var siteReadinessDoorClear = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-door-clear]') : null;
      var siteReadinessDoorSelected = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-door-selected]') : null;
      var siteReadinessDoorCount = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-door-count]') : null;
      var siteReadinessDoorEmpty = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-door-empty]') : null;
      var siteReadinessProgress = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-progress]') : null;
      var siteReadinessCheckAllButton = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-check-all]') : null;
      var siteReadinessFlash = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-flash]') : null;
      var siteReadinessSaveButton = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-save]') : null;
      var siteReadinessStepButtons = siteReadinessDrawer ? Array.prototype.slice.call(siteReadinessDrawer.querySelectorAll('[data-site-readiness-step-button]')) : [];
      var siteReadinessSectionToggles = siteReadinessDrawer ? Array.prototype.slice.call(siteReadinessDrawer.querySelectorAll('[data-site-readiness-section-toggle]')) : [];
      var siteReadinessStepPanels = siteReadinessDrawer ? Array.prototype.slice.call(siteReadinessDrawer.querySelectorAll('[data-site-readiness-step]')) : [];
      var siteReadinessPrevButton = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-prev]') : null;
      var siteReadinessNextButton = siteReadinessDrawer ? siteReadinessDrawer.querySelector('[data-site-readiness-next]') : null;
      var siteReadinessCurrentStep = 0;
      var hardwareAvailabilityTrigger = appRoot.querySelector('[data-hardware-availability-confirm]');
      var hardwareAvailabilityDrawer = appRoot.querySelector('[data-hardware-availability-drawer]');
      var hardwareAvailabilityBackdrop = appRoot.querySelector('[data-hardware-availability-backdrop]');
      var hardwareAvailabilityForm = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-form]') : null;
      var hardwareAvailabilityProjectField = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-project-id]') : null;
      var hardwareAvailabilitySubmissionField = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-submission-id]') : null;
      var hardwareAvailabilitySubmissionButtons = Array.prototype.slice.call(appRoot.querySelectorAll('[data-hardware-availability-open-submission]'));
      var hardwareAvailabilitySubmissionsJsonNode = appRoot.querySelector('[data-hardware-availability-submissions-json]');
      var hardwareAvailabilitySubmissionsById = {};
      var hardwareAvailabilitySummary = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-summary]') : null;
      var hardwareAvailabilityDoorOptions = hardwareAvailabilityDrawer ? Array.prototype.slice.call(hardwareAvailabilityDrawer.querySelectorAll('[data-hardware-availability-door-option]')) : [];
      var hardwareAvailabilityDoorInputs = hardwareAvailabilityDrawer ? Array.prototype.slice.call(hardwareAvailabilityDrawer.querySelectorAll('[data-hardware-availability-door-input]')) : [];
      var hardwareAvailabilityDoorSearch = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-door-search]') : null;
      var hardwareAvailabilityDoorClear = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-door-clear]') : null;
      var hardwareAvailabilityDoorSelected = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-door-selected]') : null;
      var hardwareAvailabilityDoorCount = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-door-count]') : null;
      var hardwareAvailabilityDoorEmpty = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-door-empty]') : null;
      var hardwareAvailabilityProgress = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-progress]') : null;
      var hardwareAvailabilityCheckAllButton = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-check-all]') : null;
      var hardwareAvailabilityFlash = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-flash]') : null;
      var hardwareAvailabilitySaveButton = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-save]') : null;
      var hardwareAvailabilityStepButtons = hardwareAvailabilityDrawer ? Array.prototype.slice.call(hardwareAvailabilityDrawer.querySelectorAll('[data-hardware-availability-step-button]')) : [];
      var hardwareAvailabilitySectionToggles = hardwareAvailabilityDrawer ? Array.prototype.slice.call(hardwareAvailabilityDrawer.querySelectorAll('[data-hardware-availability-section-toggle]')) : [];
      var hardwareAvailabilityStepPanels = hardwareAvailabilityDrawer ? Array.prototype.slice.call(hardwareAvailabilityDrawer.querySelectorAll('[data-hardware-availability-step]')) : [];
      var hardwareAvailabilityPrevButton = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-prev]') : null;
      var hardwareAvailabilityNextButton = hardwareAvailabilityDrawer ? hardwareAvailabilityDrawer.querySelector('[data-hardware-availability-next]') : null;
      var hardwareAvailabilityCurrentStep = 0;
      var priorityQueueProjectNode = appRoot.querySelector('.ado-project-shell[data-project-id]');
      var priorityDismissButtons = Array.prototype.slice.call(appRoot.querySelectorAll('[data-priority-dismiss]'));
      var priorityHealthPill = appRoot.querySelector('.ado-project-overview-side .ado-card-head .ado-project-health-pill');
      function updatePriorityHealthPillVisibility(){
        if (!priorityHealthPill) {
          return;
        }
        var isRiskPill = priorityHealthPill.classList.contains('is-risk');
        if (!isRiskPill) {
          priorityHealthPill.hidden = false;
          return;
        }
        var hasVisibleRiskRows = false;
        appRoot.querySelectorAll('.ado-project-priority-list li.is-risk').forEach(function(item){
          if (item.hidden) {
            return;
          }
          hasVisibleRiskRows = true;
        });
        priorityHealthPill.hidden = !hasVisibleRiskRows;
      }
      function priorityDismissStorageKey(dismissKey){
        var projectId = priorityQueueProjectNode ? String(priorityQueueProjectNode.getAttribute('data-project-id') || '').trim() : '';
        dismissKey = String(dismissKey || '').trim();
        if (projectId === '' || dismissKey === '') {
          return '';
        }
        return 'ado-client-priority-dismissed:' + projectId + ':' + dismissKey;
      }
      function isPriorityDismissed(dismissKey){
        var storageKey = priorityDismissStorageKey(dismissKey);
        if (storageKey === '' || !window.localStorage) {
          return false;
        }
        try {
          return window.localStorage.getItem(storageKey) === '1';
        } catch (err) {
          return false;
        }
      }
      function markPriorityDismissed(dismissKey){
        var storageKey = priorityDismissStorageKey(dismissKey);
        if (storageKey === '' || !window.localStorage) {
          return;
        }
        try {
          window.localStorage.setItem(storageKey, '1');
        } catch (err) {}
      }
      function applyPriorityDismissedState(){
        appRoot.querySelectorAll('[data-priority-dismiss-key]').forEach(function(item){
          var dismissKey = String(item.getAttribute('data-priority-dismiss-key') || '').trim();
          if (dismissKey === '') {
            return;
          }
          item.hidden = isPriorityDismissed(dismissKey);
        });
        updatePriorityHealthPillVisibility();
      }
      applyPriorityDismissedState();
      if (priorityDismissButtons.length) {
        priorityDismissButtons.forEach(function(button){
          button.addEventListener('click', function(ev){
            ev.preventDefault();
            var dismissKey = String(button.getAttribute('data-priority-dismiss') || '').trim();
            if (dismissKey === '') {
              return;
            }
            markPriorityDismissed(dismissKey);
            var row = button.closest('[data-priority-dismiss-key]');
            if (row) {
              row.hidden = true;
            }
            updatePriorityHealthPillVisibility();
          });
        });
      }
      if (siteReadinessSubmissionsJsonNode) {
        try {
          var parsedSiteReadinessSubmissions = JSON.parse(siteReadinessSubmissionsJsonNode.textContent || '{}');
          if (parsedSiteReadinessSubmissions && typeof parsedSiteReadinessSubmissions === 'object') {
            siteReadinessSubmissionsById = parsedSiteReadinessSubmissions;
          }
        } catch (err) {
          siteReadinessSubmissionsById = {};
        }
      }
      if (hardwareAvailabilitySubmissionsJsonNode) {
        try {
          var parsedHardwareAvailabilitySubmissions = JSON.parse(hardwareAvailabilitySubmissionsJsonNode.textContent || '{}');
          if (parsedHardwareAvailabilitySubmissions && typeof parsedHardwareAvailabilitySubmissions === 'object') {
            hardwareAvailabilitySubmissionsById = parsedHardwareAvailabilitySubmissions;
          }
        } catch (err) {
          hardwareAvailabilitySubmissionsById = {};
        }
      }
      if (scheduleBookingExistingJsonNode) {
        try {
          var parsedExistingRows = JSON.parse(scheduleBookingExistingJsonNode.textContent || '{}');
          if (parsedExistingRows && typeof parsedExistingRows === 'object') {
            scheduleBookingExistingByDate = parsedExistingRows;
          }
        } catch (err) {
          scheduleBookingExistingByDate = {};
        }
      }
      function bookingDoorMapKey(projectId, doorId){
        var normalizedProjectId = String(projectId || '').trim();
        var normalizedDoorId = String(doorId || '').trim();
        if (normalizedDoorId === '') {
          return '';
        }
        return normalizedProjectId + '::' + normalizedDoorId;
      }

      function escapeBookingHtml(value){
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }
      function normalizeBookingBool(value){
        if (value === true || value === 1) {
          return true;
        }
        var normalized = String(value || '').toLowerCase();
        return normalized === '1' || normalized === 'true' || normalized === 'yes';
      }
      function safeBookingUrl(value){
        var url = String(value || '').trim();
        if (!/^https?:\/\//i.test(url)) {
          return '';
        }
        return url;
      }
      function collectOwnBookingStateForDay(dayKey){
        var rows = Array.isArray(scheduleBookingExistingByDate[dayKey]) ? scheduleBookingExistingByDate[dayKey] : [];
        var ownRows = rows.filter(function(row){
          return normalizeBookingBool(row && row.is_own_booking);
        });
        var doorMap = {};
        var bookingIds = [];
        var bookingProjectIds = {};
        var projectIds = {};
        ownRows.forEach(function(row){
          var rowProjectId = String((row && row.project_id) || scheduleBookingDefaultProjectId || '').trim();
          if (rowProjectId !== '') {
            projectIds[rowProjectId] = true;
          }
          var bookingId = String((row && row.booking_id) || '').trim();
          if (bookingId !== '') {
            bookingIds.push(bookingId);
            bookingProjectIds[bookingId] = rowProjectId;
          }
          var doorIds = Array.isArray(row && row.door_ids) ? row.door_ids : [];
          doorIds.forEach(function(doorId){
            var doorKey = bookingDoorMapKey(rowProjectId, doorId);
            if (doorKey !== '') {
              doorMap[doorKey] = true;
            }
          });
        });
        return {
          rows: ownRows,
          doorMap: doorMap,
          bookingIds: bookingIds,
          bookingProjectIds: bookingProjectIds,
          projectIds: projectIds
        };
      }
      function cloneBookingDoorMap(sourceMap){
        var clone = {};
        if (!sourceMap || typeof sourceMap !== 'object') {
          return clone;
        }
        Object.keys(sourceMap).forEach(function(doorId){
          doorId = String(doorId || '').trim();
          if (doorId !== '' && sourceMap[doorId]) {
            clone[doorId] = true;
          }
        });
        return clone;
      }
      function selectedDoorMapForBooking(){
        var selected = {};
        scheduleBookingDoorOptions.forEach(function(option){
          if (!option || option.classList.contains('is-hidden')) {
            return;
          }
          var input = option.querySelector('[data-booking-door-input]');
          if (!input || !input.checked) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || '').trim();
          var projectId = String(option.getAttribute('data-door-project-id') || scheduleBookingDefaultProjectId || '').trim();
          var doorKey = bookingDoorMapKey(projectId, doorId);
          if (doorKey !== '') {
            selected[doorKey] = true;
          }
        });
        return selected;
      }
      function bookingDoorMapsEqual(leftMap, rightMap){
        leftMap = cloneBookingDoorMap(leftMap);
        rightMap = cloneBookingDoorMap(rightMap);
        var leftKeys = Object.keys(leftMap).sort();
        var rightKeys = Object.keys(rightMap).sort();
        if (leftKeys.length !== rightKeys.length) {
          return false;
        }
        for (var i = 0; i < leftKeys.length; i++) {
          if (leftKeys[i] !== rightKeys[i]) {
            return false;
          }
        }
        return true;
      }
      function setScheduleBookingActionMode(dayState, hasOwnBookings, ownBookingIds){
        var isOwnBooked = !!hasOwnBookings;
        var hasOwnBookingIds = Array.isArray(ownBookingIds) && ownBookingIds.length > 0;
        if (scheduleBookingSubmit) {
          scheduleBookingSubmit.hidden = false;
          scheduleBookingSubmit.textContent = 'Book Selected Doors';
        }
        if (scheduleBookingCloseAction) {
          scheduleBookingCloseAction.hidden = isOwnBooked;
        }
        if (scheduleBookingCancelDayButton) {
          scheduleBookingCancelDayButton.hidden = !isOwnBooked || !hasOwnBookingIds;
          scheduleBookingCancelDayButton.disabled = !isOwnBooked || !hasOwnBookingIds;
        }
        if (scheduleBookingNotesField) {
          scheduleBookingNotesField.hidden = isOwnBooked;
        }
        if (scheduleBookingNotes) {
          if (isOwnBooked) {
            scheduleBookingNotes.value = '';
          }
          scheduleBookingNotes.disabled = isOwnBooked;
        }
      }

      function clearScheduleDaySelection(){
        appRoot.querySelectorAll('[data-calendar-day].is-selected').forEach(function(node){
          node.classList.remove('is-selected');
        });
      }
      function setScheduleBookingFlash(message, isOk){
        if (!scheduleBookingFlash) {
          return;
        }
        if (!message) {
          scheduleBookingFlash.className = 'ado-schedule-booking-flash';
          scheduleBookingFlash.textContent = '';
          return;
        }
        scheduleBookingFlash.className = 'ado-schedule-booking-flash ' + (isOk ? 'ok' : 'err');
        scheduleBookingFlash.textContent = String(message || '');
      }
      function setSiteReadinessFlash(message, isOk){
        if (!siteReadinessFlash) {
          return;
        }
        if (!message) {
          siteReadinessFlash.className = 'ado-site-readiness-flash';
          siteReadinessFlash.textContent = '';
          return;
        }
        siteReadinessFlash.className = 'ado-site-readiness-flash ' + (isOk ? 'ok' : 'err');
        siteReadinessFlash.textContent = String(message || '');
      }
      function normalizeSiteReadinessDoorSearch(value){
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
      }
      function siteReadinessSelectedDoorIds(){
        var selectedDoorMap = {};
        siteReadinessDoorOptions.forEach(function(option){
          if (!option || option.classList.contains('is-hidden')) {
            return;
          }
          var input = option.querySelector('[data-site-readiness-door-input]');
          if (!input || !input.checked) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || input.value || '').trim();
          if (doorId === '') {
            return;
          }
          selectedDoorMap[doorId] = true;
        });
        return Object.keys(selectedDoorMap);
      }
      function collectSiteReadinessDoorLookupForSubmission(submissionId){
        var doorLookup = {};
        submissionId = String(submissionId || '').trim();
        if (submissionId === '') {
          return doorLookup;
        }
        var submissionState = siteReadinessSubmissionsById[submissionId];
        var submissionDoorIds = Array.isArray(submissionState && submissionState.door_ids) ? submissionState.door_ids : [];
        submissionDoorIds.forEach(function(doorIdRaw){
          var doorId = String(doorIdRaw || '').trim();
          if (doorId !== '') {
            doorLookup[doorId] = true;
          }
        });
        return doorLookup;
      }
      function collectSiteReadinessReopenedDoorLookup(){
        var reopenedLookup = {};
        siteReadinessDoorOptions.forEach(function(option){
          if (!option) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || '').trim();
          if (doorId === '') {
            return;
          }
          var isReopened = String(option.getAttribute('data-door-reopened') || '0') === '1';
          if (isReopened) {
            reopenedLookup[doorId] = true;
          }
        });
        return reopenedLookup;
      }
      function collectSiteReadinessReservedDoorLookup(excludedSubmissionId){
        var reservedLookup = {};
        var reopenedLookup = collectSiteReadinessReopenedDoorLookup();
        excludedSubmissionId = String(excludedSubmissionId || '').trim();
        Object.keys(siteReadinessSubmissionsById || {}).forEach(function(submissionId){
          submissionId = String(submissionId || '').trim();
          if (submissionId === '' || submissionId === excludedSubmissionId) {
            return;
          }
          var submissionState = siteReadinessSubmissionsById[submissionId];
          var submissionDoorIds = Array.isArray(submissionState && submissionState.door_ids) ? submissionState.door_ids : [];
          submissionDoorIds.forEach(function(doorIdRaw){
            var doorId = String(doorIdRaw || '').trim();
            if (doorId !== '') {
              if (reopenedLookup[doorId]) {
                return;
              }
              reservedLookup[doorId] = true;
            }
          });
        });
        return reservedLookup;
      }
      function applySiteReadinessDoorAvailability(activeSubmissionId){
        activeSubmissionId = String(activeSubmissionId || '').trim();
        var reservedDoorLookup = collectSiteReadinessReservedDoorLookup(activeSubmissionId);
        var activeDoorLookup = collectSiteReadinessDoorLookupForSubmission(activeSubmissionId);
        siteReadinessDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-site-readiness-door-input]');
          if (!input) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || input.value || '').trim();
          var isReserved = doorId !== '' && !!reservedDoorLookup[doorId] && !activeDoorLookup[doorId];
          option.classList.toggle('is-hidden', isReserved);
          if (isReserved) {
            input.checked = false;
            input.disabled = true;
          } else {
            input.disabled = false;
          }
        });
      }
      function renderSiteReadinessDoorSelected(){
        if (!siteReadinessDoorSelected) {
          return;
        }
        var selectedDoorIds = siteReadinessSelectedDoorIds();
        if (!selectedDoorIds.length) {
          siteReadinessDoorSelected.innerHTML = '';
          siteReadinessDoorSelected.hidden = true;
          return;
        }
        var chipHtml = selectedDoorIds.map(function(selectedDoorId){
          var option = siteReadinessDoorOptions.find(function(optionNode){
            return String(optionNode.getAttribute('data-door-id') || '').trim() === selectedDoorId;
          });
          var doorLabel = option ? String(option.getAttribute('data-door-label') || selectedDoorId) : selectedDoorId;
          return '<span class=\"ado-schedule-door-chip\">'
            + escapeBookingHtml(doorLabel)
            + '<button type=\"button\" data-site-readiness-door-remove=\"' + escapeBookingHtml(selectedDoorId) + '\" aria-label=\"Remove ' + escapeBookingHtml(doorLabel) + '\">&times;</button>'
            + '</span>';
        }).join('');
        siteReadinessDoorSelected.innerHTML = chipHtml;
        siteReadinessDoorSelected.hidden = false;
      }
      function applySiteReadinessDoorFilter(){
        var query = normalizeSiteReadinessDoorSearch(siteReadinessDoorSearch ? siteReadinessDoorSearch.value : '');
        var visibleCount = 0;
        siteReadinessDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-site-readiness-door-input]');
          if (!input || option.classList.contains('is-hidden')) {
            option.classList.add('is-filtered');
            return;
          }
          var searchText = normalizeSiteReadinessDoorSearch(option.getAttribute('data-door-search') || '');
          var keepVisible = query === '' || input.checked || searchText.indexOf(query) !== -1;
          option.classList.toggle('is-filtered', !keepVisible);
          if (!option.classList.contains('is-filtered')) {
            visibleCount++;
          }
        });
        if (siteReadinessDoorClear) {
          siteReadinessDoorClear.hidden = query === '';
        }
        return visibleCount;
      }
      function updateSiteReadinessDoorSelectionState(){
        var selectedDoorIds = siteReadinessSelectedDoorIds();
        var totalDoors = 0;
        siteReadinessDoorOptions.forEach(function(option){
          if (!option.classList.contains('is-hidden')) {
            totalDoors += 1;
          }
        });
        var visibleDoors = applySiteReadinessDoorFilter();
        siteReadinessDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-site-readiness-door-input]');
          option.classList.toggle('is-selected', !!(input && input.checked));
        });
        if (siteReadinessDoorCount) {
          if (totalDoors <= 0) {
            siteReadinessDoorCount.textContent = 'All project doors already belong to saved site readiness submittals.';
          } else if (selectedDoorIds.length <= 0) {
            siteReadinessDoorCount.textContent = 'Select at least one project door for this readiness checklist.';
          } else {
            siteReadinessDoorCount.textContent = selectedDoorIds.length + ' of ' + totalDoors + ' doors selected.';
          }
        }
        if (siteReadinessDoorEmpty) {
          if (totalDoors <= 0) {
            siteReadinessDoorEmpty.textContent = 'All project doors are already assigned to saved site readiness submittals. Open a submittal above to edit.';
          } else if (visibleDoors <= 0) {
            siteReadinessDoorEmpty.textContent = 'No project doors match the current search.';
          } else {
            siteReadinessDoorEmpty.textContent = 'No project doors are available to scope this checklist.';
          }
          siteReadinessDoorEmpty.hidden = visibleDoors > 0;
        }
        renderSiteReadinessDoorSelected();
      }
      function setActiveSiteReadinessSubmissionButton(submissionId){
        submissionId = String(submissionId || '').trim();
        siteReadinessSubmissionButtons.forEach(function(button){
          var buttonSubmissionId = String(button.getAttribute('data-site-readiness-open-submission') || '').trim();
          button.classList.toggle('is-active', submissionId !== '' && buttonSubmissionId === submissionId);
        });
      }
      function buildEmptySiteReadinessSubmissionState(){
        var emptySections = {};
        siteReadinessStepPanels.forEach(function(panel){
          if (!panel) {
            return;
          }
          var sectionKey = String(panel.getAttribute('data-section-key') || '').trim();
          if (sectionKey === '') {
            return;
          }
          var emptyItems = {};
          panel.querySelectorAll('[data-site-readiness-item]').forEach(function(itemNode){
            var itemKey = String(itemNode.getAttribute('data-item-key') || '').trim();
            if (itemKey === '') {
              return;
            }
            emptyItems[itemKey] = 0;
          });
          emptySections[sectionKey] = {
            items: emptyItems,
            note: ''
          };
        });
        return {
          submission_id: '',
          door_ids: [],
          sections: emptySections
        };
      }
      function applySiteReadinessSubmissionState(submissionState){
        submissionState = submissionState && typeof submissionState === 'object' ? submissionState : {};
        var doorLookup = {};
        var submissionDoorIds = Array.isArray(submissionState.door_ids) ? submissionState.door_ids : [];
        submissionDoorIds.forEach(function(doorIdRaw){
          var doorId = String(doorIdRaw || '').trim();
          if (doorId !== '') {
            doorLookup[doorId] = true;
          }
        });
        siteReadinessDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-site-readiness-door-input]');
          if (!input) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || input.value || '').trim();
          input.checked = doorId !== '' && !!doorLookup[doorId];
        });
        var submissionSections = submissionState.sections && typeof submissionState.sections === 'object'
          ? submissionState.sections
          : {};
        siteReadinessStepPanels.forEach(function(panel){
          if (!panel) {
            return;
          }
          var sectionKey = String(panel.getAttribute('data-section-key') || '').trim();
          var sectionState = sectionKey !== '' && submissionSections[sectionKey] && typeof submissionSections[sectionKey] === 'object'
            ? submissionSections[sectionKey]
            : {};
          var sectionItems = sectionState.items && typeof sectionState.items === 'object' ? sectionState.items : {};
          panel.querySelectorAll('[data-site-readiness-item]').forEach(function(itemNode){
            var itemKey = String(itemNode.getAttribute('data-item-key') || '').trim();
            if (itemKey === '') {
              itemNode.checked = false;
              return;
            }
            itemNode.checked = !!sectionItems[itemKey];
          });
          var noteNode = panel.querySelector('[data-site-readiness-section-note]');
          if (noteNode) {
            noteNode.value = String(sectionState.note || '');
          }
        });
        updateSiteReadinessDoorSelectionState();
        updateSiteReadinessSummary();
        updateSiteReadinessProgress();
      }
      function loadSiteReadinessSubmission(submissionId){
        submissionId = String(submissionId || '').trim();
        if (submissionId === '') {
          if (siteReadinessSubmissionField) {
            siteReadinessSubmissionField.value = '';
          }
          setActiveSiteReadinessSubmissionButton('');
          applySiteReadinessDoorAvailability('');
          applySiteReadinessSubmissionState(buildEmptySiteReadinessSubmissionState());
          return;
        }
        var submissionState = siteReadinessSubmissionsById[submissionId];
        if (!submissionState || typeof submissionState !== 'object') {
          return;
        }
        if (siteReadinessSubmissionField) {
          siteReadinessSubmissionField.value = submissionId;
        }
        setActiveSiteReadinessSubmissionButton(submissionId);
        applySiteReadinessDoorAvailability(submissionId);
        applySiteReadinessSubmissionState(submissionState);
      }
      function updateSiteReadinessSummary(){
        if (!siteReadinessSummary) {
          return;
        }
        var totalItems = 0;
        var confirmedItems = 0;
        siteReadinessStepPanels.forEach(function(panel, panelIndex){
          var sectionTotal = 0;
          var sectionConfirmed = 0;
          var tabButton = siteReadinessStepButtons[panelIndex] || null;
          var tabToggle = siteReadinessSectionToggles[panelIndex] || null;
          if (!panel) {
            if (tabButton) {
              tabButton.classList.remove('is-complete');
            }
            if (tabToggle) {
              tabToggle.checked = false;
              tabToggle.indeterminate = false;
              tabToggle.disabled = true;
            }
            return;
          }
          panel.querySelectorAll('[data-site-readiness-item]').forEach(function(itemNode){
            sectionTotal += 1;
            totalItems += 1;
            if (itemNode.checked) {
              sectionConfirmed += 1;
              confirmedItems += 1;
            }
          });
          if (tabButton) {
            tabButton.classList.toggle('is-complete', sectionTotal > 0 && sectionConfirmed >= sectionTotal);
          }
          if (tabToggle) {
            if (sectionTotal <= 0) {
              tabToggle.checked = false;
              tabToggle.indeterminate = false;
              tabToggle.disabled = true;
            } else if (sectionConfirmed <= 0) {
              tabToggle.checked = false;
              tabToggle.indeterminate = false;
              tabToggle.disabled = false;
            } else if (sectionConfirmed >= sectionTotal) {
              tabToggle.checked = true;
              tabToggle.indeterminate = false;
              tabToggle.disabled = false;
            } else {
              tabToggle.checked = false;
              tabToggle.indeterminate = true;
              tabToggle.disabled = false;
            }
          }
        });
        if (totalItems <= 0) {
          siteReadinessSummary.textContent = 'No site-readiness checklist items are configured yet.';
          return;
        }
        siteReadinessSummary.textContent = confirmedItems + ' of ' + totalItems + ' checklist items confirmed.';
      }
      function updateSiteReadinessProgress(){
        if (!siteReadinessProgress) {
          return;
        }
        if (!siteReadinessStepPanels.length) {
          siteReadinessProgress.textContent = '';
          return;
        }
        var currentPanel = siteReadinessStepPanels[siteReadinessCurrentStep] || null;
        var currentTitleNode = currentPanel ? currentPanel.querySelector('h3') : null;
        var currentTitle = currentTitleNode ? String(currentTitleNode.textContent || '').trim() : '';
        var sectionItems = currentPanel ? currentPanel.querySelectorAll('[data-site-readiness-item]') : [];
        var sectionChecked = 0;
        sectionItems.forEach(function(itemNode){
          if (itemNode.checked) {
            sectionChecked += 1;
          }
        });
        var sectionTotal = sectionItems.length || 0;
        var sectionLabel = 'Section ' + (siteReadinessCurrentStep + 1) + ' of ' + siteReadinessStepPanels.length;
        if (currentTitle !== '') {
          sectionLabel += ': ' + currentTitle;
        }
        if (sectionTotal > 0) {
          sectionLabel += ' (' + sectionChecked + '/' + sectionTotal + ' checked)';
        }
        siteReadinessProgress.textContent = sectionLabel;
      }
      function setSiteReadinessStep(index){
        if (!siteReadinessStepPanels.length) {
          return;
        }
        var maxStep = siteReadinessStepPanels.length - 1;
        siteReadinessCurrentStep = Math.max(0, Math.min(index, maxStep));
        siteReadinessStepPanels.forEach(function(panel, panelIndex){
          panel.hidden = panelIndex !== siteReadinessCurrentStep;
        });
        siteReadinessStepButtons.forEach(function(button, buttonIndex){
          button.classList.toggle('is-active', buttonIndex === siteReadinessCurrentStep);
        });
        if (siteReadinessPrevButton) {
          siteReadinessPrevButton.disabled = siteReadinessCurrentStep <= 0;
        }
        if (siteReadinessNextButton) {
          var isLastStep = siteReadinessCurrentStep >= maxStep;
          siteReadinessNextButton.textContent = isLastStep ? 'Back To Section 1' : 'Next Section';
          siteReadinessNextButton.setAttribute('data-next-mode', isLastStep ? 'restart' : 'next');
        }
        updateSiteReadinessProgress();
      }
      function collectSiteReadinessSectionsPayload(){
        var sections = {};
        siteReadinessStepPanels.forEach(function(panel){
          if (!panel) {
            return;
          }
          var sectionKey = String(panel.getAttribute('data-section-key') || '').trim();
          if (sectionKey === '') {
            return;
          }
          var itemPayload = {};
          panel.querySelectorAll('[data-site-readiness-item]').forEach(function(itemNode){
            var itemKey = String(itemNode.getAttribute('data-item-key') || '').trim();
            if (itemKey === '') {
              return;
            }
            itemPayload[itemKey] = itemNode.checked ? 1 : 0;
          });
          var noteNode = panel.querySelector('[data-site-readiness-section-note]');
          sections[sectionKey] = {
            items: itemPayload,
            note: noteNode ? String(noteNode.value || '').trim() : ''
          };
        });
        return sections;
      }
      async function submitSiteReadinessForm(){
        if (!siteReadinessForm) {
          return;
        }
        var projectIdValue = siteReadinessProjectField ? String(siteReadinessProjectField.value || '').trim() : '';
        if (projectIdValue === '') {
          setSiteReadinessFlash('Project context is missing. Reload and try again.', false);
          return;
        }
        var selectedDoorIds = siteReadinessSelectedDoorIds();
        if (selectedDoorIds.length <= 0) {
          setSiteReadinessFlash('Select at least one project door for this readiness checklist.', false);
          return;
        }
        var sectionsPayload = collectSiteReadinessSectionsPayload();
        if (!Object.keys(sectionsPayload).length) {
          setSiteReadinessFlash('No site-readiness sections are available to update yet.', false);
          return;
        }
        if (siteReadinessSaveButton) {
          siteReadinessSaveButton.disabled = true;
        }
        setSiteReadinessFlash('', true);
        var payload = new FormData();
        payload.append('action', 'ado_save_client_site_readiness');
        payload.append('nonce', nonce);
        payload.append('project_id', projectIdValue);
        var submissionIdValue = siteReadinessSubmissionField ? String(siteReadinessSubmissionField.value || '').trim() : '';
        if (submissionIdValue !== '') {
          payload.append('submission_id', submissionIdValue);
        }
        payload.append('door_ids', JSON.stringify(selectedDoorIds));
        payload.append('sections', JSON.stringify(sectionsPayload));
        try {
          var res = await fetch(ajaxUrl, { method:'POST', body: payload, credentials:'same-origin' });
          var json = await res.json();
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) ? json.data.message : 'Site readiness could not be saved.');
          }
          if (siteReadinessSubmissionField && json && json.data && json.data.submission_id) {
            siteReadinessSubmissionField.value = String(json.data.submission_id || '').trim();
            setActiveSiteReadinessSubmissionButton(siteReadinessSubmissionField.value);
          }
          updateSiteReadinessSummary();
          updateSiteReadinessProgress();
          setSiteReadinessFlash((json.data && json.data.message) ? json.data.message : 'Site readiness saved.', true);
          hideSiteReadinessDrawer();
          window.setTimeout(function(){ window.location.reload(); }, 260);
        } catch (err) {
          setSiteReadinessFlash((err && err.message) ? err.message : 'Site readiness could not be saved.', false);
        } finally {
          if (siteReadinessSaveButton) {
            siteReadinessSaveButton.disabled = false;
          }
        }
      }
      function setHardwareAvailabilityFlash(message, isOk){
        if (!hardwareAvailabilityFlash) {
          return;
        }
        if (!message) {
          hardwareAvailabilityFlash.className = 'ado-site-readiness-flash';
          hardwareAvailabilityFlash.textContent = '';
          return;
        }
        hardwareAvailabilityFlash.className = 'ado-site-readiness-flash ' + (isOk ? 'ok' : 'err');
        hardwareAvailabilityFlash.textContent = String(message || '');
      }
      function normalizeHardwareAvailabilityDoorSearch(value){
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
      }
      function hardwareAvailabilitySelectedDoorIds(){
        var selectedDoorMap = {};
        hardwareAvailabilityDoorOptions.forEach(function(option){
          if (!option || option.classList.contains('is-hidden')) {
            return;
          }
          var input = option.querySelector('[data-hardware-availability-door-input]');
          if (!input || !input.checked) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || input.value || '').trim();
          if (doorId === '') {
            return;
          }
          selectedDoorMap[doorId] = true;
        });
        return Object.keys(selectedDoorMap);
      }
      function collectHardwareAvailabilityDoorLookupForSubmission(submissionId){
        var doorLookup = {};
        submissionId = String(submissionId || '').trim();
        if (submissionId === '') {
          return doorLookup;
        }
        var submissionState = hardwareAvailabilitySubmissionsById[submissionId];
        var submissionDoorIds = Array.isArray(submissionState && submissionState.door_ids) ? submissionState.door_ids : [];
        submissionDoorIds.forEach(function(doorIdRaw){
          var doorId = String(doorIdRaw || '').trim();
          if (doorId !== '') {
            doorLookup[doorId] = true;
          }
        });
        return doorLookup;
      }
      function collectHardwareAvailabilityReopenedDoorLookup(){
        var reopenedLookup = {};
        hardwareAvailabilityDoorOptions.forEach(function(option){
          if (!option) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || '').trim();
          if (doorId === '') {
            return;
          }
          var isReopened = String(option.getAttribute('data-door-reopened') || '0') === '1';
          if (isReopened) {
            reopenedLookup[doorId] = true;
          }
        });
        return reopenedLookup;
      }
      function collectHardwareAvailabilityReservedDoorLookup(excludedSubmissionId){
        var reservedLookup = {};
        var reopenedLookup = collectHardwareAvailabilityReopenedDoorLookup();
        excludedSubmissionId = String(excludedSubmissionId || '').trim();
        Object.keys(hardwareAvailabilitySubmissionsById || {}).forEach(function(submissionId){
          submissionId = String(submissionId || '').trim();
          if (submissionId === '' || submissionId === excludedSubmissionId) {
            return;
          }
          var submissionState = hardwareAvailabilitySubmissionsById[submissionId];
          var submissionDoorIds = Array.isArray(submissionState && submissionState.door_ids) ? submissionState.door_ids : [];
          submissionDoorIds.forEach(function(doorIdRaw){
            var doorId = String(doorIdRaw || '').trim();
            if (doorId !== '') {
              if (reopenedLookup[doorId]) {
                return;
              }
              reservedLookup[doorId] = true;
            }
          });
        });
        return reservedLookup;
      }
      function applyHardwareAvailabilityDoorAvailability(activeSubmissionId){
        activeSubmissionId = String(activeSubmissionId || '').trim();
        var reservedDoorLookup = collectHardwareAvailabilityReservedDoorLookup(activeSubmissionId);
        var activeDoorLookup = collectHardwareAvailabilityDoorLookupForSubmission(activeSubmissionId);
        hardwareAvailabilityDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-hardware-availability-door-input]');
          if (!input) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || input.value || '').trim();
          var isReserved = doorId !== '' && !!reservedDoorLookup[doorId] && !activeDoorLookup[doorId];
          option.classList.toggle('is-hidden', isReserved);
          if (isReserved) {
            input.checked = false;
            input.disabled = true;
          } else {
            input.disabled = false;
          }
        });
      }
      function renderHardwareAvailabilityDoorSelected(){
        if (!hardwareAvailabilityDoorSelected) {
          return;
        }
        var selectedDoorIds = hardwareAvailabilitySelectedDoorIds();
        if (!selectedDoorIds.length) {
          hardwareAvailabilityDoorSelected.innerHTML = '';
          hardwareAvailabilityDoorSelected.hidden = true;
          return;
        }
        var chipHtml = selectedDoorIds.map(function(selectedDoorId){
          var option = hardwareAvailabilityDoorOptions.find(function(optionNode){
            return String(optionNode.getAttribute('data-door-id') || '').trim() === selectedDoorId;
          });
          var doorLabel = option ? String(option.getAttribute('data-door-label') || selectedDoorId) : selectedDoorId;
          return '<span class=\"ado-schedule-door-chip\">'
            + escapeBookingHtml(doorLabel)
            + '<button type=\"button\" data-hardware-availability-door-remove=\"' + escapeBookingHtml(selectedDoorId) + '\" aria-label=\"Remove ' + escapeBookingHtml(doorLabel) + '\">&times;</button>'
            + '</span>';
        }).join('');
        hardwareAvailabilityDoorSelected.innerHTML = chipHtml;
        hardwareAvailabilityDoorSelected.hidden = false;
      }
      function applyHardwareAvailabilityDoorFilter(){
        var query = normalizeHardwareAvailabilityDoorSearch(hardwareAvailabilityDoorSearch ? hardwareAvailabilityDoorSearch.value : '');
        var visibleCount = 0;
        hardwareAvailabilityDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-hardware-availability-door-input]');
          if (!input || option.classList.contains('is-hidden')) {
            option.classList.add('is-filtered');
            return;
          }
          var searchText = normalizeHardwareAvailabilityDoorSearch(option.getAttribute('data-door-search') || '');
          var keepVisible = query === '' || input.checked || searchText.indexOf(query) !== -1;
          option.classList.toggle('is-filtered', !keepVisible);
          if (!option.classList.contains('is-filtered')) {
            visibleCount++;
          }
        });
        if (hardwareAvailabilityDoorClear) {
          hardwareAvailabilityDoorClear.hidden = query === '';
        }
        return visibleCount;
      }
      function updateHardwareAvailabilityDoorSelectionState(){
        var selectedDoorIds = hardwareAvailabilitySelectedDoorIds();
        var totalDoors = 0;
        hardwareAvailabilityDoorOptions.forEach(function(option){
          if (!option.classList.contains('is-hidden')) {
            totalDoors += 1;
          }
        });
        var visibleDoors = applyHardwareAvailabilityDoorFilter();
        hardwareAvailabilityDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-hardware-availability-door-input]');
          option.classList.toggle('is-selected', !!(input && input.checked));
        });
        if (hardwareAvailabilityDoorCount) {
          if (totalDoors <= 0) {
            hardwareAvailabilityDoorCount.textContent = 'All project doors already belong to saved hardware availability submittals.';
          } else if (selectedDoorIds.length <= 0) {
            hardwareAvailabilityDoorCount.textContent = 'Select at least one project door for this hardware-availability checklist.';
          } else {
            hardwareAvailabilityDoorCount.textContent = selectedDoorIds.length + ' of ' + totalDoors + ' doors selected.';
          }
        }
        if (hardwareAvailabilityDoorEmpty) {
          if (totalDoors <= 0) {
            hardwareAvailabilityDoorEmpty.textContent = 'All project doors are already assigned to saved hardware availability submittals. Open a submittal above to edit.';
          } else if (visibleDoors <= 0) {
            hardwareAvailabilityDoorEmpty.textContent = 'No project doors match the current search.';
          } else {
            hardwareAvailabilityDoorEmpty.textContent = 'No project doors are available to scope this checklist.';
          }
          hardwareAvailabilityDoorEmpty.hidden = visibleDoors > 0;
        }
        renderHardwareAvailabilityDoorSelected();
      }
      function setActiveHardwareAvailabilitySubmissionButton(submissionId){
        submissionId = String(submissionId || '').trim();
        hardwareAvailabilitySubmissionButtons.forEach(function(button){
          var buttonSubmissionId = String(button.getAttribute('data-hardware-availability-open-submission') || '').trim();
          button.classList.toggle('is-active', submissionId !== '' && buttonSubmissionId === submissionId);
        });
      }
      function buildEmptyHardwareAvailabilitySubmissionState(){
        var emptySections = {};
        hardwareAvailabilityStepPanels.forEach(function(panel){
          if (!panel) {
            return;
          }
          var sectionKey = String(panel.getAttribute('data-section-key') || '').trim();
          if (sectionKey === '') {
            return;
          }
          var emptyItems = {};
          panel.querySelectorAll('[data-hardware-availability-item]').forEach(function(itemNode){
            var itemKey = String(itemNode.getAttribute('data-item-key') || '').trim();
            if (itemKey === '') {
              return;
            }
            emptyItems[itemKey] = 0;
          });
          emptySections[sectionKey] = {
            items: emptyItems,
            note: ''
          };
        });
        return {
          submission_id: '',
          door_ids: [],
          sections: emptySections
        };
      }
      function applyHardwareAvailabilitySubmissionState(submissionState){
        submissionState = submissionState && typeof submissionState === 'object' ? submissionState : {};
        var doorLookup = {};
        var submissionDoorIds = Array.isArray(submissionState.door_ids) ? submissionState.door_ids : [];
        submissionDoorIds.forEach(function(doorIdRaw){
          var doorId = String(doorIdRaw || '').trim();
          if (doorId !== '') {
            doorLookup[doorId] = true;
          }
        });
        hardwareAvailabilityDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-hardware-availability-door-input]');
          if (!input) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || input.value || '').trim();
          input.checked = doorId !== '' && !!doorLookup[doorId];
        });
        var submissionSections = submissionState.sections && typeof submissionState.sections === 'object'
          ? submissionState.sections
          : {};
        hardwareAvailabilityStepPanels.forEach(function(panel){
          if (!panel) {
            return;
          }
          var sectionKey = String(panel.getAttribute('data-section-key') || '').trim();
          var sectionState = sectionKey !== '' && submissionSections[sectionKey] && typeof submissionSections[sectionKey] === 'object'
            ? submissionSections[sectionKey]
            : {};
          var sectionItems = sectionState.items && typeof sectionState.items === 'object' ? sectionState.items : {};
          panel.querySelectorAll('[data-hardware-availability-item]').forEach(function(itemNode){
            var itemKey = String(itemNode.getAttribute('data-item-key') || '').trim();
            if (itemKey === '') {
              itemNode.checked = false;
              return;
            }
            itemNode.checked = !!sectionItems[itemKey];
          });
          var noteNode = panel.querySelector('[data-hardware-availability-section-note]');
          if (noteNode) {
            noteNode.value = String(sectionState.note || '');
          }
        });
        updateHardwareAvailabilityDoorSelectionState();
        updateHardwareAvailabilitySummary();
        updateHardwareAvailabilityProgress();
      }
      function loadHardwareAvailabilitySubmission(submissionId){
        submissionId = String(submissionId || '').trim();
        if (submissionId === '') {
          if (hardwareAvailabilitySubmissionField) {
            hardwareAvailabilitySubmissionField.value = '';
          }
          setActiveHardwareAvailabilitySubmissionButton('');
          applyHardwareAvailabilityDoorAvailability('');
          applyHardwareAvailabilitySubmissionState(buildEmptyHardwareAvailabilitySubmissionState());
          return;
        }
        var submissionState = hardwareAvailabilitySubmissionsById[submissionId];
        if (!submissionState || typeof submissionState !== 'object') {
          return;
        }
        if (hardwareAvailabilitySubmissionField) {
          hardwareAvailabilitySubmissionField.value = submissionId;
        }
        setActiveHardwareAvailabilitySubmissionButton(submissionId);
        applyHardwareAvailabilityDoorAvailability(submissionId);
        applyHardwareAvailabilitySubmissionState(submissionState);
      }
      function updateHardwareAvailabilitySummary(){
        if (!hardwareAvailabilitySummary) {
          return;
        }
        var totalItems = 0;
        var confirmedItems = 0;
        hardwareAvailabilityStepPanels.forEach(function(panel, panelIndex){
          var sectionTotal = 0;
          var sectionConfirmed = 0;
          var tabButton = hardwareAvailabilityStepButtons[panelIndex] || null;
          var tabToggle = hardwareAvailabilitySectionToggles[panelIndex] || null;
          if (!panel) {
            if (tabButton) {
              tabButton.classList.remove('is-complete');
            }
            if (tabToggle) {
              tabToggle.checked = false;
              tabToggle.indeterminate = false;
              tabToggle.disabled = true;
            }
            return;
          }
          panel.querySelectorAll('[data-hardware-availability-item]').forEach(function(itemNode){
            sectionTotal += 1;
            totalItems += 1;
            if (itemNode.checked) {
              sectionConfirmed += 1;
              confirmedItems += 1;
            }
          });
          if (tabButton) {
            tabButton.classList.toggle('is-complete', sectionTotal > 0 && sectionConfirmed >= sectionTotal);
          }
          if (tabToggle) {
            if (sectionTotal <= 0) {
              tabToggle.checked = false;
              tabToggle.indeterminate = false;
              tabToggle.disabled = true;
            } else if (sectionConfirmed <= 0) {
              tabToggle.checked = false;
              tabToggle.indeterminate = false;
              tabToggle.disabled = false;
            } else if (sectionConfirmed >= sectionTotal) {
              tabToggle.checked = true;
              tabToggle.indeterminate = false;
              tabToggle.disabled = false;
            } else {
              tabToggle.checked = false;
              tabToggle.indeterminate = true;
              tabToggle.disabled = false;
            }
          }
        });
        if (totalItems <= 0) {
          hardwareAvailabilitySummary.textContent = 'No hardware-availability checklist items are configured yet.';
          return;
        }
        hardwareAvailabilitySummary.textContent = confirmedItems + ' of ' + totalItems + ' checklist items confirmed.';
      }
      function updateHardwareAvailabilityProgress(){
        if (!hardwareAvailabilityProgress) {
          return;
        }
        if (!hardwareAvailabilityStepPanels.length) {
          hardwareAvailabilityProgress.textContent = '';
          return;
        }
        var currentPanel = hardwareAvailabilityStepPanels[hardwareAvailabilityCurrentStep] || null;
        var currentTitleNode = currentPanel ? currentPanel.querySelector('h3') : null;
        var currentTitle = currentTitleNode ? String(currentTitleNode.textContent || '').trim() : '';
        var sectionItems = currentPanel ? currentPanel.querySelectorAll('[data-hardware-availability-item]') : [];
        var sectionChecked = 0;
        sectionItems.forEach(function(itemNode){
          if (itemNode.checked) {
            sectionChecked += 1;
          }
        });
        var sectionTotal = sectionItems.length || 0;
        var sectionLabel = 'Section ' + (hardwareAvailabilityCurrentStep + 1) + ' of ' + hardwareAvailabilityStepPanels.length;
        if (currentTitle !== '') {
          sectionLabel += ': ' + currentTitle;
        }
        if (sectionTotal > 0) {
          sectionLabel += ' (' + sectionChecked + '/' + sectionTotal + ' checked)';
        }
        hardwareAvailabilityProgress.textContent = sectionLabel;
      }
      function setHardwareAvailabilityStep(index){
        if (!hardwareAvailabilityStepPanels.length) {
          return;
        }
        var maxStep = hardwareAvailabilityStepPanels.length - 1;
        hardwareAvailabilityCurrentStep = Math.max(0, Math.min(index, maxStep));
        hardwareAvailabilityStepPanels.forEach(function(panel, panelIndex){
          panel.hidden = panelIndex !== hardwareAvailabilityCurrentStep;
        });
        hardwareAvailabilityStepButtons.forEach(function(button, buttonIndex){
          button.classList.toggle('is-active', buttonIndex === hardwareAvailabilityCurrentStep);
        });
        if (hardwareAvailabilityPrevButton) {
          hardwareAvailabilityPrevButton.disabled = hardwareAvailabilityCurrentStep <= 0;
        }
        if (hardwareAvailabilityNextButton) {
          var isLastStep = hardwareAvailabilityCurrentStep >= maxStep;
          hardwareAvailabilityNextButton.textContent = isLastStep ? 'Back To Section 1' : 'Next Section';
          hardwareAvailabilityNextButton.setAttribute('data-next-mode', isLastStep ? 'restart' : 'next');
        }
        updateHardwareAvailabilityProgress();
      }
      function collectHardwareAvailabilitySectionsPayload(){
        var sections = {};
        hardwareAvailabilityStepPanels.forEach(function(panel){
          if (!panel) {
            return;
          }
          var sectionKey = String(panel.getAttribute('data-section-key') || '').trim();
          if (sectionKey === '') {
            return;
          }
          var itemPayload = {};
          panel.querySelectorAll('[data-hardware-availability-item]').forEach(function(itemNode){
            var itemKey = String(itemNode.getAttribute('data-item-key') || '').trim();
            if (itemKey === '') {
              return;
            }
            itemPayload[itemKey] = itemNode.checked ? 1 : 0;
          });
          var noteNode = panel.querySelector('[data-hardware-availability-section-note]');
          sections[sectionKey] = {
            items: itemPayload,
            note: noteNode ? String(noteNode.value || '').trim() : ''
          };
        });
        return sections;
      }
      async function submitHardwareAvailabilityForm(){
        if (!hardwareAvailabilityForm) {
          return;
        }
        var projectIdValue = hardwareAvailabilityProjectField ? String(hardwareAvailabilityProjectField.value || '').trim() : '';
        if (projectIdValue === '') {
          setHardwareAvailabilityFlash('Project context is missing. Reload and try again.', false);
          return;
        }
        var selectedDoorIds = hardwareAvailabilitySelectedDoorIds();
        if (selectedDoorIds.length <= 0) {
          setHardwareAvailabilityFlash('Select at least one project door for this hardware-availability checklist.', false);
          return;
        }
        var sectionsPayload = collectHardwareAvailabilitySectionsPayload();
        if (!Object.keys(sectionsPayload).length) {
          setHardwareAvailabilityFlash('No hardware-availability sections are available to update yet.', false);
          return;
        }
        if (hardwareAvailabilitySaveButton) {
          hardwareAvailabilitySaveButton.disabled = true;
        }
        setHardwareAvailabilityFlash('', true);
        var payload = new FormData();
        payload.append('action', 'ado_save_client_hardware_availability');
        payload.append('nonce', nonce);
        payload.append('project_id', projectIdValue);
        var submissionIdValue = hardwareAvailabilitySubmissionField ? String(hardwareAvailabilitySubmissionField.value || '').trim() : '';
        if (submissionIdValue !== '') {
          payload.append('submission_id', submissionIdValue);
        }
        payload.append('door_ids', JSON.stringify(selectedDoorIds));
        payload.append('sections', JSON.stringify(sectionsPayload));
        try {
          var res = await fetch(ajaxUrl, { method:'POST', body: payload, credentials:'same-origin' });
          var json = await res.json();
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) ? json.data.message : 'Hardware availability could not be saved.');
          }
          if (hardwareAvailabilitySubmissionField && json && json.data && json.data.submission_id) {
            hardwareAvailabilitySubmissionField.value = String(json.data.submission_id || '').trim();
            setActiveHardwareAvailabilitySubmissionButton(hardwareAvailabilitySubmissionField.value);
          }
          updateHardwareAvailabilitySummary();
          updateHardwareAvailabilityProgress();
          setHardwareAvailabilityFlash((json.data && json.data.message) ? json.data.message : 'Hardware availability saved.', true);
          hideHardwareAvailabilityDrawer();
          window.setTimeout(function(){ window.location.reload(); }, 260);
        } catch (err) {
          setHardwareAvailabilityFlash((err && err.message) ? err.message : 'Hardware availability could not be saved.', false);
        } finally {
          if (hardwareAvailabilitySaveButton) {
            hardwareAvailabilitySaveButton.disabled = false;
          }
        }
      }
      function normalizeBookingSearch(value){
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
      }
      function renderScheduleDoorSelected(){
        if (!scheduleBookingDoorSelected) {
          return;
        }
        var selected = scheduleSelectedDoorInputs();
        if (!selected.length) {
          scheduleBookingDoorSelected.innerHTML = '';
          scheduleBookingDoorSelected.hidden = true;
          return;
        }
        var html = selected.map(function(input){
          var option = input.closest('[data-booking-door-option]');
          var doorId = option ? String(option.getAttribute('data-door-id') || input.value || '') : String(input.value || '');
          var doorLabel = option ? String(option.getAttribute('data-door-label') || doorId) : doorId;
          return '<span class=\"ado-schedule-door-chip\">'
            + escapeBookingHtml(doorLabel)
            + '<button type=\"button\" data-booking-door-remove=\"' + escapeBookingHtml(doorId) + '\" aria-label=\"Remove ' + escapeBookingHtml(doorLabel) + '\">&times;</button>'
            + '</span>';
        }).join('');
        scheduleBookingDoorSelected.innerHTML = html;
        scheduleBookingDoorSelected.hidden = false;
      }
      function applyScheduleDoorFilter(){
        var query = normalizeBookingSearch(scheduleBookingDoorSearch ? scheduleBookingDoorSearch.value : '');
        var visibleCount = 0;
        scheduleBookingDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-booking-door-input]');
          if (!input) {
            option.classList.add('is-filtered');
            return;
          }
          var optionText = normalizeBookingSearch(option.getAttribute('data-door-search') || '');
          var keepVisible = query === '' || input.checked || optionText.indexOf(query) !== -1;
          option.classList.toggle('is-filtered', !keepVisible);
          if (!option.classList.contains('is-hidden') && !option.classList.contains('is-filtered')) {
            visibleCount++;
          }
        });
        if (scheduleBookingDoorClear) {
          scheduleBookingDoorClear.hidden = query === '';
        }
        return visibleCount;
      }
      function scheduleSelectedDoorInputs(){
        return scheduleBookingDoorInputs.filter(function(input){
          var option = input.closest('[data-booking-door-option]');
          return !!option
            && !option.classList.contains('is-hidden')
            && !option.classList.contains('is-filtered')
            && !input.disabled
            && !!input.checked;
        });
      }
      function updateScheduleDoorSelectionState(){
        var dayState = scheduleBookingForm ? String(scheduleBookingForm.getAttribute('data-day-state') || 'available') : 'available';
        var hasOwnBookings = scheduleBookingForm && scheduleBookingForm.getAttribute('data-day-has-own-booking') === '1';
        var isDayBlocked = scheduleBookingForm && scheduleBookingForm.getAttribute('data-day-blocked') === '1';
        var isOwnBookedDay = dayState === 'own-booked' && hasOwnBookings;
        var searchQuery = normalizeBookingSearch(scheduleBookingDoorSearch ? scheduleBookingDoorSearch.value : '');
        var selectedCount = scheduleSelectedDoorInputs().length;
        var hasVisibleOptions = false;
        var hasReadyVisibleOptions = false;
        scheduleBookingDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-booking-door-input]');
          if (!input) {
            return;
          }
          var isGloballyBooked = String(option.getAttribute('data-door-booked') || '0') === '1';
          var isReadinessReady = String(option.getAttribute('data-door-readiness-ready') || '0') === '1';
          var isHardwareReady = String(option.getAttribute('data-door-hardware-ready') || '0') === '1';
          var isOwnBookedDoor = String(option.getAttribute('data-door-own-booked') || '0') === '1';
          var isHidden = option.classList.contains('is-hidden');
          var isFiltered = option.classList.contains('is-filtered');
          var readinessBlocked = !isReadinessReady || !isHardwareReady;
          if (!isHidden && !isFiltered) {
            hasVisibleOptions = true;
            if (!readinessBlocked && !isGloballyBooked) {
              hasReadyVisibleOptions = true;
            }
          }
          option.classList.toggle('is-readiness-pending', readinessBlocked);
          option.classList.toggle('is-selected', !!input.checked);
          if (isOwnBookedDay) {
            if (isHidden) {
              input.checked = false;
              input.disabled = true;
              return;
            }
            if (readinessBlocked) {
              input.checked = false;
              input.disabled = true;
              return;
            }
            if (isFiltered && !input.checked) {
              input.disabled = true;
              return;
            }
            // Keep the same max-two constraint while allowing replacements:
            // uncheck one first, then another option becomes selectable.
            input.disabled = !input.checked && selectedCount >= 2;
            return;
          }
          if (isGloballyBooked || isHidden || readinessBlocked) {
            input.checked = false;
            input.disabled = true;
            return;
          }
          if (isDayBlocked) {
            input.checked = false;
            input.disabled = true;
            return;
          }
          if (isFiltered && !input.checked) {
            input.disabled = true;
            return;
          }
          input.disabled = !input.checked && selectedCount >= 2;
        });
        if (scheduleBookingDoorCount) {
          if (!hasVisibleOptions) {
            scheduleBookingDoorCount.textContent = searchQuery !== ''
              ? 'No doors match your search.'
              : 'No doors currently available to book.';
          } else if (!isOwnBookedDay && !hasReadyVisibleOptions) {
            scheduleBookingDoorCount.textContent = 'Doors highlighted in orange require saved Site Readiness and Hardware Availability before booking.';
          } else if (isOwnBookedDay) {
            scheduleBookingDoorCount.textContent = selectedCount > 0
              ? String(selectedCount) + ' booked door(s) selected for this day.'
              : 'No doors are selected for this booked day.';
          } else if (selectedCount === 0) {
            scheduleBookingDoorCount.textContent = 'Choose up to two doors for this day.';
          } else {
            scheduleBookingDoorCount.textContent = String(selectedCount) + ' of 2 doors selected.';
          }
        }
        if (scheduleBookingDoorEmpty) {
          scheduleBookingDoorEmpty.hidden = hasVisibleOptions;
        }
        var ownSelectedMap = isOwnBookedDay ? selectedDoorMapForBooking() : {};
        var hasOwnBookingChanges = isOwnBookedDay
          ? !bookingDoorMapsEqual(ownSelectedMap, scheduleBookingOwnDoorBaselineMap)
          : false;
        var canSubmit = !isDayBlocked && selectedCount > 0;
        if (!isOwnBookedDay) {
          canSubmit = canSubmit && hasVisibleOptions;
        } else {
          canSubmit = canSubmit && hasOwnBookingChanges;
        }
        if (scheduleBookingSubmit) {
          scheduleBookingSubmit.hidden = isOwnBookedDay ? !hasOwnBookingChanges : false;
          scheduleBookingSubmit.disabled = !canSubmit;
          scheduleBookingSubmit.setAttribute('aria-disabled', canSubmit ? 'false' : 'true');
        }
        if (scheduleBookingNotes) {
          scheduleBookingNotes.disabled = isOwnBookedDay ? true : !canSubmit;
        }
        renderScheduleDoorSelected();
      }
      function renderScheduleExistingRows(dayKey, dayState){
        if (!scheduleBookingExistingWrap || !scheduleBookingExistingList) {
          return { totalCount: 0, ownCount: 0, renderedCount: 0 };
        }
        var rows = Array.isArray(scheduleBookingExistingByDate[dayKey]) ? scheduleBookingExistingByDate[dayKey] : [];
        if (!rows.length) {
          scheduleBookingExistingList.innerHTML = '';
          scheduleBookingExistingWrap.hidden = true;
          if (scheduleBookingExistingTitle) {
            scheduleBookingExistingTitle.textContent = 'Booked doors for this day';
          }
          return { totalCount: 0, ownCount: 0, renderedCount: 0 };
        }
        var ownRows = rows.filter(function(row){
          return normalizeBookingBool(row && row.is_own_booking);
        });
        if (dayState === 'own-booked') {
          scheduleBookingExistingList.innerHTML = '';
          scheduleBookingExistingWrap.hidden = true;
          if (scheduleBookingExistingTitle) {
            scheduleBookingExistingTitle.textContent = 'Booked doors for this day';
          }
          return {
            totalCount: rows.length,
            ownCount: ownRows.length,
            renderedCount: 0
          };
        }
        var rowsToRender = rows;
        if (scheduleBookingExistingTitle) {
          scheduleBookingExistingTitle.textContent = 'Booked doors for this day';
        }
        var html = rowsToRender.map(function(row){
          var bookingId = escapeBookingHtml(row.booking_id || '');
          var scheduleDate = escapeBookingHtml(row.schedule_date || dayKey || '');
          var doorIds = Array.isArray(row.door_ids) ? row.door_ids.map(function(doorId){ return escapeBookingHtml(doorId); }) : [];
          var labels = Array.isArray(row.door_labels) ? row.door_labels.map(function(label){ return escapeBookingHtml(label); }).join(', ') : '';
          var createdAt = escapeBookingHtml(row.created_at || '');
          var note = escapeBookingHtml(row.note || '');
          var createdByName = String(row.created_by_name || '').trim();
          var createdByEmail = String(row.created_by_email || '').trim();
          var eventId = escapeBookingHtml(row.google_event_id || '');
          var eventLink = safeBookingUrl(row.google_event_link || '');
          var isOwnBooking = normalizeBookingBool(row && row.is_own_booking);
          var rowProjectId = String((row && row.project_id) || scheduleBookingDefaultProjectId || '').trim();
          var doorIdsHtml = doorIds.length ? '<small>Door IDs: ' + doorIds.join(', ') + '</small>' : '';
          var dateHtml = scheduleDate !== '' ? '<small>Date: ' + scheduleDate + '</small>' : '';
          var bookingRefHtml = bookingId !== '' ? '<small>Booking ID: ' + bookingId + '</small>' : '';
          var bookedByValue = createdByName !== '' ? createdByName : 'Client';
          if (createdByEmail !== '') {
            bookedByValue += ' (' + createdByEmail + ')';
          }
          var bookedByHtml = '<small>Booked by: ' + escapeBookingHtml(bookedByValue) + '</small>';
          var noteHtml = note !== '' ? '<small>Note: ' + note + '</small>' : '';
          var createdHtml = createdAt !== '' ? '<small>Booked: ' + createdAt + '</small>' : '';
          var googleHtml = '';
          if (eventLink !== '') {
            googleHtml = '<small>Google event: <a href=\"' + escapeBookingHtml(eventLink) + '\" target=\"_blank\" rel=\"noopener noreferrer\">Open calendar event</a></small>';
          } else if (eventId !== '') {
            googleHtml = '<small>Google event ID: ' + eventId + '</small>';
          }
          var cancelButtonHtml = (isOwnBooking && bookingId !== '')
            ? '<button class=\"ado-btn\" type=\"button\" data-booking-cancel=\"' + bookingId + '\" data-booking-cancel-project=\"' + escapeBookingHtml(rowProjectId) + '\">Cancel</button>'
            : '';
          return '<div class=\"ado-schedule-booking-existing-item\">'
            + '<div class=\"ado-schedule-booking-existing-copy\"><strong>' + labels + '</strong>' + dateHtml + doorIdsHtml + bookingRefHtml + bookedByHtml + createdHtml + noteHtml + googleHtml + '</div>'
            + cancelButtonHtml
            + '</div>';
        }).join('');
        scheduleBookingExistingList.innerHTML = html;
        scheduleBookingExistingWrap.hidden = false;
        return {
          totalCount: rows.length,
          ownCount: ownRows.length,
          renderedCount: rowsToRender.length
        };
      }
      function renderScheduleDoorOptionsForDay(dayState, ownDoorMap, hasOwnBookings, targetProjectId){
        ownDoorMap = ownDoorMap && typeof ownDoorMap === 'object' ? ownDoorMap : {};
        var isPastDay = dayState === 'past';
        var isBlockedDay = dayState === 'booked' || isPastDay;
        var isOwnBookedDay = dayState === 'own-booked' && !!hasOwnBookings;
        var normalizedTargetProjectId = String(targetProjectId || scheduleBookingDefaultProjectId || '').trim();
        scheduleBookingDoorOptions.forEach(function(option){
          var input = option.querySelector('[data-booking-door-input]');
          if (!input) {
            return;
          }
          var doorId = String(option.getAttribute('data-door-id') || '').trim();
          var doorProjectId = String(option.getAttribute('data-door-project-id') || scheduleBookingDefaultProjectId || '').trim();
          var isGloballyBooked = String(option.getAttribute('data-door-booked') || '0') === '1';
          var isReadinessReady = String(option.getAttribute('data-door-readiness-ready') || '0') === '1';
          var isHardwareReady = String(option.getAttribute('data-door-hardware-ready') || '0') === '1';
          var doorKey = bookingDoorMapKey(doorProjectId, doorId);
          var isOwnBookedDoor = doorKey !== '' && !!ownDoorMap[doorKey];
          var isDifferentProject = normalizedTargetProjectId !== '' && doorProjectId !== '' && doorProjectId !== normalizedTargetProjectId;
          var readinessBlocked = !isReadinessReady || !isHardwareReady;
          var shouldHide = isDifferentProject || (isGloballyBooked && !(isOwnBookedDay && isOwnBookedDoor));
          option.setAttribute('data-door-own-booked', isOwnBookedDoor ? '1' : '0');
          option.classList.toggle('is-hidden', shouldHide);
          option.classList.toggle('is-readiness-pending', readinessBlocked);
          if (shouldHide || isBlockedDay) {
            input.checked = false;
          }
          if (isOwnBookedDay) {
            input.checked = isOwnBookedDoor;
          }
          input.disabled = shouldHide || isBlockedDay || readinessBlocked;
        });
        applyScheduleDoorFilter();
        updateScheduleDoorSelectionState();
      }
      function showScheduleBookingDrawer(){
        if (!scheduleBookingDrawer || !scheduleBookingBackdrop) {
          return;
        }
        hideSiteReadinessDrawer();
        hideHardwareAvailabilityDrawer();
        scheduleBookingDrawer.hidden = false;
        scheduleBookingBackdrop.hidden = false;
        scheduleBookingDrawer.setAttribute('aria-hidden', 'false');
        window.requestAnimationFrame(function(){
          scheduleBookingDrawer.classList.add('is-open');
          scheduleBookingBackdrop.classList.add('is-open');
          document.body.classList.add('ado-schedule-booking-open');
        });
      }
      function hideScheduleBookingDrawer(){
        if (!scheduleBookingDrawer || !scheduleBookingBackdrop) {
          return;
        }
        scheduleBookingDrawer.classList.remove('is-open');
        scheduleBookingBackdrop.classList.remove('is-open');
        scheduleBookingDrawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ado-schedule-booking-open');
        window.setTimeout(function(){
          if (!scheduleBookingDrawer.classList.contains('is-open')) {
            scheduleBookingDrawer.hidden = true;
          }
          if (!scheduleBookingBackdrop.classList.contains('is-open')) {
            scheduleBookingBackdrop.hidden = true;
          }
        }, 180);
      }
      function showSiteReadinessDrawer(){
        if (!siteReadinessDrawer || !siteReadinessBackdrop) {
          return;
        }
        hideScheduleBookingDrawer();
        hideHardwareAvailabilityDrawer();
        updateSiteReadinessSummary();
        updateSiteReadinessDoorSelectionState();
        setSiteReadinessStep(siteReadinessCurrentStep);
        setSiteReadinessFlash('', true);
        siteReadinessDrawer.hidden = false;
        siteReadinessBackdrop.hidden = false;
        siteReadinessDrawer.setAttribute('aria-hidden', 'false');
        window.requestAnimationFrame(function(){
          siteReadinessDrawer.classList.add('is-open');
          siteReadinessBackdrop.classList.add('is-open');
          document.body.classList.add('ado-site-readiness-open');
        });
      }
      function hideSiteReadinessDrawer(){
        if (!siteReadinessDrawer || !siteReadinessBackdrop) {
          return;
        }
        siteReadinessDrawer.classList.remove('is-open');
        siteReadinessBackdrop.classList.remove('is-open');
        siteReadinessDrawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ado-site-readiness-open');
        window.setTimeout(function(){
          if (!siteReadinessDrawer.classList.contains('is-open')) {
            siteReadinessDrawer.hidden = true;
          }
          if (!siteReadinessBackdrop.classList.contains('is-open')) {
            siteReadinessBackdrop.hidden = true;
          }
        }, 180);
      }
      function showHardwareAvailabilityDrawer(){
        if (!hardwareAvailabilityDrawer || !hardwareAvailabilityBackdrop) {
          return;
        }
        hideScheduleBookingDrawer();
        hideSiteReadinessDrawer();
        updateHardwareAvailabilitySummary();
        updateHardwareAvailabilityDoorSelectionState();
        setHardwareAvailabilityStep(hardwareAvailabilityCurrentStep);
        setHardwareAvailabilityFlash('', true);
        hardwareAvailabilityDrawer.hidden = false;
        hardwareAvailabilityBackdrop.hidden = false;
        hardwareAvailabilityDrawer.setAttribute('aria-hidden', 'false');
        window.requestAnimationFrame(function(){
          hardwareAvailabilityDrawer.classList.add('is-open');
          hardwareAvailabilityBackdrop.classList.add('is-open');
          document.body.classList.add('ado-site-readiness-open');
        });
      }
      function hideHardwareAvailabilityDrawer(){
        if (!hardwareAvailabilityDrawer || !hardwareAvailabilityBackdrop) {
          return;
        }
        hardwareAvailabilityDrawer.classList.remove('is-open');
        hardwareAvailabilityBackdrop.classList.remove('is-open');
        hardwareAvailabilityDrawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ado-site-readiness-open');
        window.setTimeout(function(){
          if (!hardwareAvailabilityDrawer.classList.contains('is-open')) {
            hardwareAvailabilityDrawer.hidden = true;
          }
          if (!hardwareAvailabilityBackdrop.classList.contains('is-open')) {
            hardwareAvailabilityBackdrop.hidden = true;
          }
        }, 180);
      }
      function setScheduleBookingDay(dayCell){
        if (!scheduleBookingDrawer || !dayCell) {
          return;
        }
        clearScheduleDaySelection();
        dayCell.classList.add('is-selected');
        var dayKey = String(dayCell.getAttribute('data-day-key') || '');
        var dayLabel = String(dayCell.getAttribute('data-day-label') || dayKey || 'Selected Day');
        var dayState = String(dayCell.getAttribute('data-day-state') || 'available');
        var isPast = dayState === 'past';
        var isBlocked = dayState === 'booked' || isPast;
        var ownBookingState = collectOwnBookingStateForDay(dayKey);
        var hasOwnBookings = dayState === 'own-booked' && ownBookingState.rows.length > 0;
        var ownProjectIds = Object.keys(ownBookingState.projectIds || {});
        var selectedBookingProjectId = scheduleBookingDefaultProjectId;
        if (hasOwnBookings && ownProjectIds.length > 0) {
          selectedBookingProjectId = String(ownProjectIds[0] || '').trim();
        }
        if (scheduleBookingProjectField) {
          scheduleBookingProjectField.value = selectedBookingProjectId;
        }
        scheduleBookingOwnDayBookingProjectIds = {};
        scheduleBookingOwnDayBookingIds = [];
        ownBookingState.bookingIds.forEach(function(bookingId){
          bookingId = String(bookingId || '').trim();
          if (bookingId === '') {
            return;
          }
          var bookingProjectId = String((ownBookingState.bookingProjectIds && ownBookingState.bookingProjectIds[bookingId]) || selectedBookingProjectId || '').trim();
          scheduleBookingOwnDayBookingProjectIds[bookingId] = bookingProjectId;
          if (!hasOwnBookings || bookingProjectId === selectedBookingProjectId) {
            scheduleBookingOwnDayBookingIds.push(bookingId);
          }
        });
        if (hasOwnBookings && !scheduleBookingOwnDayBookingIds.length) {
          scheduleBookingOwnDayBookingIds = ownBookingState.bookingIds.slice();
        }
        if (hasOwnBookings) {
          var scopedOwnDoorMap = {};
          Object.keys(ownBookingState.doorMap || {}).forEach(function(doorKey){
            if (selectedBookingProjectId !== '' && doorKey.indexOf(selectedBookingProjectId + '::') !== 0) {
              return;
            }
            scopedOwnDoorMap[doorKey] = true;
          });
          scheduleBookingOwnDoorBaselineMap = cloneBookingDoorMap(scopedOwnDoorMap);
        } else {
          scheduleBookingOwnDoorBaselineMap = {};
        }
        if (scheduleBookingForm) {
          scheduleBookingForm.setAttribute('data-day-blocked', isBlocked ? '1' : '0');
          scheduleBookingForm.setAttribute('data-day-state', dayState);
          scheduleBookingForm.setAttribute('data-day-has-own-booking', hasOwnBookings ? '1' : '0');
        }
        if (scheduleBookingDayLabel) {
          scheduleBookingDayLabel.textContent = dayLabel;
        }
        if (scheduleBookingDateInput) {
          scheduleBookingDateInput.value = dayKey;
        }
        if (scheduleBookingDateDisplay) {
          scheduleBookingDateDisplay.value = dayLabel;
        }
        if (scheduleBookingDoorSearch) {
          scheduleBookingDoorSearch.value = '';
        }
        if (scheduleBookingDayState) {
          if (isPast) {
            scheduleBookingDayState.textContent = 'This date is in the past and cannot be booked.';
          } else if (hasOwnBookings) {
            var ownProjectName = String(((ownBookingState.rows[0] || {}).project_name) || '').trim();
            scheduleBookingDayState.textContent = ownProjectName !== ''
              ? 'Booked doors are preselected below for ' + ownProjectName + '. Adjust checkmarks, then click Book Selected Doors to update calendars.'
              : 'Booked doors are preselected below for this day. Adjust checkmarks, then click Book Selected Doors to update calendars.';
          } else if (dayState === 'booked') {
            scheduleBookingDayState.textContent = 'This day already has calendar events and is blocked for new bookings.';
          } else {
            scheduleBookingDayState.textContent = 'This day has no calendar events. Search and choose up to two doors.';
          }
        }
        setScheduleBookingActionMode(dayState, hasOwnBookings, scheduleBookingOwnDayBookingIds);
        var existingRows = renderScheduleExistingRows(dayKey, dayState);
        renderScheduleDoorOptionsForDay(dayState, ownBookingState.doorMap, hasOwnBookings, selectedBookingProjectId);
        var confirmationEligibleDoorCount = scheduleBookingDoorOptions.filter(function(option){
          var doorProjectId = String(option.getAttribute('data-door-project-id') || scheduleBookingDefaultProjectId || '').trim();
          var isProjectMatch = selectedBookingProjectId === ''
            || doorProjectId === ''
            || doorProjectId === selectedBookingProjectId;
          if (!isProjectMatch) {
            return false;
          }
          var isReadinessReady = String(option.getAttribute('data-door-readiness-ready') || '0') === '1';
          var isHardwareReady = String(option.getAttribute('data-door-hardware-ready') || '0') === '1';
          return isReadinessReady && isHardwareReady;
        }).length;
        var visibleDoorCount = scheduleBookingDoorOptions.filter(function(option){
          return !option.classList.contains('is-hidden') && !option.classList.contains('is-filtered');
        }).length;
        var selectedCount = scheduleSelectedDoorInputs().length;
        var hasOwnBookingChanges = hasOwnBookings
          ? !bookingDoorMapsEqual(selectedDoorMapForBooking(), scheduleBookingOwnDoorBaselineMap)
          : false;
        var canSubmit = !isBlocked && dayKey !== '' && selectedCount > 0;
        if (!hasOwnBookings) {
          canSubmit = canSubmit && visibleDoorCount > 0;
        } else {
          canSubmit = canSubmit && hasOwnBookingChanges;
        }
        if (!hasOwnBookings && !isBlocked && confirmationEligibleDoorCount <= 0 && scheduleBookingDayState) {
          scheduleBookingDayState.textContent = 'Complete and save Confirm Site Readiness and Confirm Hardware Availability before booking.';
        } else if (!hasOwnBookings && !isBlocked && visibleDoorCount <= 0 && scheduleBookingDayState) {
          scheduleBookingDayState.textContent = 'All project doors are already booked. Cancel a booking to free doors.';
        } else if (!hasOwnBookings && !isBlocked && existingRows.totalCount > 0 && scheduleBookingDayState) {
          scheduleBookingDayState.textContent = 'Some doors are already booked on this day. You can cancel them below.';
        }
        if (scheduleBookingSubmit) {
          scheduleBookingSubmit.hidden = hasOwnBookings ? !hasOwnBookingChanges : false;
          scheduleBookingSubmit.disabled = !canSubmit;
          scheduleBookingSubmit.setAttribute('aria-disabled', scheduleBookingSubmit.disabled ? 'true' : 'false');
        }
        if (scheduleBookingNotes) {
          scheduleBookingNotes.disabled = hasOwnBookings ? true : !canSubmit;
        }
        setScheduleBookingFlash('', true);
      }
      async function submitScheduleBookingForm(){
        if (!scheduleBookingForm) {
          return;
        }
        var currentDayState = String(scheduleBookingForm.getAttribute('data-day-state') || 'available');
        var hasOwnBookings = scheduleBookingForm.getAttribute('data-day-has-own-booking') === '1';
        var formData = new FormData(scheduleBookingForm);
        var dayKey = String(formData.get('schedule_date') || '').trim();
        if (dayKey === '') {
          setScheduleBookingFlash('Select a day before submitting booking.', false);
          return;
        }
        var selectedDoorIds = scheduleSelectedDoorInputs().map(function(input){
          return String(input.value || '').trim();
        }).filter(function(doorId){ return doorId !== ''; });
        if (selectedDoorIds.length <= 0) {
          setScheduleBookingFlash('Select at least one door to book.', false);
          return;
        }
        if (selectedDoorIds.length > 2) {
          setScheduleBookingFlash('Select no more than two doors for a single day.', false);
          return;
        }
        if (currentDayState === 'own-booked' && hasOwnBookings) {
          if (!scheduleBookingOwnDayBookingIds.length) {
            setScheduleBookingFlash('No existing booking reference was found for this day. Reload and try again.', false);
            return;
          }
          scheduleBookingOwnDayBookingIds.forEach(function(bookingId){
            bookingId = String(bookingId || '').trim();
            if (bookingId !== '') {
              formData.append('replace_booking_ids[]', bookingId);
            }
          });
        }
        if (scheduleBookingSubmit) {
          scheduleBookingSubmit.disabled = true;
        }
        formData.append('action', 'ado_submit_client_schedule_request');
        formData.append('nonce', nonce);
        try {
          var res = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
          var json = await res.json();
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) ? json.data.message : 'Booking could not be submitted.');
          }
          setScheduleBookingFlash((json.data && json.data.message) ? json.data.message : 'Booking submitted.', true);
          window.setTimeout(function(){ window.location.reload(); }, 420);
        } catch (err) {
          setScheduleBookingFlash((err && err.message) ? err.message : 'Booking could not be submitted.', false);
        } finally {
          if (scheduleBookingSubmit && scheduleBookingSubmit.getAttribute('aria-disabled') !== 'true') {
            scheduleBookingSubmit.disabled = false;
          }
          updateScheduleDoorSelectionState();
        }
      }
      async function cancelScheduleBookingRequest(bookingId, projectIdOverride){
        bookingId = String(bookingId || '').trim();
        if (bookingId === '') {
          throw new Error('Booking reference is missing.');
        }
        var projectIdValue = String(projectIdOverride || '').trim();
        if (projectIdValue === '' && scheduleBookingProjectField) {
          projectIdValue = String(scheduleBookingProjectField.value || '').trim();
        }
        if (projectIdValue === '') {
          projectIdValue = scheduleBookingDefaultProjectId;
        }
        var payload = new FormData();
        payload.append('action', 'ado_cancel_client_schedule_request');
        payload.append('nonce', nonce);
        if (projectIdValue !== '') {
          payload.append('project_id', projectIdValue);
        }
        payload.append('booking_id', bookingId);
        var res = await fetch(ajaxUrl, { method:'POST', body: payload, credentials:'same-origin' });
        var json = await res.json();
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) ? json.data.message : 'Booking could not be cancelled.');
        }
        return json;
      }
      async function cancelScheduleBooking(bookingId, trigger, projectId){
        if (trigger) {
          trigger.disabled = true;
        }
        try {
          var json = await cancelScheduleBookingRequest(bookingId, projectId);
          setScheduleBookingFlash((json.data && json.data.message) ? json.data.message : 'Booking cancelled.', true);
          window.setTimeout(function(){ window.location.reload(); }, 420);
        } catch (err) {
          setScheduleBookingFlash((err && err.message) ? err.message : 'Booking could not be cancelled.', false);
          if (trigger) {
            trigger.disabled = false;
          }
        }
      }
      async function cancelScheduleBookingsForDay(bookingIds, trigger){
        var ids = Array.isArray(bookingIds) ? bookingIds.map(function(id){ return String(id || '').trim(); }).filter(function(id){ return id !== ''; }) : [];
        if (!ids.length) {
          setScheduleBookingFlash('No booking is available to cancel for this day.', false);
          return;
        }
        if (trigger) {
          trigger.disabled = true;
        }
        try {
          var lastJson = null;
          for (var i = 0; i < ids.length; i++) {
            var bookingProjectId = String(scheduleBookingOwnDayBookingProjectIds[ids[i]] || '').trim();
            lastJson = await cancelScheduleBookingRequest(ids[i], bookingProjectId);
          }
          setScheduleBookingFlash((lastJson && lastJson.data && lastJson.data.message) ? lastJson.data.message : 'Booking cancelled.', true);
          window.setTimeout(function(){ window.location.reload(); }, 420);
        } catch (err) {
          setScheduleBookingFlash((err && err.message) ? err.message : 'Booking could not be cancelled.', false);
          if (trigger) {
            trigger.disabled = false;
          }
        }
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
          hideSiteReadinessDrawer();
          hideHardwareAvailabilityDrawer();
          if (target !== 'doors') {
            closeDoorDrawer();
          }
          if (target !== 'overview') {
            hideScheduleBookingDrawer();
          }
        });
      });
      var overviewJumpButtons = appRoot.querySelectorAll('[data-overview-jump-tab]');
      overviewJumpButtons.forEach(function(button){
        button.addEventListener('click', function(ev){
          ev.preventDefault();
          var target = String(button.getAttribute('data-overview-jump-tab') || '').trim();
          if (!target) {
            return;
          }
          activateTab(target);
          hideSiteReadinessDrawer();
          hideHardwareAvailabilityDrawer();
          if (target !== 'doors') {
            closeDoorDrawer();
          }
          if (target !== 'overview') {
            hideScheduleBookingDrawer();
          }
        });
      });
      var overviewOpenSiteReadinessButtons = appRoot.querySelectorAll('[data-overview-open-site-readiness]');
      overviewOpenSiteReadinessButtons.forEach(function(button){
        button.addEventListener('click', function(ev){
          ev.preventDefault();
          if (!siteReadinessDrawer) {
            return;
          }
          activateTab('overview');
          loadSiteReadinessSubmission('');
          setSiteReadinessStep(0);
          showSiteReadinessDrawer();
        });
      });
      var overviewOpenHardwareAvailabilityButtons = appRoot.querySelectorAll('[data-overview-open-hardware-availability]');
      overviewOpenHardwareAvailabilityButtons.forEach(function(button){
        button.addEventListener('click', function(ev){
          ev.preventDefault();
          if (!hardwareAvailabilityDrawer) {
            return;
          }
          activateTab('overview');
          loadHardwareAvailabilitySubmission('');
          setHardwareAvailabilityStep(0);
          showHardwareAvailabilityDrawer();
        });
      });
      var overviewBookingCard = appRoot.querySelector('[data-overview-booking-card]');
      var overviewScrollBookingButtons = appRoot.querySelectorAll('[data-overview-scroll-booking]');
      overviewScrollBookingButtons.forEach(function(button){
        button.addEventListener('click', function(ev){
          ev.preventDefault();
          activateTab('overview');
          hideSiteReadinessDrawer();
          hideHardwareAvailabilityDrawer();
          closeDoorDrawer();
          hideScheduleBookingDrawer();
          if (overviewBookingCard && typeof overviewBookingCard.scrollIntoView === 'function') {
            overviewBookingCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      });

      var scheduleCalendar = appRoot.querySelector('[data-schedule-calendar]');
      if (scheduleCalendar) {
        var calendarMonths = Array.prototype.slice.call(scheduleCalendar.querySelectorAll('[data-calendar-month]'));
        var calendarLabel = scheduleCalendar.querySelector('[data-calendar-current-label]');
        var prevMonthBtn = scheduleCalendar.querySelector('[data-calendar-nav=\"prev\"]');
        var nextMonthBtn = scheduleCalendar.querySelector('[data-calendar-nav=\"next\"]');
        var activeMonthIndex = 0;
        for (var m = 0; m < calendarMonths.length; m++) {
          if (!calendarMonths[m].hidden) {
            activeMonthIndex = m;
            break;
          }
        }
        function renderCalendarMonth(index) {
          if (!calendarMonths.length) {
            return;
          }
          activeMonthIndex = Math.max(0, Math.min(index, calendarMonths.length - 1));
          calendarMonths.forEach(function(monthRow, monthIndex){
            monthRow.hidden = monthIndex !== activeMonthIndex;
          });
          var activeMonth = calendarMonths[activeMonthIndex];
          if (calendarLabel && activeMonth) {
            calendarLabel.textContent = String(activeMonth.getAttribute('data-month-label') || '');
          }
          if (prevMonthBtn) {
            prevMonthBtn.disabled = activeMonthIndex <= 0;
          }
          if (nextMonthBtn) {
            nextMonthBtn.disabled = activeMonthIndex >= (calendarMonths.length - 1);
          }
        }
        if (prevMonthBtn) {
          prevMonthBtn.addEventListener('click', function(){
            renderCalendarMonth(activeMonthIndex - 1);
          });
        }
        if (nextMonthBtn) {
          nextMonthBtn.addEventListener('click', function(){
            renderCalendarMonth(activeMonthIndex + 1);
          });
        }
        renderCalendarMonth(activeMonthIndex);

        scheduleCalendar.addEventListener('click', function(ev){
          var dayCell = ev.target.closest('[data-calendar-day]');
          if (!dayCell || !scheduleCalendar.contains(dayCell)) {
            return;
          }
          var dayState = String(dayCell.getAttribute('data-day-state') || '');
          if (dayState !== 'available' && dayState !== 'own-booked') {
            return;
          }
          setScheduleBookingDay(dayCell);
          showScheduleBookingDrawer();
        });
        scheduleCalendar.addEventListener('keydown', function(ev){
          if (ev.key !== 'Enter' && ev.key !== ' ') {
            return;
          }
          var dayCell = ev.target.closest('[data-calendar-day]');
          if (!dayCell || !scheduleCalendar.contains(dayCell)) {
            return;
          }
          var dayState = String(dayCell.getAttribute('data-day-state') || '');
          if (dayState !== 'available' && dayState !== 'own-booked') {
            return;
          }
          ev.preventDefault();
          setScheduleBookingDay(dayCell);
          showScheduleBookingDrawer();
        });
        var firstAvailableDay = scheduleCalendar.querySelector('[data-calendar-day][data-day-state=\"available\"]');
        if (firstAvailableDay) {
          setScheduleBookingDay(firstAvailableDay);
        }
      }
      if (scheduleBookingDrawer) {
        scheduleBookingDrawer.querySelectorAll('[data-booking-close]').forEach(function(closeButton){
          closeButton.addEventListener('click', function(){
            hideScheduleBookingDrawer();
          });
        });
        scheduleBookingDrawer.addEventListener('click', function(ev){
          var cancelDayButton = ev.target.closest('[data-booking-cancel-day]');
          if (cancelDayButton && scheduleBookingDrawer.contains(cancelDayButton)) {
            ev.preventDefault();
            cancelScheduleBookingsForDay(scheduleBookingOwnDayBookingIds, cancelDayButton);
            return;
          }
          var removeButton = ev.target.closest('[data-booking-door-remove]');
          if (removeButton && scheduleBookingDrawer.contains(removeButton)) {
            ev.preventDefault();
            var removeDoorId = String(removeButton.getAttribute('data-booking-door-remove') || '');
            scheduleBookingDoorOptions.forEach(function(option){
              if (String(option.getAttribute('data-door-id') || '') !== removeDoorId) {
                return;
              }
              var input = option.querySelector('[data-booking-door-input]');
              if (input) {
                input.checked = false;
              }
            });
            applyScheduleDoorFilter();
            updateScheduleDoorSelectionState();
            return;
          }
          var cancelButton = ev.target.closest('[data-booking-cancel]');
          if (!cancelButton || !scheduleBookingDrawer.contains(cancelButton)) {
            return;
          }
          ev.preventDefault();
          cancelScheduleBooking(
            cancelButton.getAttribute('data-booking-cancel') || '',
            cancelButton,
            cancelButton.getAttribute('data-booking-cancel-project') || ''
          );
        });
      }
      if (scheduleBookingBackdrop) {
        scheduleBookingBackdrop.addEventListener('click', function(){
          hideScheduleBookingDrawer();
        });
      }
      if (scheduleBookingForm) {
        scheduleBookingForm.addEventListener('submit', function(ev){
          ev.preventDefault();
          submitScheduleBookingForm();
        });
      }
      if (scheduleBookingDoorInputs.length) {
        scheduleBookingDoorInputs.forEach(function(input){
          input.addEventListener('change', function(){
            applyScheduleDoorFilter();
            updateScheduleDoorSelectionState();
          });
        });
      }
      if (scheduleBookingDoorSearch) {
        scheduleBookingDoorSearch.addEventListener('input', function(){
          applyScheduleDoorFilter();
          updateScheduleDoorSelectionState();
        });
      }
      if (scheduleBookingDoorClear) {
        scheduleBookingDoorClear.addEventListener('click', function(ev){
          ev.preventDefault();
          if (scheduleBookingDoorSearch) {
            scheduleBookingDoorSearch.value = '';
            scheduleBookingDoorSearch.focus();
          }
          applyScheduleDoorFilter();
          updateScheduleDoorSelectionState();
        });
      }
      if (siteReadinessSubmissionButtons.length) {
        siteReadinessSubmissionButtons.forEach(function(button){
          button.addEventListener('click', function(ev){
            ev.preventDefault();
            var submissionId = String(button.getAttribute('data-site-readiness-open-submission') || '').trim();
            loadSiteReadinessSubmission(submissionId);
            setSiteReadinessStep(0);
            showSiteReadinessDrawer();
          });
        });
      }
      if (siteReadinessTrigger) {
        siteReadinessTrigger.addEventListener('click', function(ev){
          ev.preventDefault();
          loadSiteReadinessSubmission('');
          setSiteReadinessStep(0);
          showSiteReadinessDrawer();
        });
      }
      if (siteReadinessStepPanels.length) {
        setSiteReadinessStep(0);
        updateSiteReadinessSummary();
      }
      if (siteReadinessDoorOptions.length) {
        var initialSiteReadinessSubmissionId = siteReadinessSubmissionField ? String(siteReadinessSubmissionField.value || '').trim() : '';
        applySiteReadinessDoorAvailability(initialSiteReadinessSubmissionId);
        updateSiteReadinessDoorSelectionState();
      }
      if (siteReadinessSubmissionField) {
        setActiveSiteReadinessSubmissionButton(String(siteReadinessSubmissionField.value || '').trim());
      }
      if (siteReadinessStepButtons.length) {
        siteReadinessStepButtons.forEach(function(button){
          button.addEventListener('click', function(ev){
            ev.preventDefault();
            var stepIndex = parseInt(button.getAttribute('data-step-index') || '0', 10);
            if (!isNaN(stepIndex)) {
              setSiteReadinessStep(stepIndex);
            }
          });
        });
      }
      if (siteReadinessPrevButton) {
        siteReadinessPrevButton.addEventListener('click', function(ev){
          ev.preventDefault();
          setSiteReadinessStep(siteReadinessCurrentStep - 1);
        });
      }
      if (siteReadinessNextButton) {
        siteReadinessNextButton.addEventListener('click', function(ev){
          ev.preventDefault();
          var nextMode = String(siteReadinessNextButton.getAttribute('data-next-mode') || 'next');
          if (nextMode === 'restart') {
            setSiteReadinessStep(0);
            return;
          }
          setSiteReadinessStep(siteReadinessCurrentStep + 1);
        });
      }
      if (siteReadinessForm) {
        siteReadinessForm.addEventListener('submit', function(ev){
          ev.preventDefault();
          submitSiteReadinessForm();
        });
        siteReadinessForm.addEventListener('change', function(ev){
          var checklistItem = ev.target.closest('[data-site-readiness-item]');
          if (!checklistItem || !siteReadinessForm.contains(checklistItem)) {
            return;
          }
          updateSiteReadinessSummary();
          updateSiteReadinessProgress();
        });
      }
      if (siteReadinessCheckAllButton) {
        siteReadinessCheckAllButton.addEventListener('click', function(ev){
          ev.preventDefault();
          var changedCount = 0;
          siteReadinessStepPanels.forEach(function(panel){
            if (!panel) {
              return;
            }
            panel.querySelectorAll('[data-site-readiness-item]').forEach(function(itemNode){
              if (itemNode.checked) {
                return;
              }
              itemNode.checked = true;
              changedCount += 1;
            });
          });
          updateSiteReadinessSummary();
          updateSiteReadinessProgress();
          if (changedCount > 0) {
            setSiteReadinessFlash('All checklist fields are now checked. Click Save Site Readiness to persist.', true);
            return;
          }
          setSiteReadinessFlash('All checklist fields were already checked.', true);
        });
      }
      if (siteReadinessSectionToggles.length) {
        siteReadinessSectionToggles.forEach(function(toggle){
          toggle.addEventListener('change', function(){
            var stepIndex = parseInt(toggle.getAttribute('data-step-index') || '-1', 10);
            if (isNaN(stepIndex) || stepIndex < 0 || stepIndex >= siteReadinessStepPanels.length) {
              return;
            }
            var panel = siteReadinessStepPanels[stepIndex];
            if (!panel) {
              return;
            }
            var shouldCheck = !!toggle.checked;
            panel.querySelectorAll('[data-site-readiness-item]').forEach(function(itemNode){
              itemNode.checked = shouldCheck;
            });
            updateSiteReadinessSummary();
            updateSiteReadinessProgress();
          });
        });
      }
      if (siteReadinessDoorInputs.length) {
        siteReadinessDoorInputs.forEach(function(input){
          input.addEventListener('change', function(){
            updateSiteReadinessDoorSelectionState();
          });
        });
      }
      if (siteReadinessDoorSearch) {
        siteReadinessDoorSearch.addEventListener('input', function(){
          updateSiteReadinessDoorSelectionState();
        });
      }
      if (siteReadinessDoorClear) {
        siteReadinessDoorClear.addEventListener('click', function(ev){
          ev.preventDefault();
          if (siteReadinessDoorSearch) {
            siteReadinessDoorSearch.value = '';
            siteReadinessDoorSearch.focus();
          }
          updateSiteReadinessDoorSelectionState();
        });
      }
      if (siteReadinessDrawer) {
        siteReadinessDrawer.addEventListener('click', function(ev){
          var removeButton = ev.target.closest('[data-site-readiness-door-remove]');
          if (!removeButton || !siteReadinessDrawer.contains(removeButton)) {
            return;
          }
          ev.preventDefault();
          var removeDoorId = String(removeButton.getAttribute('data-site-readiness-door-remove') || '').trim();
          if (removeDoorId === '') {
            return;
          }
          siteReadinessDoorOptions.forEach(function(option){
            if (String(option.getAttribute('data-door-id') || '').trim() !== removeDoorId) {
              return;
            }
            var input = option.querySelector('[data-site-readiness-door-input]');
            if (input) {
              input.checked = false;
            }
          });
          updateSiteReadinessDoorSelectionState();
        });
        siteReadinessDrawer.querySelectorAll('[data-site-readiness-close]').forEach(function(closeButton){
          closeButton.addEventListener('click', function(){
            hideSiteReadinessDrawer();
          });
        });
      }
      if (siteReadinessBackdrop) {
        siteReadinessBackdrop.addEventListener('click', function(){
          hideSiteReadinessDrawer();
        });
      }
      if (hardwareAvailabilitySubmissionButtons.length) {
        hardwareAvailabilitySubmissionButtons.forEach(function(button){
          button.addEventListener('click', function(ev){
            ev.preventDefault();
            var submissionId = String(button.getAttribute('data-hardware-availability-open-submission') || '').trim();
            loadHardwareAvailabilitySubmission(submissionId);
            setHardwareAvailabilityStep(0);
            showHardwareAvailabilityDrawer();
          });
        });
      }
      if (hardwareAvailabilityTrigger) {
        hardwareAvailabilityTrigger.addEventListener('click', function(ev){
          ev.preventDefault();
          loadHardwareAvailabilitySubmission('');
          setHardwareAvailabilityStep(0);
          showHardwareAvailabilityDrawer();
        });
      }
      if (hardwareAvailabilityStepPanels.length) {
        setHardwareAvailabilityStep(0);
        updateHardwareAvailabilitySummary();
      }
      if (hardwareAvailabilityDoorOptions.length) {
        var initialHardwareAvailabilitySubmissionId = hardwareAvailabilitySubmissionField ? String(hardwareAvailabilitySubmissionField.value || '').trim() : '';
        applyHardwareAvailabilityDoorAvailability(initialHardwareAvailabilitySubmissionId);
        updateHardwareAvailabilityDoorSelectionState();
      }
      if (hardwareAvailabilitySubmissionField) {
        setActiveHardwareAvailabilitySubmissionButton(String(hardwareAvailabilitySubmissionField.value || '').trim());
      }
      if (hardwareAvailabilityStepButtons.length) {
        hardwareAvailabilityStepButtons.forEach(function(button){
          button.addEventListener('click', function(ev){
            ev.preventDefault();
            var stepIndex = parseInt(button.getAttribute('data-step-index') || '0', 10);
            if (!isNaN(stepIndex)) {
              setHardwareAvailabilityStep(stepIndex);
            }
          });
        });
      }
      if (hardwareAvailabilityPrevButton) {
        hardwareAvailabilityPrevButton.addEventListener('click', function(ev){
          ev.preventDefault();
          setHardwareAvailabilityStep(hardwareAvailabilityCurrentStep - 1);
        });
      }
      if (hardwareAvailabilityNextButton) {
        hardwareAvailabilityNextButton.addEventListener('click', function(ev){
          ev.preventDefault();
          var nextMode = String(hardwareAvailabilityNextButton.getAttribute('data-next-mode') || 'next');
          if (nextMode === 'restart') {
            setHardwareAvailabilityStep(0);
            return;
          }
          setHardwareAvailabilityStep(hardwareAvailabilityCurrentStep + 1);
        });
      }
      if (hardwareAvailabilityForm) {
        hardwareAvailabilityForm.addEventListener('submit', function(ev){
          ev.preventDefault();
          submitHardwareAvailabilityForm();
        });
        hardwareAvailabilityForm.addEventListener('change', function(ev){
          var checklistItem = ev.target.closest('[data-hardware-availability-item]');
          if (!checklistItem || !hardwareAvailabilityForm.contains(checklistItem)) {
            return;
          }
          updateHardwareAvailabilitySummary();
          updateHardwareAvailabilityProgress();
        });
      }
      if (hardwareAvailabilityCheckAllButton) {
        hardwareAvailabilityCheckAllButton.addEventListener('click', function(ev){
          ev.preventDefault();
          var changedCount = 0;
          hardwareAvailabilityStepPanels.forEach(function(panel){
            if (!panel) {
              return;
            }
            panel.querySelectorAll('[data-hardware-availability-item]').forEach(function(itemNode){
              if (itemNode.checked) {
                return;
              }
              itemNode.checked = true;
              changedCount += 1;
            });
          });
          updateHardwareAvailabilitySummary();
          updateHardwareAvailabilityProgress();
          if (changedCount > 0) {
            setHardwareAvailabilityFlash('All checklist fields are now checked. Click Save Hardware Availability to persist.', true);
            return;
          }
          setHardwareAvailabilityFlash('All checklist fields were already checked.', true);
        });
      }
      if (hardwareAvailabilitySectionToggles.length) {
        hardwareAvailabilitySectionToggles.forEach(function(toggle){
          toggle.addEventListener('change', function(){
            var stepIndex = parseInt(toggle.getAttribute('data-step-index') || '-1', 10);
            if (isNaN(stepIndex) || stepIndex < 0 || stepIndex >= hardwareAvailabilityStepPanels.length) {
              return;
            }
            var panel = hardwareAvailabilityStepPanels[stepIndex];
            if (!panel) {
              return;
            }
            var shouldCheck = !!toggle.checked;
            panel.querySelectorAll('[data-hardware-availability-item]').forEach(function(itemNode){
              itemNode.checked = shouldCheck;
            });
            updateHardwareAvailabilitySummary();
            updateHardwareAvailabilityProgress();
          });
        });
      }
      if (hardwareAvailabilityDoorInputs.length) {
        hardwareAvailabilityDoorInputs.forEach(function(input){
          input.addEventListener('change', function(){
            updateHardwareAvailabilityDoorSelectionState();
          });
        });
      }
      if (hardwareAvailabilityDoorSearch) {
        hardwareAvailabilityDoorSearch.addEventListener('input', function(){
          updateHardwareAvailabilityDoorSelectionState();
        });
      }
      if (hardwareAvailabilityDoorClear) {
        hardwareAvailabilityDoorClear.addEventListener('click', function(ev){
          ev.preventDefault();
          if (hardwareAvailabilityDoorSearch) {
            hardwareAvailabilityDoorSearch.value = '';
            hardwareAvailabilityDoorSearch.focus();
          }
          updateHardwareAvailabilityDoorSelectionState();
        });
      }
      if (hardwareAvailabilityDrawer) {
        hardwareAvailabilityDrawer.addEventListener('click', function(ev){
          var removeButton = ev.target.closest('[data-hardware-availability-door-remove]');
          if (!removeButton || !hardwareAvailabilityDrawer.contains(removeButton)) {
            return;
          }
          ev.preventDefault();
          var removeDoorId = String(removeButton.getAttribute('data-hardware-availability-door-remove') || '').trim();
          if (removeDoorId === '') {
            return;
          }
          hardwareAvailabilityDoorOptions.forEach(function(option){
            if (String(option.getAttribute('data-door-id') || '').trim() !== removeDoorId) {
              return;
            }
            var input = option.querySelector('[data-hardware-availability-door-input]');
            if (input) {
              input.checked = false;
            }
          });
          updateHardwareAvailabilityDoorSelectionState();
        });
        hardwareAvailabilityDrawer.querySelectorAll('[data-hardware-availability-close]').forEach(function(closeButton){
          closeButton.addEventListener('click', function(){
            hideHardwareAvailabilityDrawer();
          });
        });
      }
      if (hardwareAvailabilityBackdrop) {
        hardwareAvailabilityBackdrop.addEventListener('click', function(){
          hideHardwareAvailabilityDrawer();
        });
      }

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
        if (hardwareAvailabilityDrawer && hardwareAvailabilityDrawer.classList.contains('is-open')) {
          hideHardwareAvailabilityDrawer();
          return;
        }
        if (siteReadinessDrawer && siteReadinessDrawer.classList.contains('is-open')) {
          hideSiteReadinessDrawer();
          return;
        }
        if (scheduleBookingDrawer && scheduleBookingDrawer.classList.contains('is-open')) {
          hideScheduleBookingDrawer();
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
    if (!is_user_logged_in() || !ado_is_client()) {
        wp_send_json_error(['message' => 'Client access only.'], 403);
    }
    check_ajax_referer('ado_client_portal_nonce', 'nonce');

    $project_id = (int) ($_POST['project_id'] ?? 0);
    $schedule_date = sanitize_text_field((string) ($_POST['schedule_date'] ?? ''));
    $booking_note = sanitize_textarea_field((string) ($_POST['booking_note'] ?? ''));
    $door_ids_raw = isset($_POST['door_ids']) ? (array) $_POST['door_ids'] : [];
    $replace_booking_ids_raw = isset($_POST['replace_booking_ids']) ? (array) $_POST['replace_booking_ids'] : [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedule_date)) {
        wp_send_json_error(['message' => 'Project and booking date are required.'], 400);
    }
    $replace_booking_ids = [];
    foreach ($replace_booking_ids_raw as $replace_booking_id_raw) {
        $replace_booking_id = sanitize_text_field((string) $replace_booking_id_raw);
        if ($replace_booking_id === '') {
            continue;
        }
        $replace_booking_ids[$replace_booking_id] = true;
    }
    $replace_booking_ids = array_values(array_keys($replace_booking_ids));
    $is_replace_mode = count($replace_booking_ids) > 0;
    $replace_booking_lookup = array_fill_keys($replace_booking_ids, true);
    if ($project_id <= 0 && !$is_replace_mode) {
        wp_send_json_error(['message' => 'Project and booking date are required.'], 400);
    }

    $user_id = (int) get_current_user_id();
    $user = wp_get_current_user();
    $client_display_name = '';
    $client_email = '';
    if ($user instanceof WP_User) {
        $client_display_name = trim((string) $user->display_name);
        $client_email = trim((string) $user->user_email);
    }
    $client_orders = ado_cd_client_orders($user_id);
    $order = ado_cd_find_project_order($client_orders, $project_id);
    if ($is_replace_mode) {
        $replace_resolved_order_ids = [];
        foreach ($replace_booking_ids as $replace_booking_id) {
            $replace_booking_ref = ado_cd_client_booking_reference($client_orders, $replace_booking_id);
            if (!is_array($replace_booking_ref)) {
                wp_send_json_error(['message' => 'One or more selected bookings could not be updated. Reload and try again.'], 404);
            }
            $replace_booking_row = is_array($replace_booking_ref['booking'] ?? null) ? (array) $replace_booking_ref['booking'] : [];
            if (!ado_cd_booking_is_owned_by_client($replace_booking_row, $user_id, $user instanceof WP_User ? $user : null)) {
                wp_send_json_error(['message' => 'You can only update bookings created by your account.'], 403);
            }
            $replace_resolved_order_id = (int) ($replace_booking_ref['order_id'] ?? 0);
            if ($replace_resolved_order_id <= 0) {
                wp_send_json_error(['message' => 'One or more selected bookings could not be resolved. Reload and try again.'], 404);
            }
            $replace_resolved_order_ids[$replace_resolved_order_id] = true;
            if (!($order instanceof WC_Order)) {
                $resolved_order = $replace_booking_ref['order'] ?? null;
                if ($resolved_order instanceof WC_Order) {
                    $order = $resolved_order;
                }
            }
        }
        if (count($replace_resolved_order_ids) > 1) {
            wp_send_json_error(['message' => 'Bookings from multiple projects cannot be updated in one request.'], 409);
        }
        if ($replace_resolved_order_ids) {
            $resolved_order_id = (int) array_key_first($replace_resolved_order_ids);
            if (!($order instanceof WC_Order) || (int) $order->get_id() !== $resolved_order_id) {
                $resolved_order = ado_cd_find_project_order($client_orders, $resolved_order_id);
                if ($resolved_order instanceof WC_Order) {
                    $order = $resolved_order;
                    $project_id = $resolved_order_id;
                }
            }
        }
    }
    if (!($order instanceof WC_Order)) {
        wp_send_json_error(['message' => 'Project not found.'], 404);
    }

    $schedule_timezone = ado_cd_schedule_timezone();
    $today_key = wp_date('Y-m-d', null, $schedule_timezone);
    if (strcmp($schedule_date, $today_key) < 0) {
        wp_send_json_error(['message' => 'Past dates cannot be booked.'], 400);
    }
    $schedule_ts = strtotime($schedule_date . ' 12:00:00');
    if ($schedule_ts === false) {
        wp_send_json_error(['message' => 'Booking date is invalid.'], 400);
    }
    $weekday = (int) wp_date('N', (int) $schedule_ts, $schedule_timezone);
    if ($weekday > 5) {
        wp_send_json_error(['message' => 'Bookings are limited to weekdays.'], 400);
    }

    $project_doors_map = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $project_doors_map[$door_id] = trim((string) ($door_row['door_label'] ?? ('Door ' . $door_id)));
    }
    if (!$project_doors_map) {
        wp_send_json_error(['message' => 'No project doors are available for booking.'], 400);
    }

    $selected_door_ids = [];
    foreach ($door_ids_raw as $door_id_raw) {
        $door_id = sanitize_text_field((string) $door_id_raw);
        if ($door_id === '' || !isset($project_doors_map[$door_id])) {
            continue;
        }
        $selected_door_ids[$door_id] = true;
    }
    $selected_door_ids = array_values(array_keys($selected_door_ids));
    if (count($selected_door_ids) <= 0) {
        wp_send_json_error(['message' => 'Select at least one door to book.'], 400);
    }
    if (count($selected_door_ids) > 2) {
        wp_send_json_error(['message' => 'You can book a maximum of 2 doors per day.'], 400);
    }

    $site_readiness_gate = ado_cd_site_readiness_booking_gate($order);
    $site_readiness_door_lookup = is_array($site_readiness_gate['door_lookup'] ?? null)
        ? (array) $site_readiness_gate['door_lookup']
        : [];
    $hardware_availability_gate = ado_cd_hardware_availability_booking_gate($order);
    $hardware_availability_door_lookup = is_array($hardware_availability_gate['door_lookup'] ?? null)
        ? (array) $hardware_availability_gate['door_lookup']
        : [];
    if (empty($site_readiness_gate['is_ready_for_booking']) || empty($hardware_availability_gate['is_ready_for_booking'])) {
        wp_send_json_error([
            'message' => 'Complete and save Confirm Site Readiness and Confirm Hardware Availability before booking.',
        ], 409);
    }
    $not_site_ready_labels = [];
    $not_hardware_ready_labels = [];
    foreach ($selected_door_ids as $selected_door_id) {
        if (!isset($site_readiness_door_lookup[$selected_door_id])) {
            $not_site_ready_labels[] = (string) ($project_doors_map[$selected_door_id] ?? ('Door ' . $selected_door_id));
        }
        if (!isset($hardware_availability_door_lookup[$selected_door_id])) {
            $not_hardware_ready_labels[] = (string) ($project_doors_map[$selected_door_id] ?? ('Door ' . $selected_door_id));
        }
    }
    if ($not_site_ready_labels || $not_hardware_ready_labels) {
        $message_parts = [];
        if ($not_site_ready_labels) {
            $message_parts[] = 'Missing saved Site Readiness: ' . implode(', ', $not_site_ready_labels) . '.';
        }
        if ($not_hardware_ready_labels) {
            $message_parts[] = 'Missing saved Hardware Availability: ' . implode(', ', $not_hardware_ready_labels) . '.';
        }
        wp_send_json_error([
            'message' => implode(' ', $message_parts),
        ], 409);
    }

    $availability = ado_cd_google_availability_adapter($order, ado_cd_schedule_availability_window_days());
    $availability_state = (string) ($availability['state'] ?? 'fetch_error');
    if ($availability_state !== 'ok') {
        $adapter_message = trim((string) ($availability['message'] ?? 'Live availability could not be confirmed.'));
        wp_send_json_error(['message' => $adapter_message !== '' ? $adapter_message : 'Live availability could not be confirmed.'], 409);
    }
    if (!$is_replace_mode) {
        foreach ((array) ($availability['slots'] ?? []) as $slot_row) {
            if (!is_array($slot_row)) {
                continue;
            }
            $slot_date_key = trim((string) ($slot_row['date_key'] ?? ''));
            if ($slot_date_key === '') {
                $slot_start_ts = strtotime(trim((string) ($slot_row['slot_start'] ?? '')));
                if ($slot_start_ts !== false) {
                    $slot_date_key = wp_date('Y-m-d', (int) $slot_start_ts, $schedule_timezone);
                }
            }
            if ($slot_date_key === $schedule_date) {
                wp_send_json_error(['message' => 'Selected day already has calendar events. Please choose a different day.'], 409);
            }
        }
    }

    $bookings = ado_cd_client_schedule_bookings($order);
    $active_booked_door_ids_map = [];
    $active_day_door_ids_map = [];
    $replace_rows = [];
    $replace_row_indexes = [];
    foreach ($bookings as $booking_index => $booking_row) {
        if (!is_array($booking_row) || (string) ($booking_row['status'] ?? '') !== 'active') {
            continue;
        }
        $booking_id = trim((string) ($booking_row['booking_id'] ?? ''));
        $booking_date = trim((string) ($booking_row['schedule_date'] ?? ''));
        $is_replace_target = $is_replace_mode && $booking_id !== '' && isset($replace_booking_lookup[$booking_id]);
        if ($is_replace_target) {
            $booking_owner_id = (int) ($booking_row['created_by_client_id'] ?? ($booking_row['created_by'] ?? 0));
            if ($booking_owner_id > 0 && $booking_owner_id !== $user_id) {
                wp_send_json_error(['message' => 'You can only update bookings created by your account.'], 403);
            }
            if ($booking_date !== $schedule_date) {
                wp_send_json_error(['message' => 'Booking updates must stay on the same scheduled day.'], 409);
            }
            $replace_rows[] = $booking_row;
            $replace_row_indexes[] = (int) $booking_index;
            continue;
        }
        foreach ((array) ($booking_row['door_ids'] ?? []) as $booking_door_id) {
            $booking_door_id = sanitize_text_field((string) $booking_door_id);
            if ($booking_door_id === '' || !isset($project_doors_map[$booking_door_id])) {
                continue;
            }
            $active_booked_door_ids_map[$booking_door_id] = true;
            if ($booking_date === $schedule_date) {
                $active_day_door_ids_map[$booking_door_id] = true;
            }
        }
    }
    if ($is_replace_mode) {
        if (count($replace_rows) !== count($replace_booking_ids)) {
            wp_send_json_error(['message' => 'One or more selected bookings could not be updated. Reload and try again.'], 404);
        }
        if (!$replace_rows) {
            wp_send_json_error(['message' => 'No matching active booking was found to update.'], 404);
        }
    }

    $conflict_doors = [];
    foreach ($selected_door_ids as $selected_door_id) {
        if (!isset($active_booked_door_ids_map[$selected_door_id])) {
            continue;
        }
        $conflict_doors[] = (string) ($project_doors_map[$selected_door_id] ?? ('Door ' . $selected_door_id));
    }
    if ($conflict_doors) {
        wp_send_json_error(['message' => 'Already booked doors cannot be booked again until cancelled: ' . implode(', ', $conflict_doors)], 409);
    }

    $day_doors_after_booking = $active_day_door_ids_map;
    foreach ($selected_door_ids as $selected_door_id) {
        $day_doors_after_booking[$selected_door_id] = true;
    }
    if (count($day_doors_after_booking) > 2) {
        wp_send_json_error(['message' => 'This day already has door bookings. Maximum is 2 doors per day.'], 409);
    }

    $door_labels = array_map(static function (string $door_id) use ($project_doors_map): string {
        return (string) ($project_doors_map[$door_id] ?? ('Door ' . $door_id));
    }, $selected_door_ids);
    if ($is_replace_mode) {
        $primary_replace = (array) ($replace_rows[0] ?? []);
        $primary_calendar_id = trim((string) ($primary_replace['calendar_id'] ?? ''));
        $primary_event_id = trim((string) ($primary_replace['google_event_id'] ?? ''));
        $google_event = null;
        if ($primary_calendar_id !== '' && $primary_event_id !== '') {
            $google_event = ado_cd_google_update_booking_event(
                $order,
                $primary_calendar_id,
                $primary_event_id,
                $schedule_date,
                $door_labels,
                $booking_note
            );
            if (empty($google_event['ok'])) {
                wp_send_json_error([
                    'message' => trim((string) ($google_event['message'] ?? 'Google calendar booking update failed.')),
                    'code' => 'google_event_update_failed',
                ], 409);
            }
        } else {
            $google_event = ado_cd_google_create_booking_event($order, $schedule_date, $door_labels, $booking_note);
            if (empty($google_event['ok'])) {
                wp_send_json_error([
                    'message' => trim((string) ($google_event['message'] ?? 'Google calendar booking creation failed.')),
                    'code' => 'google_event_create_failed',
                ], 409);
            }
        }

        $primary_booking_id = trim((string) ($primary_replace['booking_id'] ?? ''));
        $booking_id = $primary_booking_id !== '' ? $primary_booking_id : ('ado_booking_' . wp_generate_uuid4());
        $primary_index = (int) ($replace_row_indexes[0] ?? -1);
        if ($primary_index < 0 || !isset($bookings[$primary_index]) || !is_array($bookings[$primary_index])) {
            wp_send_json_error(['message' => 'Could not locate booking row for update.'], 409);
        }
        $bookings[$primary_index] = array_merge((array) $bookings[$primary_index], [
            'booking_id' => $booking_id,
            'schedule_date' => $schedule_date,
            'door_ids' => $selected_door_ids,
            'status' => 'active',
            'note' => $booking_note,
            'created_at' => wp_date('Y-m-d H:i'),
            'created_by' => $user_id,
            'created_by_client_id' => $user_id,
            'created_by_name' => $client_display_name,
            'created_by_email' => $client_email,
            'cancelled_at' => '',
            'cancelled_by' => 0,
            'calendar_id' => trim((string) ($google_event['calendar_id'] ?? $primary_calendar_id)),
            'google_event_id' => trim((string) ($google_event['event_id'] ?? $primary_event_id)),
            'google_event_link' => trim((string) ($google_event['event_link'] ?? ($primary_replace['google_event_link'] ?? ''))),
        ]);

        for ($i = 1; $i < count($replace_rows); $i++) {
            $replace_row = (array) $replace_rows[$i];
            $replace_index = (int) ($replace_row_indexes[$i] ?? -1);
            if ($replace_index < 0 || !isset($bookings[$replace_index]) || !is_array($bookings[$replace_index])) {
                continue;
            }
            $calendar_id = trim((string) ($replace_row['calendar_id'] ?? ''));
            $google_event_id = trim((string) ($replace_row['google_event_id'] ?? ''));
            if ($google_event_id !== '') {
                $cancel_event_result = ado_cd_google_cancel_booking_event($order, $calendar_id, $google_event_id);
                if (empty($cancel_event_result['ok'])) {
                    wp_send_json_error([
                        'message' => trim((string) ($cancel_event_result['message'] ?? 'Google calendar cancellation failed for merged booking rows.')),
                        'code' => 'google_event_cancel_failed',
                    ], 409);
                }
            }
            $bookings[$replace_index]['status'] = 'cancelled';
            $bookings[$replace_index]['cancelled_at'] = wp_date('Y-m-d H:i');
            $bookings[$replace_index]['cancelled_by'] = $user_id;
        }

        if (function_exists('ado_project_timeline_append_event')) {
            $merged_booking_ids = [];
            foreach ($replace_rows as $replace_row) {
                $replace_booking_id = trim((string) ($replace_row['booking_id'] ?? ''));
                if ($replace_booking_id !== '') {
                    $merged_booking_ids[] = $replace_booking_id;
                }
            }
            $timeline_details = [
                'Booking ID: ' . $booking_id,
                'Date: ' . $schedule_date,
                'Doors: ' . implode(', ', $door_labels),
            ];
            if ($booking_note !== '') {
                $timeline_details[] = 'Client note: ' . $booking_note;
            }
            if ($merged_booking_ids) {
                $timeline_details[] = 'Updated from booking IDs: ' . implode(', ', array_values(array_unique($merged_booking_ids)));
            }
            ado_project_timeline_append_event($order, [
                'title' => 'Booking Updated',
                'summary' => 'Client updated an existing booking scope.',
                'category' => 'booking',
                'action' => 'updated',
                'actor_id' => $user_id,
                'actor_name' => $client_display_name !== '' ? $client_display_name : ado_project_timeline_actor_name($user_id),
                'actor_role' => 'client',
                'door_ids' => $selected_door_ids,
                'door_labels' => $door_labels,
                'details' => $timeline_details,
            ]);
        }
        $order->update_meta_data('_ado_client_schedule_bookings', array_values($bookings));
        $order->save();

        wp_send_json_success([
            'message' => 'Booking updated for ' . $schedule_date . ' and synced to Google Calendar: ' . implode(', ', $door_labels),
            'booking_id' => $booking_id,
            'schedule_date' => $schedule_date,
            'door_ids' => $selected_door_ids,
            'door_labels' => $door_labels,
            'google_event_id' => trim((string) ($google_event['event_id'] ?? '')),
            'updated' => true,
        ]);
    }

    $google_event = ado_cd_google_create_booking_event($order, $schedule_date, $door_labels, $booking_note);
    if (empty($google_event['ok'])) {
        wp_send_json_error([
            'message' => trim((string) ($google_event['message'] ?? 'Google calendar booking creation failed.')),
            'code' => 'google_event_create_failed',
        ], 409);
    }

    $booking_id = 'ado_booking_' . wp_generate_uuid4();
    $bookings[] = [
        'booking_id' => $booking_id,
        'schedule_date' => $schedule_date,
        'door_ids' => $selected_door_ids,
        'status' => 'active',
        'note' => $booking_note,
        'created_at' => wp_date('Y-m-d H:i'),
        'created_by' => $user_id,
        'created_by_client_id' => $user_id,
        'created_by_name' => $client_display_name,
        'created_by_email' => $client_email,
        'cancelled_at' => '',
        'cancelled_by' => 0,
        'calendar_id' => trim((string) ($google_event['calendar_id'] ?? '')),
        'google_event_id' => trim((string) ($google_event['event_id'] ?? '')),
        'google_event_link' => trim((string) ($google_event['event_link'] ?? '')),
    ];
    if (function_exists('ado_project_timeline_append_event')) {
        $timeline_details = [
            'Booking ID: ' . $booking_id,
            'Date: ' . $schedule_date,
            'Doors: ' . implode(', ', $door_labels),
        ];
        if ($booking_note !== '') {
            $timeline_details[] = 'Client note: ' . $booking_note;
        }
        $timeline_details[] = 'Google Event ID: ' . trim((string) ($google_event['event_id'] ?? ''));
        ado_project_timeline_append_event($order, [
            'title' => 'Booking Submitted',
            'summary' => 'Client submitted a booking request synced to Google Calendar.',
            'category' => 'booking',
            'action' => 'submitted',
            'actor_id' => $user_id,
            'actor_name' => $client_display_name !== '' ? $client_display_name : ado_project_timeline_actor_name($user_id),
            'actor_role' => 'client',
            'door_ids' => $selected_door_ids,
            'door_labels' => $door_labels,
            'details' => $timeline_details,
        ]);
    }
    $order->update_meta_data('_ado_client_schedule_bookings', array_values($bookings));
    $order->save();

    wp_send_json_success([
        'message' => 'Doors booked for ' . $schedule_date . ' and synced to Google Calendar: ' . implode(', ', $door_labels),
        'booking_id' => $booking_id,
        'schedule_date' => $schedule_date,
        'door_ids' => $selected_door_ids,
        'door_labels' => $door_labels,
        'google_event_id' => trim((string) ($google_event['event_id'] ?? '')),
    ]);
});

add_action('wp_ajax_ado_cancel_client_schedule_request', static function (): void {
    if (!is_user_logged_in() || !ado_is_client()) {
        wp_send_json_error(['message' => 'Client access only.'], 403);
    }
    check_ajax_referer('ado_client_portal_nonce', 'nonce');

    $project_id = (int) ($_POST['project_id'] ?? 0);
    $booking_id = sanitize_text_field((string) ($_POST['booking_id'] ?? ''));
    if ($booking_id === '') {
        wp_send_json_error(['message' => 'Booking reference is required.'], 400);
    }

    $user_id = (int) get_current_user_id();
    $client_user = wp_get_current_user();
    $client_orders = ado_cd_client_orders($user_id);
    $order = $project_id > 0 ? ado_cd_find_project_order($client_orders, $project_id) : null;
    $booking_reference = null;
    if ($order instanceof WC_Order) {
        $project_bookings = ado_cd_client_schedule_bookings($order);
        foreach ($project_bookings as $project_booking_index => $project_booking_row) {
            if (!is_array($project_booking_row)) {
                continue;
            }
            if ((string) ($project_booking_row['booking_id'] ?? '') !== $booking_id) {
                continue;
            }
            $booking_reference = [
                'order' => $order,
                'order_id' => (int) $order->get_id(),
                'bookings' => $project_bookings,
                'index' => (int) $project_booking_index,
                'booking' => $project_booking_row,
            ];
            break;
        }
    }
    if (!is_array($booking_reference)) {
        $booking_reference = ado_cd_client_booking_reference($client_orders, $booking_id);
    }
    if (!is_array($booking_reference) || !($booking_reference['order'] ?? null) instanceof WC_Order) {
        wp_send_json_error(['message' => 'Booking not found or already cancelled.'], 404);
    }

    $order = $booking_reference['order'];
    $bookings = is_array($booking_reference['bookings'] ?? null)
        ? (array) $booking_reference['bookings']
        : ado_cd_client_schedule_bookings($order);
    $booking_index = (int) ($booking_reference['index'] ?? -1);
    if ($booking_index < 0 || !isset($bookings[$booking_index]) || !is_array($bookings[$booking_index])) {
        wp_send_json_error(['message' => 'Booking not found or already cancelled.'], 404);
    }
    $booking_row = (array) $bookings[$booking_index];
    if ((string) ($booking_row['status'] ?? '') !== 'active') {
        wp_send_json_error(['message' => 'Booking not found or already cancelled.'], 404);
    }
    if (!ado_cd_booking_is_owned_by_client($booking_row, $user_id, $client_user instanceof WP_User ? $client_user : null)) {
        wp_send_json_error(['message' => 'You can only cancel bookings created by your account.'], 403);
    }
    $calendar_id = trim((string) ($booking_row['calendar_id'] ?? ''));
    $google_event_id = trim((string) ($booking_row['google_event_id'] ?? ''));
    if ($google_event_id !== '') {
        $cancel_event_result = ado_cd_google_cancel_booking_event($order, $calendar_id, $google_event_id);
        if (empty($cancel_event_result['ok'])) {
            wp_send_json_error([
                'message' => trim((string) ($cancel_event_result['message'] ?? 'Google calendar cancellation failed.')),
                'code' => 'google_event_cancel_failed',
            ], 409);
        }
    }
    $bookings[$booking_index]['status'] = 'cancelled';
    $bookings[$booking_index]['cancelled_at'] = wp_date('Y-m-d H:i');
    $bookings[$booking_index]['cancelled_by'] = $user_id;

    if (function_exists('ado_project_timeline_append_event')) {
        $door_lookup = [];
        foreach (ado_cd_order_doors($order) as $door_row) {
            if (!is_array($door_row)) {
                continue;
            }
            $door_row_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
            if ($door_row_id === '') {
                continue;
            }
            $door_lookup[$door_row_id] = trim((string) ($door_row['door_label'] ?? ('Door ' . $door_row_id)));
        }
        $booking_door_ids = [];
        $booking_door_labels = [];
        foreach ((array) ($booking_row['door_ids'] ?? []) as $booking_door_id_raw) {
            $booking_door_id = sanitize_text_field((string) $booking_door_id_raw);
            if ($booking_door_id === '') {
                continue;
            }
            $booking_door_ids[] = $booking_door_id;
            $booking_door_labels[] = (string) ($door_lookup[$booking_door_id] ?? ('Door ' . $booking_door_id));
        }
        ado_project_timeline_append_event($order, [
            'title' => 'Booking Cancelled',
            'summary' => 'Client cancelled a scheduled booking.',
            'category' => 'booking',
            'action' => 'cancelled',
            'actor_id' => $user_id,
            'actor_name' => ado_project_timeline_actor_name($user_id, trim((string) ($booking_row['created_by_name'] ?? ''))),
            'actor_role' => 'client',
            'door_ids' => $booking_door_ids,
            'door_labels' => $booking_door_labels,
            'details' => [
                'Booking ID: ' . $booking_id,
                'Date: ' . trim((string) ($booking_row['schedule_date'] ?? '')),
                'Doors: ' . ($booking_door_labels ? implode(', ', $booking_door_labels) : 'None'),
            ],
        ]);
    }
    $order->update_meta_data('_ado_client_schedule_bookings', array_values($bookings));
    $order->save();

    wp_send_json_success([
        'message' => 'Booking cancelled. Doors are available again.',
        'booking_id' => $booking_id,
    ]);
});

add_action('wp_ajax_ado_save_client_site_readiness', static function (): void {
    if (!is_user_logged_in() || !ado_is_client()) {
        wp_send_json_error(['message' => 'Client access only.'], 403);
    }
    check_ajax_referer('ado_client_portal_nonce', 'nonce');

    $project_id = (int) ($_POST['project_id'] ?? 0);
    if ($project_id <= 0) {
        wp_send_json_error(['message' => 'Project is required.'], 400);
    }

    $user_id = (int) get_current_user_id();
    $order = ado_cd_find_project_order(ado_cd_client_orders($user_id), $project_id);
    if (!($order instanceof WC_Order)) {
        wp_send_json_error(['message' => 'Project not found.'], 404);
    }

    $sections_raw = wp_unslash((string) ($_POST['sections'] ?? ''));
    $sections = json_decode($sections_raw, true);
    if (is_array($sections)) {
        $definition = ado_cd_site_readiness_sections();
        if (!$definition) {
            wp_send_json_error(['message' => 'No site-readiness sections are configured.'], 400);
        }
        $door_lookup = [];
        $door_label_lookup = [];
        foreach (ado_cd_order_doors($order) as $door_row) {
            if (!is_array($door_row)) {
                continue;
            }
            $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
            if ($door_id === '') {
                continue;
            }
            $door_lookup[$door_id] = true;
            $door_label = trim((string) ($door_row['door_label'] ?? ('Door ' . $door_id)));
            if ($door_label === '') {
                $door_label = 'Door ' . $door_id;
            }
            $door_label_lookup[$door_id] = $door_label;
        }
        if (!$door_lookup) {
            wp_send_json_error(['message' => 'No project doors are available to scope this checklist.'], 400);
        }
        $door_ids_present = array_key_exists('door_ids', $_POST);
        $door_ids_input = [];
        if ($door_ids_present) {
            $door_ids_raw = $_POST['door_ids'];
            if (is_array($door_ids_raw)) {
                $door_ids_input = (array) wp_unslash($door_ids_raw);
            } elseif (is_string($door_ids_raw)) {
                $decoded_door_ids = json_decode(wp_unslash($door_ids_raw), true);
                if (is_array($decoded_door_ids)) {
                    $door_ids_input = $decoded_door_ids;
                }
            }
        }
        $normalized_door_ids_map = [];
        foreach ($door_ids_input as $door_id_raw) {
            $door_id = sanitize_text_field((string) $door_id_raw);
            if ($door_id === '' || !isset($door_lookup[$door_id])) {
                continue;
            }
            $normalized_door_ids_map[$door_id] = true;
        }
        if (!$normalized_door_ids_map) {
            if ($door_ids_present) {
                wp_send_json_error(['message' => 'Select at least one valid project door for this checklist.'], 400);
            }
            $normalized_door_ids_map = $door_lookup;
        }
        $normalized_door_ids = array_values(array_keys($normalized_door_ids_map));
        $normalized_sections = [];
        $total_items = 0;
        $checked_items = 0;
        foreach ($definition as $section_key => $section_row) {
            $input_section = is_array($sections[$section_key] ?? null) ? (array) $sections[$section_key] : [];
            $input_items = is_array($input_section['items'] ?? null) ? (array) $input_section['items'] : [];
            $normalized_items = [];
            foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
                $is_checked = !empty($input_items[$item_key]);
                $normalized_items[$item_key] = $is_checked;
                $total_items++;
                if ($is_checked) {
                    $checked_items++;
                }
            }
            $normalized_sections[$section_key] = [
                'items' => $normalized_items,
                'note' => sanitize_textarea_field((string) ($input_section['note'] ?? '')),
            ];
        }
        $submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($_POST['submission_id'] ?? '')));
        if (!is_string($submission_id)) {
            $submission_id = '';
        }
        $existing_submissions = ado_cd_site_readiness_submissions($order);
        $existing_submission_index = -1;
        foreach ($existing_submissions as $existing_index => $existing_submission_row) {
            if (!is_array($existing_submission_row)) {
                continue;
            }
            if ((string) ($existing_submission_row['submission_id'] ?? '') !== $submission_id) {
                continue;
            }
            $existing_submission_index = (int) $existing_index;
            break;
        }
        $previous_submission_row = $existing_submission_index >= 0 && isset($existing_submissions[$existing_submission_index]) && is_array($existing_submissions[$existing_submission_index])
            ? (array) $existing_submissions[$existing_submission_index]
            : [];
        $reopened_door_lookup = ado_cd_site_readiness_reopened_door_lookup($order);
        $reserved_door_lookup = [];
        foreach ($existing_submissions as $existing_index => $existing_submission_row) {
            if (!is_array($existing_submission_row)) {
                continue;
            }
            if ($existing_submission_index >= 0 && (int) $existing_index === $existing_submission_index) {
                continue;
            }
            foreach ((array) ($existing_submission_row['door_ids'] ?? []) as $existing_submission_door_id_raw) {
                $existing_submission_door_id = sanitize_text_field((string) $existing_submission_door_id_raw);
                if ($existing_submission_door_id === '' || !isset($door_lookup[$existing_submission_door_id])) {
                    continue;
                }
                if (isset($reopened_door_lookup[$existing_submission_door_id])) {
                    continue;
                }
                $reserved_door_lookup[$existing_submission_door_id] = true;
            }
        }
        $overlapping_door_labels = [];
        foreach ($normalized_door_ids as $normalized_door_id) {
            if (!isset($reserved_door_lookup[$normalized_door_id])) {
                continue;
            }
            $overlapping_door_labels[] = (string) ($door_label_lookup[$normalized_door_id] ?? ('Door ' . $normalized_door_id));
        }
        if ($overlapping_door_labels) {
            wp_send_json_error([
                'message' => 'These doors are already assigned to another site readiness submittal: ' . implode(', ', $overlapping_door_labels) . '. Open the existing submittal to edit door scope.',
            ], 409);
        }
        $is_new_submission = false;
        if ($submission_id === '' || $existing_submission_index < 0) {
            $submission_id = 'sr_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
            $existing_submission_index = -1;
            $is_new_submission = true;
        }
        $updated_at = wp_date('Y-m-d H:i');
        $submission_row = [
            'submission_id' => $submission_id,
            'door_ids' => $normalized_door_ids,
            'sections' => $normalized_sections,
            'updated_at' => $updated_at,
            'updated_by' => $user_id,
        ];
        if ($existing_submission_index >= 0 && isset($existing_submissions[$existing_submission_index])) {
            $existing_submissions[$existing_submission_index] = $submission_row;
        } else {
            array_unshift($existing_submissions, $submission_row);
        }
        if (function_exists('ado_project_timeline_append_event')) {
            $submission_door_labels = [];
            foreach ($normalized_door_ids as $submission_door_id) {
                $submission_door_labels[] = (string) ($door_label_lookup[$submission_door_id] ?? ('Door ' . $submission_door_id));
            }
            $timeline_details = [
                'Submission ID: ' . $submission_id,
                'Checklist: ' . $checked_items . '/' . $total_items . ' checked',
                'Doors: ' . ($submission_door_labels ? implode(', ', $submission_door_labels) : 'None'),
            ];
            if (!$is_new_submission && $previous_submission_row) {
                $previous_door_map = [];
                foreach ((array) ($previous_submission_row['door_ids'] ?? []) as $previous_door_id_raw) {
                    $previous_door_id = sanitize_text_field((string) $previous_door_id_raw);
                    if ($previous_door_id === '') {
                        continue;
                    }
                    $previous_door_map[$previous_door_id] = true;
                }
                $current_door_map = array_fill_keys($normalized_door_ids, true);
                $added_door_labels = [];
                $removed_door_labels = [];
                foreach ($normalized_door_ids as $current_door_id) {
                    if (!isset($previous_door_map[$current_door_id])) {
                        $added_door_labels[] = (string) ($door_label_lookup[$current_door_id] ?? ('Door ' . $current_door_id));
                    }
                }
                foreach (array_keys($previous_door_map) as $previous_door_id) {
                    if (isset($current_door_map[$previous_door_id])) {
                        continue;
                    }
                    $removed_door_labels[] = (string) ($door_label_lookup[$previous_door_id] ?? ('Door ' . $previous_door_id));
                }
                if ($added_door_labels) {
                    $timeline_details[] = 'Door scope added: ' . implode(', ', $added_door_labels);
                }
                if ($removed_door_labels) {
                    $timeline_details[] = 'Door scope removed: ' . implode(', ', $removed_door_labels);
                }
            }
            ado_project_timeline_append_event($order, [
                'title' => $is_new_submission ? 'Site Readiness Submitted' : 'Site Readiness Updated',
                'summary' => $is_new_submission
                    ? 'Project manager submitted a new site-readiness checklist.'
                    : 'Project manager updated an existing site-readiness checklist.',
                'category' => 'site_readiness',
                'action' => $is_new_submission ? 'submitted' : 'updated',
                'actor_id' => $user_id,
                'actor_name' => ado_project_timeline_actor_name($user_id),
                'actor_role' => 'project_manager',
                'door_ids' => $normalized_door_ids,
                'door_labels' => $submission_door_labels,
                'details' => $timeline_details,
            ]);
        }
        $order->update_meta_data('_ado_site_readiness_checklist', [
            'active_submission_id' => $submission_id,
            'submissions' => array_values($existing_submissions),
            'updated_at' => $updated_at,
            'updated_by' => $user_id,
        ]);
        $order->save();
        wp_send_json_success([
            'message' => 'Site readiness checklist saved (' . $checked_items . ' of ' . $total_items . ' items confirmed).',
            'submission_id' => $submission_id,
            'is_new_submission' => $is_new_submission,
            'submission_total' => count($existing_submissions),
            'checked_items' => $checked_items,
            'total_items' => $total_items,
            'section_total' => count($definition),
            'door_total' => count($normalized_door_ids),
        ]);
    }

    // Backward-compatible fallback for older payload shape.
    $entries_raw = wp_unslash((string) ($_POST['entries'] ?? ''));
    $entries = json_decode($entries_raw, true);
    if (!is_array($entries)) {
        wp_send_json_error(['message' => 'Readiness payload is invalid.'], 400);
    }

    $door_lookup = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $door_lookup[$door_id] = true;
    }
    if (!$door_lookup) {
        wp_send_json_error(['message' => 'No project doors are available for readiness updates.'], 400);
    }

    $feedback_map = ado_cd_client_door_feedback_map($order);
    $updated_count = 0;
    foreach ($entries as $entry_row) {
        if (!is_array($entry_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($entry_row['door_id'] ?? ''));
        if ($door_id === '' || !isset($door_lookup[$door_id])) {
            continue;
        }
        $state = is_array($feedback_map[$door_id] ?? null) ? (array) $feedback_map[$door_id] : ado_cd_client_door_feedback_defaults();
        $state['readiness_confirmed'] = !empty($entry_row['readiness_confirmed']);
        $state['readiness_note'] = sanitize_textarea_field((string) ($entry_row['readiness_note'] ?? ''));
        $state['updated_at'] = wp_date('Y-m-d H:i');
        $state['updated_by'] = $user_id;
        $feedback_map[$door_id] = $state;
        $updated_count++;
    }
    if ($updated_count <= 0) {
        wp_send_json_error(['message' => 'No valid door readiness updates were received.'], 400);
    }

    $confirmed_count = 0;
    foreach (array_keys($door_lookup) as $door_id) {
        $door_state = is_array($feedback_map[$door_id] ?? null) ? (array) $feedback_map[$door_id] : [];
        if (!empty($door_state['readiness_confirmed'])) {
            $confirmed_count++;
        }
    }
    if (function_exists('ado_project_timeline_append_event')) {
        ado_project_timeline_append_event($order, [
            'title' => 'Site Readiness Updated',
            'summary' => 'Project manager updated legacy site-readiness door confirmations.',
            'category' => 'site_readiness',
            'action' => 'updated',
            'actor_id' => $user_id,
            'actor_name' => ado_project_timeline_actor_name($user_id),
            'actor_role' => 'project_manager',
            'details' => [
                'Doors updated: ' . (string) $updated_count,
                'Confirmed doors: ' . (string) $confirmed_count . ' of ' . (string) count($door_lookup),
            ],
        ]);
    }
    $order->update_meta_data('_ado_client_door_feedback', $feedback_map);
    $order->save();
    wp_send_json_success([
        'message' => 'Site readiness saved for ' . $confirmed_count . ' of ' . count($door_lookup) . ' doors.',
        'confirmed_count' => $confirmed_count,
        'door_total' => count($door_lookup),
        'updated_count' => $updated_count,
    ]);
});

add_action('wp_ajax_ado_save_client_hardware_availability', static function (): void {
    if (!is_user_logged_in() || !ado_is_client()) {
        wp_send_json_error(['message' => 'Client access only.'], 403);
    }
    check_ajax_referer('ado_client_portal_nonce', 'nonce');

    $project_id = (int) ($_POST['project_id'] ?? 0);
    if ($project_id <= 0) {
        wp_send_json_error(['message' => 'Project is required.'], 400);
    }

    $user_id = (int) get_current_user_id();
    $order = ado_cd_find_project_order(ado_cd_client_orders($user_id), $project_id);
    if (!($order instanceof WC_Order)) {
        wp_send_json_error(['message' => 'Project not found.'], 404);
    }

    $sections_raw = wp_unslash((string) ($_POST['sections'] ?? ''));
    $sections = json_decode($sections_raw, true);
    if (!is_array($sections)) {
        wp_send_json_error(['message' => 'Hardware availability payload is invalid.'], 400);
    }

    $definition = ado_cd_hardware_availability_sections();
    if (!$definition) {
        wp_send_json_error(['message' => 'No hardware-availability sections are configured.'], 400);
    }
    $door_lookup = [];
    $door_label_lookup = [];
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        $door_id = sanitize_text_field((string) ($door_row['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $door_lookup[$door_id] = true;
        $door_label = trim((string) ($door_row['door_label'] ?? ('Door ' . $door_id)));
        if ($door_label === '') {
            $door_label = 'Door ' . $door_id;
        }
        $door_label_lookup[$door_id] = $door_label;
    }
    if (!$door_lookup) {
        wp_send_json_error(['message' => 'No project doors are available to scope this checklist.'], 400);
    }

    $door_ids_present = array_key_exists('door_ids', $_POST);
    $door_ids_input = [];
    if ($door_ids_present) {
        $door_ids_raw = $_POST['door_ids'];
        if (is_array($door_ids_raw)) {
            $door_ids_input = (array) wp_unslash($door_ids_raw);
        } elseif (is_string($door_ids_raw)) {
            $decoded_door_ids = json_decode(wp_unslash($door_ids_raw), true);
            if (is_array($decoded_door_ids)) {
                $door_ids_input = $decoded_door_ids;
            }
        }
    }
    $normalized_door_ids_map = [];
    foreach ($door_ids_input as $door_id_raw) {
        $door_id = sanitize_text_field((string) $door_id_raw);
        if ($door_id === '' || !isset($door_lookup[$door_id])) {
            continue;
        }
        $normalized_door_ids_map[$door_id] = true;
    }
    if (!$normalized_door_ids_map) {
        if ($door_ids_present) {
            wp_send_json_error(['message' => 'Select at least one valid project door for this checklist.'], 400);
        }
        $normalized_door_ids_map = $door_lookup;
    }
    $normalized_door_ids = array_values(array_keys($normalized_door_ids_map));

    $normalized_sections = [];
    $total_items = 0;
    $checked_items = 0;
    foreach ($definition as $section_key => $section_row) {
        $input_section = is_array($sections[$section_key] ?? null) ? (array) $sections[$section_key] : [];
        $input_items = is_array($input_section['items'] ?? null) ? (array) $input_section['items'] : [];
        $normalized_items = [];
        foreach ((array) ($section_row['items'] ?? []) as $item_key => $_item_label) {
            $is_checked = !empty($input_items[$item_key]);
            $normalized_items[$item_key] = $is_checked;
            $total_items++;
            if ($is_checked) {
                $checked_items++;
            }
        }
        $normalized_sections[$section_key] = [
            'items' => $normalized_items,
            'note' => sanitize_textarea_field((string) ($input_section['note'] ?? '')),
        ];
    }

    $submission_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', sanitize_text_field((string) ($_POST['submission_id'] ?? '')));
    if (!is_string($submission_id)) {
        $submission_id = '';
    }
    $existing_submissions = ado_cd_hardware_availability_submissions($order);
    $existing_submission_index = -1;
    foreach ($existing_submissions as $existing_index => $existing_submission_row) {
        if (!is_array($existing_submission_row)) {
            continue;
        }
        if ((string) ($existing_submission_row['submission_id'] ?? '') !== $submission_id) {
            continue;
        }
        $existing_submission_index = (int) $existing_index;
        break;
    }
    $previous_submission_row = $existing_submission_index >= 0 && isset($existing_submissions[$existing_submission_index]) && is_array($existing_submissions[$existing_submission_index])
        ? (array) $existing_submissions[$existing_submission_index]
        : [];
    $reopened_door_lookup = ado_cd_hardware_availability_reopened_door_lookup($order);
    $reserved_door_lookup = [];
    foreach ($existing_submissions as $existing_index => $existing_submission_row) {
        if (!is_array($existing_submission_row)) {
            continue;
        }
        if ($existing_submission_index >= 0 && (int) $existing_index === $existing_submission_index) {
            continue;
        }
        foreach ((array) ($existing_submission_row['door_ids'] ?? []) as $existing_submission_door_id_raw) {
            $existing_submission_door_id = sanitize_text_field((string) $existing_submission_door_id_raw);
            if ($existing_submission_door_id === '' || !isset($door_lookup[$existing_submission_door_id])) {
                continue;
            }
            if (isset($reopened_door_lookup[$existing_submission_door_id])) {
                continue;
            }
            $reserved_door_lookup[$existing_submission_door_id] = true;
        }
    }
    $overlapping_door_labels = [];
    foreach ($normalized_door_ids as $normalized_door_id) {
        if (!isset($reserved_door_lookup[$normalized_door_id])) {
            continue;
        }
        $overlapping_door_labels[] = (string) ($door_label_lookup[$normalized_door_id] ?? ('Door ' . $normalized_door_id));
    }
    if ($overlapping_door_labels) {
        wp_send_json_error([
            'message' => 'These doors are already assigned to another hardware availability submittal: ' . implode(', ', $overlapping_door_labels) . '. Open the existing submittal to edit door scope.',
        ], 409);
    }

    $is_new_submission = false;
    if ($submission_id === '' || $existing_submission_index < 0) {
        $submission_id = 'ha_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
        $existing_submission_index = -1;
        $is_new_submission = true;
    }
    $updated_at = wp_date('Y-m-d H:i');
    $submission_row = [
        'submission_id' => $submission_id,
        'door_ids' => $normalized_door_ids,
        'sections' => $normalized_sections,
        'updated_at' => $updated_at,
        'updated_by' => $user_id,
    ];
    if ($existing_submission_index >= 0 && isset($existing_submissions[$existing_submission_index])) {
        $existing_submissions[$existing_submission_index] = $submission_row;
    } else {
        array_unshift($existing_submissions, $submission_row);
    }

    if (function_exists('ado_project_timeline_append_event')) {
        $submission_door_labels = [];
        foreach ($normalized_door_ids as $submission_door_id) {
            $submission_door_labels[] = (string) ($door_label_lookup[$submission_door_id] ?? ('Door ' . $submission_door_id));
        }
        $timeline_details = [
            'Submission ID: ' . $submission_id,
            'Checklist: ' . $checked_items . '/' . $total_items . ' checked',
            'Doors: ' . ($submission_door_labels ? implode(', ', $submission_door_labels) : 'None'),
        ];
        if (!$is_new_submission && $previous_submission_row) {
            $previous_door_map = [];
            foreach ((array) ($previous_submission_row['door_ids'] ?? []) as $previous_door_id_raw) {
                $previous_door_id = sanitize_text_field((string) $previous_door_id_raw);
                if ($previous_door_id === '') {
                    continue;
                }
                $previous_door_map[$previous_door_id] = true;
            }
            $current_door_map = array_fill_keys($normalized_door_ids, true);
            $added_door_labels = [];
            $removed_door_labels = [];
            foreach ($normalized_door_ids as $current_door_id) {
                if (!isset($previous_door_map[$current_door_id])) {
                    $added_door_labels[] = (string) ($door_label_lookup[$current_door_id] ?? ('Door ' . $current_door_id));
                }
            }
            foreach (array_keys($previous_door_map) as $previous_door_id) {
                if (isset($current_door_map[$previous_door_id])) {
                    continue;
                }
                $removed_door_labels[] = (string) ($door_label_lookup[$previous_door_id] ?? ('Door ' . $previous_door_id));
            }
            if ($added_door_labels) {
                $timeline_details[] = 'Door scope added: ' . implode(', ', $added_door_labels);
            }
            if ($removed_door_labels) {
                $timeline_details[] = 'Door scope removed: ' . implode(', ', $removed_door_labels);
            }
        }
        ado_project_timeline_append_event($order, [
            'title' => $is_new_submission ? 'Hardware Availability Submitted' : 'Hardware Availability Updated',
            'summary' => $is_new_submission
                ? 'Project manager submitted a new hardware-availability checklist.'
                : 'Project manager updated an existing hardware-availability checklist.',
            'category' => 'hardware_availability',
            'action' => $is_new_submission ? 'submitted' : 'updated',
            'actor_id' => $user_id,
            'actor_name' => ado_project_timeline_actor_name($user_id),
            'actor_role' => 'project_manager',
            'door_ids' => $normalized_door_ids,
            'door_labels' => $submission_door_labels,
            'details' => $timeline_details,
        ]);
    }

    $order->update_meta_data('_ado_hardware_availability_checklist', [
        'active_submission_id' => $submission_id,
        'submissions' => array_values($existing_submissions),
        'updated_at' => $updated_at,
        'updated_by' => $user_id,
    ]);
    $order->save();
    wp_send_json_success([
        'message' => 'Hardware availability checklist saved (' . $checked_items . ' of ' . $total_items . ' items confirmed).',
        'submission_id' => $submission_id,
        'is_new_submission' => $is_new_submission,
        'submission_total' => count($existing_submissions),
        'checked_items' => $checked_items,
        'total_items' => $total_items,
        'section_total' => count($definition),
        'door_total' => count($normalized_door_ids),
    ]);
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
    $original_state = is_array($state) ? (array) $state : [];
    $door_label = 'Door ' . $door_id;
    foreach (ado_cd_order_doors($order) as $door_row) {
        if (!is_array($door_row)) {
            continue;
        }
        if ((string) ($door_row['door_id'] ?? '') !== $door_id) {
            continue;
        }
        $door_label = trim((string) ($door_row['door_label'] ?? $door_label));
        if ($door_label === '') {
            $door_label = 'Door ' . $door_id;
        }
        break;
    }
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

    $uploaded_document_name = '';
    if (!empty($_FILES['door_document']['tmp_name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($_FILES['door_document'], ['test_form' => false]);
        if (!empty($upload['error'])) {
            wp_send_json_error(['message' => (string) $upload['error']], 400);
        }
        if (empty($upload['url'])) {
            wp_send_json_error(['message' => 'Failed to upload file.'], 400);
        }
        $uploaded_document_name = sanitize_text_field((string) ($_FILES['door_document']['name'] ?? 'Document'));
        $documents[] = [
            'url' => esc_url_raw((string) $upload['url']),
            'name' => $uploaded_document_name,
            'uploaded_at' => wp_date('Y-m-d H:i'),
            'uploaded_by' => $user_id,
        ];
    }

    $state['documents'] = $documents;
    $feedback_map[$door_id] = $state;
    if (function_exists('ado_project_timeline_append_event')) {
        $change_lines = [];
        if ((bool) ($original_state['readiness_confirmed'] ?? false) !== (bool) ($state['readiness_confirmed'] ?? false)) {
            $change_lines[] = 'Readiness confirmed: ' . (!empty($original_state['readiness_confirmed']) ? 'Yes' : 'No') . ' -> ' . (!empty($state['readiness_confirmed']) ? 'Yes' : 'No');
        }
        if (trim((string) ($original_state['readiness_note'] ?? '')) !== trim((string) ($state['readiness_note'] ?? ''))) {
            $change_lines[] = 'Readiness note updated.';
        }
        if (trim((string) ($original_state['note'] ?? '')) !== trim((string) ($state['note'] ?? ''))) {
            $change_lines[] = 'Project manager note updated.';
        }
        $original_document_count = is_array($original_state['documents'] ?? null) ? count((array) $original_state['documents']) : 0;
        $current_document_count = count($documents);
        if ($current_document_count !== $original_document_count) {
            $change_lines[] = 'Document count: ' . $original_document_count . ' -> ' . $current_document_count;
        }
        if ($uploaded_document_name !== '') {
            $change_lines[] = 'Document uploaded: ' . $uploaded_document_name;
        }
        if ($change_lines) {
            ado_project_timeline_append_event($order, [
                'title' => 'Project Manager Door Update Submitted',
                'summary' => $door_label . ' was updated by the project manager.',
                'category' => $uploaded_document_name !== '' ? 'media' : 'door_update',
                'action' => 'updated',
                'actor_id' => $user_id,
                'actor_name' => ado_project_timeline_actor_name($user_id),
                'actor_role' => 'project_manager',
                'door_ids' => [$door_id],
                'door_labels' => [$door_label],
                'details' => $change_lines,
            ]);
        }
    }
    $order->update_meta_data('_ado_client_door_feedback', $feedback_map);
    $order->save();

    wp_send_json_success([
        'message' => 'Door update saved.',
        'document_count' => count($documents),
    ]);
});

