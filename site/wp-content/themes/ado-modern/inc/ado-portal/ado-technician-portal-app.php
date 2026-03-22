<?php
// ADO technician portal shell + backend-integrated views.
if (defined('ADO_TECHNICIAN_PORTAL_APP_LOADED')) {
    return;
}
define('ADO_TECHNICIAN_PORTAL_APP_LOADED', true);

function ado_tp_view_url(string $view, array $extra = []): string
{
    return esc_url(add_query_arg(array_merge(['view' => $view], $extra), home_url('/technician-portal/')));
}

function ado_tp_parse_tech_ids(string $raw): array
{
    $parts = preg_split('/[\s,]+/', trim($raw));
    $ids = [];
    foreach ((array) $parts as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function ado_tp_orders_for_user(int $user_id): array
{
    $orders = wc_get_orders(['limit' => 200, 'orderby' => 'date', 'order' => 'DESC']);
    $out = [];
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) {
            continue;
        }
        $ids = ado_tp_parse_tech_ids((string) $order->get_meta('_ado_technician_ids'));
        if (in_array($user_id, $ids, true)) {
            $out[] = $order;
        }
    }
    return $out;
}

function ado_tp_order_name(WC_Order $order): string
{
    $company = trim((string) $order->get_billing_company());
    if ($company !== '') {
        return $company;
    }
    foreach ($order->get_items() as $item) {
        if ($item instanceof WC_Order_Item_Product) {
            $name = trim((string) $item->get_name());
            if ($name !== '') {
                return $name;
            }
        }
    }
    return 'Project #' . (string) $order->get_id();
}

function ado_tp_order_location(WC_Order $order): string
{
    $parts = array_filter([$order->get_shipping_city(), $order->get_shipping_state()]);
    if (!$parts) {
        $parts = array_filter([$order->get_billing_city(), $order->get_billing_state()]);
    }
    return $parts ? implode(', ', array_map('strval', $parts)) : 'Location pending';
}

function ado_tp_scope_payload(WC_Order $order): array
{
    $scope_path = (string) $order->get_meta('_ado_scoped_json_path');
    if ($scope_path !== '' && is_readable($scope_path)) {
        $json = json_decode((string) file_get_contents($scope_path), true);
        if (is_array($json)) {
            return $json;
        }
    }

    $snapshot = (string) $order->get_meta('_ado_scoped_json_snapshot');
    if ($snapshot !== '') {
        $json = json_decode($snapshot, true);
        if (is_array($json)) {
            return $json;
        }
        $clean_snapshot = preg_replace('/,"_scope_pass\\d+_examples":\\[[\\s\\S]*?\\](?=,|})/', '', $snapshot);
        if (is_string($clean_snapshot) && $clean_snapshot !== $snapshot) {
            $json = json_decode($clean_snapshot, true);
            if (is_array($json)) {
                return $json;
            }
        }
    }

    return [];
}

