<?php
if (!defined('ABSPATH')) { exit; }

final class ADO_PDF_Beta_Manager {
    private ADO_PDF_Beta_Pipeline $pipeline;
    private ADO_PDF_Beta_Artifact_Store $store;

    public function __construct() {
        $this->store = new ADO_PDF_Beta_Artifact_Store();
        $this->pipeline = new ADO_PDF_Beta_Pipeline($this->store);
        add_action('init', [$this, 'maybe_upgrade'], 5);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_ado_pdf_beta_run', [$this, 'handle_admin_run']);
        add_action('admin_post_ado_pdf_beta_accept', [$this, 'handle_accept_candidate']);
        add_action('admin_post_ado_pdf_beta_import_csv', [$this, 'handle_import_csv']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'ado_pdf_beta_aliases';
        dbDelta("CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            component_type varchar(64) NOT NULL,
            normalized_key varchar(191) NOT NULL,
            product_id bigint unsigned NOT NULL,
            match_type varchar(64) NOT NULL DEFAULT 'alias_table',
            source varchar(64) NOT NULL DEFAULT 'review_accept',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY normalized_component (component_type, normalized_key)
        ) {$charset};");
        add_option('ado_pdf_beta_next_upload_id', 1, '', false);
        update_option('ado_pdf_beta_schema_version', 1, false);
    }

    public function maybe_upgrade(): void {
        if ((int) get_option('ado_pdf_beta_schema_version', 0) < 1) {
            self::activate();
        }
    }

    public function get_pipeline(): ADO_PDF_Beta_Pipeline {
        return $this->pipeline;
    }

    public function register_admin_page(): void {
        add_submenu_page(
            'woocommerce',
            'PDF Quote Beta',
            'PDF Quote Beta',
            'manage_woocommerce',
            'ado-pdf-quote-beta',
            [$this, 'render_admin_page']
        );
    }

    public function register_rest_routes(): void {
        register_rest_route('ado-pdf-beta/v1', '/process', [
            'methods' => 'POST',
            'permission_callback' => static fn(): bool => current_user_can('manage_woocommerce'),
            'callback' => function(WP_REST_Request $request) {
                $pdf_path = (string) $request->get_param('pdf_path');
                if ($pdf_path === '' || !file_exists($pdf_path)) {
                    return new WP_REST_Response(['message' => 'pdf_path not found'], 400);
                }
                return new WP_REST_Response($this->pipeline->process_pdf($pdf_path), 200);
            },
        ]);
    }

    public function handle_admin_run(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden');
        }
        check_admin_referer('ado_pdf_beta_run');
        $pdf_path = isset($_POST['pdf_path']) ? trim((string) wp_unslash($_POST['pdf_path'])) : '';
        if ($pdf_path !== '' && file_exists($pdf_path)) {
            $result = $this->pipeline->process_pdf($pdf_path);
            set_transient('ado_pdf_beta_last_result', $result, HOUR_IN_SECONDS);
        }
        wp_safe_redirect(admin_url('admin.php?page=ado-pdf-quote-beta'));
        exit;
    }

    public function handle_accept_candidate(): void {
        global $wpdb;
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden');
        }
        check_admin_referer('ado_pdf_beta_accept');
        $component_type = sanitize_key((string) ($_POST['component_type'] ?? ''));
        $normalized_key = strtoupper(preg_replace('/[^A-Z0-9]+/', '', (string) ($_POST['normalized_key'] ?? '')) ?: '');
        $product_id = (int) ($_POST['product_id'] ?? 0);
        if ($component_type !== '' && $normalized_key !== '' && $product_id > 0) {
            $wpdb->insert($wpdb->prefix . 'ado_pdf_beta_aliases', [
                'component_type' => $component_type,
                'normalized_key' => $normalized_key,
                'product_id' => $product_id,
                'match_type' => 'alias_table',
                'source' => 'review_accept',
                'created_at' => current_time('mysql'),
            ]);
        }
        wp_safe_redirect(admin_url('admin.php?page=ado-pdf-quote-beta'));
        exit;
    }

    public function handle_import_csv(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden');
        }
        check_admin_referer('ado_pdf_beta_import_csv');
        $csv_path = isset($_POST['csv_path']) ? trim((string) wp_unslash($_POST['csv_path'])) : '';
        if ($csv_path !== '') {
            $result = ADO_PDF_Beta_CSV_Importer::import_alias_csv($csv_path);
            set_transient('ado_pdf_beta_csv_result', $result, HOUR_IN_SECONDS);
        }
        wp_safe_redirect(admin_url('admin.php?page=ado-pdf-quote-beta'));
        exit;
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden');
        }
        $result = get_transient('ado_pdf_beta_last_result');
        echo '<div class="wrap"><h1>PDF Quote Beta Reviewer</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ado_pdf_beta_run');
        echo '<input type="hidden" name="action" value="ado_pdf_beta_run" />';
        echo '<p><input type="text" name="pdf_path" style="width:60%" placeholder="Absolute PDF path" />';
        echo ' <button class="button button-primary">Process PDF</button></p>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        wp_nonce_field('ado_pdf_beta_import_csv');
        echo '<input type="hidden" name="action" value="ado_pdf_beta_import_csv" />';
        echo '<p><input type="text" name="csv_path" style="width:60%" placeholder="Absolute alias CSV path" />';
        echo ' <button class="button">Import Alias CSV</button></p>';
        echo '</form>';

        $csv_result = get_transient('ado_pdf_beta_csv_result');
        if (is_array($csv_result)) {
            echo '<p><strong>CSV import:</strong> ' . esc_html(wp_json_encode($csv_result)) . '</p>';
        }

        if (is_array($result)) {
            echo '<p><strong>Upload ID:</strong> ' . esc_html((string) ($result['upload_id'] ?? '')) . '</p>';
            echo '<p><strong>Artifacts:</strong> ' . esc_html((string) ($result['run_dir'] ?? '')) . '</p>';
            echo '<table class="widefat striped"><thead><tr><th>Raw line</th><th>Intent</th><th>Scope</th><th>Top candidates</th></tr></thead><tbody>';
            foreach ((array) ($result['resolution']['items'] ?? []) as $item) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['source']['raw_line'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['component_type'] ?? '') . ' / ' . (string) ($item['model_raw'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['scope_status'] ?? '') . ' / ' . (string) (($item['scope_reason']['code'] ?? ''))) . '</td>';
                echo '<td>';
                foreach ((array) (($item['resolver']['candidates'] ?? [])) as $candidate) {
                    echo '<div style="margin-bottom:8px;">';
                    echo esc_html((string) ($candidate['title'] ?? '')) . ' (#' . esc_html((string) ($candidate['product_id'] ?? 0)) . ', ' . esc_html(number_format((float) ($candidate['score'] ?? 0), 2)) . ')';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block; margin-left:8px;">';
                    wp_nonce_field('ado_pdf_beta_accept');
                    echo '<input type="hidden" name="action" value="ado_pdf_beta_accept" />';
                    echo '<input type="hidden" name="component_type" value="' . esc_attr((string) ($item['component_type'] ?? '')) . '" />';
                    echo '<input type="hidden" name="normalized_key" value="' . esc_attr((string) ($item['model_normalized'] ?? '')) . '" />';
                    echo '<input type="hidden" name="product_id" value="' . esc_attr((string) ($candidate['product_id'] ?? 0)) . '" />';
                    echo '<button class="button">Accept</button>';
                    echo '</form></div>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
