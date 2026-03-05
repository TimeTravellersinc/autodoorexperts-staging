<?php
if (!defined('ABSPATH')) {
    exit;
}

function ado_el_id(): string
{
    return substr(md5(uniqid((string) mt_rand(), true)), 0, 7);
}

function ado_px_box($top, $right, $bottom, $left, bool $linked = false): array
{
    return [
        'unit' => 'px',
        'top' => (string) $top,
        'right' => (string) $right,
        'bottom' => (string) $bottom,
        'left' => (string) $left,
        'isLinked' => $linked,
    ];
}

function ado_border_width($w): array
{
    return [
        'unit' => 'px',
        'top' => (string) $w,
        'right' => (string) $w,
        'bottom' => (string) $w,
        'left' => (string) $w,
        'isLinked' => true,
    ];
}

function ado_gap(int $row = 16, int $column = 16): array
{
    return [
        'unit' => 'px',
        'size' => $row,
        'row' => (string) $row,
        'column' => (string) $column,
        'isLinked' => false,
    ];
}

function ado_widget(string $widget_type, array $settings = []): array
{
    return [
        'id' => ado_el_id(),
        'elType' => 'widget',
        'widgetType' => $widget_type,
        'settings' => $settings,
        'elements' => [],
    ];
}

function ado_container(array $elements, array $settings = [], bool $is_inner = false): array
{
    $defaults = [
        'content_width' => 'full',
        'flex_direction' => 'column',
        'flex_gap' => ado_gap(),
    ];

    return [
        'id' => ado_el_id(),
        'elType' => 'container',
        'isInner' => $is_inner,
        'settings' => array_merge($defaults, $settings),
        'elements' => $elements,
    ];
}

function ado_heading(string $text, string $size = 'h2', string $color = ''): array
{
    $settings = [
        'title' => $text,
        'size' => $size,
    ];
    if ($color !== '') {
        $settings['title_color'] = $color;
    }
    return ado_widget('heading', $settings);
}

function ado_text(string $html, string $color = ''): array
{
    $settings = [
        'editor' => $html,
    ];
    if ($color !== '') {
        $settings['text_color'] = $color;
    }
    return ado_widget('text-editor', $settings);
}

function ado_button(string $text, string $url, bool $outline = false): array
{
    $settings = [
        'text' => $text,
        'size' => 'md',
        'align' => 'left',
        'link' => [
            'url' => $url,
            'is_external' => '',
            'nofollow' => '',
            'custom_attributes' => '',
        ],
        'border_radius' => ado_px_box(12, 12, 12, 12, true),
        'text_padding' => ado_px_box(12, 18, 12, 18, false),
    ];

    if ($outline) {
        $settings['button_text_color'] = '#F4FBFF';
        $settings['background_color'] = '#1A4468';
        $settings['hover_color'] = '#F4FBFF';
        $settings['button_background_hover_color'] = '#0F253A';
    } else {
        $settings['button_text_color'] = '#10314A';
        $settings['background_color'] = '#FFFFFF';
        $settings['hover_color'] = '#0F253A';
        $settings['button_background_hover_color'] = '#E9F8FF';
    }

    return ado_widget('button', $settings);
}

function ado_shortcode_widget(string $shortcode): array
{
    return ado_widget('shortcode', ['shortcode' => $shortcode]);
}

function ado_panel(array $elements, string $class = 'ado-panel', array $extra = []): array
{
    $settings = array_merge(
        [
            '_css_classes' => $class,
            'background_background' => 'classic',
            'background_color' => '#FFFFFF',
            'border_border' => 'solid',
            'border_color' => '#DBE5F1',
            'border_width' => ado_border_width(1),
            'border_radius' => ado_px_box(16, 16, 16, 16, true),
            'padding' => ado_px_box(20, 20, 20, 20, false),
        ],
        $extra
    );

    return ado_container($elements, $settings, true);
}

