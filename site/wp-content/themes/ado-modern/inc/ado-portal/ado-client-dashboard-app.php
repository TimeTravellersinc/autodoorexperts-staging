<?php
// Lightweight client app shell: consistent UI across dashboard views + live backend actions.
if (defined('ADO_CLIENT_DASHBOARD_APP_LOADED')) { return; }
define('ADO_CLIENT_DASHBOARD_APP_LOADED', true);

function ado_cd_view_url(string $view): string {
    return esc_url(add_query_arg(['view' => $view], home_url('/client-dashboard/')));
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
    $drafts = (array) ado_get_quote_drafts($user_id);
    if (!$drafts) {
        return '<div class="ado-empty">No saved quote drafts.</div>';
    }
    ob_start();
    echo '<table class="ado-table"><thead><tr><th>Quote</th><th>Created</th><th></th></tr></thead><tbody>';
    foreach ($drafts as $draft) {
        $id = (string) ($draft['id'] ?? '');
        $name = (string) ($draft['name'] ?? 'Quote Draft');
        $created = (string) ($draft['created_at'] ?? '');
        echo '<tr>';
        echo '<td><strong>' . esc_html($name) . '</strong></td>';
        echo '<td>' . esc_html($created) . '</td>';
        echo '<td><div class="ado-action-row"><button class="ado-btn primary ado-approve-quote" data-draft-id="' . esc_attr($id) . '">Approve</button><button class="ado-btn ado-decline-quote" data-draft-id="' . esc_attr($id) . '">Decline</button></div></td>';
        echo '</tr>';
    }
    echo '</tbody></table><div id="ado-dashboard-flash" class="ado-flash"></div>';
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
    $orders = ado_cd_client_orders($user_id);
    $rows = [];
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) { continue; }
        $visit = trim((string) $order->get_meta('_ado_next_visit_date'));
        if ($visit === '') { continue; }
        $ts = strtotime($visit);
        if ($ts === false) { continue; }
        $rows[] = [
            'name' => trim((string) $order->get_billing_company()) ?: ('Project #' . $order->get_id()),
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
    echo '<article class="ado-card" style="margin-top:16px;"><div class="ado-card-head"><span class="ado-card-title">Technician Availability Calendar</span></div><div style="padding:16px 18px;">';
    echo shortcode_exists('google-calendar-events') ? do_shortcode('[google-calendar-events id="technician-availability"]') : '<p class="ado-row-sub">Google Calendar feed not configured yet.</p>';
    echo '</div></article>';
    return (string) ob_get_clean();
}