function ado_tp_door_rows(WC_Order $order): array
{
    $rows = [];
    $door_state = ado_tp_project_door_state_maps($order);
    $scoped_rows = [];
    $scoped_lookup = [];
    $payload = ado_tp_scope_payload($order);
    foreach ((array) ($payload['result']['doors'] ?? []) as $door) {
        if (!is_array($door)) {
            continue;
        }
        $door_id = trim((string) ($door['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $model = '';
        $items = [];
        foreach ((array) ($door['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $token = trim((string) ($item['catalog'] ?? ''));
            if ($token !== '' && $model === '') {
                $model = $token;
            }
            $normalized_item = ado_tp_normalize_project_door_item($item);
            if ($normalized_item === null) {
                continue;
            }
            $items[] = $normalized_item;
            if ($model === '' && $normalized_item['catalog'] !== '') {
                $model = (string) $normalized_item['catalog'];
            }
            if ($model === '' && $normalized_item['desc'] !== '') {
                $model = (string) $normalized_item['desc'];
            }
        }
        $door_number = trim((string) ($door['door_number'] ?? $door_id));
        $door_label = trim((string) ($door['door_label'] ?? ''));
        if ($door_label === '') {
            $door_label = 'Door ' . $door_number;
        }
        $scoped_row = [
            'door_id' => $door_id,
            'door_number' => $door_number,
            'door_label' => $door_label,
            'model' => $model !== '' ? $model : 'Model pending',
            'location' => trim((string) ($door['heading'] ?? '')),
            'door_type' => trim((string) ($door['door_type'] ?? '')),
            'notes' => (string) ($door_state['notes'][$door_id] ?? ($door['notes'] ?? '')),
            'items' => $items,
            'checks' => ado_tp_project_door_check_state((array) ($door_state['checks'][$door_id] ?? ($door['install_checks'] ?? []))),
            'is_scoped' => true,
        ];
        $scoped_rows[] = $scoped_row;
        foreach ([$door_id, $door_number] as $door_key) {
            $door_key = strtolower(trim((string) $door_key));
            if ($door_key !== '') {
                $scoped_lookup[$door_key] = $scoped_row;
            }
        }
    }

    $project_doors = $order->get_meta('_ado_project_doors');
    if (is_array($project_doors) && $project_doors) {
        foreach ($project_doors as $door) {
            if (!is_array($door)) {
                continue;
            }
            $door_id = trim((string) ($door['door_number'] ?? ($door['door_id'] ?? '')));
            if ($door_id === '') {
                continue;
            }
            $model = '';
            $items = [];
            if (!empty($door['signals']) && is_array($door['signals'])) {
                $model = trim((string) reset($door['signals']));
            }
            if (!empty($door['items']) && is_array($door['items'])) {
                foreach ($door['items'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $normalized_item = ado_tp_normalize_project_door_item($item);
                    if ($normalized_item === null) {
                        continue;
                    }
                    $items[] = $normalized_item;
                    if ($model === '' && $normalized_item['catalog'] !== '') {
                        $model = (string) $normalized_item['catalog'];
                    }
                    if ($model === '' && $normalized_item['desc'] !== '') {
                        $model = (string) $normalized_item['desc'];
                    }
                }
            }
            $door_number = trim((string) ($door['door_number'] ?? $door_id));
            $door_label = trim((string) ($door['door_label'] ?? ''));
            $scoped_door = null;
            foreach ([$door_id, $door_number] as $door_key) {
                $door_key = strtolower(trim((string) $door_key));
                if ($door_key !== '' && isset($scoped_lookup[$door_key])) {
                    $scoped_door = $scoped_lookup[$door_key];
                    break;
                }
            }
            if (is_array($scoped_door)) {
                if (!$items && !empty($scoped_door['items']) && is_array($scoped_door['items'])) {
                    $items = $scoped_door['items'];
                }
                if (($model === '' || $model === 'Model pending') && trim((string) ($scoped_door['model'] ?? '')) !== '') {
                    $model = trim((string) $scoped_door['model']);
                }
                if ($door_label === '' && trim((string) ($scoped_door['door_label'] ?? '')) !== '') {
                    $door_label = trim((string) $scoped_door['door_label']);
                }
            }
            if ($door_label === '') {
                $door_label = 'Door ' . $door_number;
            }
            $rows[] = [
                'door_id' => $door_id,
                'door_number' => $door_number,
                'door_label' => $door_label,
                'model' => $model !== '' ? $model : 'Model pending',
                'location' => trim((string) ($door['heading'] ?? '')),
                'door_type' => trim((string) ($door['door_type'] ?? '')),
                'notes' => (string) ($door_state['notes'][$door_id] ?? ($door['notes'] ?? '')),
                'items' => $items,
                'checks' => ado_tp_project_door_check_state((array) ($door_state['checks'][$door_id] ?? ($door['install_checks'] ?? []))),
                'is_scoped' => true,
            ];
        }
        if ($rows) {
            return $rows;
        }
    }

    $item_seen = [];
    foreach ($order->get_items() as $item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            continue;
        }
        $door_id = trim((string) $item->get_meta('_adq_door_number'));
        if ($door_id === '') {
            $door_id = trim((string) $item->get_meta('_adq_door_id'));
        }
        if ($door_id === '' || isset($item_seen[$door_id])) {
            continue;
        }
        $item_seen[$door_id] = true;
        $model = trim((string) $item->get_meta('_adq_model'));
        $item_name = trim((string) $item->get_name());
        $rows[] = [
            'door_id' => $door_id,
            'door_number' => $door_id,
            'door_label' => 'Door ' . $door_id,
            'model' => $model !== '' ? $model : 'Model pending',
            'location' => '',
            'door_type' => '',
            'notes' => (string) ($door_state['notes'][$door_id] ?? ''),
            'items' => $item_name !== '' ? [ado_tp_normalize_project_door_item([
                'catalog' => $model !== '' ? $model : $item_name,
                'desc' => $item_name,
                'raw' => $item_name,
                'qty' => max(1, (int) $item->get_quantity()),
            ])] : [],
            'checks' => ado_tp_project_door_check_state((array) ($door_state['checks'][$door_id] ?? [])),
            'is_scoped' => false,
        ];
    }
    if ($rows) {
        return $rows;
    }

    return $scoped_rows;
}

function ado_tp_normalize_project_door_item(array $item): ?array
{
    $catalog = trim((string) ($item['catalog'] ?? ($item['model'] ?? '')));
    $desc = trim((string) ($item['desc'] ?? ''));
    $raw = trim((string) ($item['raw'] ?? ''));
    $qty = max(1, (int) ($item['qty'] ?? 1));
    if ($catalog === '' && $desc === '' && $raw === '') {
        return null;
    }
    return [
        'catalog' => $catalog,
        'desc' => $desc,
        'raw' => $raw,
        'qty' => $qty,
    ];
}

function ado_tp_project_door_check_labels(): array
{
    return [
        'installed' => 'Install complete',
        'hardware_verified' => 'Hardware verified',
        'cleanup_complete' => 'Cleanup complete',
    ];
}

function ado_tp_project_door_check_state(array $saved = []): array
{
    $state = [];
    foreach (ado_tp_project_door_check_labels() as $key => $label) {
        $state[$key] = !empty($saved[$key]);
    }
    return $state;
}

function ado_tp_project_door_state_maps(WC_Order $order): array
{
    $note_map = $order->get_meta('_ado_quote_door_notes');
    $note_map = is_array($note_map) ? $note_map : [];
    $tech_note_map = $order->get_meta('_ado_tech_door_notes');
    if (is_array($tech_note_map) && $tech_note_map) {
        $note_map = array_merge($note_map, $tech_note_map);
    }

    $check_map = $order->get_meta('_ado_tech_door_checks');
    $check_map = is_array($check_map) ? $check_map : [];

    return [
        'notes' => $note_map,
        'checks' => $check_map,
    ];
}

function ado_tp_project_door_workflow_map(WC_Order $order): array
{
    $workflow_map = $order->get_meta('_ado_tp_project_door_workflow');
    return is_array($workflow_map) ? $workflow_map : [];
}

function ado_tp_project_door_workflow_defaults(): array
{
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
        ],
    ];
}

function ado_tp_project_door_workflow_state(WC_Order $order, string $door_id): array
{
    $workflow_map = ado_tp_project_door_workflow_map($order);
    $door_state = is_array($workflow_map[$door_id] ?? null) ? $workflow_map[$door_id] : [];
    $defaults = ado_tp_project_door_workflow_defaults();

    foreach (['site_preparation', 'hardware_availability'] as $section) {
        $defaults[$section]['state'] = strtolower(trim((string) ($door_state[$section]['state'] ?? 'yes'))) === 'no' ? 'no' : 'yes';
        $defaults[$section]['comment'] = trim((string) ($door_state[$section]['comment'] ?? ''));
    }

    $hardware_entries = $door_state['hardware_entries'] ?? [];
    $defaults['hardware_entries'] = is_array($hardware_entries) ? $hardware_entries : [];

    $testing = is_array($door_state['testing'] ?? null) ? $door_state['testing'] : [];
    $defaults['testing']['note'] = trim((string) ($testing['note'] ?? ''));
    $defaults['testing']['complete'] = !empty($testing['complete']);
    $defaults['testing']['final_video'] = is_array($testing['final_video'] ?? null) ? $testing['final_video'] : [];

    return $defaults;
}

function ado_tp_project_door_binary_state($value): string
{
    $value = strtolower(trim((string) $value));
    return in_array($value, ['no', '0', 'false', 'off'], true) ? 'no' : 'yes';
}

function ado_tp_project_door_hardware_category(array $item): string
{
    $text = strtolower(trim(implode(' ', array_filter([
        (string) ($item['catalog'] ?? ''),
        (string) ($item['desc'] ?? ''),
        (string) ($item['raw'] ?? ''),
    ]))));
    $rules = [
        'Operators and Closers' => ['operator', 'closer'],
        'Access Control' => ['reader', 'key switch', 'card', 'relay', 'sensor', 'push', 'wire', 'control'],
        'Locks and Strikes' => ['strike', 'lock', 'latch', 'cylinder', 'thumbturn', 'deadbolt'],
        'Hinges and Pivots' => ['hinge', 'pivot'],
        'Door Components' => ['plate', 'arm', 'spindle', 'bracket', 'mount', 'adapter'],
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

function ado_tp_project_door_hardware_model(array $item): string
{
    foreach (['catalog', 'model', 'desc', 'raw'] as $field) {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'Model pending';
}

function ado_tp_project_door_hardware_groups(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $category_label = ado_tp_project_door_hardware_category($item);
        $category_key = ado_tp_dom_id('ado-hw-cat-', $category_label);
        $model_label = ado_tp_project_door_hardware_model($item);
        $model_key = ado_tp_dom_id('ado-hw-model-', $model_label);
        $qty = max(1, (int) ($item['qty'] ?? 1));
        if (!isset($groups[$category_key])) {
            $groups[$category_key] = [
                'category_key' => $category_key,
                'category_label' => $category_label,
                'models' => [],
            ];
        }
        if (!isset($groups[$category_key]['models'][$model_key])) {
            $groups[$category_key]['models'][$model_key] = [
                'model_key' => $model_key,
                'model_label' => $model_label,
                'qty' => 0,
                'items' => [],
            ];
        }
        $groups[$category_key]['models'][$model_key]['qty'] += $qty;
        $groups[$category_key]['models'][$model_key]['items'][] = $item;
    }
    return array_values(array_map(static function (array $group): array {
        $group['models'] = array_values($group['models']);
        return $group;
    }, $groups));
}

function ado_tp_project_door_log_text(array $log): string
{
    foreach (['note', 'text', 'message', 'description', 'comment'] as $key) {
        $value = trim((string) ($log[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function ado_tp_project_door_overview_documents(WC_Order $order): array
{
    $logs = ado_tp_order_logs($order);
    $files = ado_tp_order_files($order, $logs);
    $documents = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $url = trim((string) ($file['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $name = trim((string) ($file['name'] ?? ''));
        $meta = trim((string) ($file['meta'] ?? ''));
        $type = strtolower(trim((string) ($file['type'] ?? '')));
        $haystack = strtolower(trim($name . ' ' . $meta . ' ' . $url));
        if ($type === 'json' || strpos($haystack, '.json') !== false) {
            continue;
        }
        $documents[] = [
            'name' => $name !== '' ? $name : 'Document',
            'meta' => $meta,
            'url' => $url,
            'type' => $type !== '' ? $type : 'file',
            'is_pdf' => $type === 'pdf' || strpos($haystack, '.pdf') !== false || strpos($haystack, 'pdf') !== false,
            'is_hardware_schedule' => strpos($haystack, 'hardware schedule') !== false || (strpos($haystack, 'hardware') !== false && strpos($haystack, 'schedule') !== false),
            'is_floor_plans' => strpos($haystack, 'floor plan') !== false || strpos($haystack, 'floor plans') !== false || strpos($haystack, 'floorplan') !== false || strpos($haystack, 'plan set') !== false,
        ];
    }

    $schedule = null;
    $floor_plans = null;
    $other_docs = [];
    foreach ($documents as $document) {
        if (!is_array($document)) {
            continue;
        }
        if ($schedule === null && $document['is_hardware_schedule']) {
            $schedule = $document;
            continue;
        }
        if ($floor_plans === null && $document['is_floor_plans']) {
            $floor_plans = $document;
            continue;
        }
        if (!empty($document['is_pdf']) || in_array(strtolower((string) ($document['type'] ?? '')), ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'm4v', 'webm'], true)) {
            $other_docs[] = $document;
        }
    }

    $comments = [];
    foreach ($logs as $log) {
        if (!is_array($log)) {
            continue;
        }
        $text = ado_tp_project_door_log_text($log);
        if ($text === '') {
            continue;
        }
        if (!preg_match('/hardware|function|functionality|operate|operating|review|adjust|issue|problem|confirm|verify|testing|test|consult/i', $text)) {
            continue;
        }
        $comments[] = [
            'text' => $text,
            'created_at' => trim((string) ($log['created_at'] ?? '')),
            'door_hint' => trim((string) ($log['door_hint'] ?? ado_tp_note_door_hint($text))),
        ];
    }

    return [
        'hardware_schedule' => $schedule,
        'floor_plans' => $floor_plans,
        'other_documents' => $other_docs,
        'comments' => $comments,
    ];
}

function ado_tp_project_door_upload_file(array $file): array
{
    $result = [
        'ok' => false,
        'error' => '',
        'name' => '',
        'url' => '',
        'type' => '',
        'size' => 0,
    ];
    $name = sanitize_file_name((string) ($file['name'] ?? ''));
    $tmp_name = (string) ($file['tmp_name'] ?? '');
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($name === '' || $tmp_name === '' || $error === UPLOAD_ERR_NO_FILE) {
        return $result;
    }
    if ($error !== UPLOAD_ERR_OK) {
        $result['error'] = 'Upload failed.';
        return $result;
    }
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'm4v', 'webm'];
    if ($ext === '' || !in_array($ext, $allowed_exts, true)) {
        $result['error'] = 'Unsupported file type.';
        return $result;
    }
    if (!function_exists('wp_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $upload = wp_handle_sideload($file, [
        'test_form' => false,
        'test_type' => false,
    ]);
    if (!empty($upload['error'])) {
        $result['error'] = (string) $upload['error'];
        return $result;
    }
    $result['ok'] = true;
    $result['name'] = $name;
    $result['url'] = (string) ($upload['url'] ?? '');
    $result['type'] = $ext;
    $result['size'] = (int) ($file['size'] ?? 0);
    return $result;
}

function ado_tp_project_door_nested_upload_file(array $files, string $group_key, string $model_key): array
{
    $name = $files['name'][$group_key][$model_key] ?? '';
    if (!is_string($name) || trim($name) === '') {
        return [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ];
    }
    return [
        'name' => (string) ($files['name'][$group_key][$model_key] ?? ''),
        'type' => (string) ($files['type'][$group_key][$model_key] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'][$group_key][$model_key] ?? ''),
        'error' => (int) ($files['error'][$group_key][$model_key] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($files['size'][$group_key][$model_key] ?? 0),
    ];
}

function ado_tp_project_door_media_entry(array $upload, string $section, string $door_id, string $category_key = '', string $model_key = ''): array
{
    return [
        'name' => trim((string) ($upload['name'] ?? '')),
        'url' => trim((string) ($upload['url'] ?? '')),
        'type' => trim((string) ($upload['type'] ?? '')),
        'size' => (int) ($upload['size'] ?? 0),
        'section' => $section,
        'door_id' => $door_id,
        'category_key' => $category_key,
        'model_key' => $model_key,
        'created_at' => wp_date('c'),
    ];
}

function ado_tp_project_door_normalize_media_entries($entries): array
{
    $entries = is_array($entries) ? $entries : [];
    $normalized = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $url = trim((string) ($entry['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $normalized[] = [
            'name' => trim((string) ($entry['name'] ?? '')),
            'url' => $url,
            'type' => trim((string) ($entry['type'] ?? '')),
            'size' => (int) ($entry['size'] ?? 0),
            'section' => trim((string) ($entry['section'] ?? '')),
            'door_id' => trim((string) ($entry['door_id'] ?? '')),
            'category_key' => trim((string) ($entry['category_key'] ?? '')),
            'model_key' => trim((string) ($entry['model_key'] ?? '')),
            'created_at' => trim((string) ($entry['created_at'] ?? '')),
        ];
    }
    return $normalized;
}

function ado_tp_process_project_door_save(WC_Order $order, string $door_id, array $post, array $files): array
{
    $door = null;
    foreach (ado_tp_door_rows($order) as $row) {
        if ((string) ($row['door_id'] ?? '') === $door_id) {
            $door = $row;
            break;
        }
    }
    if (!is_array($door)) {
        return ['ok' => false, 'code' => 404, 'message' => 'Door not found on this project.'];
    }

    $workflow_map = ado_tp_project_door_workflow_map($order);
    $door_state = ado_tp_project_door_workflow_state($order, $door_id);
    $door_items = (array) ($door['items'] ?? []);
    $hardware_groups = ado_tp_project_door_hardware_groups($door_items);
    $hardware_inputs = isset($post['hardware_notes']) && is_array($post['hardware_notes']) ? (array) $post['hardware_notes'] : [];
    $hardware_installed_inputs = isset($post['hardware_installed']) && is_array($post['hardware_installed']) ? (array) $post['hardware_installed'] : [];
    $hardware_files = isset($files['hardware_media']) && is_array($files['hardware_media']) ? (array) $files['hardware_media'] : [];
    $workflow_media = [];

    foreach (['site_preparation', 'hardware_availability'] as $section) {
        $incoming = isset($post[$section]) && is_array($post[$section]) ? (array) $post[$section] : [];
        $state = ado_tp_project_door_binary_state((string) ($incoming['state'] ?? 'yes'));
        $comment = sanitize_textarea_field((string) wp_unslash((string) ($incoming['comment'] ?? '')));
        if ($state === 'no' && $comment === '') {
            return [
                'ok' => false,
                'code' => 400,
                'message' => ucfirst(str_replace('_', ' ', $section)) . ' requires a comment when marked No.',
            ];
        }
        $door_state[$section]['state'] = $state;
        $door_state[$section]['comment'] = $comment;
    }

    $existing_hardware_entries = isset($door_state['hardware_entries']) && is_array($door_state['hardware_entries']) ? (array) $door_state['hardware_entries'] : [];
    $saved_hardware_models = 0;
    $total_hardware_models = 0;
    $installed_hardware_models = 0;

    foreach ($hardware_groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $category_key = (string) ($group['category_key'] ?? '');
        $category_label = (string) ($group['category_label'] ?? '');
        if ($category_key === '' || $category_label === '') {
            continue;
        }
        if (!isset($door_state['hardware_entries'][$category_key]) || !is_array($door_state['hardware_entries'][$category_key])) {
            $door_state['hardware_entries'][$category_key] = [
                'category_key' => $category_key,
                'category_label' => $category_label,
                'models' => [],
            ];
        }
        foreach ((array) ($group['models'] ?? []) as $model) {
            if (!is_array($model)) {
                continue;
            }
            $model_key = (string) ($model['model_key'] ?? '');
            $model_label = trim((string) ($model['model_label'] ?? ''));
            if ($model_key === '' || $model_label === '') {
                continue;
            }
            $total_hardware_models++;
            $incoming_note = sanitize_textarea_field((string) wp_unslash((string) ($hardware_inputs[$category_key][$model_key] ?? '')));
            $existing_entry = isset($existing_hardware_entries[$category_key]['models'][$model_key]) && is_array($existing_hardware_entries[$category_key]['models'][$model_key]) ? (array) $existing_hardware_entries[$category_key]['models'][$model_key] : [];
            $incoming_installed = !empty($hardware_installed_inputs[$category_key][$model_key]);
            $saved_note = trim((string) ($existing_entry['note'] ?? ''));
            if ($incoming_note !== '') {
                $saved_note = $incoming_note;
            }
            if ($incoming_installed) {
                $installed_hardware_models++;
            }

            $media = isset($existing_entry['media']) && is_array($existing_entry['media']) ? ado_tp_project_door_normalize_media_entries($existing_entry['media']) : [];
            $nested_file = ado_tp_project_door_nested_upload_file($hardware_files, $category_key, $model_key);
            if ((string) ($nested_file['name'] ?? '') !== '' && (int) ($nested_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = ado_tp_project_door_upload_file($nested_file);
                if (empty($upload['ok'])) {
                    return ['ok' => false, 'code' => 400, 'message' => 'Hardware media upload failed: ' . (string) ($upload['error'] ?? 'Upload failed.')];
                }
                $media[] = ado_tp_project_door_media_entry($upload, 'hardware', $door_id, $category_key, $model_key);
                $workflow_media[] = $upload['name'];
            }

            if ($incoming_installed || $saved_note !== '' || !empty($media) || !empty($existing_entry)) {
                $door_state['hardware_entries'][$category_key]['models'][$model_key] = [
                    'model_key' => $model_key,
                    'model_label' => $model_label,
                    'installed' => $incoming_installed,
                    'note' => $saved_note,
                    'media' => $media,
                    'updated_at' => wp_date('c'),
                ];
                $saved_hardware_models++;
            }
        }
    }

    $testing_input = isset($post['testing']) && is_array($post['testing']) ? (array) $post['testing'] : [];
    $testing_note = sanitize_textarea_field((string) wp_unslash((string) ($testing_input['note'] ?? '')));
    if ($testing_note === '' && isset($post['door_note'])) {
        $testing_note = sanitize_textarea_field((string) wp_unslash((string) ($post['door_note'] ?? '')));
    }
    $testing_complete = !empty($testing_input['complete']);
    $existing_videos = isset($door_state['testing']['final_video']) && is_array($door_state['testing']['final_video']) ? ado_tp_project_door_normalize_media_entries($door_state['testing']['final_video']) : [];
    $final_video_upload = ado_tp_project_door_upload_file(isset($files['testing_final_video']) && is_array($files['testing_final_video']) ? (array) $files['testing_final_video'] : []);
    if (empty($final_video_upload['ok']) && ((string) ($final_video_upload['error'] ?? '') !== '' && (int) ($final_video_upload['error'] ?? '') !== UPLOAD_ERR_NO_FILE)) {
        return ['ok' => false, 'code' => 400, 'message' => 'Final test video upload failed: ' . (string) ($final_video_upload['error'] ?? 'Upload failed.')];
    }
    if (!empty($final_video_upload['ok'])) {
        $existing_videos[] = ado_tp_project_door_media_entry($final_video_upload, 'testing', $door_id);
    }
    if ($testing_complete && empty($existing_videos)) {
        return ['ok' => false, 'code' => 400, 'message' => 'Final test video is required before confirming installation complete.'];
    }
    if ($testing_complete && $total_hardware_models > 0 && $installed_hardware_models < $total_hardware_models) {
        return ['ok' => false, 'code' => 400, 'message' => 'Every hardware line must be marked Installed before confirming installation complete.'];
    }

    $door_state['testing']['note'] = $testing_note;
    $door_state['testing']['complete'] = $testing_complete;
    $door_state['testing']['final_video'] = $existing_videos;

    $workflow_map[$door_id] = $door_state;
    $order->update_meta_data('_ado_tp_project_door_workflow', $workflow_map);

    $note_map = $order->get_meta('_ado_quote_door_notes');
    $note_map = is_array($note_map) ? $note_map : [];
    $note_map[$door_id] = $testing_note;
    $order->update_meta_data('_ado_quote_door_notes', $note_map);

    $check_map = $order->get_meta('_ado_tech_door_checks');
    $check_map = is_array($check_map) ? $check_map : [];
    $check_map[$door_id] = [
        'site_preparation' => $door_state['site_preparation']['state'] ?? 'yes',
        'hardware_availability' => $door_state['hardware_availability']['state'] ?? 'yes',
        'installation_complete' => $testing_complete ? 'yes' : 'no',
    ];
    $order->update_meta_data('_ado_tech_door_checks', $check_map);

    $order->save();

    return [
        'ok' => true,
        'code' => 200,
        'message' => 'Door workflow saved.',
        'door_id' => $door_id,
        'project_id' => (int) $order->get_id(),
        'workflow' => $door_state,
        'hardware_groups' => count($hardware_groups),
        'hardware_models_saved' => $saved_hardware_models,
        'hardware_models_total' => $total_hardware_models,
        'hardware_models_installed' => $installed_hardware_models,
        'final_video_count' => count($existing_videos),
        'uploaded_media' => $workflow_media,
    ];
}

function ado_tp_order_logs(WC_Order $order): array
{
    $logs = $order->get_meta('_ado_tech_logs');
    return is_array($logs) ? $logs : [];
}

function ado_tp_note_door_hint(string $note): string
{
    if (preg_match('/\[\s*door\s*[:\-]?\s*([A-Za-z0-9\-]+)\s*\]/i', $note, $m)) {
        return strtoupper((string) $m[1]);
    }
    if (preg_match('/\bD(?:oor)?\s*[-#:]?\s*([A-Za-z0-9\-]+)/i', $note, $m)) {
        return strtoupper((string) $m[1]);
    }
    return '';
}

function ado_tp_pay_period_bounds(int $now_ts): array
{
    $day = (int) wp_date('j', $now_ts);
    if ($day <= 15) {
        $start = strtotime(wp_date('Y-m-01 00:00:00', $now_ts));
        $end = strtotime(wp_date('Y-m-15 23:59:59', $now_ts));
    } else {
        $start = strtotime(wp_date('Y-m-16 00:00:00', $now_ts));
        $end = strtotime(wp_date('Y-m-t 23:59:59', $now_ts));
    }
    return ['start' => (int) $start, 'end' => (int) $end, 'label' => wp_date('M j', $start) . ' - ' . wp_date('M j', $end)];
}

function ado_tp_order_files(WC_Order $order, array $logs): array
{
    $rows = [];
    $seen = [];
    $scope_url = trim((string) $order->get_meta('_ado_scoped_json_url'));
    if ($scope_url !== '') {
        $rows[] = ['name' => 'Scoped JSON', 'meta' => 'Door scope export', 'type' => 'json', 'url' => $scope_url, 'ts' => 0];
        $seen[$scope_url] = true;
    }
    $invoice_url = trim((string) $order->get_meta('_ado_wave_invoice_url'));
    if ($invoice_url !== '' && empty($seen[$invoice_url])) {
        $rows[] = ['name' => 'Wave Invoice', 'meta' => 'Invoice link', 'type' => 'invoice', 'url' => $invoice_url, 'ts' => 0];
        $seen[$invoice_url] = true;
    }
    foreach ($logs as $log) {
        if (!is_array($log)) {
            continue;
        }
        $url = trim((string) ($log['attachment_url'] ?? ''));
        if ($url === '' || !empty($seen[$url])) {
            continue;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'file';
        }
        $rows[] = [
            'name' => basename($path),
            'meta' => 'Field upload - ' . trim((string) ($log['created_at'] ?? '')),
            'type' => $ext,
            'url' => $url,
            'ts' => (int) (strtotime((string) ($log['created_at'] ?? '')) ?: 0),
        ];
        $seen[$url] = true;
    }
    usort($rows, static fn(array $a, array $b): int => ((int) $b['ts']) <=> ((int) $a['ts']));
    return $rows;
}

function ado_tp_initials(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'T';
    }
    $out = '';
    foreach (preg_split('/\s+/', $value) as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $out .= strtoupper(substr($part, 0, 1));
        }
    }
    return $out !== '' ? $out : 'T';
}

function ado_tp_hms(float $hours): string
{
    $seconds = max(0, (int) round($hours * 3600));
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function ado_tp_shift_label(float $hours): string
{
    $seconds = max(0, (int) round($hours * 3600));
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(($seconds % 3600) / 60);
    return sprintf('%dh %02dm', $h, $m);
}

function ado_tp_context(int $user_id): array
{
    $orders = ado_tp_orders_for_user($user_id);
    $today = wp_date('Y-m-d');
    $week_start = strtotime('monday this week');
    $week_end = strtotime('sunday this week 23:59:59');
    $week_days = [];
    for ($i = 0; $i < 7; $i++) {
        $ts = strtotime('+' . $i . ' day', $week_start);
        $week_days[] = ['date' => wp_date('Y-m-d', $ts), 'label' => wp_date('D', $ts), 'num' => wp_date('j', $ts), 'ts' => (int) $ts];
    }

    $jobs_today = [];
    $upcoming = [];
    $jobs_by_day = [];
    $flagged = [];
    $photos = [];
    $logs_for_user = [];
    $all_logs = [];
    $files_by_order = [];
    $door_total = 0;
    $progress_sum = 0;
    $missing_photo_orders = [];

    foreach ($orders as $order) {
        $visit = trim((string) $order->get_meta('_ado_next_visit_date'));
        $visit_ts = $visit !== '' ? strtotime($visit) : false;
        $doors = ado_tp_door_rows($order);
        $door_count = count($doors);
        $door_total += $door_count;

        $progress = (int) $order->get_meta('_ado_progress_pct');
        if ($progress <= 0) {
            $status = (string) $order->get_status();
            $progress = $status === 'completed' ? 100 : ($status === 'processing' ? 60 : 20);
        }
        $progress_sum += $progress;

        $job = [
            'order_id' => (int) $order->get_id(),
            'name' => ado_tp_order_name($order),
            'location' => ado_tp_order_location($order),
            'visit' => $visit,
            'visit_ts' => $visit_ts ? (int) $visit_ts : 0,
            'door_count' => $door_count,
            'progress' => max(0, min(100, $progress)),
            'status' => (string) $order->get_status(),
            'view_url' => wc_get_endpoint_url('view-order', (string) $order->get_id(), wc_get_page_permalink('myaccount')),
        ];
        if ($visit === $today) {
            $jobs_today[] = $job;
        }
        if ($visit_ts) {
            $upcoming[] = $job;
            $day_key = wp_date('Y-m-d', (int) $visit_ts);
            if (!isset($jobs_by_day[$day_key])) {
                $jobs_by_day[$day_key] = [];
            }
            $jobs_by_day[$day_key][] = $job;
        }

        $critical_raw = trim((string) $order->get_meta('_ado_critical_notes'));
        if ($critical_raw !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $critical_raw) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $priority = stripos($line, 'critical') !== false ? 'critical' : 'high';
                $flagged[] = ['order_id' => (int) $order->get_id(), 'project' => ado_tp_order_name($order), 'text' => $line, 'priority' => $priority];
            }
        }

        $logs = ado_tp_order_logs($order);
        $files_by_order[(int) $order->get_id()] = ado_tp_order_files($order, $logs);
        $order_photo_count = 0;

        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $entry = [
                'order_id' => (int) $order->get_id(),
                'project' => ado_tp_order_name($order),
                'created_at' => trim((string) ($log['created_at'] ?? '')),
                'ts' => (int) (strtotime((string) ($log['created_at'] ?? '')) ?: 0),
                'hours' => (float) ($log['hours'] ?? 0),
                'priority' => (string) ($log['priority'] ?? 'normal'),
                'note' => (string) ($log['note'] ?? ''),
                'attachment_url' => trim((string) ($log['attachment_url'] ?? '')),
                'user_id' => (int) ($log['user_id'] ?? 0),
                'door_hint' => ado_tp_note_door_hint((string) ($log['note'] ?? '')),
            ];
            $all_logs[] = $entry;
            if ($entry['user_id'] === $user_id) {
                $logs_for_user[] = $entry;
            }
            if (in_array($entry['priority'], ['critical', 'high'], true) && trim($entry['note']) !== '') {
                $flagged[] = ['order_id' => (int) $order->get_id(), 'project' => ado_tp_order_name($order), 'text' => $entry['note'], 'priority' => $entry['priority']];
            }
            if ($entry['attachment_url'] !== '') {
                $order_photo_count++;
                $photos[] = [
                    'order_id' => (int) $order->get_id(),
                    'project' => ado_tp_order_name($order),
                    'url' => $entry['attachment_url'],
                    'created_at' => $entry['created_at'],
                    'ts' => $entry['ts'],
                    'door_hint' => $entry['door_hint'],
                ];
            }
        }

        if ($door_count > $order_photo_count) {
            $missing_photo_orders[] = ['order_id' => (int) $order->get_id(), 'project' => ado_tp_order_name($order), 'missing' => $door_count - $order_photo_count];
        }
    }

    usort($upcoming, static fn(array $a, array $b): int => ((int) $a['visit_ts']) <=> ((int) $b['visit_ts']));
    usort($logs_for_user, static fn(array $a, array $b): int => ((int) $b['ts']) <=> ((int) $a['ts']));
    usort($all_logs, static fn(array $a, array $b): int => ((int) $b['ts']) <=> ((int) $a['ts']));
    usort($photos, static fn(array $a, array $b): int => ((int) $b['ts']) <=> ((int) $a['ts']));

    $day_hours = ['Mon' => 0.0, 'Tue' => 0.0, 'Wed' => 0.0, 'Thu' => 0.0, 'Fri' => 0.0, 'Sat' => 0.0, 'Sun' => 0.0];
    $week_hours = 0.0;
    $today_hours = 0.0;
    $week_groups = [];
    foreach ($logs_for_user as $log) {
        $ts = (int) $log['ts'];
        if ($ts <= 0) {
            continue;
        }
        $hours = (float) $log['hours'];
        $key = wp_date('Y-m-d', $ts);
        if (!isset($week_groups[$key])) {
            $week_groups[$key] = [];
        }
        if ($key === $today) {
            $today_hours += $hours;
        }
        if ($ts >= $week_start && $ts <= $week_end) {
            $week_hours += $hours;
            $d = wp_date('D', $ts);
            if (isset($day_hours[$d])) {
                $day_hours[$d] += $hours;
            }
            $week_groups[$key][] = $log;
        }
    }

    $pay_bounds = ado_tp_pay_period_bounds((int) time());
    $pay_period_hours = 0.0;
    $pay_period_projects = [];
    foreach ($logs_for_user as $log) {
        $ts = (int) $log['ts'];
        if ($ts < (int) $pay_bounds['start'] || $ts > (int) $pay_bounds['end']) {
            continue;
        }
        $hours = (float) $log['hours'];
        $pay_period_hours += $hours;
        $project = (string) $log['project'];
        if (!isset($pay_period_projects[$project])) {
            $pay_period_projects[$project] = 0.0;
        }
        $pay_period_projects[$project] += $hours;
    }
    arsort($pay_period_projects);

    $active_job = !empty($jobs_today) ? $jobs_today[0] : (!empty($upcoming) ? $upcoming[0] : null);
    $active_doors = [];
    if ($active_job) {
        $order = wc_get_order((int) $active_job['order_id']);
        if ($order instanceof WC_Order) {
            $active_doors = array_slice(ado_tp_door_rows($order), 0, 14);
        }
    }

    return [
        'orders' => $orders,
        'jobs_today' => $jobs_today,
        'upcoming' => $upcoming,
        'jobs_by_day' => $jobs_by_day,
        'week_days' => $week_days,
        'flagged' => array_slice($flagged, 0, 24),
        'logs' => $logs_for_user,
        'all_logs' => $all_logs,
        'photos' => array_slice($photos, 0, 120),
        'files_by_order' => $files_by_order,
        'week_hours' => $week_hours,
        'day_hours' => $day_hours,
        'today_hours' => $today_hours,
        'week_groups' => $week_groups,
        'pay_period_hours' => $pay_period_hours,
        'pay_period_label' => (string) $pay_bounds['label'],
        'pay_period_projects' => $pay_period_projects,
        'active_doors' => $active_doors,
        'door_total' => $door_total,
        'avg_progress' => count($orders) > 0 ? (int) round($progress_sum / count($orders)) : 0,
        'missing_photo_orders' => $missing_photo_orders,
        'today' => $today,
        'week_start' => (int) $week_start,
        'week_end' => (int) $week_end,
    ];
}

function ado_tp_note_form(array $orders, bool $photo_mode = false): string
{
    if (empty($orders)) {
        return '<div class="ado-empty">No assigned projects are available.</div>';
    }
    ob_start();
    ?>
    <form class="ado-tech-log-form" data-photo-mode="<?php echo $photo_mode ? '1' : '0'; ?>" enctype="multipart/form-data">
      <div class="compose-row">
        <select class="compose-select" name="order_id" required>
          <?php foreach ($orders as $order) { if (!($order instanceof WC_Order)) { continue; } ?>
            <option value="<?php echo esc_attr((string) $order->get_id()); ?>"><?php echo esc_html(ado_tp_order_name($order) . ' (#' . $order->get_id() . ')'); ?></option>
          <?php } ?>
        </select>
        <?php if ($photo_mode) { ?>
          <input class="compose-select" type="text" name="door_label" placeholder="Door (optional)">
          <input type="hidden" name="hours" value="0">
          <input type="hidden" name="priority" value="normal">
        <?php } else { ?>
          <input class="compose-select" type="number" min="0" step="0.25" name="hours" placeholder="Hours">
          <select class="compose-select" name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="critical">Critical</option></select>
        <?php } ?>
      </div>
      <textarea class="compose-textarea" name="note" rows="4" placeholder="<?php echo $photo_mode ? 'Caption (optional)' : 'Describe work, issue, or update'; ?>" <?php echo $photo_mode ? '' : 'required'; ?>></textarea>
      <div class="compose-row" style="margin-top:10px;">
        <input class="compose-select" type="file" name="attachment" <?php echo $photo_mode ? 'required' : ''; ?>>
        <button class="btn btn-primary" type="submit"><?php echo $photo_mode ? 'Upload Photo' : 'Save Note'; ?></button>
      </div>
      <div class="ado-form-flash"></div>
    </form>
    <?php
    return (string) ob_get_clean();
}

function ado_tp_dom_id(string $prefix, string $value): string
{
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($value));
    $slug = trim((string) $slug, '-_');
    if ($slug === '') {
        $slug = 'item';
    }
    return $prefix . $slug;
}

function ado_tp_render_project_door_drawer(array $door, WC_Order $project): string
{
    $door_id = trim((string) ($door['door_id'] ?? ''));
    $door_number = trim((string) ($door['door_number'] ?? $door_id));
    $door_label = trim((string) ($door['door_label'] ?? ''));
    if ($door_label === '') {
        $door_label = 'Door ' . ($door_number !== '' ? $door_number : 'Unknown');
    }
    $model = trim((string) ($door['model'] ?? ''));
    $location = trim((string) ($door['location'] ?? ''));
    $door_type = trim((string) ($door['door_type'] ?? ''));
    $items = (array) ($door['items'] ?? []);
    $project_id = (int) $project->get_id();
    $documents = ado_tp_project_door_overview_documents($project);
    $workflow_state = ado_tp_project_door_workflow_state($project, $door_id);
    $hardware_groups = ado_tp_project_door_hardware_groups($items);
    $hardware_entries = isset($workflow_state['hardware_entries']) && is_array($workflow_state['hardware_entries']) ? (array) $workflow_state['hardware_entries'] : [];
    $site_preparation = isset($workflow_state['site_preparation']) && is_array($workflow_state['site_preparation']) ? (array) $workflow_state['site_preparation'] : [];
    $hardware_availability = isset($workflow_state['hardware_availability']) && is_array($workflow_state['hardware_availability']) ? (array) $workflow_state['hardware_availability'] : [];
    $testing = isset($workflow_state['testing']) && is_array($workflow_state['testing']) ? (array) $workflow_state['testing'] : [];
    $testing_note = trim((string) ($testing['note'] ?? ''));
    $testing_complete = !empty($testing['complete']);
    $final_videos = ado_tp_project_door_normalize_media_entries($testing['final_video'] ?? []);

    $confirmations = [
        'site_preparation' => [
            'label' => 'Site preparation',
            'state' => strtolower(trim((string) ($site_preparation['state'] ?? 'yes'))) === 'no' ? 'no' : 'yes',
            'comment' => trim((string) ($site_preparation['comment'] ?? '')),
            'help' => 'Confirm the site is ready before final installation.',
        ],
        'hardware_availability' => [
            'label' => 'Hardware availability',
            'state' => strtolower(trim((string) ($hardware_availability['state'] ?? 'yes'))) === 'no' ? 'no' : 'yes',
            'comment' => trim((string) ($hardware_availability['comment'] ?? '')),
            'help' => 'Confirm the required hardware is on site before closing the drawer.',
        ],
    ];

    ob_start();
    ?>
    <div class="ado-door-card ado-door-accordion-card">
      <details class="ado-door-accordion">
        <summary class="ado-door-accordion-summary">
          <h4 class="ado-door-section-title">Information</h4>
          <span class="ado-door-accordion-icon" aria-hidden="true">▾</span>
        </summary>
      <div class="ado-door-overview-blocks ado-door-accordion-body">
        <div class="ado-door-overview-block">
          <strong>Hardware Schedule PDF</strong>
          <?php if (!empty($documents['hardware_schedule']) && is_array($documents['hardware_schedule']) && trim((string) ($documents['hardware_schedule']['url'] ?? '')) !== '') { ?>
            <a class="ado-door-overview-link" href="<?php echo esc_url((string) $documents['hardware_schedule']['url']); ?>" target="_blank" rel="noopener">
              <span><?php echo esc_html((string) ($documents['hardware_schedule']['name'] ?? 'Hardware Schedule PDF')); ?></span>
              <?php if (trim((string) ($documents['hardware_schedule']['meta'] ?? '')) !== '') { ?><small><?php echo esc_html((string) $documents['hardware_schedule']['meta']); ?></small><?php } ?>
            </a>
          <?php } else { ?>
            <div class="ado-empty ado-door-overview-fallback">No hardware schedule PDF is available yet.</div>
          <?php } ?>
        </div>
        <div class="ado-door-overview-block">
          <strong>Floor Plans PDF</strong>
          <?php if (!empty($documents['floor_plans']) && is_array($documents['floor_plans']) && trim((string) ($documents['floor_plans']['url'] ?? '')) !== '') { ?>
            <a class="ado-door-overview-link" href="<?php echo esc_url((string) $documents['floor_plans']['url']); ?>" target="_blank" rel="noopener">
              <span><?php echo esc_html((string) ($documents['floor_plans']['name'] ?? 'Floor Plans PDF')); ?></span>
              <?php if (trim((string) ($documents['floor_plans']['meta'] ?? '')) !== '') { ?><small><?php echo esc_html((string) $documents['floor_plans']['meta']); ?></small><?php } ?>
            </a>
          <?php } else { ?>
            <div class="ado-empty ado-door-overview-fallback">No floor plans PDF is available yet.</div>
          <?php } ?>
        </div>
        <div class="ado-door-overview-block">
          <strong>Other relevant documents</strong>
          <?php if (!empty($documents['other_documents']) && is_array($documents['other_documents'])) { ?>
            <div class="ado-door-document-list">
              <?php foreach ($documents['other_documents'] as $document) { if (!is_array($document) || trim((string) ($document['url'] ?? '')) === '') { continue; } ?>
                <a class="ado-door-overview-link" href="<?php echo esc_url((string) $document['url']); ?>" target="_blank" rel="noopener">
                  <span><?php echo esc_html((string) ($document['name'] ?? 'Document')); ?></span>
                  <small><?php echo esc_html(trim((string) ($document['meta'] ?? '')) !== '' ? (string) $document['meta'] : strtoupper((string) ($document['type'] ?? 'file'))); ?></small>
                </a>
              <?php } ?>
            </div>
          <?php } else { ?>
            <div class="ado-empty ado-door-overview-fallback">No additional documents were found for this project.</div>
          <?php } ?>
        </div>
        <div class="ado-door-overview-block">
          <strong>Consultant comments about hardware functionality</strong>
          <?php if (!empty($documents['comments']) && is_array($documents['comments'])) { ?>
            <div class="ado-door-comment-list">
              <?php foreach ($documents['comments'] as $comment) { if (!is_array($comment)) { continue; } $text = trim((string) ($comment['text'] ?? '')); if ($text === '') { continue; } ?>
                <div class="ado-door-comment-item">
                  <strong><?php echo esc_html($text); ?></strong>
                  <small><?php echo esc_html(trim(implode(' | ', array_filter([trim((string) ($comment['created_at'] ?? '')), trim((string) ($comment['door_hint'] ?? ''))])))); ?></small>
                </div>
              <?php } ?>
            </div>
          <?php } else { ?>
            <div class="ado-empty ado-door-overview-fallback">No consultant comments about hardware functionality were found.</div>
          <?php } ?>
        </div>
      </div>
      </details>
    </div>
    <form class="ado-door-update-form" data-project-id="<?php echo esc_attr((string) $project_id); ?>" data-door-id="<?php echo esc_attr($door_id); ?>" data-final-video-count="<?php echo esc_attr((string) count($final_videos)); ?>" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="project_id" value="<?php echo esc_attr((string) $project_id); ?>">
      <input type="hidden" name="door_id" value="<?php echo esc_attr($door_id); ?>">
      <div class="ado-door-card">
        <h4 class="ado-door-section-title">Site prep + hardware availability</h4>
        <div class="ado-door-unconfirm-grid">
          <?php foreach ($confirmations as $key => $confirmation) { $state = (string) ($confirmation['state'] ?? 'yes'); $comment = trim((string) ($confirmation['comment'] ?? '')); $is_unconfirmed = $state === 'no'; $button_label = $key === 'site_preparation' ? 'Unconfirm Site Prep' : ($key === 'hardware_availability' ? 'Unconfirm Hardware Availability' : 'Unconfirm'); $saved_comment = $comment !== '' ? $comment : 'Unconfirmed by technician via portal control.'; ?>
            <button class="ado-door-unconfirm-btn <?php echo $is_unconfirmed ? 'is-active' : ''; ?>" type="button" data-unconfirm-key="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $is_unconfirmed ? 'true' : 'false'; ?>">
              <strong><?php echo esc_html($button_label); ?></strong>
              <small data-unconfirm-status><?php echo $is_unconfirmed ? 'Currently unconfirmed' : 'Currently confirmed'; ?></small>
            </button>
            <input type="hidden" name="<?php echo esc_attr($key . '[state]'); ?>" value="<?php echo esc_attr($is_unconfirmed ? 'no' : 'yes'); ?>" data-unconfirm-state="<?php echo esc_attr($key); ?>">
            <input type="hidden" name="<?php echo esc_attr($key . '[comment]'); ?>" value="<?php echo esc_attr($is_unconfirmed ? $saved_comment : ''); ?>" data-unconfirm-comment="<?php echo esc_attr($key); ?>">
          <?php } ?>
        </div>
      </div>
      <div class="ado-door-card">
        <h4 class="ado-door-section-title">Hardware</h4>
        <?php if (!empty($hardware_groups)) { ?>
          <div class="ado-door-hardware-groups">
            <?php foreach ($hardware_groups as $group) { if (!is_array($group)) { continue; } $category_key = trim((string) ($group['category_key'] ?? '')); $category_label = trim((string) ($group['category_label'] ?? '')); if ($category_key === '' || $category_label === '') { continue; } ?>
              <div class="ado-door-hardware-group">
                <div class="ado-door-hardware-group-title"><?php echo esc_html($category_label); ?></div>
                <div class="ado-door-hardware-models">
                  <?php foreach ((array) ($group['models'] ?? []) as $model) { if (!is_array($model)) { continue; } $model_key = trim((string) ($model['model_key'] ?? '')); $model_label = trim((string) ($model['model_label'] ?? '')); if ($model_key === '' || $model_label === '') { continue; } $entry = isset($hardware_entries[$category_key]['models'][$model_key]) && is_array($hardware_entries[$category_key]['models'][$model_key]) ? (array) $hardware_entries[$category_key]['models'][$model_key] : []; $entry_installed = !empty($entry['installed']); $entry_note = trim((string) ($entry['note'] ?? '')); $entry_media = ado_tp_project_door_normalize_media_entries($entry['media'] ?? []); $entry_id = ado_tp_dom_id('ado-door-hw-', $door_id . '-' . $category_key . '-' . $model_key); ?>
                    <div class="ado-door-hardware-model" data-hardware-entry>
                      <div class="ado-door-hardware-model-head">
                        <strong><?php echo esc_html($model_label); ?></strong>
                        <?php if ((int) ($model['qty'] ?? 1) > 1) { ?><span class="ado-door-hardware-qty">x<?php echo esc_html((string) (int) ($model['qty'] ?? 1)); ?></span><?php } ?>
                        <div class="ado-door-hardware-head-actions">
                          <label class="ado-door-hardware-installed-label">
                            <input type="checkbox" class="ado-door-hardware-installed" name="<?php echo esc_attr('hardware_installed[' . $category_key . '][' . $model_key . ']'); ?>" value="1" <?php echo $entry_installed ? 'checked' : ''; ?>>
                            <span>Installed</span>
                          </label>
                          <button class="btn btn-ghost btn-sm ado-door-hardware-toggle" type="button" data-target="<?php echo esc_attr($entry_id); ?>">Add note/media</button>
                        </div>
                      </div>
                      <div class="ado-door-hardware-panel" id="<?php echo esc_attr($entry_id); ?>" <?php echo ($entry_note !== '' || !empty($entry_media)) ? '' : 'hidden'; ?>>
                        <label class="ado-door-field">
                          <span>Model note</span>
                          <textarea name="<?php echo esc_attr('hardware_notes[' . $category_key . '][' . $model_key . ']'); ?>" placeholder="Add a note or issue for this hardware model."><?php echo esc_textarea($entry_note); ?></textarea>
                        </label>
                        <label class="ado-door-field">
                          <span>Photo or video</span>
                          <input type="file" name="<?php echo esc_attr('hardware_media[' . $category_key . '][' . $model_key . ']'); ?>" accept="image/*,video/*">
                        </label>
                        <?php if (!empty($entry_media)) { ?>
                          <div class="ado-door-existing-media">
                            <?php foreach ($entry_media as $media) { if (!is_array($media) || trim((string) ($media['url'] ?? '')) === '') { continue; } ?>
                              <a href="<?php echo esc_url((string) ($media['url'] ?? '')); ?>" target="_blank" rel="noopener">
                                <strong><?php echo esc_html(trim((string) ($media['name'] ?? 'Media')) !== '' ? (string) ($media['name'] ?? 'Media') : 'Media'); ?></strong>
                                <small><?php echo esc_html(trim((string) ($media['created_at'] ?? '')) !== '' ? (string) $media['created_at'] : strtoupper((string) ($media['type'] ?? 'file'))); ?></small>
                              </a>
                            <?php } ?>
                          </div>
                        <?php } ?>
                      </div>
                    </div>
                  <?php } ?>
                </div>
              </div>
            <?php } ?>
          </div>
        <?php } else { ?>
          <div class="ado-empty">No hardware lines were found for this door.</div>
        <?php } ?>
      </div>
      <div class="ado-door-card">
        <h4 class="ado-door-section-title">Testing</h4>
        <label class="ado-door-field">
          <span>Test notes</span>
          <textarea class="ado-door-note" name="testing[note]" placeholder="Add test notes for this door."><?php echo esc_textarea($testing_note); ?></textarea>
        </label>
        <label class="ado-door-field">
          <span>Final test video</span>
          <input type="file" name="testing_final_video" accept="video/*">
        </label>
        <?php if (!empty($final_videos)) { ?>
          <div class="ado-door-existing-media">
            <?php foreach ($final_videos as $video) { if (!is_array($video) || trim((string) ($video['url'] ?? '')) === '') { continue; } ?>
              <a href="<?php echo esc_url((string) ($video['url'] ?? '')); ?>" target="_blank" rel="noopener">
                <strong><?php echo esc_html(trim((string) ($video['name'] ?? 'Final test video')) !== '' ? (string) ($video['name'] ?? 'Final test video') : 'Final test video'); ?></strong>
                <small><?php echo esc_html(trim((string) ($video['created_at'] ?? '')) !== '' ? (string) $video['created_at'] : strtoupper((string) ($video['type'] ?? 'video'))); ?></small>
              </a>
            <?php } ?>
          </div>
        <?php } ?>
        <label class="ado-door-complete">
          <input type="checkbox" name="testing[complete]" value="1" <?php echo $testing_complete ? 'checked' : ''; ?>>
          <span>Confirm hardware installation complete</span>
        </label>
        <div class="ado-door-form-hint">Completion requires every hardware line marked Installed and an existing final test video (or one uploaded with this save).</div>
        <div class="ado-door-actions" style="margin-top:10px;">
          <button class="btn btn-primary" type="submit">Save Door Update</button>
        </div>
        <div class="ado-door-flash"></div>
      </div>
    </form>
    <?php
    return (string) ob_get_clean();
}

function ado_tp_render_view(string $view, array $ctx): string
{
    $selected_project = (int) ($_GET['project_id'] ?? 0);
    $selected_door_id = sanitize_text_field((string) ($_GET['door_id'] ?? ''));
    $note_filter = sanitize_key((string) ($_GET['note_filter'] ?? 'all'));
    $selected_day = sanitize_text_field((string) ($_GET['day'] ?? $ctx['today']));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_day)) {
        $selected_day = (string) $ctx['today'];
    }

    ob_start();
    if ($view === 'schedule') {
        ?>
        <div class="page-header"><div><div class="page-title">My Schedule</div><div class="page-sub">Week of <?php echo esc_html(wp_date('M j', (int) $ctx['week_start'])); ?> - <?php echo esc_html(wp_date('M j, Y', (int) $ctx['week_end'])); ?></div></div></div>
        <div class="week-nav"><?php foreach ((array) $ctx['week_days'] as $day) { $key = (string) $day['date']; ?><a class="week-day <?php echo $key === $selected_day ? 'today' : ''; ?> <?php echo !empty($ctx['jobs_by_day'][$key]) ? 'has-jobs' : ''; ?>" href="<?php echo ado_tp_view_url('schedule', ['day' => $key]); ?>"><div class="wday-label"><?php echo esc_html((string) $day['label']); ?></div><div class="wday-num"><?php echo esc_html((string) $day['num']); ?></div></a><?php } ?></div>
        <div class="two-col-60">
          <div class="card"><div class="card-header"><div class="card-title"><?php echo esc_html(wp_date('l, F j', strtotime($selected_day) ?: time())); ?></div></div><div class="card-body"><?php $jobs = (array) ($ctx['jobs_by_day'][$selected_day] ?? []); if (!$jobs) { ?><div class="ado-empty">No jobs on this day.</div><?php } else { foreach ($jobs as $idx => $job) { $project_url = ado_tp_view_url('project', ['project_id' => (int) ($job['order_id'] ?? 0)]); ?><div class="job-block <?php echo esc_attr(['blue', 'green', 'purple'][$idx % 3]); ?>"><div class="jb-name"><?php echo esc_html((string) $job['name']); ?></div><div class="jb-meta"><?php echo esc_html((string) $job['location']); ?> &middot; <?php echo esc_html((string) ((int) $job['door_count'])); ?> doors</div><div class="jb-tags"><span class="tag tag-orange"><?php echo esc_html(ucfirst((string) $job['status'])); ?></span><a class="btn btn-ghost btn-sm" href="<?php echo esc_url($project_url); ?>">Open</a></div></div><?php } } ?></div></div>
          <div><div class="card"><div class="card-header"><div class="card-title">Upcoming Jobs</div></div><div class="card-body"><?php if (empty($ctx['upcoming'])) { ?><div class="ado-empty">No upcoming jobs.</div><?php } else { ?><div class="list"><?php foreach (array_slice((array) $ctx['upcoming'], 0, 8) as $job) { $project_url = ado_tp_view_url('project', ['project_id' => (int) ($job['order_id'] ?? 0)]); ?><a class="list-item" href="<?php echo esc_url($project_url); ?>"><strong><?php echo esc_html((string) $job['name']); ?></strong><small><?php echo esc_html((string) $job['visit']); ?> &middot; <?php echo esc_html((string) $job['location']); ?></small></a><?php } ?></div><?php } ?></div></div></div>
        </div>
        <?php
    } elseif ($view === 'project') {
        $project = null;
        if ($selected_project > 0) {
            foreach ((array) $ctx['orders'] as $order) {
                if ($order instanceof WC_Order && (int) $order->get_id() === $selected_project) {
                    $project = $order;
                    break;
                }
            }
        }
        if (!($project instanceof WC_Order)) {
            ?>
            <div class="page-header"><div><div class="page-title">Project Detail</div><div class="page-sub">Project not found or not assigned to your account.</div></div></div>
            <div class="card"><div class="card-body"><div class="ado-empty">This project is unavailable in your technician portal.</div></div></div>
            <?php
        } else {
            $project_name = ado_tp_order_name($project);
            $project_location = ado_tp_order_location($project);
            $project_doors = ado_tp_door_rows($project);
            $project_door_index = [];
            foreach ($project_doors as $door) {
                if (!is_array($door)) {
                    continue;
                }
                $door_id = trim((string) ($door['door_id'] ?? ''));
                if ($door_id === '') {
                    continue;
                }
                $project_door_index[$door_id] = $door;
            }
            $selected_door = $selected_door_id !== '' && isset($project_door_index[$selected_door_id]) ? $project_door_index[$selected_door_id] : null;
            $door_drawer_message = '';
            if ($selected_door_id !== '' && !($selected_door && is_array($selected_door))) {
                $door_drawer_message = 'Door not found in this project.';
            }
            $project_progress = (int) $project->get_meta('_ado_progress_pct');
            if ($project_progress <= 0) {
                $project_progress = $project->get_status() === 'completed' ? 100 : ($project->get_status() === 'processing' ? 60 : 20);
            }
            $next_visit = trim((string) $project->get_meta('_ado_next_visit_date'));
            $project_id = (int) $project->get_id();
            $project_logs = array_values(array_filter((array) $ctx['all_logs'], static function (array $entry) use ($project_id): bool {
                return (int) ($entry['order_id'] ?? 0) === $project_id;
            }));
            ?>
            <div class="ado-project-workspace" data-project-id="<?php echo esc_attr((string) $project_id); ?>" data-selected-door="<?php echo esc_attr($selected_door_id); ?>">
              <div class="page-header"><div><div class="page-title"><?php echo esc_html($project_name); ?></div><div class="page-sub"><?php echo esc_html($project_location); ?> &middot; Project #<?php echo esc_html((string) $project->get_id()); ?> &middot; <?php echo esc_html(ucfirst((string) $project->get_status())); ?> &middot; <?php echo esc_html((string) count((array) $project_doors)); ?> doors &middot; <?php echo esc_html((string) max(0, min(100, $project_progress))); ?>%<?php if ($next_visit !== '') { ?> &middot; Next visit <?php echo esc_html($next_visit); ?><?php } ?></div></div></div>
              <div class="card" style="margin-bottom:12px;"><div class="card-header"><div class="card-title">Doors</div><span class="tag tag-blue"><?php echo esc_html((string) count((array) $project_doors)); ?> total</span></div><div class="card-body"><?php if (empty($project_doors)) { ?><div class="ado-empty">No doors are available for this project yet.</div><?php } else { ?><div class="list"><?php foreach ($project_doors as $door) { if (!is_array($door)) { continue; } $door_id = trim((string) ($door['door_id'] ?? '')); if ($door_id === '') { continue; } $door_label = trim((string) ($door['door_label'] ?? ($door['door_number'] ?? 'Door'))); if ($door_label === '') { $door_label = 'Door ' . $door_id; } $hardware_count = count((array) ($door['items'] ?? [])); $template_id = ado_tp_dom_id('ado-door-template-', $door_id); $trigger_url = ado_tp_view_url('project', ['project_id' => $project_id, 'door_id' => $door_id]); $door_meta = trim(implode(' | ', array_filter([trim((string) ($door['model'] ?? '')), trim((string) ($door['door_type'] ?? '')), trim((string) ($door['location'] ?? ''))]))); ?><a class="list-item ado-door-trigger <?php echo $selected_door_id === $door_id ? 'active' : ''; ?>" href="<?php echo esc_url($trigger_url); ?>" data-door-id="<?php echo esc_attr($door_id); ?>" data-door-template="<?php echo esc_attr($template_id); ?>" data-door-label="<?php echo esc_attr($door_label); ?>" data-door-meta="<?php echo esc_attr($door_meta); ?>"><strong><?php echo esc_html($door_label); ?></strong><small><?php echo esc_html($door['model'] ?? 'Model pending'); ?> &middot; <?php echo esc_html((string) $hardware_count); ?> hardware lines</small><span class="ado-door-chip"><?php echo esc_html((string) $hardware_count); ?> lines</span></a><?php } ?></div><?php } ?></div></div>
              <div class="card" style="margin-top:12px;"><div class="card-header"><div class="card-title">Notes / Activity</div><span class="tag tag-blue"><?php echo esc_html((string) count((array) $project_logs)); ?> entries</span></div><div class="card-body"><?php if (empty($project_logs)) { ?><div class="ado-empty">No notes or activity yet for this project.</div><?php } else { ?><div class="list"><?php foreach ($project_logs as $entry) { $priority = (string) ($entry['priority'] ?? 'normal'); if ($priority === '') { $priority = 'normal'; } $priority_class = $priority === 'normal' ? 'info' : $priority; $created_at = trim((string) ($entry['created_at'] ?? '')); $hours = (float) ($entry['hours'] ?? 0); $note = trim((string) ($entry['note'] ?? '')); $door_hint = trim((string) ($entry['door_hint'] ?? '')); if ($door_hint === '') { $door_hint = ado_tp_note_door_hint($note); } $attachment_url = trim((string) ($entry['attachment_url'] ?? '')); ?><div class="note-card <?php echo esc_attr($priority_class); ?>"><div class="nc-top"><span class="nc-flag <?php echo esc_attr($priority_class); ?>"><?php echo esc_html(strtoupper($priority === 'normal' ? 'info' : $priority)); ?></span><?php if ($created_at !== '') { ?><span class="nc-time"><?php echo esc_html($created_at); ?></span><?php } ?><span class="nc-time"><?php echo esc_html(number_format($hours, 2)); ?>h</span><?php if ($door_hint !== '') { ?><span class="nc-door"><?php echo esc_html('Door ' . $door_hint); ?></span><?php } ?></div><div class="nc-body"><?php echo esc_html($note !== '' ? $note : 'No note text.'); ?></div><?php if ($attachment_url !== '') { ?><div class="nc-body"><a class="btn btn-ghost btn-sm" href="<?php echo esc_url($attachment_url); ?>" target="_blank" rel="noopener">Attachment</a></div><?php } ?></div><?php } ?></div><?php } ?></div></div>
              <div class="ado-door-backdrop <?php echo ($selected_door || $door_drawer_message !== '') ? 'is-open' : ''; ?>" <?php echo ($selected_door || $door_drawer_message !== '') ? '' : 'hidden'; ?>></div>
              <aside class="ado-door-drawer <?php echo ($selected_door || $door_drawer_message !== '') ? 'is-open' : ''; ?>" <?php echo ($selected_door || $door_drawer_message !== '') ? '' : 'hidden'; ?>>
                <div class="ado-door-drawer-head">
                  <div>
                    <div class="ado-door-drawer-kicker">Project Door</div>
                    <div class="ado-door-drawer-title"><?php echo esc_html($selected_door ? (string) ($selected_door['door_label'] ?? 'Door details') : 'Door details'); ?></div>
                    <div class="ado-door-drawer-sub"><?php echo esc_html($selected_door ? trim(implode(' | ', array_filter([(string) ($selected_door['model'] ?? ''), (string) ($selected_door['door_type'] ?? ''), (string) ($selected_door['location'] ?? '')]))) : ($door_drawer_message !== '' ? $door_drawer_message : 'Select a door to review hardware, notes, and install status.')); ?></div>
                  </div>
                  <button class="btn btn-ghost btn-sm ado-door-close" type="button">Close</button>
                </div>
                <div class="ado-door-drawer-body"><?php if ($selected_door && is_array($selected_door)) { echo ado_tp_render_project_door_drawer($selected_door, $project); } elseif ($door_drawer_message !== '') { ?><div class="ado-empty"><?php echo esc_html($door_drawer_message); ?></div><?php } else { ?><div class="ado-empty">Select a door to review hardware, notes, and install status.</div><?php } ?></div>
              </aside>
              <?php foreach ($project_doors as $door) { if (!is_array($door)) { continue; } $door_id = trim((string) ($door['door_id'] ?? '')); if ($door_id === '') { continue; } $template_id = ado_tp_dom_id('ado-door-template-', $door_id); ?><template id="<?php echo esc_attr($template_id); ?>"><?php echo ado_tp_render_project_door_drawer($door, $project); ?></template><?php } ?>
            </div>
            <?php
        }
    } elseif ($view === 'notes') {
        $notes = array_values(array_filter((array) $ctx['all_logs'], static function (array $n) use ($note_filter, $selected_project): bool {
            if ($selected_project > 0 && (int) ($n['order_id'] ?? 0) !== $selected_project) {
                return false;
            }
            if ($note_filter !== 'all' && (string) ($n['priority'] ?? 'normal') !== $note_filter) {
                return false;
            }
            return true;
        }));
        ?>
        <div class="page-header"><div><div class="page-title">Job Notes</div><div class="page-sub">Field observations and flags across your active projects.</div></div></div>
        <div class="notes-grid">
          <div><div class="notes-filter-bar"><a class="filter-btn <?php echo $note_filter === 'all' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('notes', ['note_filter' => 'all', 'project_id' => $selected_project ?: null]); ?>">All</a><a class="filter-btn <?php echo $note_filter === 'critical' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('notes', ['note_filter' => 'critical', 'project_id' => $selected_project ?: null]); ?>">Critical</a><a class="filter-btn <?php echo $note_filter === 'high' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('notes', ['note_filter' => 'high', 'project_id' => $selected_project ?: null]); ?>">High</a><a class="filter-btn <?php echo $note_filter === 'normal' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('notes', ['note_filter' => 'normal', 'project_id' => $selected_project ?: null]); ?>">Info</a></div>
            <div class="list" style="margin-top:12px;"><?php if (!$notes) { ?><div class="ado-empty">No notes in this filter.</div><?php } else { foreach (array_slice($notes, 0, 30) as $n) { $priority = (string) ($n['priority'] ?: 'normal'); ?><div class="note-card <?php echo esc_attr($priority === 'normal' ? 'info' : $priority); ?>"><div class="nc-top"><span class="nc-flag <?php echo esc_attr($priority === 'normal' ? 'info' : $priority); ?>"><?php echo esc_html(strtoupper($priority === 'normal' ? 'info' : $priority)); ?></span><span class="nc-project"><?php echo esc_html((string) $n['project']); ?> #<?php echo esc_html((string) ((int) $n['order_id'])); ?></span><?php if (!empty($n['door_hint'])) { ?><span class="nc-door"><?php echo esc_html('Door ' . (string) $n['door_hint']); ?></span><?php } ?><span class="nc-time"><?php echo esc_html((string) $n['created_at']); ?></span></div><div class="nc-body"><?php echo esc_html((string) $n['note']); ?></div></div><?php } } ?></div>
          </div>
          <div><div class="card"><div class="card-header"><div class="card-title">Add Note</div></div><div class="card-body"><?php echo ado_tp_note_form((array) $ctx['orders'], false); ?></div></div></div>
        </div>
        <?php
    } elseif ($view === 'files') {
        ?>
        <div class="page-header"><div><div class="page-title">Project Files</div><div class="page-sub">Scoped JSON, invoice links, and uploaded field documents.</div></div></div>
        <div class="list"><?php if (empty($ctx['orders'])) { ?><div class="ado-empty">No assigned projects.</div><?php } else { foreach ((array) $ctx['orders'] as $order) { if (!($order instanceof WC_Order)) { continue; } $oid = (int) $order->get_id(); $files = (array) ($ctx['files_by_order'][$oid] ?? []); ?><div class="card"><div class="card-header"><div class="card-title"><?php echo esc_html(ado_tp_order_name($order)); ?></div><span class="tag tag-orange"><?php echo esc_html(ucfirst((string) $order->get_status())); ?></span></div><div class="card-body"><div class="list"><a class="list-item" href="<?php echo esc_url(wc_get_endpoint_url('view-order', (string) $oid, wc_get_page_permalink('myaccount'))); ?>"><strong>Project Order #<?php echo esc_html((string) $oid); ?></strong><small><?php echo esc_html(ado_tp_order_location($order)); ?></small></a><?php foreach ($files as $file) { ?><a class="list-item" href="<?php echo esc_url((string) ($file['url'] ?: '#')); ?>" <?php echo !empty($file['url']) ? 'target="_blank" rel="noopener"' : ''; ?>><strong><?php echo esc_html((string) ($file['name'] ?: 'File')); ?></strong><small><?php echo esc_html((string) ($file['meta'] ?: '')); ?></small></a><?php } ?></div></div></div><?php } } ?></div>
        <?php
    } elseif ($view === 'photos') {
        $photo_pool = (array) $ctx['photos'];
        if ($selected_project > 0) {
            $photo_pool = array_values(array_filter($photo_pool, static fn(array $p): bool => (int) ($p['order_id'] ?? 0) === $selected_project));
        }
        $grouped = [];
        foreach ($photo_pool as $photo) {
            $door = trim((string) ($photo['door_hint'] ?? ''));
            if ($door === '') {
                $door = 'General';
            }
            if (!isset($grouped[$door])) {
                $grouped[$door] = [];
            }
            $grouped[$door][] = $photo;
        }
        ?>
        <div class="page-header"><div><div class="page-title">Photo Uploads</div><div class="page-sub">Site documentation photos by project and door.</div></div></div>
        <div class="photo-project-selector"><a class="filter-btn <?php echo $selected_project <= 0 ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('photos'); ?>">All Projects</a><?php foreach ((array) $ctx['orders'] as $order) { if (!($order instanceof WC_Order)) { continue; } $oid = (int) $order->get_id(); ?><a class="filter-btn <?php echo $selected_project === $oid ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('photos', ['project_id' => $oid]); ?>"><?php echo esc_html(ado_tp_order_name($order)); ?></a><?php } ?></div>
        <div class="photos-layout">
          <div><div class="card"><div class="card-header"><div class="card-title">Upload Photos</div></div><div class="card-body"><?php echo ado_tp_note_form((array) $ctx['orders'], true); ?></div></div>
            <div class="list" style="margin-top:12px;"><?php if (!$grouped) { ?><div class="ado-empty">No photos uploaded yet.</div><?php } else { foreach ($grouped as $door => $items) { ?><div class="card"><div class="card-header"><div class="card-title"><?php echo esc_html($door); ?></div><span class="tag tag-blue"><?php echo esc_html((string) count($items)); ?> photos</span></div><div class="card-body"><div class="photo-grid"><?php foreach ($items as $p) { ?><a class="photo-card" href="<?php echo esc_url((string) $p['url']); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url((string) $p['url']); ?>" alt=""><small><?php echo esc_html((string) $p['created_at']); ?></small></a><?php } ?></div></div></div><?php } } ?></div>
          </div>
          <div><div class="card"><div class="card-header"><div class="card-title">Missing Coverage</div></div><div class="card-body"><?php if (empty($ctx['missing_photo_orders'])) { ?><div class="ado-empty">No missing coverage detected.</div><?php } else { foreach (array_slice((array) $ctx['missing_photo_orders'], 0, 10) as $row) { ?><div class="list-item"><strong><?php echo esc_html((string) $row['project']); ?></strong><small><?php echo esc_html((string) ((int) $row['missing'])); ?> doors may need photos</small></div><?php } } ?></div></div></div>
        </div>
        <?php
    } elseif ($view === 'profile') {
        $user = wp_get_current_user();
        $name = trim((string) $user->display_name);
        $email = trim((string) $user->user_email);
        $phone = trim((string) get_user_meta((int) $user->ID, 'billing_phone', true));
        $region = trim((string) get_user_meta((int) $user->ID, 'ado_region', true));
        if ($region === '') {
            $region = 'Assigned region';
        }
        ?>
        <div class="page-header"><div><div class="page-title">My Profile</div><div class="page-sub">Technician details and workload stats.</div></div></div>
        <div class="profile-hero"><div class="profile-avatar-lg"><?php echo esc_html(ado_tp_initials($name)); ?></div><div><div class="profile-name"><?php echo esc_html($name !== '' ? $name : 'Technician'); ?></div><div class="page-sub">Field Technician &middot; <?php echo esc_html($region); ?></div></div></div>
        <div class="profile-grid"><div class="card"><div class="card-header"><div class="card-title">Personal Info</div></div><div class="card-body"><div class="kv">Name: <?php echo esc_html($name); ?></div><div class="kv">Email: <?php echo esc_html($email); ?></div><div class="kv">Phone: <?php echo esc_html($phone !== '' ? $phone : 'Not set'); ?></div></div></div><div class="card"><div class="card-header"><div class="card-title">Portal Links</div></div><div class="card-body"><a class="btn btn-ghost btn-sm" href="<?php echo ado_tp_view_url('schedule'); ?>">My Schedule</a> <a class="btn btn-ghost btn-sm" href="<?php echo ado_tp_view_url('timesheets'); ?>">Timesheets</a> <a class="btn btn-ghost btn-sm" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Sign Out</a></div></div></div>
        <?php
    } elseif ($view === 'timesheets') {
        $rate = (float) get_user_meta((int) get_current_user_id(), 'ado_hourly_rate', true);
        if ($rate <= 0) {
            $rate = 31.0;
        }
        $overtime = max(0.0, (float) $ctx['pay_period_hours'] - 80.0);
        ?>
        <div class="page-header"><div><div class="page-title">Timesheets</div><div class="page-sub">Hours logged by shift, project, and pay period.</div></div></div>
        <div class="ts-hero"><div class="stat"><strong><?php echo esc_html(number_format((float) $ctx['week_hours'], 1)); ?>h</strong><small>This Week</small></div><div class="stat"><strong><?php echo esc_html(number_format((float) $ctx['pay_period_hours'], 1)); ?>h</strong><small>Pay Period (<?php echo esc_html((string) $ctx['pay_period_label']); ?>)</small></div><div class="stat"><strong>$<?php echo esc_html(number_format((float) $ctx['pay_period_hours'] * $rate, 2)); ?></strong><small>Estimated Gross</small></div><div class="stat"><strong><?php echo esc_html(number_format($overtime, 1)); ?>h</strong><small>Overtime</small></div></div>
        <div class="two-col-60"><div class="card"><div class="card-header"><div class="card-title">Week Entries</div></div><div class="card-body"><?php foreach ((array) $ctx['week_days'] as $day) { $key = (string) $day['date']; $entries = (array) ($ctx['week_groups'][$key] ?? []); ?><div class="list-item"><strong><?php echo esc_html(wp_date('l j', (int) $day['ts'])); ?></strong><small><?php echo esc_html(number_format((float) ($ctx['day_hours'][(string) $day['label']] ?? 0), 1)); ?>h</small></div><?php foreach ($entries as $e) { ?><div class="sub-item"><?php echo esc_html((string) $e['created_at']); ?> &middot; <?php echo esc_html((string) $e['project']); ?> &middot; <?php echo esc_html(number_format((float) $e['hours'], 2)); ?>h</div><?php } } ?></div></div><div><div class="card"><div class="card-header"><div class="card-title">Manual Entry</div></div><div class="card-body"><?php echo ado_tp_note_form((array) $ctx['orders'], false); ?></div></div></div></div>
        <?php
    } else {
        ?>
        <div class="page-header"><div><div class="page-title">Dashboard</div><div class="page-sub">Today dispatch, flagged notes, and scoped progress.</div></div></div>
        <div class="ts-hero"><div class="stat"><strong><?php echo esc_html((string) count((array) $ctx['jobs_today'])); ?></strong><small>Jobs Today</small></div><div class="stat"><strong><?php echo esc_html((string) ((int) $ctx['door_total'])); ?></strong><small>Doors In Scope</small></div><div class="stat"><strong><?php echo esc_html(number_format((float) $ctx['week_hours'], 1)); ?>h</strong><small>This Week</small></div><div class="stat"><strong><?php echo esc_html((string) count((array) $ctx['flagged'])); ?></strong><small>Flagged Notes</small></div></div>
        <div class="two-col-60">
          <div><div class="card"><div class="card-header"><div class="card-title">Today Jobs</div></div><div class="card-body"><?php if (empty($ctx['jobs_today'])) { ?><div class="ado-empty">No jobs today.</div><?php } else { foreach ((array) $ctx['jobs_today'] as $job) { $project_url = ado_tp_view_url('project', ['project_id' => (int) ($job['order_id'] ?? 0)]); ?><a class="list-item" href="<?php echo esc_url($project_url); ?>"><strong><?php echo esc_html((string) $job['name']); ?></strong><small><?php echo esc_html((string) $job['location']); ?> &middot; <?php echo esc_html((string) ((int) $job['door_count'])); ?> doors</small></a><?php } } ?></div></div><div class="card" style="margin-top:12px;"><div class="card-header"><div class="card-title">Door Progress</div></div><div class="card-body"><?php if (empty($ctx['active_doors'])) { ?><div class="ado-empty">No active door scope.</div><?php } else { foreach ((array) $ctx['active_doors'] as $door) { ?><div class="list-item"><strong><?php echo esc_html((string) $door['door_id']); ?></strong><small><?php echo esc_html((string) $door['model']); ?></small></div><?php } } ?></div></div><div class="card" style="margin-top:12px;"><div class="card-header"><div class="card-title">Add Field Note</div></div><div class="card-body"><?php echo ado_tp_note_form((array) $ctx['orders'], false); ?></div></div></div>
          <div><div class="card"><div class="card-header"><div class="card-title">Flagged Notes</div></div><div class="card-body"><?php if (empty($ctx['flagged'])) { ?><div class="ado-empty">No flagged notes.</div><?php } else { foreach (array_slice((array) $ctx['flagged'], 0, 8) as $n) { ?><div class="note-card <?php echo esc_attr((string) $n['priority']); ?>"><div class="nc-top"><span class="nc-flag <?php echo esc_attr((string) $n['priority']); ?>"><?php echo esc_html(strtoupper((string) $n['priority'])); ?></span><span class="nc-project"><?php echo esc_html((string) $n['project']); ?> #<?php echo esc_html((string) ((int) $n['order_id'])); ?></span></div><div class="nc-body"><?php echo esc_html((string) $n['text']); ?></div></div><?php } } ?></div></div><div class="card" style="margin-top:12px;"><div class="card-header"><div class="card-title">Recent Photos</div></div><div class="card-body"><?php if (empty($ctx['photos'])) { ?><div class="ado-empty">No photos uploaded.</div><?php } else { ?><div class="photo-grid"><?php foreach (array_slice((array) $ctx['photos'], 0, 8) as $p) { ?><a class="photo-card" href="<?php echo esc_url((string) $p['url']); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url((string) $p['url']); ?>" alt=""><small><?php echo esc_html((string) $p['created_at']); ?></small></a><?php } ?></div><?php } ?></div></div></div>
        </div>
        <?php
    }
    return (string) ob_get_clean();
}

add_shortcode('ado_technician_portal_app', static function (): string {
    if (!is_user_logged_in() || !ado_is_technician()) {
        return '<p>Technician access only.</p>';
    }

    $view = sanitize_key((string) ($_GET['view'] ?? 'dashboard'));
    $views = ['dashboard' => 'Dashboard', 'schedule' => 'My Schedule', 'project' => 'Project Detail', 'notes' => 'Job Notes', 'files' => 'Project Files', 'photos' => 'Photo Uploads', 'profile' => 'My Profile', 'timesheets' => 'Timesheets'];
    if (!isset($views[$view])) {
        $view = 'dashboard';
    }

    $uid = (int) get_current_user_id();
    $ctx = ado_tp_context($uid);
    $selected_project = (int) ($_GET['project_id'] ?? 0);
    $user = wp_get_current_user();
    $name = trim((string) $user->display_name);
    if ($name === '') {
        $name = 'Technician';
    }
    $initials = ado_tp_initials($name);
    $nonce = wp_create_nonce('ado_tech_nonce');
    $clock_seed = max(0, (int) round(((float) ($ctx['today_hours'] ?? 0.0)) * 3600));

    ob_start();
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap');
    .ado-tech{--bg:#0f1117;--surface:#1a1d27;--border:rgba(255,255,255,.08);--accent:#f97316;--accent-soft:rgba(249,115,22,.12);--blue:#3b82f6;--blue-soft:rgba(59,130,246,.12);--green:#22c55e;--warn:#eab308;--danger:#ef4444;--danger-soft:rgba(239,68,68,.12);--text:#f1f5f9;--muted:#94a3b8;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh}.ado-tech *{box-sizing:border-box}.ado-tech .sidebar{width:240px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh}.ado-tech .logo{padding:22px 18px;border-bottom:1px solid var(--border);font-family:'Syne',sans-serif;font-weight:700}.ado-tech .tech-card{margin:14px;background:var(--accent-soft);border:1px solid rgba(249,115,22,.25);border-radius:8px;padding:10px;display:flex;gap:10px;align-items:center}.ado-tech .avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#fb923c);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700}.ado-tech .status{font-size:11px;color:var(--green)}.ado-tech nav{padding:8px 10px;overflow:auto;flex:1}.ado-tech .label{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#4b5563;padding:10px 10px 6px}.ado-tech .nav-item{display:flex;align-items:center;justify-content:space-between;padding:9px 10px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:13px}.ado-tech .nav-item.active{background:var(--accent-soft);color:var(--accent)}.ado-tech .nav-item:hover{background:rgba(255,255,255,.05);color:var(--text)}.ado-tech .badge{font-size:10px;padding:2px 6px;border-radius:999px;background:var(--accent);color:#fff}.ado-tech .main{flex:1;display:flex;flex-direction:column}.ado-tech .top{height:60px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px}.ado-tech .top h1{margin:0;font-family:'Syne',sans-serif;font-size:16px}.ado-tech .clock{font-family:'Syne',sans-serif;color:var(--green);font-size:14px}.ado-tech .content{padding:22px}.ado-tech .page-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800}.ado-tech .page-sub{font-size:13px;color:#64748b;margin-top:4px}.ado-tech .page-header{margin-bottom:14px}.ado-tech .ts-hero{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.ado-tech .stat{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px}.ado-tech .stat strong{display:block;font-family:'Syne',sans-serif;font-size:22px}.ado-tech .stat small{display:block;color:#94a3b8;margin-top:3px}.ado-tech .two-col-60{display:grid;grid-template-columns:1fr 340px;gap:14px}.ado-tech .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden}.ado-tech .card-header{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}.ado-tech .card-title{font-family:'Syne',sans-serif;font-size:14px}.ado-tech .card-body{padding:14px}.ado-tech .ado-empty{padding:10px;border:1px dashed var(--border);border-radius:8px;color:#94a3b8}.ado-tech .list{display:flex;flex-direction:column;gap:8px}.ado-tech .list-item{display:block;padding:10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.03);text-decoration:none;color:var(--text)}.ado-tech .list-item small{display:block;color:#94a3b8;margin-top:3px}.ado-tech .sub-item{padding:6px 10px;margin-left:8px;color:#94a3b8;font-size:12px}.ado-tech .tag{font-size:10px;padding:2px 8px;border-radius:999px;background:var(--accent-soft);color:var(--accent)}.ado-tech .tag-blue{background:var(--blue-soft);color:var(--blue)}.ado-tech .tag-orange{background:var(--accent-soft);color:var(--accent)}.ado-tech .job-block{padding:10px;border-radius:9px;background:rgba(255,255,255,.03);border-left:3px solid var(--accent);margin-bottom:8px}.ado-tech .job-block.blue{border-color:var(--blue)}.ado-tech .job-block.green{border-color:var(--green)}.ado-tech .job-block.purple{border-color:#a78bfa}.ado-tech .jb-name{font-weight:600}.ado-tech .jb-meta{font-size:12px;color:#94a3b8;margin-top:3px}.ado-tech .jb-tags{margin-top:6px;display:flex;gap:6px;align-items:center}.ado-tech .btn{display:inline-flex;align-items:center;justify-content:center;padding:7px 12px;border-radius:8px;border:1px solid var(--border);font-size:12px;text-decoration:none;color:#cbd5e1;background:transparent;cursor:pointer}.ado-tech .btn:hover{background:rgba(255,255,255,.08)}.ado-tech .btn-primary{background:var(--accent);border-color:transparent;color:#fff}.ado-tech .notes-grid{display:grid;grid-template-columns:1fr 320px;gap:14px}.ado-tech .notes-filter-bar{display:flex;gap:6px;flex-wrap:wrap}.ado-tech .filter-btn{display:inline-flex;padding:6px 12px;border-radius:999px;border:1px solid var(--border);font-size:12px;text-decoration:none;color:#94a3b8}.ado-tech .filter-btn.active{background:var(--accent-soft);color:var(--accent)}.ado-tech .note-card{padding:10px;border-radius:9px;border:1px solid var(--border);background:rgba(255,255,255,.02);margin-bottom:8px}.ado-tech .note-card.critical{border-left:3px solid var(--danger);background:rgba(239,68,68,.08)}.ado-tech .note-card.high{border-left:3px solid var(--warn)}.ado-tech .note-card.info{border-left:3px solid var(--blue)}.ado-tech .nc-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.ado-tech .nc-flag{font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px}.ado-tech .nc-flag.critical{background:var(--danger-soft);color:var(--danger)}.ado-tech .nc-flag.high{background:rgba(234,179,8,.15);color:var(--warn)}.ado-tech .nc-flag.info{background:var(--blue-soft);color:var(--blue)}.ado-tech .nc-project,.ado-tech .nc-time,.ado-tech .nc-door{font-size:11px;color:#94a3b8}.ado-tech .nc-body{margin-top:6px;font-size:13px}.ado-tech .week-nav{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-bottom:12px}.ado-tech .week-day{border:1px solid var(--border);border-radius:8px;text-align:center;padding:8px;text-decoration:none;color:#94a3b8}.ado-tech .week-day.today{background:var(--accent-soft);color:var(--accent)}.ado-tech .week-day.has-jobs{border-color:rgba(249,115,22,.5)}.ado-tech .wday-label{font-size:10px;text-transform:uppercase}.ado-tech .wday-num{font-family:'Syne',sans-serif;font-size:16px}.ado-tech .photos-layout{display:grid;grid-template-columns:1fr 300px;gap:14px}.ado-tech .photo-project-selector{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.ado-tech .photo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.ado-tech .photo-card{display:block;border:1px solid var(--border);border-radius:8px;overflow:hidden;text-decoration:none}.ado-tech .photo-card img{width:100%;height:100px;object-fit:cover;display:block}.ado-tech .photo-card small{display:block;padding:6px;color:#94a3b8}.ado-tech .compose-row{display:flex;gap:8px;flex-wrap:wrap}.ado-tech .compose-select,.ado-tech .compose-textarea{background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;color:#f1f5f9;padding:8px 10px;font-size:13px}.ado-tech .compose-select{flex:1;min-width:120px}.ado-tech .compose-textarea{width:100%;height:88px;resize:vertical;margin-top:8px}.ado-tech .ado-form-flash{display:none;margin-top:8px;padding:8px;border-radius:8px;font-size:12px}.ado-tech .ado-form-flash.ok{display:block;background:rgba(34,197,94,.15);color:#86efac}.ado-tech .ado-form-flash.err{display:block;background:rgba(239,68,68,.15);color:#fecaca}.ado-tech .profile-hero{display:flex;gap:12px;align-items:center;background:linear-gradient(135deg,rgba(249,115,22,.15),rgba(249,115,22,.04));border:1px solid rgba(249,115,22,.3);border-radius:12px;padding:14px;margin-bottom:12px}.ado-tech .profile-avatar-lg{width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#fb923c);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:20px}.ado-tech .profile-name{font-family:'Syne',sans-serif;font-size:20px}.ado-tech .profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ado-tech .kv{padding:7px 0;border-bottom:1px solid var(--border);font-size:13px}@media (max-width:1100px){.ado-tech .two-col-60,.ado-tech .notes-grid,.ado-tech .photos-layout,.ado-tech .profile-grid{grid-template-columns:1fr}.ado-tech .ts-hero{grid-template-columns:1fr 1fr}}@media (max-width:840px){.ado-tech{flex-direction:column}.ado-tech .sidebar{width:100%;height:auto;position:relative}.ado-tech .content{padding:14px}.ado-tech .top{padding:0 12px}.ado-tech .week-nav{grid-template-columns:repeat(4,minmax(0,1fr))}.ado-tech .photo-grid{grid-template-columns:1fr 1fr}}
    .ado-tech .ado-project-workspace{position:relative}
.ado-tech .ado-door-trigger{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;text-decoration:none;color:var(--text);cursor:pointer}.ado-tech .ado-door-trigger strong{display:block}.ado-tech .ado-door-trigger small{display:block;color:#94a3b8;margin-top:3px}.ado-tech .ado-door-trigger.active{border-color:rgba(249,115,22,.55);background:rgba(249,115,22,.12)}.ado-tech .ado-door-chip{font-size:10px;padding:2px 8px;border-radius:999px;background:var(--blue-soft);color:var(--blue);white-space:nowrap;flex-shrink:0}
.ado-tech .ado-door-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.64);opacity:0;pointer-events:none;transition:opacity .16s ease;z-index:9990}.ado-tech .ado-door-backdrop.is-open{opacity:1;pointer-events:auto}
.ado-tech .ado-door-drawer{position:fixed;top:0;right:0;bottom:0;width:min(92vw,560px);background:var(--surface);border-left:1px solid var(--border);box-shadow:-18px 0 42px rgba(2,6,23,.28);z-index:9991;transform:translateX(100%);transition:transform .2s ease;display:flex;flex-direction:column}.ado-tech .ado-door-drawer.is-open{transform:translateX(0)}body.admin-bar .ado-tech .ado-door-drawer{top:32px}@media (max-width:782px){body.admin-bar .ado-tech .ado-door-drawer{top:46px}.ado-tech .ado-door-drawer{width:100vw}}
.ado-tech .ado-door-drawer-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ado-tech .ado-door-drawer-kicker{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#64748b}.ado-tech .ado-door-drawer-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-top:3px}.ado-tech .ado-door-drawer-sub{font-size:12px;color:#94a3b8;margin-top:4px;line-height:1.4}.ado-tech .ado-door-drawer-body{padding:16px 18px;overflow:auto;flex:1;display:flex;flex-direction:column;gap:14px}
.ado-tech .ado-door-card{border:1px solid var(--border);border-radius:10px;padding:12px;background:rgba(255,255,255,.02)}.ado-tech .ado-door-section-title{font-family:'Syne',sans-serif;font-size:13px;margin:0 0 10px}.ado-tech .ado-door-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ado-tech .ado-door-kv{padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.03);font-size:12px}.ado-tech .ado-door-kv strong{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:3px}.ado-tech .ado-door-kv small{display:block;color:var(--text)}
.ado-tech .ado-door-accordion{display:block}.ado-tech .ado-door-accordion-summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer}.ado-tech .ado-door-accordion-summary::-webkit-details-marker{display:none}.ado-tech .ado-door-accordion-summary .ado-door-section-title{margin:0}.ado-tech .ado-door-accordion-icon{color:#94a3b8;font-size:12px;line-height:1;transition:transform .16s ease}.ado-tech .ado-door-accordion[open] .ado-door-accordion-icon{transform:rotate(180deg)}.ado-tech .ado-door-accordion-body{margin-top:10px}
.ado-tech .ado-door-unconfirm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ado-tech .ado-door-unconfirm-btn{width:100%;text-align:left;display:flex;flex-direction:column;gap:4px;padding:12px;border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,.03);color:var(--text);cursor:pointer;transition:border-color .15s ease,background .15s ease,transform .15s ease}.ado-tech .ado-door-unconfirm-btn:hover{border-color:rgba(249,115,22,.45);background:rgba(249,115,22,.08)}.ado-tech .ado-door-unconfirm-btn.is-active{border-color:rgba(249,115,22,.65);background:rgba(249,115,22,.16)}.ado-tech .ado-door-unconfirm-btn strong{font-size:12px;letter-spacing:.02em}.ado-tech .ado-door-unconfirm-btn small{color:#94a3b8;font-size:11px}
.ado-tech .ado-door-hardware-list{display:flex;flex-direction:column;gap:8px}.ado-tech .ado-door-hardware-item{padding:10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.03)}.ado-tech .ado-door-hardware-item strong{display:flex;align-items:center;gap:8px;font-size:13px}.ado-tech .ado-door-hardware-item small{display:block;color:#94a3b8;margin-top:4px}.ado-tech .ado-door-hardware-qty{font-size:10px;padding:2px 6px;border-radius:999px;background:var(--accent-soft);color:var(--accent)}
.ado-tech .ado-door-overview-blocks{display:grid;grid-template-columns:1fr;gap:10px}.ado-tech .ado-door-overview-block{padding:10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.03)}.ado-tech .ado-door-overview-block strong{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:6px}.ado-tech .ado-door-overview-link{display:block;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.03);text-decoration:none;color:var(--text)}.ado-tech .ado-door-overview-link span{display:block;font-size:13px;font-weight:600}.ado-tech .ado-door-overview-link small{display:block;color:#94a3b8;margin-top:3px}.ado-tech .ado-door-overview-fallback{margin:0}
.ado-tech .ado-door-document-list,.ado-tech .ado-door-comment-list,.ado-tech .ado-door-hardware-groups,.ado-tech .ado-door-hardware-models,.ado-tech .ado-door-confirmation-list{display:flex;flex-direction:column;gap:8px}.ado-tech .ado-door-document-list .ado-door-overview-link,.ado-tech .ado-door-comment-item,.ado-tech .ado-door-hardware-group,.ado-tech .ado-door-confirmation{padding:10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.02)}.ado-tech .ado-door-comment-item strong,.ado-tech .ado-door-hardware-group-title{display:block;font-size:13px;font-weight:600}.ado-tech .ado-door-comment-item small,.ado-tech .ado-door-hardware-group-title,.ado-tech .ado-door-form-hint{color:#94a3b8}.ado-tech .ado-door-confirmation-head,.ado-tech .ado-door-hardware-model-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.ado-tech .ado-door-confirmation-options{display:flex;gap:10px;flex-wrap:wrap}.ado-tech .ado-door-confirmation-options label,.ado-tech .ado-door-complete{display:flex;align-items:center;gap:8px;font-size:12px}.ado-tech .ado-door-field{display:block;margin-top:10px}.ado-tech .ado-door-field span{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:5px}.ado-tech .ado-door-field textarea,.ado-tech .ado-door-field input[type="text"],.ado-tech .ado-door-field input[type="file"]{width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px 12px;font-size:13px}.ado-tech .ado-door-field textarea{min-height:92px;resize:vertical}.ado-tech .ado-door-form-hint{margin-top:8px;font-size:12px;line-height:1.4}.ado-tech .ado-door-hardware-model{padding:10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.03)}.ado-tech .ado-door-hardware-model-head strong{font-size:13px}.ado-tech .ado-door-hardware-toggle{white-space:nowrap}.ado-tech .ado-door-hardware-panel{margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}.ado-tech .ado-door-existing-media{display:flex;flex-direction:column;gap:8px;margin-top:10px}.ado-tech .ado-door-existing-media a{display:block;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.03);text-decoration:none;color:var(--text)}.ado-tech .ado-door-existing-media strong{display:block;font-size:13px}.ado-tech .ado-door-existing-media small{display:block;color:#94a3b8;margin-top:3px}.ado-tech .ado-door-note{width:100%;min-height:110px;resize:vertical;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px 12px;font-size:13px}.ado-tech .ado-door-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.ado-tech .ado-door-flash{display:none;margin-top:4px;padding:8px 10px;border-radius:8px;font-size:12px}.ado-tech .ado-door-flash.ok{display:block;background:rgba(34,197,94,.15);color:#86efac}.ado-tech .ado-door-flash.err{display:block;background:rgba(239,68,68,.15);color:#fecaca}.ado-tech .ado-door-empty{padding:10px;border:1px dashed var(--border);border-radius:8px;color:#94a3b8}
.ado-tech .ado-door-hardware-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.ado-tech .ado-door-hardware-installed-label{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text)}.ado-tech .ado-door-hardware-installed-label input[type="checkbox"]{margin:0}
body.ado-door-drawer-open{overflow:hidden}
@media (max-width:840px){.ado-tech .ado-door-meta-grid,.ado-tech .ado-door-overview-blocks,.ado-tech .ado-door-unconfirm-grid{grid-template-columns:1fr}.ado-tech .ado-door-drawer-head{padding:14px}.ado-tech .ado-door-drawer-body{padding:14px}.ado-tech .ado-door-card{padding:10px}}</style>

    <div class="ado-tech">
      <aside class="sidebar">
        <div class="logo">AutoDoor <small style="display:block;color:#f97316;font-size:10px;letter-spacing:.08em;text-transform:uppercase;">Field Portal</small></div>
        <div class="tech-card"><div class="avatar"><?php echo esc_html($initials); ?></div><div><div><?php echo esc_html($name); ?></div><div class="status">On shift &middot; <?php echo esc_html(ado_tp_shift_label((float) ($ctx['today_hours'] ?? 0))); ?></div></div></div>
        <nav>
          <div class="label">Today</div>
          <a class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('dashboard'); ?>">Dashboard</a>
          <a class="nav-item <?php echo $view === 'schedule' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('schedule'); ?>">My Schedule <span class="badge"><?php echo esc_html((string) count((array) $ctx['jobs_today'])); ?></span></a>
          <a class="nav-item <?php echo $view === 'notes' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('notes'); ?>">Job Notes <?php if (!empty($ctx['flagged'])) { ?><span class="badge"><?php echo esc_html((string) count((array) $ctx['flagged'])); ?></span><?php } ?></a>
          <div class="label">Projects</div>
          <?php if (!empty($ctx['orders'])) { foreach ((array) $ctx['orders'] as $order) { if (!($order instanceof WC_Order)) { continue; } $order_id = (int) $order->get_id(); $door_count = count((array) ado_tp_door_rows($order)); $project_active = $selected_project === $order_id; ?>
          <a class="list-item" href="<?php echo esc_url(ado_tp_view_url('project', ['project_id' => $order_id])); ?>" style="margin:0 0 8px 0;<?php echo $project_active ? 'border-color:rgba(249,115,22,.55);background:rgba(249,115,22,.12);' : ''; ?>">
            <strong><?php echo esc_html(ado_tp_order_name($order)); ?></strong>
            <small><?php echo esc_html(ucfirst((string) $order->get_status())); ?> &middot; <?php echo esc_html((string) $door_count); ?> doors</small>
          </a>
          <?php } } else { ?><div class="ado-empty" style="margin:0 0 10px 0;">No assigned projects.</div><?php } ?>
          <div class="label" style="margin-top:10px;">Tools</div>
          <a class="nav-item <?php echo $view === 'files' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('files'); ?>">Project Files</a>
          <a class="nav-item <?php echo $view === 'photos' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('photos'); ?>">Photo Uploads</a>
          <div class="label">Account</div>
          <a class="nav-item <?php echo $view === 'profile' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('profile'); ?>">My Profile</a>
          <div class="label">Time</div>
          <a class="nav-item <?php echo $view === 'timesheets' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('timesheets'); ?>">Timesheets</a>
        </nav>
        <div style="padding:10px;border-top:1px solid var(--border);"><a class="nav-item" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Sign Out</a></div>
      </aside>
      <section class="main">
        <header class="top"><h1><?php echo esc_html((string) $views[$view]); ?></h1><div class="clock ado-live-clock"><?php echo esc_html(ado_tp_hms((float) ($ctx['today_hours'] ?? 0))); ?></div></header>
        <div class="content"><?php echo ado_tp_render_view($view, $ctx); ?></div>
      </section>
    </div>

    <script>
    (function(){
      var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      var nonce = <?php echo wp_json_encode($nonce); ?>;
      async function submitForm(form){
        var isPhoto = form.getAttribute('data-photo-mode') === '1';
        var fd = new FormData(form);
        if (isPhoto) {
          var noteNode = form.querySelector('textarea[name="note"]');
          var doorNode = form.querySelector('input[name="door_label"]');
          var note = noteNode ? noteNode.value.trim() : '';
          var door = doorNode ? doorNode.value.trim() : '';
          if (door) note = '[Door:' + door + '] ' + note;
          if (!note) note = 'Photo upload';
          fd.set('note', note);
        }
        fd.append('action', 'ado_add_tech_log');
        fd.append('nonce', nonce);
        var flash = form.querySelector('.ado-form-flash');
        try {
          var res = await fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' });
          var json = await res.json();
          if (!json || !json.success) {
            var msg = (json && json.data && json.data.message) ? json.data.message : 'Failed to save.';
            if (flash) { flash.className = 'ado-form-flash err'; flash.textContent = msg; }
            return;
          }
          if (flash) { flash.className = 'ado-form-flash ok'; flash.textContent = isPhoto ? 'Photo uploaded.' : 'Note saved.'; }
          setTimeout(function(){ window.location.reload(); }, 350);
        } catch(e) {
          if (flash) { flash.className = 'ado-form-flash err'; flash.textContent = 'Failed to save.'; }
        }
      }
      var projectWorkspace = document.querySelector('.ado-project-workspace');
      var projectDrawer = projectWorkspace ? projectWorkspace.querySelector('.ado-door-drawer') : null;
      var projectBackdrop = projectWorkspace ? projectWorkspace.querySelector('.ado-door-backdrop') : null;
      function setDoorUrl(doorId){
        var url = new URL(window.location.href);
        if (doorId) {
          url.searchParams.set('door_id', doorId);
        } else {
          url.searchParams.delete('door_id');
        }
        window.history.replaceState({}, '', url.toString());
      }
      function findDoorTrigger(doorId){
        if (!projectWorkspace) {
          return null;
        }
        var triggers = projectWorkspace.querySelectorAll('.ado-door-trigger');
        for (var i = 0; i < triggers.length; i++) {
          if (String(triggers[i].dataset.doorId || '') === String(doorId || '')) {
            return triggers[i];
          }
        }
        return null;
      }
      function showDoorDrawer(){
        if (!projectDrawer || !projectBackdrop) {
          return;
        }
        projectDrawer.hidden = false;
        projectBackdrop.hidden = false;
        window.requestAnimationFrame(function(){
          projectDrawer.classList.add('is-open');
          projectBackdrop.classList.add('is-open');
          document.body.classList.add('ado-door-drawer-open');
        });
      }
      function hideDoorDrawer(){
        if (!projectDrawer || !projectBackdrop) {
          return;
        }
        projectDrawer.classList.remove('is-open');
        projectBackdrop.classList.remove('is-open');
        document.body.classList.remove('ado-door-drawer-open');
        window.setTimeout(function(){
          if (!projectDrawer.classList.contains('is-open')) {
            projectDrawer.hidden = true;
          }
          if (!projectBackdrop.classList.contains('is-open')) {
            projectBackdrop.hidden = true;
          }
        }, 180);
      }
      function loadDoorDrawer(trigger){
        if (!projectWorkspace || !projectDrawer || !trigger) {
          return false;
        }
        var templateId = trigger.getAttribute('data-door-template') || '';
        var template = templateId ? document.getElementById(templateId) : null;
        var drawerBody = projectWorkspace.querySelector('.ado-door-drawer-body');
        var drawerTitle = projectWorkspace.querySelector('.ado-door-drawer-title');
        var drawerSub = projectWorkspace.querySelector('.ado-door-drawer-sub');
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
        drawerSub.textContent = trigger.getAttribute('data-door-meta') || 'Select a door to review hardware, notes, and install status.';
        syncDoorFormState(drawerBody);
        return true;
      }
      function setDoorFormMessage(form, message, isOk){
        var flash = form.querySelector('.ado-door-flash');
        if (!flash) {
          return;
        }
        flash.className = 'ado-door-flash ' + (isOk ? 'ok' : 'err');
        flash.textContent = message;
      }
      function syncDoorConfirmation(row){
        if (!row) {
          return;
        }
        var checked = row.querySelector('input[type="radio"]:checked');
        var state = checked ? String(checked.value || 'yes') : 'yes';
        var commentWrap = row.querySelector('.ado-door-confirmation-comment-wrap');
        var comment = row.querySelector('.ado-door-confirmation-comment');
        var panelId = '';
        if (checked) {
          panelId = String(checked.getAttribute('data-confirmation-target') || '');
        }
        if (panelId) {
          var linkedPanel = document.getElementById(panelId);
          if (linkedPanel) {
            linkedPanel.hidden = state !== 'no';
          }
        }
        if (commentWrap) {
          commentWrap.hidden = state !== 'no';
        }
        if (comment) {
          comment.required = state === 'no';
          if (state !== 'no') {
            comment.setCustomValidity('');
          }
        }
      }
      function syncDoorFormState(root){
        if (!root) {
          return;
        }
        root.querySelectorAll('.ado-door-confirmation').forEach(function(row){
          syncDoorConfirmation(row);
        });
        root.querySelectorAll('.ado-door-unconfirm-btn').forEach(function(button){
          syncUnconfirmButton(button);
        });
        root.querySelectorAll('.ado-door-hardware-toggle').forEach(function(button){
          var targetId = button.getAttribute('data-target') || '';
          var panel = targetId ? document.getElementById(targetId) : null;
          if (panel) {
            button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
          }
        });
      }
      function syncUnconfirmButton(button){
        if (!button) {
          return;
        }
        var key = button.getAttribute('data-unconfirm-key') || '';
        var form = button.closest('form');
        if (!form || !key) {
          return;
        }
        var stateInput = form.querySelector('input[data-unconfirm-state=\"' + key + '\"]');
        var commentInput = form.querySelector('input[data-unconfirm-comment=\"' + key + '\"]');
        if (!stateInput) {
          return;
        }
        var isUnconfirmed = String(stateInput.value || 'yes') === 'no';
        button.classList.toggle('is-active', isUnconfirmed);
        button.setAttribute('aria-pressed', isUnconfirmed ? 'true' : 'false');
        var statusNode = button.querySelector('[data-unconfirm-status]');
        if (statusNode) {
          statusNode.textContent = isUnconfirmed ? 'Currently unconfirmed' : 'Currently confirmed';
        }
        if (commentInput) {
          if (isUnconfirmed && !String(commentInput.value || '').trim()) {
            commentInput.value = 'Unconfirmed by technician via portal control.';
          }
          if (!isUnconfirmed) {
            commentInput.value = '';
          }
        }
      }
      function toggleUnconfirmButton(button){
        if (!button) {
          return;
        }
        var key = button.getAttribute('data-unconfirm-key') || '';
        var form = button.closest('form');
        if (!form || !key) {
          return;
        }
        var stateInput = form.querySelector('input[data-unconfirm-state=\"' + key + '\"]');
        if (!stateInput) {
          return;
        }
        stateInput.value = String(stateInput.value || 'yes') === 'no' ? 'yes' : 'no';
        syncUnconfirmButton(button);
      }
      function toggleHardwarePanel(button){
        if (!button) {
          return;
        }
        var targetId = button.getAttribute('data-target') || '';
        var panel = targetId ? document.getElementById(targetId) : null;
        if (!panel) {
          return;
        }
        panel.hidden = !panel.hidden;
        button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
      }
      function validateDoorForm(form){
        form.querySelectorAll('.ado-door-unconfirm-btn').forEach(function(button){
          syncUnconfirmButton(button);
        });
        var confirmations = form.querySelectorAll('.ado-door-confirmation');
        for (var i = 0; i < confirmations.length; i++) {
          var row = confirmations[i];
          syncDoorConfirmation(row);
          var checked = row.querySelector('input[type="radio"]:checked');
          if (!checked) {
            setDoorFormMessage(form, 'Please choose Yes or No for each confirmation.', false);
            return false;
          }
          if (String(checked.value || 'yes') === 'no') {
            var comment = row.querySelector('.ado-door-confirmation-comment');
            var labelNode = row.querySelector('strong');
            var label = labelNode ? labelNode.textContent.trim() : 'This confirmation';
            if (!comment || !comment.value.trim()) {
              setDoorFormMessage(form, label + ' requires a comment when marked No.', false);
              if (comment) {
                comment.focus();
              }
              return false;
            }
          }
        }
        var complete = form.querySelector('input[name="testing[complete]"]');
        if (complete && complete.checked) {
          var hardwareChecks = form.querySelectorAll('input.ado-door-hardware-installed[type="checkbox"]');
          for (var j = 0; j < hardwareChecks.length; j++) {
            if (!hardwareChecks[j].checked) {
              setDoorFormMessage(form, 'Every hardware line must be marked Installed before confirming hardware installation complete.', false);
              hardwareChecks[j].focus();
              return false;
            }
          }
          var existingCount = parseInt(form.getAttribute('data-final-video-count') || '0', 10) || 0;
          var videoInput = form.querySelector('input[name="testing_final_video"]');
          var hasNewVideo = !!(videoInput && videoInput.files && videoInput.files.length > 0);
          if (existingCount <= 0 && !hasNewVideo) {
            setDoorFormMessage(form, 'A final test video is required before confirming hardware installation complete.', false);
            if (videoInput) {
              videoInput.focus();
            }
            return false;
          }
        }
        return true;
      }
      function openDoorDrawer(trigger){
        if (!trigger || !loadDoorDrawer(trigger)) {
          return;
        }
        if (projectWorkspace) {
          projectWorkspace.querySelectorAll('.ado-door-trigger.active').forEach(function(node){ node.classList.remove('active'); });
        }
        trigger.classList.add('active');
        setDoorUrl(trigger.dataset.doorId || '');
        showDoorDrawer();
      }
      function closeDoorDrawer(){
        if (!projectWorkspace) {
          return;
        }
        projectWorkspace.querySelectorAll('.ado-door-trigger.active').forEach(function(node){ node.classList.remove('active'); });
        setDoorUrl('');
        hideDoorDrawer();
      }
      async function submitDoorForm(form){
        if (!validateDoorForm(form)) {
          return;
        }
        var fd = new FormData(form);
        fd.append('action', 'ado_save_project_door');
        fd.append('nonce', nonce);
        try {
          var res = await fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' });
          var json = await res.json();
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) ? json.data.message : 'Failed to save door update.');
          }
          setDoorFormMessage(form, (json.data && json.data.message) ? json.data.message : 'Door update saved.', true);
          window.setTimeout(function(){ window.location.reload(); }, 350);
        } catch (err) {
          setDoorFormMessage(form, (err && err.message) ? err.message : 'Failed to save door update.', false);
        }
      }
      if (projectWorkspace) {
        var initialDoorId = projectWorkspace.getAttribute('data-selected-door') || '';
        if (projectDrawer && projectDrawer.classList.contains('is-open')) {
          document.body.classList.add('ado-door-drawer-open');
        }
        if (initialDoorId) {
          var initialTrigger = findDoorTrigger(initialDoorId);
          if (initialTrigger) {
            initialTrigger.classList.add('active');
          }
        }
        projectWorkspace.querySelectorAll('.ado-door-trigger').forEach(function(trigger){
          trigger.addEventListener('click', function(ev){
            ev.preventDefault();
            openDoorDrawer(trigger);
          });
        });
        projectWorkspace.addEventListener('click', function(ev){
          var unconfirmButton = ev.target.closest('.ado-door-unconfirm-btn');
          if (unconfirmButton) {
            ev.preventDefault();
            toggleUnconfirmButton(unconfirmButton);
            return;
          }
          var hardwareToggle = ev.target.closest('.ado-door-hardware-toggle');
          if (hardwareToggle) {
            ev.preventDefault();
            toggleHardwarePanel(hardwareToggle);
          }
        });
        projectWorkspace.addEventListener('change', function(ev){
          var input = ev.target;
          if (!input || !(input instanceof HTMLInputElement)) {
            return;
          }
          if (input.matches('.ado-door-confirmation input[type="radio"]')) {
            syncDoorConfirmation(input.closest('.ado-door-confirmation'));
          }
        });
        projectWorkspace.addEventListener('submit', function(ev){
          var form = ev.target.closest('.ado-door-update-form');
          if (!form) {
            return;
          }
          ev.preventDefault();
          submitDoorForm(form);
        });
        if (projectBackdrop) {
          projectBackdrop.addEventListener('click', function(){
            closeDoorDrawer();
          });
        }
        if (projectDrawer) {
          var closeButton = projectDrawer.querySelector('.ado-door-close');
          if (closeButton) {
            closeButton.addEventListener('click', function(){
              closeDoorDrawer();
            });
          }
        }
        document.addEventListener('keydown', function(ev){
          if (ev.key !== 'Escape') {
            return;
          }
          if (projectDrawer && projectDrawer.classList.contains('is-open')) {
            closeDoorDrawer();
          }
        });
      }
      document.querySelectorAll('.ado-tech-log-form').forEach(function(form){
        form.addEventListener('submit', function(ev){ ev.preventDefault(); submitForm(form); });
      });
      var seconds = <?php echo (int) $clock_seed; ?>;
      function pad(v){ return v < 10 ? '0' + v : String(v); }
      setInterval(function(){
        seconds += 1;
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        var text = pad(h) + ':' + pad(m) + ':' + pad(s);
        document.querySelectorAll('.ado-live-clock').forEach(function(n){ n.textContent = text; });
      }, 1000);
    })();
    </script>
    <?php
    return (string) ob_get_clean();
});

add_action('wp_ajax_ado_save_project_door', static function (): void {
    if (!is_user_logged_in() || !ado_is_technician()) {
        wp_send_json_error(['message' => 'Technician access only.'], 403);
    }
    check_ajax_referer('ado_tech_nonce', 'nonce');

    $project_id = (int) ($_POST['project_id'] ?? 0);
    $door_id = sanitize_text_field((string) ($_POST['door_id'] ?? ''));
    if ($project_id <= 0 || $door_id === '') {
        wp_send_json_error(['message' => 'Project and door are required.'], 400);
    }

    $order = wc_get_order($project_id);
    if (!($order instanceof WC_Order)) {
        wp_send_json_error(['message' => 'Project not found.'], 404);
    }

    $user_id = (int) get_current_user_id();
    $assigned_ids = ado_tp_parse_tech_ids((string) $order->get_meta('_ado_technician_ids'));
    if (!in_array($user_id, $assigned_ids, true)) {
        wp_send_json_error(['message' => 'Project not assigned to your account.'], 403);
    }

    $door = null;
    foreach (ado_tp_door_rows($order) as $row) {
        if ((string) ($row['door_id'] ?? '') === $door_id) {
            $door = $row;
            break;
        }
    }
    if (!is_array($door)) {
        wp_send_json_error(['message' => 'Door not found on this project.'], 404);
    }

    $result = ado_tp_process_project_door_save($order, $door_id, (array) $_POST, (array) $_FILES);
    if (empty($result['ok'])) {
        $status = (int) ($result['code'] ?? 400);
        wp_send_json_error([
            'message' => (string) ($result['message'] ?? 'Failed to save door update.'),
            'code' => $status,
        ], $status);
    }

    wp_send_json_success($result, (int) ($result['code'] ?? 200));
});
