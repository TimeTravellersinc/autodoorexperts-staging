<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ADQ_Post_Types {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_post_types' ] );

        // Ensure the post edit form supports file uploads (needed for JSON upload field).
        add_action( 'post_edit_form_tag', [ __CLASS__, 'add_multipart_form_tag' ] );

        // Quote admin UI (dev/testing + admin review).
        add_action( 'add_meta_boxes_adq_quote', [ __CLASS__, 'add_quote_metaboxes' ] );
        add_action( 'save_post_adq_quote', [ __CLASS__, 'save_quote_metaboxes' ], 10, 2 );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_show_json_error_notice' ] );
    }

    public static function add_multipart_form_tag(): void {
        // WordPress will ignore duplicates; safe to always print.
        echo ' enctype="multipart/form-data"';
    }

    public static function register_roles_and_caps(): void {
        $client_caps = [ 'adq_client' => true ];
        $tech_caps   = [ 'adq_tech' => true ];
        $acct_caps   = [ 'adq_accounting' => true ];
        $admin_caps  = [
            'adq_admin' => true,
            'adq_client' => true,
            'adq_tech' => true,
            'adq_accounting' => true,
        ];

        if ( ! get_role( 'adq_client' ) ) {
            add_role( 'adq_client', 'ADQ Client', $client_caps );
        }
        if ( ! get_role( 'adq_technician' ) ) {
            add_role( 'adq_technician', 'ADQ Technician', $tech_caps );
        }
        if ( ! get_role( 'adq_accounting' ) ) {
            add_role( 'adq_accounting', 'ADQ Accounting', $acct_caps );
        }

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( array_keys( $admin_caps ) as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    public static function register_post_types(): void {
        self::register_quote();
        self::register_project();
        self::register_door();
        self::register_hardware();
        self::register_note();
        self::register_visit();
        self::register_worklog();
        self::register_product();
    }

    private static function register_quote(): void {
        register_post_type( 'adq_quote', [
            'labels' => [
                'name' => 'Quotes',
                'singular_name' => 'Quote',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'supports' => [ 'title', 'author', 'custom-fields' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    private static function register_project(): void {
        register_post_type( 'adq_project', [
            'labels' => [
                'name' => 'Projects',
                'singular_name' => 'Project',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    private static function register_door(): void {
        register_post_type( 'adq_door', [
            'labels' => [
                'name' => 'Doors',
                'singular_name' => 'Door',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'hierarchical' => true,
            'exclude_from_search' => true,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'rewrite' => false,
        ] );
    }

    private static function register_hardware(): void {
        register_post_type( 'adq_hardware', [
            'labels' => [
                'name' => 'Hardware',
                'singular_name' => 'Hardware Item',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'hierarchical' => true,
            'exclude_from_search' => true,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'rewrite' => false,
        ] );
    }

    private static function register_note(): void {
        register_post_type( 'adq_note', [
            'labels' => [
                'name' => 'Notes',
                'singular_name' => 'Note',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'hierarchical' => true,
            'exclude_from_search' => true,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'rewrite' => false,
        ] );
    }

    private static function register_visit(): void {
        register_post_type( 'adq_visit', [
            'labels' => [
                'name' => 'Visits',
                'singular_name' => 'Visit',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    private static function register_worklog(): void {
        register_post_type( 'adq_worklog', [
            'labels' => [
                'name' => 'Work Logs',
                'singular_name' => 'Work Log',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );

        // Never allow deletion from wp-admin (Blueprint: work logs are never deleted).
        add_filter( 'user_has_cap', static function (array $allcaps, array $caps, array $args): array {
            $cap = $args[0] ?? '';
            $post_id = (int) ( $args[2] ?? 0 );
            if ( 'delete_post' === $cap && $post_id && 'adq_worklog' === get_post_type( $post_id ) ) {
                $allcaps['delete_post'] = false;
                $allcaps['delete_posts'] = false;
            }
            return $allcaps;
        }, 10, 3 );
    }

    private static function register_product(): void {
        register_post_type( 'adq_product', [
            'labels' => [
                'name' => 'Product Catalog',
                'singular_name' => 'Product',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    /* ---------------------------
     * Quote Metaboxes (admin)
     * --------------------------- */

    public static function add_quote_metaboxes(): void {
        add_meta_box(
            'adq_quote_snapshot_box',
            'Quote JSON Snapshot (Frozen)',
            [ __CLASS__, 'render_quote_snapshot_box' ],
            'adq_quote',
            'normal',
            'high'
        );

        add_meta_box(
            'adq_quote_status_box',
            'Quote Status',
            [ __CLASS__, 'render_quote_status_box' ],
            'adq_quote',
            'side',
            'high'
        );
    }

    private static function validate_snapshot_string( string $snapshot ): array {
        if ( $snapshot === '' ) {
            return [ 'ok' => false, 'msg' => 'Empty', 'decoded' => null ];
        }
        $decoded = json_decode( $snapshot, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [ 'ok' => false, 'msg' => json_last_error_msg(), 'decoded' => null ];
        }
        if ( ! is_array( $decoded ) || empty( $decoded['result'] ) || ! is_array( $decoded['result'] ) ) {
            return [ 'ok' => false, 'msg' => 'JSON is valid but missing required root.result object', 'decoded' => $decoded ];
        }
        return [ 'ok' => true, 'msg' => 'OK', 'decoded' => $decoded ];
    }

    public static function render_quote_snapshot_box( \WP_Post $post ): void {
        wp_nonce_field( 'adq_quote_metabox_save', 'adq_quote_metabox_nonce' );

        $snapshot = (string) get_post_meta( $post->ID, 'quote_json_snapshot', true );
        $locked   = ( $snapshot !== '' );

        $v        = self::validate_snapshot_string( $snapshot );
        $is_valid = (bool) $v['ok'];

        echo '<p><strong>Source of truth:</strong> This JSON is written once at quote creation and never modified.</p>';

        if ( $locked ) {
            if ( $is_valid ) {
                echo '<p style="color:#1d7f1d;"><strong>Locked:</strong> snapshot is immutable. <strong>Valid JSON</strong>.</p>';
            } else {
                echo '<p style="color:#b32d2e;"><strong>Locked:</strong> snapshot is immutable. <strong>INVALID JSON</strong>: ' . esc_html( (string) $v['msg'] ) . '</p>';
            }
        } else {
            echo '<p style="color:#666;">Upload a JSON snapshot file once. After save, it locks.</p>';

            echo '<p style="margin:10px 0 6px;"><strong>Upload snapshot (.json)</strong></p>';
            echo '<input type="file" name="adq_quote_json_snapshot_file" accept="application/json,.json" />';

            echo '<p style="color:#666; margin-top:6px;">Tip: export the snapshot as a .json file and upload it here. No copy/paste involved.</p>';
        }

        // Always show what’s stored (readonly display for debugging).
        echo '<hr/>';
        echo '<p style="margin:0 0 6px;"><strong>Stored snapshot (read-only)</strong></p>';
        printf(
            '<textarea rows="12" style="width:100%%; font-family: monospace;" readonly>%s</textarea>',
            esc_textarea( $snapshot )
        );
    }

    public static function render_quote_status_box( \WP_Post $post ): void {
        $status = (string) get_post_meta( $post->ID, 'quote_status', true );
        if ( $status === '' ) { $status = 'draft'; }

        $allowed = [ 'draft','sent','approved','po_received','converted','declined','revised','void','extraction_failed' ];

        echo '<label for="adq_quote_status"><strong>Status</strong></label><br />';
        echo '<select id="adq_quote_status" name="adq_quote_status" style="width:100%;">';
        foreach ( $allowed as $s ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $s ),
                selected( $status, $s, false ),
                esc_html( $s )
            );
        }
        echo '</select>';

        $proj_id = (int) get_post_meta( $post->ID, 'converted_project_id', true );
        if ( $proj_id > 0 ) {
            echo '<hr/><p><strong>Converted Project:</strong><br />';
            $link = get_edit_post_link( $proj_id );
            echo $link ? '<a href="' . esc_url( $link ) . '">Open Project #' . esc_html( (string) $proj_id ) . '</a>' : '#' . esc_html( (string) $proj_id );
            echo '</p>';
        }
    }

    public static function save_quote_metaboxes( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['adq_quote_metabox_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce( (string) $_POST['adq_quote_metabox_nonce'], 'adq_quote_metabox_save' ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        // Snapshot: upload once (no paste).
        $current = (string) get_post_meta( $post_id, 'quote_json_snapshot', true );
        if ( $current === '' ) {
            if ( isset( $_FILES['adq_quote_json_snapshot_file'] ) && is_array( $_FILES['adq_quote_json_snapshot_file'] ) ) {
                $f = $_FILES['adq_quote_json_snapshot_file'];

                // If user didn't choose a file, ignore.
                if ( isset( $f['error'] ) && (int) $f['error'] === UPLOAD_ERR_NO_FILE ) {
                    // do nothing
                } elseif ( ! isset( $f['error'] ) || (int) $f['error'] !== UPLOAD_ERR_OK ) {
                    $err = isset( $f['error'] ) ? (int) $f['error'] : -1;
                    set_transient( 'adq_last_json_error', 'Upload failed (code ' . $err . ').', 30 );
                } else {
                    $tmp = (string) ( $f['tmp_name'] ?? '' );
                    $name = (string) ( $f['name'] ?? '' );

                    if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
                        set_transient( 'adq_last_json_error', 'Upload missing/invalid temp file.', 30 );
                    } else {
                        // Basic extension sanity check (not security, just UX).
                        if ( $name !== '' && ! preg_match( '/\.json$/i', $name ) ) {
                            set_transient( 'adq_last_json_error', 'Please upload a .json file.', 30 );
                        } else {
                            $raw = file_get_contents( $tmp );
                            if ( $raw === false ) {
                                set_transient( 'adq_last_json_error', 'Could not read uploaded file.', 30 );
                            } else {
                                $raw = trim( (string) $raw );

                                $decoded = json_decode( $raw, true );
                                if ( json_last_error() === JSON_ERROR_NONE ) {
                                    // Canonicalize storage. This prevents any weird escaping/copy artifacts.
                                    $canonical = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );

                                    ADQ_Immutability::with_immutable_write( static function () use ( $post_id, $canonical ): void {
                                        update_post_meta( $post_id, 'quote_json_snapshot', (string) $canonical );
                                    } );
                                } else {
                                    set_transient( 'adq_last_json_error', json_last_error_msg(), 30 );
                                }
                            }
                        }
                    }
                }
            }
        }

        // Status: must transition via state machine (audit-first).
        if ( isset( $_POST['adq_quote_status'] ) ) {
            $requested = sanitize_key( (string) wp_unslash( $_POST['adq_quote_status'] ) );
            $existing  = (string) get_post_meta( $post_id, 'quote_status', true );
            if ( $existing === '' ) { $existing = 'draft'; }

            if ( $requested !== $existing ) {
                $res = ADQ_State_Machines::transition_quote_status(
                    $post_id,
                    $requested,
                    get_current_user_id(),
                    [ 'reason' => 'admin action' ]
                );

                if ( is_wp_error( $res ) ) {
                    set_transient( 'adq_last_transition_error', $res->get_error_message(), 30 );
                }
            }
        }
    }

    public static function maybe_show_json_error_notice(): void {
        if ( ! is_admin() ) { return; }

        $msg = get_transient( 'adq_last_json_error' );
        if ( $msg ) {
            delete_transient( 'adq_last_json_error' );
            echo '<div class="notice notice-error"><p><strong>ADQ:</strong> Snapshot upload/JSON error: ' . esc_html( (string) $msg ) . '</p></div>';
        }

        $tmsg = get_transient( 'adq_last_transition_error' );
        if ( $tmsg ) {
            delete_transient( 'adq_last_transition_error' );
            echo '<div class="notice notice-error"><p><strong>ADQ:</strong> Transition rejected. ' . esc_html( (string) $tmsg ) . '</p></div>';
        }
    }
}