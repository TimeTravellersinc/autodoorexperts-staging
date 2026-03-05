<?php
if (!defined('ABSPATH')) {
    exit;
}

function ado_dash_id(): string
{
    return substr(md5(uniqid((string) mt_rand(), true)), 0, 7);
}

function ado_dash_widget(string $type, array $settings): array
{
    return [
        'id' => ado_dash_id(),
        'elType' => 'widget',
        'widgetType' => $type,
        'settings' => $settings,
        'elements' => [],
    ];
}

function ado_dash_container(array $elements, array $settings = []): array
{
    return [
        'id' => ado_dash_id(),
        'elType' => 'container',
        'isInner' => false,
        'settings' => array_merge(
            [
                'content_width' => 'full',
                'flex_direction' => 'column',
            ],
            $settings
        ),
        'elements' => $elements,
    ];
}

$page = get_page_by_path('client-dashboard', OBJECT, 'page');
if (!$page instanceof WP_Post) {
    fwrite(STDERR, "Missing client-dashboard page.\n");
    exit(1);
}

$html = <<<'HTML'
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap');
.ado-client-shell{
  --bg:#f4f5f7;
  --surface:#fff;
  --surface-2:#f9fafb;
  --border:#e8eaed;
  --accent:#1a56db;
  --accent-soft:#eff4ff;
  --accent-2:#0e9f6e;
  --accent-2-soft:#f0fdf4;
  --warn:#e3a008;
  --warn-soft:#fffbeb;
  --danger:#e02424;
  --danger-soft:#fef2f2;
  --text-primary:#111928;
  --text-secondary:#6b7280;
  --text-muted:#9ca3af;
  --shadow-sm:0 1px 3px rgba(0,0,0,0.06),0 1px 2px rgba(0,0,0,0.04);
  --shadow-md:0 4px 16px rgba(0,0,0,0.07),0 1px 4px rgba(0,0,0,0.04);
  --radius:14px;
  --radius-sm:8px;
  font-family:'DM Sans',sans-serif;
  display:flex;
  min-height:100vh;
  background:var(--bg);
  color:var(--text-primary);
}
.ado-client-shell *{box-sizing:border-box}
.ado-client-sidebar{
  width:256px;
  background:var(--text-primary);
  color:#fff;
  padding:0;
  min-height:100vh;
  position:sticky;
  top:0;
  align-self:flex-start;
}
.ado-client-logo{padding:28px 24px 24px;border-bottom:1px solid rgba(255,255,255,0.08)}
.ado-client-logo strong{
  font-family:'Syne',sans-serif;
  font-size:20px;
  font-weight:700;
  letter-spacing:-0.3px;
}
.ado-client-logo strong span{color:var(--accent)}
.ado-client-nav{padding:16px 12px;display:flex;flex-direction:column;gap:2px}
.ado-client-label{
  font-size:10px;
  font-weight:600;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:rgba(255,255,255,.3);
  padding:12px 12px 6px;
  margin-top:8px;
}
.ado-client-item{
  display:flex;
  align-items:center;
  gap:10px;
  padding:9px 12px;
  border-radius:8px;
  color:rgba(255,255,255,.6);
  text-decoration:none;
  font-size:14px;
  font-weight:500;
}
.ado-client-item:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.95)}
.ado-client-item.active{background:var(--accent);color:#fff}
.ado-client-main{flex:1;display:flex;flex-direction:column;min-width:0}
.ado-client-topbar{
  background:var(--surface);
  border-bottom:1px solid var(--border);
  padding:16px 32px;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.ado-client-topbar h1{
  margin:0;
  font-family:'Syne',sans-serif;
  font-size:20px;
  letter-spacing:-.3px;
}
.ado-client-actions{display:flex;gap:10px;flex-wrap:wrap}
.ado-client-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:9px 16px;
  border-radius:8px;
  border:1px solid var(--border);
  background:transparent;
  color:var(--text-secondary);
  text-decoration:none;
  font-size:13px;
  font-weight:600;
}
.ado-client-btn.primary{
  border-color:transparent;
  background:var(--accent);
  color:#fff;
  box-shadow:0 1px 3px rgba(26,86,219,.3);
}
.ado-client-content{padding:28px}
.ado-client-alert{
  background:var(--danger-soft);
  border:1px solid #fca5a5;
  border-radius:var(--radius);
  padding:14px 16px;
  margin-bottom:20px;
  color:#991b1b;
  font-size:13px;
}
.ado-client-welcome{
  margin-bottom:20px;
}
.ado-client-welcome .kicker{
  font-family:'Syne',sans-serif;
  font-size:12px;
  font-weight:700;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:var(--text-muted);
}
.ado-client-welcome .name{
  font-family:'Syne',sans-serif;
  font-size:26px;
  font-weight:800;
  letter-spacing:-.4px;
  margin-top:2px;
}
.ado-client-stats{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:14px;
  margin-bottom:20px;
}
.ado-client-stat{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:18px;
  box-shadow:var(--shadow-sm);
}
.ado-client-stat .label{
  font-size:11px;
  font-weight:700;
  letter-spacing:.05em;
  text-transform:uppercase;
  color:var(--text-muted);
}
.ado-client-stat .value{
  margin-top:8px;
  font-family:'Syne',sans-serif;
  font-size:28px;
  font-weight:700;
  line-height:1;
}
.ado-client-stat .sub{
  margin-top:4px;
  font-size:12px;
  color:var(--text-muted);
}
.ado-client-grid{
  display:grid;
  grid-template-columns:minmax(0,1fr) 320px;
  gap:16px;
}
.ado-client-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow-sm);
  overflow:hidden;
}
.ado-client-card-header{
  padding:16px 18px;
  border-bottom:1px solid var(--border);
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.ado-client-card-title{
  font-family:'Syne',sans-serif;
  font-size:15px;
  font-weight:700;
  letter-spacing:-.2px;
}
.ado-client-card-link{
  font-size:12px;
  color:var(--accent);
  text-decoration:none;
  font-weight:600;
}
.ado-client-list{padding:0;margin:0;list-style:none}
.ado-client-list li{
  padding:14px 18px;
  border-bottom:1px solid var(--border);
}
.ado-client-list li:last-child{border-bottom:none}
.ado-client-list .title{
  font-size:14px;
  font-weight:700;
  color:var(--text-primary);
}
.ado-client-list .meta{
  font-size:12px;
  color:var(--text-muted);
  margin-top:2px;
}
.ado-client-badge{
  display:inline-block;
  margin-top:6px;
  font-size:10px;
  font-weight:700;
  letter-spacing:.05em;
  text-transform:uppercase;
  padding:2px 8px;
  border-radius:999px;
}
.ado-client-badge.warn{background:var(--warn-soft);color:#92400e}
.ado-client-badge.critical{background:var(--danger-soft);color:var(--danger)}
.ado-client-badge.ok{background:var(--accent-2-soft);color:#065f46}
.ado-client-qa{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
  padding:14px;
}
.ado-client-qa a{
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
  min-height:74px;
  border-radius:8px;
  border:1px solid var(--border);
  background:var(--surface-2);
  text-decoration:none;
  font-size:12px;
  font-weight:700;
  color:var(--text-secondary);
}
.ado-client-qa a:hover{background:var(--accent-soft);color:var(--accent);border-color:var(--accent)}
.ado-client-live-wrap{
  margin-top:16px;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow-sm);
  padding:16px;
}
.ado-client-live-wrap h3{
  margin:0 0 10px;
  font-family:'Syne',sans-serif;
  font-size:15px;
  letter-spacing:-.2px;
}
@media (max-width: 1100px){
  .ado-client-shell{flex-direction:column}
  .ado-client-sidebar{position:relative;width:100%;min-height:auto}
  .ado-client-main{width:100%}
  .ado-client-stats{grid-template-columns:1fr}
  .ado-client-grid{grid-template-columns:1fr}
}
</style>
<div class="ado-client-shell">
  <aside class="ado-client-sidebar">
    <div class="ado-client-logo"><strong>Auto<span>Door</span></strong></div>
    <nav class="ado-client-nav">
      <div class="ado-client-label">Overview</div>
      <a class="ado-client-item active" href="/client-dashboard/">Dashboard</a>
      <div class="ado-client-label">Quotes & Projects</div>
      <a class="ado-client-item" href="/new-quote/">New Quote</a>
      <a class="ado-client-item" href="/quotes/">My Quotes</a>
      <a class="ado-client-item" href="/project-tracking/">My Projects</a>
      <div class="ado-client-label">Scheduling</div>
      <a class="ado-client-item" href="/schedule/">Schedule</a>
      <div class="ado-client-label">Billing</div>
      <a class="ado-client-item" href="/invoices/">Invoices</a>
    </nav>
  </aside>
  <section class="ado-client-main">
    <header class="ado-client-topbar">
      <h1>Dashboard</h1>
      <div class="ado-client-actions">
        <a class="ado-client-btn" href="/my-account/">Account</a>
        <a class="ado-client-btn primary" href="/new-quote/">New Quote</a>
      </div>
    </header>
    <div class="ado-client-content">
      <div class="ado-client-alert"><strong>Action required:</strong> overdue or urgent items are surfaced here so clients can respond quickly.</div>
      <div class="ado-client-welcome">
        <div class="kicker">Client Portal</div>
        <div class="name">Welcome back</div>
      </div>
      <div class="ado-client-stats">
        <article class="ado-client-stat">
          <div class="label">Outstanding Balance</div>
          <div class="value">Live</div>
          <div class="sub">Wave invoice status sync</div>
        </article>
        <article class="ado-client-stat">
          <div class="label">Active Projects</div>
          <div class="value">Live</div>
          <div class="sub">Approved orders in execution</div>
        </article>
        <article class="ado-client-stat">
          <div class="label">Next Visit</div>
          <div class="value">Live</div>
          <div class="sub">Scheduled + pending confirmations</div>
        </article>
      </div>
      <div class="ado-client-grid">
        <div style="display:flex;flex-direction:column;gap:16px;">
          <article class="ado-client-card">
            <div class="ado-client-card-header">
              <span class="ado-client-card-title">Active Projects</span>
              <a class="ado-client-card-link" href="/project-tracking/">View all</a>
            </div>
            <ul class="ado-client-list">
              <li>
                <div class="title">Projects are shown dynamically below</div>
                <div class="meta">Door-level scope, status, and critical notes remain linked to purchased quote data.</div>
              </li>
            </ul>
          </article>
          <article class="ado-client-card">
            <div class="ado-client-card-header">
              <span class="ado-client-card-title">Quotes Awaiting Decision</span>
              <a class="ado-client-card-link" href="/quotes/">Open quotes</a>
            </div>
            <ul class="ado-client-list">
              <li>
                <div class="title">Generate and review quotes</div>
                <div class="meta">Approve or decline from your quote flow.</div>
                <span class="ado-client-badge warn">Attention</span>
              </li>
            </ul>
          </article>
        </div>
        <div style="display:flex;flex-direction:column;gap:16px;">
          <article class="ado-client-card">
            <div class="ado-client-card-header"><span class="ado-client-card-title">Quick Actions</span></div>
            <div class="ado-client-qa">
              <a href="/new-quote/">New Quote</a>
              <a href="/project-tracking/">Track Project</a>
              <a href="/schedule/">Book Visit</a>
              <a href="/invoices/">Invoices</a>
            </div>
          </article>
          <article class="ado-client-card">
            <div class="ado-client-card-header">
              <span class="ado-client-card-title">Priority Notes</span>
              <a class="ado-client-card-link" href="/project-tracking/">All notes</a>
            </div>
            <ul class="ado-client-list">
              <li>
                <div class="title">Critical and high notes surface here</div>
                <div class="meta">Notes flagged by technicians are prioritized for client action.</div>
                <span class="ado-client-badge critical">Critical</span>
              </li>
              <li>
                <div class="title">Upcoming visits and invoice indicators</div>
                <div class="meta">Schedule and billing context stays visible on dashboard.</div>
                <span class="ado-client-badge ok">On schedule</span>
              </li>
            </ul>
          </article>
        </div>
      </div>
      <div class="ado-client-live-wrap">
        <h3>Live Dashboard Data</h3>
        <p style="margin:0;color:#6b7280;font-size:12px;">The widget below is your actual live feed from portal logic.</p>
      </div>
    </div>
  </section>
</div>
HTML;

$layout = [
    ado_dash_container(
        [
            ado_dash_widget(
                'html',
                [
                    'html' => $html,
                ]
            ),
            ado_dash_container(
                [
                    ado_dash_widget('shortcode', ['shortcode' => '[ado_client_dashboard]']),
                ],
                [
                    '_css_classes' => 'ado-client-live-wrap',
                    'margin' => [
                        'unit' => 'px',
                        'top' => '-12',
                        'right' => '28',
                        'bottom' => '24',
                        'left' => '28',
                        'isLinked' => false,
                    ],
                ]
            ),
        ],
        [
            'padding' => [
                'unit' => 'px',
                'top' => '0',
                'right' => '0',
                'bottom' => '0',
                'left' => '0',
                'isLinked' => true,
            ],
        ]
    ),
];

$json = wp_json_encode($layout);
if (!is_string($json) || $json === '') {
    fwrite(STDERR, "Failed to encode layout JSON.\n");
    exit(1);
}

update_post_meta($page->ID, '_elementor_data', wp_slash($json));
update_post_meta($page->ID, '_elementor_edit_mode', 'builder');
update_post_meta($page->ID, '_elementor_template_type', 'wp-page');
update_post_meta($page->ID, '_elementor_page_settings', []);
update_post_meta($page->ID, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.35.5');
update_post_meta($page->ID, '_wp_page_template', 'elementor_canvas');
wp_update_post(['ID' => $page->ID, 'post_content' => '']);

echo 'updated:' . $page->ID . PHP_EOL;