function ado_cd_render_view_content(string $view, int $user_id): string {
    switch ($view) {
        case 'new-quote':
            return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Create Quote</span></div><div style="padding:16px 18px;">' . do_shortcode('[ado_quote_workspace]') . '</div></article>';
        case 'quotes':
            return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Quotes Awaiting Decision</span></div>' . ado_cd_render_quotes_queue($user_id) . '</article><article class="ado-card" style="margin-top:16px;"><div class="ado-card-head"><span class="ado-card-title">Current Quote Cart</span></div><div style="padding:16px 18px;">' . do_shortcode('[woocommerce_cart]') . '</div></article>';
        case 'projects':
            return '<article class="ado-card"><div class="ado-card-head"><span class="ado-card-title">Project Tracking</span></div><div style="padding:16px 18px;">' . do_shortcode('[ado_client_projects]') . '</div></article>';
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
    $counts = ado_cd_counts($uid);
    $view = sanitize_key((string) ($_GET['view'] ?? 'dashboard'));
    $view_titles = [
        'dashboard' => 'Dashboard',
        'new-quote' => 'New Quote',
        'quotes' => 'My Quotes',
        'projects' => 'My Projects',
        'schedule' => 'Schedule',
        'invoices' => 'Invoices',
    ];
    if (!isset($view_titles[$view])) {
        $view = 'dashboard';
    }
    $nonce = wp_create_nonce('ado_quote_nonce');

    ob_start();
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap');
    .ado-app{--bg:#f4f5f7;--surface:#fff;--surface-2:#f9fafb;--border:#e8eaed;--accent:#1a56db;--warn:#e3a008;--danger:#e02424;--text-primary:#111928;--text-secondary:#6b7280;--text-muted:#9ca3af;--shadow-sm:0 1px 3px rgba(0,0,0,0.06),0 1px 2px rgba(0,0,0,0.04);--radius:14px;--radius-sm:8px;font-family:'DM Sans',sans-serif;background:var(--bg);display:flex;min-height:100vh;color:var(--text-primary)}
    .ado-app *{box-sizing:border-box}.ado-side{width:256px;background:var(--text-primary);min-height:100vh;position:sticky;top:0}.ado-side-logo{padding:28px 24px 24px;border-bottom:1px solid rgba(255,255,255,.08);font-family:'Syne',sans-serif;font-weight:800;font-size:20px;color:#fff}.ado-side-logo span{color:var(--accent)}.ado-nav{padding:16px 12px;display:flex;flex-direction:column;gap:2px}.ado-nav-label{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:12px 12px 6px;margin-top:8px}.ado-nav a{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.6);font-size:14px;font-weight:500;text-decoration:none}.ado-nav a:hover{background:rgba(255,255,255,.07);color:#fff}.ado-nav a.active{background:var(--accent);color:#fff}.ado-nav-badge{font-size:10px;font-weight:700;background:var(--danger);padding:2px 6px;border-radius:999px;color:#fff}
    .ado-main{flex:1;display:flex;flex-direction:column}.ado-top{background:var(--surface);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:20}.ado-top h1{margin:0;font-family:'Syne',sans-serif;font-size:20px}.ado-top-right{display:flex;gap:10px}.ado-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 16px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;cursor:pointer}.ado-btn.primary{background:var(--accent);border-color:transparent;color:#fff}.ado-content{padding:28px}
    .ado-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm)}.ado-card-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}.ado-card-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700}.ado-card-link{font-size:12px;font-weight:600;text-decoration:none;color:var(--accent)}.ado-empty{padding:24px 18px;font-size:13px;color:var(--text-muted)}.ado-table{width:100%;border-collapse:collapse}.ado-table th{padding:10px 18px;text-align:left;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;color:var(--text-muted)}.ado-table td{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px}
    .ado-list{list-style:none;margin:0;padding:0}.ado-list li{padding:14px 18px;border-bottom:1px solid var(--border)}.ado-row-title{font-weight:700}.ado-row-sub{font-size:12px;color:var(--text-muted)}.ado-pill{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:999px}.ado-pill.high{background:#fffbeb;color:#92400e}.ado-pill.ok{background:#f0fdf4;color:#065f46}.ado-pill.critical{background:#fef2f2;color:#e02424}.ado-action-row{display:flex;gap:6px}.ado-action-row .ado-btn{padding:6px 10px;font-size:12px}
    .ado-flash{display:none;margin:10px 18px 16px;padding:10px 12px;border-radius:8px;font-size:12px}.ado-flash.ok{display:block;background:#ecfdf3;color:#027a48}.ado-flash.err{display:block;background:#fef2f2;color:#b42318}
    @media (max-width:1100px){.ado-app{flex-direction:column}.ado-side{position:relative;width:100%;min-height:auto}}
    </style>
    <div class="ado-app">
      <aside class="ado-side">
        <div class="ado-side-logo">Auto<span>Door</span></div>
        <nav class="ado-nav">
          <div class="ado-nav-label">Overview</div>
          <a class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('dashboard'); ?>"><span>Dashboard</span></a>
          <div class="ado-nav-label">Quotes &amp; Projects</div>
          <a class="<?php echo $view === 'new-quote' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('new-quote'); ?>"><span>New Quote</span></a>
          <a class="<?php echo $view === 'quotes' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('quotes'); ?>"><span>My Quotes</span><?php if ($counts['quotes_count'] > 0) { ?><span class="ado-nav-badge"><?php echo esc_html((string) $counts['quotes_count']); ?></span><?php } ?></a>
          <a class="<?php echo $view === 'projects' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('projects'); ?>"><span>My Projects</span></a>
          <div class="ado-nav-label">Scheduling</div>
          <a class="<?php echo $view === 'schedule' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('schedule'); ?>"><span>Schedule</span></a>
          <div class="ado-nav-label">Billing</div>
          <a class="<?php echo $view === 'invoices' ? 'active' : ''; ?>" href="<?php echo ado_cd_view_url('invoices'); ?>"><span>Invoices</span><?php if ($counts['overdue_count'] > 0) { ?><span class="ado-nav-badge"><?php echo esc_html((string) $counts['overdue_count']); ?></span><?php } ?></a>
        </nav>
      </aside>
      <section class="ado-main">
        <header class="ado-top">
          <h1><?php echo esc_html((string) $view_titles[$view]); ?></h1>
          <div class="ado-top-right">
            <a class="ado-btn" href="mailto:info@autodoorexperts.ca">Support</a>
            <a class="ado-btn primary" href="<?php echo ado_cd_view_url('new-quote'); ?>">New Quote</a>
          </div>
        </header>
        <div class="ado-content"><?php echo ado_cd_render_view_content($view, $uid); ?></div>
      </section>
    </div>
    <script>
    (function($){
      var nonce = <?php echo wp_json_encode($nonce); ?>;
      var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      var checkoutFallback = <?php echo wp_json_encode(wc_get_checkout_url()); ?>;
      function flash(msg, ok){
        var el = $('#ado-dashboard-flash');
        if (!el.length) { return; }
        el.removeClass('ok err').addClass(ok ? 'ok' : 'err').text(msg);
      }
      function post(action, data, cb){
        var payload = $.extend({action: action, nonce: nonce}, data || {});
        $.post(ajaxUrl, payload).done(function(res){ cb(res || {success:false,data:{message:'Request failed'}}); }).fail(function(){ cb({success:false,data:{message:'Request failed'}}); });
      }
      $('.ado-approve-quote').on('click', function(){
        var draftId = $(this).data('draft-id');
        if (!draftId) { return; }
        post('ado_load_quote_draft', {draft_id: draftId}, function(res){
          if (!res.success) {
            flash((res.data && res.data.message) ? res.data.message : 'Could not load quote.', false);
            return;
          }
          var target = (res.data && res.data.checkout_url) ? res.data.checkout_url : checkoutFallback;
          window.location.href = target;
        });
      });
      $('.ado-decline-quote').on('click', function(){
        var draftId = $(this).data('draft-id');
        if (!draftId) { return; }
        if (!window.confirm('Decline and remove this quote draft?')) { return; }
        post('ado_delete_quote_draft', {draft_id: draftId}, function(res){
          if (!res.success) {
            flash((res.data && res.data.message) ? res.data.message : 'Could not remove quote.', false);
            return;
          }
          flash('Quote declined and removed.', true);
          window.setTimeout(function(){ window.location.reload(); }, 400);
        });
      });
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
});