function ado_metric_card(string $title, string $value, string $note): array
{
    return ado_panel(
        [
            ado_heading($title, 'h4'),
            ado_heading($value, 'h3', '#0F7B8F'),
            ado_text('<p>' . esc_html($note) . '</p>', '#5F6F85'),
        ],
        'ado-panel -third'
    );
}

function ado_step_card(string $title, string $body): array
{
    return ado_panel(
        [
            ado_heading($title, 'h4'),
            ado_text('<p>' . esc_html($body) . '</p>', '#5F6F85'),
        ],
        'ado-panel -third'
    );
}

function ado_hero(string $kicker, string $title, string $desc, array $buttons = []): array
{
    $button_widgets = [];
    foreach ($buttons as $button) {
        $button_widgets[] = ado_button($button['text'], $button['url'], !empty($button['outline']));
    }

    $button_row = ado_container(
        $button_widgets,
        [
            'flex_direction' => 'row',
            'flex_wrap' => 'wrap',
            'justify_content' => 'flex-start',
            'align_items' => 'center',
            'flex_gap' => ado_gap(12, 12),
            '_css_classes' => 'ado-button-row',
        ],
        true
    );

    return ado_container(
        [
            ado_text('<span class="ado-kicker">' . esc_html($kicker) . '</span>'),
            ado_heading($title, 'h1', '#F4FBFF'),
            ado_text('<p style="color:#DCEAF7;">' . esc_html($desc) . '</p>'),
            $button_row,
        ],
        [
            '_css_classes' => 'ado-hero',
            'background_background' => 'classic',
            'background_color' => '#0F253A',
            'padding' => ado_px_box(34, 34, 34, 34, false),
            'border_radius' => ado_px_box(22, 22, 22, 22, true),
            'margin' => ado_px_box(12, 0, 16, 0, false),
        ]
    );
}

function ado_three_col_row(array $cards): array
{
    return ado_container(
        $cards,
        [
            'flex_direction' => 'row',
            'flex_wrap' => 'wrap',
            'align_items' => 'stretch',
            'justify_content' => 'space-between',
            'flex_gap' => ado_gap(16, 16),
            '_css_classes' => 'ado-grid',
            'margin' => ado_px_box(14, 0, 10, 0, false),
        ]
    );
}

function ado_page_shell(array $elements): array
{
    return [
        ado_container(
            $elements,
            [
                '_css_classes' => 'ado-shell',
                'padding' => ado_px_box(18, 18, 18, 18, false),
                'margin' => ado_px_box(12, 0, 18, 0, false),
            ]
        ),
    ];
}

