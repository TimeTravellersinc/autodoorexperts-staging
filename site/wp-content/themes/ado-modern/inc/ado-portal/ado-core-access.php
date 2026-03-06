<?php
// ADO Core Access + UI base styles
if (defined('ADO_CORE_ACCESS_LOADED')) { return; }
define('ADO_CORE_ACCESS_LOADED', true);

function ado_user_has_role(array $roles, int $user_id = 0): bool {
    $user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
    if ($user_id <= 0) { return false; }
    $user = get_userdata($user_id);
    if (!$user) { return false; }
    foreach ($roles as $role) {
        if (in_array($role, (array) $user->roles, true)) { return true; }
    }
    return false;
}

function ado_is_client(int $user_id = 0): bool {
    return ado_user_has_role(['client', 'customer', 'administrator'], $user_id);
}

function ado_is_technician(int $user_id = 0): bool {
    return ado_user_has_role(['technician', 'administrator'], $user_id);
}

add_action('init', static function (): void {
    if (!get_role('client')) {
        add_role('client', 'Client', ['read' => true, 'upload_files' => true]);
    }
    if (!get_role('technician')) {
        add_role('technician', 'Technician', ['read' => true, 'upload_files' => true]);
    }
    if (!get_role('admin_staff')) {
        add_role('admin_staff', 'Admin Staff', ['read' => true, 'list_users' => true]);
    }
});

add_filter('login_redirect', static function ($redirect_to, $requested, $user) {
    if (!($user instanceof WP_User)) { return $redirect_to; }
    if (in_array('technician', (array) $user->roles, true)) {
        return home_url('/technician-portal/');
    }
    if (in_array('client', (array) $user->roles, true) || in_array('customer', (array) $user->roles, true)) {
        return home_url('/client-dashboard/');
    }
    if (in_array('admin_staff', (array) $user->roles, true)) {
        return home_url('/wp-admin/');
    }
    return $redirect_to;
}, 20, 3);

add_filter('redirect_canonical', static function ($redirect_url, $requested_url) {
    $request_path = trim((string) parse_url((string) ($requested_url ?: ($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH), '/');
    if (preg_match('#^portal(?:/|$)#', $request_path)) {
        return false;
    }
    return $redirect_url;
}, 10, 2);

add_action('template_redirect', static function (): void {
    $request_path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');

    if (preg_match('#^portal(?:/|$)#', $request_path)) {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/' . $request_path . '/')));
            exit;
        }
        if (!ado_is_client()) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $portal_route = trim((string) substr($request_path, strlen('portal')), '/');
        $target_args = ['view' => 'dashboard'];

        if ($portal_route === '' || $portal_route === 'dashboard') {
            $target_args = ['view' => 'dashboard'];
        } elseif ($portal_route === 'new-quote') {
            $target_args = ['view' => 'new-quote'];
        } elseif (in_array($portal_route, ['quotes', 'client-quotes'], true)) {
            $target_args = ['view' => 'quotes'];
        } elseif (in_array($portal_route, ['projects', 'client-projects'], true)) {
            $target_args = ['view' => 'projects'];
        } elseif (in_array($portal_route, ['schedule', 'client-schedule'], true)) {
            $target_args = ['view' => 'schedule'];
        } elseif ($portal_route === 'site-docs') {
            $target_args = ['view' => 'site-docs'];
        } elseif (in_array($portal_route, ['invoices', 'client-invoices'], true)) {
            $target_args = ['view' => 'invoices'];
        }

        $target = add_query_arg($target_args, home_url('/client-dashboard/'));
        if (untrailingslashit((string) $target) !== untrailingslashit(home_url('/' . $request_path))) {
            wp_safe_redirect($target);
            exit;
        }
    }

    $client_only_pages = [
        'client-dashboard',
        'new-quote',
        'project-tracking',
        'invoices',
        'schedule',
        'quotes',
        'site-docs',
        'checkout',
    ];
    $technician_only_pages = [
        'technician-portal',
    ];
    $client_shell_views = [
        'new-quote' => 'new-quote',
        'project-tracking' => 'projects',
        'quotes' => 'quotes',
        'schedule' => 'schedule',
        'site-docs' => 'site-docs',
        'invoices' => 'invoices',
    ];

    foreach ($client_only_pages as $slug) {
        if (!is_page($slug)) {
            continue;
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(get_permalink()));
            exit;
        }
        if (!ado_is_client()) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        if (isset($client_shell_views[$slug])) {
            $target = add_query_arg(['view' => $client_shell_views[$slug]], home_url('/client-dashboard/'));
            wp_safe_redirect($target);
            exit;
        }
        return;
    }

    foreach ($technician_only_pages as $slug) {
        if (!is_page($slug)) {
            continue;
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(get_permalink()));
            exit;
        }
        if (!ado_is_technician()) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        return;
    }
});

add_action('wp_head', static function (): void {
    ?>
    <style>
    .ado-grid-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:0 0 16px}
    .ado-card{background:#fff;border:1px solid #e7eaf0;border-radius:14px;padding:16px;box-shadow:0 10px 30px rgba(16,24,40,.06);margin:0 0 16px}
    .ado-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:6px 0}
    .ado-chip{display:inline-block;padding:2px 10px;border-radius:999px;background:#eef4ff;color:#1d4ed8;font-size:12px}
    .ado-warning{color:#b54708}
    .ado-muted{color:#667085}
    .ado-metric{font-size:32px;line-height:1.1;margin:6px 0 0}
    .ado-project{border:1px solid #eef2f7;border-radius:12px;padding:12px;margin:10px 0}
    .ado-draft{border:1px solid #eef2f7;border-radius:12px;padding:10px;margin:10px 0}
    @media (max-width: 900px){.ado-grid-cards{grid-template-columns:1fr}}
    </style>
    <?php
});
