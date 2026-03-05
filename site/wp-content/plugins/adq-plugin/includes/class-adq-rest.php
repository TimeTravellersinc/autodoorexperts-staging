<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ADQ_Rest {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( 'adq/v1', '/quote-status/(?P<id>\d+)', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'get_quote_status' ],
			'permission_callback' => [ __CLASS__, 'perm_quote_status' ],
			'args' => [
				'id' => [
					'required' => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $value, $request, $param ) {
						return is_numeric( $value ) && (int) $value > 0;
					},
				],
			],
		] );

		register_rest_route( 'adq/v1', '/quote/(?P<id>\d+)/approve', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'approve_quote' ],
			'permission_callback' => [ __CLASS__, 'perm_quote_approve' ],
			'args' => [
				'id' => [
					'required' => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $value, $request, $param ) {
						return is_numeric( $value ) && (int) $value > 0;
					},
				],
			],
		] );
	}

	public static function perm_quote_status( WP_REST_Request $req ): bool {
		$quote_id = (int) $req['id'];
		$quote = get_post( $quote_id );
		if ( ! $quote || 'adq_quote' !== $quote->post_type ) { return false; }

		if ( current_user_can( 'manage_options' ) ) { return true; }

		if ( ! is_user_logged_in() ) { return false; }

		$client_id = (int) get_post_meta( $quote_id, 'client_id', true );
		if ( $client_id <= 0 ) { $client_id = (int) $quote->post_author; }

		return $client_id === get_current_user_id();
	}

	public static function get_quote_status( WP_REST_Request $req ): WP_REST_Response {
		$quote_id = (int) $req['id'];
		$status = (string) get_post_meta( $quote_id, 'quote_status', true );
		if ( $status === '' ) { $status = 'draft'; }

		return new WP_REST_Response( [
			'quote_id' => $quote_id,
			'quote_status' => $status,
		], 200 );
	}

	public static function perm_quote_approve( WP_REST_Request $request ) {
		$quote_id = absint( $request['id'] );
		if ( $quote_id <= 0 ) {
			return new WP_Error( 'adq_bad_id', 'Invalid quote id', [ 'status' => 400 ] );
		}

		// Must be authenticated (cookie session OR application password).
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Not logged in', [ 'status' => 401 ] );
		}

		$quote = get_post( $quote_id );
		if ( ! $quote || 'adq_quote' !== $quote->post_type ) {
			return new WP_Error( 'adq_bad_quote', 'Quote not found', [ 'status' => 404 ] );
		}

		// Ownership gate (Blueprint: client owns quote).
		$user_id   = get_current_user_id();
		$client_id = (int) get_post_meta( $quote_id, 'client_id', true );
		if ( $client_id <= 0 ) {
			$client_id = (int) $quote->post_author;
		}
		if ( $client_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'Not your quote', [ 'status' => 403 ] );
		}

		// Status gate (Blueprint: only approve after sent).
		$status = (string) get_post_meta( $quote_id, 'quote_status', true );
		if ( $status === '' ) { $status = 'draft'; }
		if ( $status !== 'sent' ) {
			return new WP_Error( 'adq_bad_state', 'Quote must be in sent status to approve', [ 'status' => 400 ] );
		}

		/**
		 * CSRF rules:
		 * - If this looks like a browser cookie session => require nonce.
		 * - If there are NO WP auth cookies but user is logged in => treat as app-password/non-cookie auth => skip nonce.
		 */
		$has_wp_auth_cookie = false;
		foreach ( array_keys( $_COOKIE ?? [] ) as $cookie_name ) {
			if ( str_starts_with( $cookie_name, 'wordpress_logged_in_' ) || str_starts_with( $cookie_name, 'wordpress_sec_' ) ) {
				$has_wp_auth_cookie = true;
				break;
			}
		}

		if ( $has_wp_auth_cookie ) {
			$adq_nonce = (string) $request->get_header( 'x-adq-nonce' );
			$wp_nonce  = (string) $request->get_header( 'x-wp-nonce' );

			$adq_ok = ( $adq_nonce !== '' ) && wp_verify_nonce( $adq_nonce, 'adq_approve_quote_' . $quote_id );
			$wp_ok  = ( $wp_nonce !== '' ) && wp_verify_nonce( $wp_nonce, 'wp_rest' );

			if ( ! $adq_ok && ! $wp_ok ) {
				return new WP_Error( 'adq_bad_nonce', 'Invalid nonce', [ 'status' => 403 ] );
			}
		}

		return true;
	}

	public static function approve_quote( WP_REST_Request $req ) {
		$quote_id = (int) $req['id'];

		/**
		 * CSRF nonce enforcement:
		 * - Browser/cookie flows: require per-quote X-ADQ-Nonce (or nonce param).
		 * - Non-cookie auth (e.g., Application Password): skip CSRF nonce.
		 */
		$has_wp_auth_cookie = false;
		foreach ( array_keys( $_COOKIE ?? [] ) as $cookie_name ) {
			if ( str_starts_with( $cookie_name, 'wordpress_logged_in_' ) || str_starts_with( $cookie_name, 'wordpress_sec_' ) ) {
				$has_wp_auth_cookie = true;
				break;
			}
		}

		if ( $has_wp_auth_cookie ) {
			// Nonce requirement: wp_create_nonce('adq_approve_quote_{quote_id}')
			$nonce = (string) ( $req->get_header( 'X-ADQ-Nonce' ) ?: $req->get_param( 'nonce' ) );
			if ( ! wp_verify_nonce( $nonce, 'adq_approve_quote_' . $quote_id ) ) {
				return new WP_Error( 'adq_bad_nonce', 'Invalid nonce', [ 'status' => 403 ] );
			}
		}

		$po_number = (string) $req->get_param( 'po_number' );
		$po_number = trim( sanitize_text_field( $po_number ) );
		if ( $po_number === '' ) {
			return new WP_Error( 'adq_missing_po', 'Missing PO number', [ 'status' => 400 ] );
		}

		$files = $req->get_file_params();
		if ( empty( $files['po_file'] ) || empty( $files['po_file']['tmp_name'] ) ) {
			return new WP_Error( 'adq_missing_file', 'Missing PO file', [ 'status' => 400 ] );
		}

		$status = (string) get_post_meta( $quote_id, 'quote_status', true );
		if ( $status === '' ) { $status = 'draft'; }

		if ( $status !== 'sent' ) {
			return new WP_Error( 'adq_wrong_state', 'Quote must be in sent state to approve', [ 'status' => 400 ] );
		}

		// Store PO file privately.
		$upload = self::store_private_upload( $files['po_file'], 'po/' . $quote_id );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		// Immutable PO fields written once.
		ADQ_Immutability::with_immutable_write( static function () use ( $quote_id, $po_number, $upload ): void {
			update_post_meta( $quote_id, 'po_number', $po_number );
			update_post_meta( $quote_id, 'po_file_url', $upload['url'] );
			update_post_meta( $quote_id, 'po_received_at', gmdate( 'Y-m-d H:i:s' ) );
		} );


		$snap = (string) get_post_meta( $quote_id, 'quote_json_snapshot', true );
$data = json_decode( $snap, true );

if ( ! is_array( $data ) || empty( $data['result'] ) || ! is_array( $data['result'] ) ) {
	return new WP_Error(
		'adq_missing_snapshot',
		'Snapshot missing/invalid at approval time.',
		[
			'status' => 400,
			'snapshot_len' => strlen( $snap ),
			'json_error' => json_last_error_msg(),
			'snapshot_head' => substr( $snap, 0, 200 ),
		]
	);
}

		// Transitions: sent -> approved (client), then approved -> po_received (system).
		$res1 = ADQ_State_Machines::transition_quote_status(
			$quote_id,
			'approved',
			get_current_user_id(),
			[ 'reason' => 'client approval' ]
		);
		if ( is_wp_error( $res1 ) ) { return $res1; }

		$res2 = ADQ_State_Machines::transition_quote_status(
			$quote_id,
			'po_received',
			0,
			[ 'reason' => 'automatic transition', 'po_number' => $po_number ]
		);
		if ( is_wp_error( $res2 ) ) { return $res2; }

		// Seeding runs automatically on po_received hook; if successful, quote becomes converted.
		$project_id = (int) get_post_meta( $quote_id, 'converted_project_id', true );
		$final_status = (string) get_post_meta( $quote_id, 'quote_status', true );

		return new WP_REST_Response( [
			'quote_id' => $quote_id,
			'quote_status' => $final_status,
			'converted_project_id' => $project_id > 0 ? $project_id : null,
			'seeded' => $project_id > 0 && $final_status === 'converted',
		], 200 );
	}

	private static function store_private_upload( array $file, string $subdir ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'adq_uploads_error', $uploads['error'], [ 'status' => 500 ] );
		}

		$base_dir = trailingslashit( $uploads['basedir'] ) . 'adq-private/' . trim( $subdir, '/' ) . '/';
		$base_url = trailingslashit( $uploads['baseurl'] ) . 'adq-private/' . trim( $subdir, '/' ) . '/';

		if ( ! wp_mkdir_p( $base_dir ) ) {
			return new WP_Error( 'adq_mkdir_failed', 'Failed to create upload directory', [ 'status' => 500 ] );
		}

		$name = sanitize_file_name( $file['name'] ?? 'po' );
		if ( $name === '' ) { $name = 'po'; }

		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$stem = pathinfo( $name, PATHINFO_FILENAME );

		$final = $stem . '-' . time() . ( $ext ? '.' . $ext : '' );
		$dest  = $base_dir . $final;

		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'adq_move_failed', 'Failed to save uploaded file', [ 'status' => 500 ] );
		}

		return [
			'file' => $dest,
			'url'  => $base_url . $final,
		];
	}
}