function ado_build_layouts(): array
{
    $home = ado_page_shell(
        [
            ado_hero(
                'AutoDoor Experts Portal',
                'Quote, schedule, and track automatic door projects from one workspace.',
                'Upload hardware schedules, generate quote drafts, and convert approved orders into trackable project work.',
                [
                    ['text' => 'Start New Quote', 'url' => '/new-quote/'],
                    ['text' => 'Sign In', 'url' => '/my-account/', 'outline' => true],
                ]
            ),
            ado_three_col_row(
                [
                    ado_step_card('Quote Engine', 'Drag and drop hardware schedules, auto-fill quote carts, and keep manual fallback available.'),
                    ado_step_card('Project Continuity', 'Approved quotes become projects with preserved door and hardware line detail.'),
                    ado_step_card('Field Visibility', 'Clients and technicians can see critical notes, updates, and current scope status.'),
                ]
            ),
            ado_panel(
                [
                    ado_heading('Portal Flow', 'h3'),
                    ado_text('<p>1) Upload PDF to generate quote draft. 2) Confirm quantities and submit purchase order. 3) Project tracking activates with technician logs and invoice status.</p>', '#5F6F85'),
                ],
                'ado-panel'
            ),
        ]
    );

    $client_dashboard = ado_page_shell(
        [
            ado_hero(
                'Client Workspace',
                'Client Dashboard',
                'Primary actions: generate quotes, approve work, schedule visits, and track active projects.',
                [
                    ['text' => 'Generate Quote', 'url' => '/new-quote/'],
                    ['text' => 'View Projects', 'url' => '/project-tracking/', 'outline' => true],
                ]
            ),
            ado_three_col_row(
                [
                    ado_metric_card('Outstanding Invoices', 'Live', 'Wave invoice totals and statuses are surfaced in project/order metadata.'),
                    ado_metric_card('Upcoming Visits', 'Live', 'Preferred and scheduled dates are shown from project data.'),
                    ado_metric_card('Critical Notes', 'Live', 'High-priority technician/client notes are flagged at top priority.'),
                ]
            ),
            ado_panel(
                [
                    ado_heading('Dashboard Feed', 'h3'),
                    ado_text('<p class="ado-muted">This section is dynamic and role-aware.</p>', '#5F6F85'),
                    ado_shortcode_widget('[ado_client_dashboard]'),
                ],
                'ado-panel'
            ),
        ]
    );

    $new_quote = ado_page_shell(
        [
            ado_hero(
                'Quote Builder',
                'Create Quote',
                'Upload a hardware schedule PDF to auto-build quote carts, then review line items before submission.',
                [
                    ['text' => 'My Quotes', 'url' => '/quotes/'],
                    ['text' => 'My Account', 'url' => '/my-account/', 'outline' => true],
                ]
            ),
            ado_three_col_row(
                [
                    ado_step_card('Step 1: Upload', 'Drop hardware schedule PDF. Parser output scopes operator-relevant door records.'),
                    ado_step_card('Step 2: Review', 'Validate mapped products, pricing tiers, and unresolved items before finalizing.'),
                    ado_step_card('Step 3: Submit', 'Store quote draft and move to checkout when purchase order is ready.'),
                ]
            ),
            ado_panel(
                [
                    ado_heading('Quote Workspace', 'h3'),
                    ado_text('<p class="ado-muted">PDF upload + manual entry fallback lives below.</p>', '#5F6F85'),
                    ado_shortcode_widget('[ado_quote_workspace]'),
                ],
                'ado-panel'
            ),
        ]
    );

    $project_tracking = ado_page_shell(
        [
            ado_hero(
                'Project Visibility',
                'Project Tracking',
                'Approved orders are displayed as projects with status, scoped JSON history, and field updates.',
                [
                    ['text' => 'Client Dashboard', 'url' => '/client-dashboard/'],
                    ['text' => 'Invoices', 'url' => '/invoices/', 'outline' => true],
                ]
            ),
            ado_panel(
                [
                    ado_heading('Active Projects', 'h3'),
                    ado_shortcode_widget('[ado_client_projects]'),
                ],
                'ado-panel'
            ),
        ]
    );

    $tech_portal = ado_page_shell(
        [
            ado_hero(
                'Technician Workspace',
                'Technician Portal',
                'Review assigned projects, upload photos, log notes/hours, and flag critical updates.',
                [
                    ['text' => 'Assigned Projects', 'url' => '/technician-portal/'],
                    ['text' => 'Schedule', 'url' => '/schedule/', 'outline' => true],
                ]
            ),
            ado_panel(
                [
                    ado_heading('Assigned Work', 'h3'),
                    ado_shortcode_widget('[ado_technician_portal]'),
                ],
                'ado-panel'
            ),
        ]
    );

    $invoices = ado_page_shell(
        [
            ado_hero(
                'Billing',
                'Invoices',
                'Use WaveApps as the source of truth and mirror key status fields on each project order.',
                [
                    ['text' => 'Project Tracking', 'url' => '/project-tracking/'],
                    ['text' => 'My Account', 'url' => '/my-account/', 'outline' => true],
                ]
            ),
            ado_panel(
                [
                    ado_heading('Wave Billing Checklist', 'h3'),
                    ado_text('<p>Set <strong>invoice ID</strong>, <strong>invoice URL</strong>, <strong>status</strong>, and <strong>amount due</strong> on each project in WooCommerce order admin.</p>', '#5F6F85'),
                ],
                'ado-panel'
            ),
        ]
    );

    $schedule = ado_page_shell(
        [
            ado_hero(
                'Scheduling',
                'Upcoming Visits',
                'Clients submit preferred dates and technicians confirm final mobilization once site readiness is verified.',
                [
                    ['text' => 'Request via Quote', 'url' => '/new-quote/'],
                    ['text' => 'Technician Portal', 'url' => '/technician-portal/', 'outline' => true],
                ]
            ),
            ado_panel(
                [
                    ado_heading('Calendar Feed', 'h3'),
                    ado_text('<p>Paste your finalized Google Calendar Events shortcode here when your production calendar is connected.</p>', '#5F6F85'),
                    ado_shortcode_widget('[google-calendar-events id="technician-availability"]'),
                ],
                'ado-panel'
            ),
        ]
    );

    $quotes = ado_page_shell(
        [
            ado_heading('Quotes', 'h1'),
            ado_text('<p>Saved quote carts and active quote sessions.</p>', '#5F6F85'),
            ado_panel([ado_shortcode_widget('[woocommerce_cart]')], 'ado-panel'),
        ]
    );

    $checkout = ado_page_shell(
        [
            ado_heading('Checkout', 'h1'),
            ado_text('<p>Confirm purchase order details and preferred visit windows.</p>', '#5F6F85'),
            ado_panel([ado_shortcode_widget('[woocommerce_checkout]')], 'ado-panel'),
        ]
    );

    $account = ado_page_shell(
        [
            ado_heading('My Account', 'h1'),
            ado_text('<p>Account details, projects, and saved quote activity.</p>', '#5F6F85'),
            ado_panel([ado_shortcode_widget('[woocommerce_my_account]')], 'ado-panel'),
        ]
    );

    $shop = ado_page_shell(
        [
            ado_heading('Catalog', 'h1'),
            ado_text('<p>Door and hardware catalog for manual quote assembly.</p>', '#5F6F85'),
            ado_panel([ado_shortcode_widget('[products limit="16" columns="4" paginate="true"]')], 'ado-panel'),
        ]
    );

    return [
        'home' => $home,
        'client-dashboard' => $client_dashboard,
        'new-quote' => $new_quote,
        'project-tracking' => $project_tracking,
        'technician-portal' => $tech_portal,
        'invoices' => $invoices,
        'schedule' => $schedule,
        'quotes' => $quotes,
        'checkout' => $checkout,
        'my-account' => $account,
        'shop' => $shop,
    ];
}

function ado_apply_layout(string $slug, array $layout): void
{
    $page = get_page_by_path($slug, OBJECT, 'page');
    if (!$page instanceof WP_Post) {
        fwrite(STDERR, "Missing page for slug: {$slug}" . PHP_EOL);
        return;
    }

    $json = wp_json_encode($layout);
    if (!is_string($json) || $json === '') {
        fwrite(STDERR, "Failed encoding Elementor JSON for: {$slug}" . PHP_EOL);
        return;
    }

    update_post_meta($page->ID, '_elementor_data', wp_slash($json));
    update_post_meta($page->ID, '_elementor_edit_mode', 'builder');
    update_post_meta($page->ID, '_elementor_template_type', 'wp-page');
    update_post_meta($page->ID, '_elementor_page_settings', []);
    update_post_meta($page->ID, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.35.5');
    update_post_meta($page->ID, '_wp_page_template', 'default');

    wp_update_post(
        [
            'ID' => $page->ID,
            'post_content' => '',
        ]
    );

    echo $slug . ':' . $page->ID . PHP_EOL;
}

$layouts = ado_build_layouts();
foreach ($layouts as $slug => $layout) {
    ado_apply_layout($slug, $layout);
}
