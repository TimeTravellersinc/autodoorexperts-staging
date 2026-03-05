<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ADQ_Project_Seeder {

    /**
     * Seeds a project hierarchy from a quote's frozen JSON snapshot.
     *
     * Blueprint critical rule: ALWAYS seed from quote_json_snapshot (immutable).
     *
     * @return int|WP_Error Project ID or WP_Error
     */
    public static function create_project_from_quote( int $quote_id ) {
        $quote = get_post( $quote_id );
        if ( ! $quote || 'adq_quote' !== $quote->post_type ) {
            return new WP_Error( 'adq_bad_quote', 'Invalid quote', [ 'status' => 400 ] );
        }

        $snap = (string) get_post_meta( $quote_id, 'quote_json_snapshot', true );
        $data = json_decode( $snap, true );

    if ( ! is_array( $data ) || empty( $data['result'] ) || ! is_array( $data['result'] ) ) {
	    return new WP_Error(
		    'adq_bad_snapshot',
		    'Missing/invalid quote snapshot JSON. json_error=' . json_last_error_msg() . ' len=' . strlen( $snap ),
		    [ 'status' => 500 ]
	    );
    }

        $result = $data['result'];

        $client = (int) get_post_meta( $quote_id, 'client_id', true );
        if ( $client <= 0 ) {
            $client = (int) $quote->post_author;
        }

        return ADQ_Immutability::with_immutable_write( static function () use ( $quote_id, $client, $result ) {

            $project_name = (string) get_post_meta( $quote_id, 'project_name', true );
            if ( $project_name === '' ) {
                $project_name = 'Project — ' . $quote_id;
            }

            $proj_id = wp_insert_post( [
                'post_type'   => 'adq_project',
                'post_title'  => $project_name,
                'post_status' => 'publish',
                'post_author' => $client,
            ], true );

            if ( is_wp_error( $proj_id ) ) {
                return $proj_id;
            }

            update_post_meta( $proj_id, 'client_id', $client );
            update_post_meta( $proj_id, 'quote_id', $quote_id );
            update_post_meta( $proj_id, 'project_status', 'new' );
            update_post_meta( $proj_id, 'total_doors', (int) ( $result['door_count'] ?? 0 ) );
            update_post_meta( $proj_id, 'completed_doors', 0 );

            $doors = $result['doors'] ?? [];
            foreach ( $doors as $door ) {
                $door_num = (string) ( $door['door_id'] ?? '' );
                $desc     = (string) ( $door['desc'] ?? '' );

                $door_id = wp_insert_post( [
                    'post_type'   => 'adq_door',
                    'post_title'  => 'Door ' . $door_num . ' — ' . $desc,
                    'post_status' => 'publish',
                    'post_parent' => (int) $proj_id,
                ], true );

                if ( is_wp_error( $door_id ) ) {
                    return $door_id;
                }

                $op_signals   = $door['_scope_operator_signals'] ?? [];
                $has_operator = ! empty( $op_signals );

                update_post_meta( $door_id, 'project_id', (int) $proj_id );

                // BASELINE (immutable)
                update_post_meta( $door_id, 'baseline_door_number', $door_num );
                update_post_meta( $door_id, 'baseline_door_type', (string) ( $door['door_type'] ?? '' ) );
                update_post_meta( $door_id, 'baseline_location_desc', $desc );
                update_post_meta( $door_id, 'baseline_header_line', (string) ( $door['header_line'] ?? '' ) );
                update_post_meta( $door_id, 'baseline_has_operator', $has_operator ? 1 : 0 );
                update_post_meta( $door_id, 'baseline_op_signals', $op_signals );

                // LIVE (mutable)
                update_post_meta( $door_id, 'live_door_status', $has_operator ? 'not_started' : 'n_a_no_operator' );
                update_post_meta( $door_id, 'live_status_log', [] );
                update_post_meta( $door_id, 'live_photos', [] );
                update_post_meta( $door_id, 'live_photo_visibility', [] );

                $items = $door['items'] ?? [];
                foreach ( $items as $item ) {
                    if ( empty( $item['_scope_kept'] ) ) { continue; }

                    $title = (string) ( $item['catalog'] ?? $item['desc'] ?? 'Hardware' );
                    $title = mb_substr( $title, 0, 100 );

                    $hw_id = wp_insert_post( [
                        'post_type'   => 'adq_hardware',
                        'post_title'  => $title,
                        'post_status' => 'publish',
                        'post_parent' => (int) $door_id,
                    ], true );

                    if ( is_wp_error( $hw_id ) ) {
                        return $hw_id;
                    }

                    update_post_meta( $hw_id, 'door_id', (int) $door_id );
                    update_post_meta( $hw_id, 'project_id', (int) $proj_id );

                    // BASELINE (immutable)
                    update_post_meta( $hw_id, 'baseline_catalog', (string) ( $item['catalog'] ?? '' ) );
                    update_post_meta( $hw_id, 'baseline_desc', (string) ( $item['desc'] ?? '' ) );
                    update_post_meta( $hw_id, 'baseline_qty', (int) ( $item['qty'] ?? 1 ) );
                    update_post_meta( $hw_id, 'baseline_finish', (string) ( $item['finish'] ?? '' ) );
                    update_post_meta( $hw_id, 'baseline_raw_line', (string) ( $item['raw'] ?? '' ) );
                    update_post_meta( $hw_id, 'baseline_signals', $item['_scope_signals'] ?? [] );

                    // LIVE (mutable)
                    update_post_meta( $hw_id, 'live_installed_qty', 0 );
                    update_post_meta( $hw_id, 'live_missing_parts', 0 );
                    update_post_meta( $hw_id, 'live_missing_reason', '' );
                    update_post_meta( $hw_id, 'live_notes', '' );
                    update_post_meta( $hw_id, 'live_install_date', '' );
                }
            }

            // Link quote to the created project.
            update_post_meta( $quote_id, 'converted_project_id', (int) $proj_id );

            return (int) $proj_id;
        } );
    }
}
