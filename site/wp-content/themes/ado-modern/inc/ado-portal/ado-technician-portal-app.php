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
    if ($scope_path === '' || !file_exists($scope_path)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($scope_path), true);
    return is_array($json) ? $json : [];
}

function ado_tp_door_rows(WC_Order $order): array
{
    $payload = ado_tp_scope_payload($order);
    $rows = [];
    foreach ((array) ($payload['result']['doors'] ?? []) as $door) {
        if (!is_array($door)) {
            continue;
        }
        $door_id = trim((string) ($door['door_id'] ?? ''));
        if ($door_id === '') {
            continue;
        }
        $model = '';
        foreach ((array) ($door['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $token = trim((string) ($item['catalog'] ?? ''));
            if ($token !== '') {
                $model = $token;
                break;
            }
        }
        $rows[] = ['door_id' => $door_id, 'model' => $model !== '' ? $model : 'Model pending'];
    }
    return $rows;
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

function ado_tp_render_view(string $view, array $ctx): string
{
    $selected_project = (int) ($_GET['project_id'] ?? 0);
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
          <div class="card"><div class="card-header"><div class="card-title"><?php echo esc_html(wp_date('l, F j', strtotime($selected_day) ?: time())); ?></div></div><div class="card-body"><?php $jobs = (array) ($ctx['jobs_by_day'][$selected_day] ?? []); if (!$jobs) { ?><div class="ado-empty">No jobs on this day.</div><?php } else { foreach ($jobs as $idx => $job) { ?><div class="job-block <?php echo esc_attr(['blue', 'green', 'purple'][$idx % 3]); ?>"><div class="jb-name"><?php echo esc_html((string) $job['name']); ?></div><div class="jb-meta"><?php echo esc_html((string) $job['location']); ?> &middot; <?php echo esc_html((string) ((int) $job['door_count'])); ?> doors</div><div class="jb-tags"><span class="tag tag-orange"><?php echo esc_html(ucfirst((string) $job['status'])); ?></span><a class="btn btn-ghost btn-sm" href="<?php echo esc_url((string) $job['view_url']); ?>">Open</a></div></div><?php } } ?></div></div>
          <div><div class="card"><div class="card-header"><div class="card-title">Upcoming Jobs</div></div><div class="card-body"><?php if (empty($ctx['upcoming'])) { ?><div class="ado-empty">No upcoming jobs.</div><?php } else { ?><div class="list"><?php foreach (array_slice((array) $ctx['upcoming'], 0, 8) as $job) { ?><a class="list-item" href="<?php echo esc_url((string) $job['view_url']); ?>"><strong><?php echo esc_html((string) $job['name']); ?></strong><small><?php echo esc_html((string) $job['visit']); ?> &middot; <?php echo esc_html((string) $job['location']); ?></small></a><?php } ?></div><?php } ?></div></div></div>
        </div>
        <?php
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
          <div><div class="card"><div class="card-header"><div class="card-title">Today Jobs</div></div><div class="card-body"><?php if (empty($ctx['jobs_today'])) { ?><div class="ado-empty">No jobs today.</div><?php } else { foreach ((array) $ctx['jobs_today'] as $job) { ?><a class="list-item" href="<?php echo esc_url((string) $job['view_url']); ?>"><strong><?php echo esc_html((string) $job['name']); ?></strong><small><?php echo esc_html((string) $job['location']); ?> &middot; <?php echo esc_html((string) ((int) $job['door_count'])); ?> doors</small></a><?php } } ?></div></div><div class="card" style="margin-top:12px;"><div class="card-header"><div class="card-title">Door Progress</div></div><div class="card-body"><?php if (empty($ctx['active_doors'])) { ?><div class="ado-empty">No active door scope.</div><?php } else { foreach ((array) $ctx['active_doors'] as $door) { ?><div class="list-item"><strong><?php echo esc_html((string) $door['door_id']); ?></strong><small><?php echo esc_html((string) $door['model']); ?></small></div><?php } } ?></div></div><div class="card" style="margin-top:12px;"><div class="card-header"><div class="card-title">Add Field Note</div></div><div class="card-body"><?php echo ado_tp_note_form((array) $ctx['orders'], false); ?></div></div></div>
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
    $views = ['dashboard' => 'Dashboard', 'schedule' => 'My Schedule', 'notes' => 'Job Notes', 'files' => 'Project Files', 'photos' => 'Photo Uploads', 'profile' => 'My Profile', 'timesheets' => 'Timesheets'];
    if (!isset($views[$view])) {
        $view = 'dashboard';
    }

    $uid = (int) get_current_user_id();
    $ctx = ado_tp_context($uid);
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
    </style>

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
          <a class="nav-item <?php echo $view === 'files' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('files'); ?>">Project Files</a>
          <a class="nav-item <?php echo $view === 'photos' ? 'active' : ''; ?>" href="<?php echo ado_tp_view_url('photos'); ?>">Photo Uploads</a>
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
