<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create a fresh page and return the post ID.
 */
function ado_create_page(string $title, string $slug, string $content): int
{
    $post_id = wp_insert_post(
        [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => trim($content),
        ],
        true
    );

    if (is_wp_error($post_id)) {
        fwrite(STDERR, "Failed to create page {$slug}: " . $post_id->get_error_message() . PHP_EOL);
        exit(1);
    }

    return (int) $post_id;
}

$home_content = <<<HTML
<section class="ado-hero">
  <span class="ado-kicker">AutoDoor Experts Portal</span>
  <h1>Quote, schedule, and track automatic door projects from one workspace.</h1>
  <p>Upload hardware schedule PDFs, build client-ready quotes, approve projects, and keep every door-level item tied to field execution.</p>
  <div class="ado-button-row">
    <a class="ado-btn ado-btn-primary" href="/new-quote/">Start a quote</a>
    <a class="ado-btn ado-btn-outline" href="/my-account/">Sign in</a>
  </div>
</section>
<section class="ado-grid">
  <article class="ado-panel -third">
    <h3>Quote Engine</h3>
    <p>Turn uploaded hardware schedules into quote drafts with manual override when needed.</p>
  </article>
  <article class="ado-panel -third">
    <h3>Project Tracking</h3>
    <p>Approved quotes become trackable project records with door and hardware detail.</p>
  </article>
  <article class="ado-panel -third">
    <h3>Field Visibility</h3>
    <p>Technicians log notes, photos, and hours while clients view status and critical updates.</p>
  </article>
</section>
HTML;

$client_dashboard_content = <<<HTML
<div class="ado-shell">
  <h1>Client Dashboard</h1>
  <p class="ado-muted">Outstanding invoices, upcoming visits, and critical project notes at a glance.</p>
  [ado_client_dashboard]
</div>
HTML;

$new_quote_content = <<<HTML
<div class="ado-shell">
  <h1>Create Quote</h1>
  <p class="ado-muted">Upload a hardware schedule PDF or build the quote manually. Saved quotes stay available in your workspace.</p>
  [ado_quote_workspace]
</div>
HTML;

$project_tracking_content = <<<HTML
<div class="ado-shell">
  <h1>Project Tracking</h1>
  <p class="ado-muted">Once a quote is approved and ordered, track all project records here.</p>
  [ado_client_projects]
</div>
HTML;

$tech_portal_content = <<<HTML
<div class="ado-shell">
  <h1>Technician Portal</h1>
  <p class="ado-muted">Assigned projects, field notes, upload evidence, and hour logging.</p>
  [ado_technician_portal]
</div>
HTML;

$invoices_content = <<<HTML
<div class="ado-shell">
  <h1>Invoices</h1>
  <p>Wave invoice IDs, status, and links are attached to project orders. Use this page for invoice review and reconciliation workflows.</p>
  <p class="ado-muted">Tip: keep Wave invoice ID/status synced on each project order in WooCommerce admin.</p>
</div>
HTML;

$schedule_content = <<<HTML
<div class="ado-shell">
  <h1>Schedule</h1>
  <p>Connect Google Calendar Events and paste the final calendar shortcode below when ready:</p>
  <p><code>[google-calendar-events id="technician-availability"]</code></p>
</div>
HTML;

$quote_cart_content = <<<HTML
<div class="ado-shell">
  <h1>Quotes</h1>
  [woocommerce_cart]
</div>
HTML;

$checkout_content = <<<HTML
<div class="ado-shell">
  <h1>Checkout</h1>
  [woocommerce_checkout]
</div>
HTML;

$my_account_content = <<<HTML
<div class="ado-shell">
  <h1>Account</h1>
  [woocommerce_my_account]
</div>
HTML;

$shop_content = <<<HTML
<div class="ado-shell">
  <h1>Catalog</h1>
  [products limit="16" columns="4" paginate="true"]
</div>
HTML;

$pages = [
    'home' => [
        'title'   => 'Home',
        'content' => $home_content,
    ],
    'client-dashboard' => [
        'title'   => 'Client Dashboard',
        'content' => $client_dashboard_content,
    ],
    'new-quote' => [
        'title'   => 'New Quote',
        'content' => $new_quote_content,
    ],
    'project-tracking' => [
        'title'   => 'Project Tracking',
        'content' => $project_tracking_content,
    ],
    'technician-portal' => [
        'title'   => 'Technician Portal',
        'content' => $tech_portal_content,
    ],
    'invoices' => [
        'title'   => 'Invoices',
        'content' => $invoices_content,
    ],
    'schedule' => [
        'title'   => 'Schedule',
        'content' => $schedule_content,
    ],
    'quotes' => [
        'title'   => 'Quotes',
        'content' => $quote_cart_content,
    ],
    'checkout' => [
        'title'   => 'Checkout',
        'content' => $checkout_content,
    ],
    'my-account' => [
        'title'   => 'My Account',
        'content' => $my_account_content,
    ],
    'shop' => [
        'title'   => 'Catalog',
        'content' => $shop_content,
    ],
];

$page_ids = [];
foreach ($pages as $slug => $def) {
    $page_ids[$slug] = ado_create_page($def['title'], $slug, $def['content']);
}

update_option('show_on_front', 'page');
update_option('page_on_front', $page_ids['home']);
update_option('page_for_posts', 0);

update_option('woocommerce_shop_page_id', $page_ids['shop']);
update_option('woocommerce_cart_page_id', $page_ids['quotes']);
update_option('woocommerce_checkout_page_id', $page_ids['checkout']);
update_option('woocommerce_myaccount_page_id', $page_ids['my-account']);

set_theme_mod('nav_menu_locations', []);

$elementor_pages = [
    'home',
    'client-dashboard',
    'new-quote',
    'project-tracking',
    'technician-portal',
    'invoices',
    'schedule',
];

foreach ($elementor_pages as $slug) {
    if (empty($page_ids[$slug])) {
        continue;
    }
    update_post_meta($page_ids[$slug], '_elementor_edit_mode', 'builder');
    update_post_meta($page_ids[$slug], '_wp_page_template', 'default');
}

flush_rewrite_rules();

foreach ($page_ids as $slug => $id) {
    echo "{$slug}:{$id}" . PHP_EOL;
}
