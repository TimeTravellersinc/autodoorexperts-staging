<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ADQ_Immutability {

    /** Guard to allow internal immutable writes during creation/seeding. */
    private static bool $allow_immutable_write = false;

    public static function init(): void {
        add_filter( 'update_post_metadata', [ __CLASS__, 'block_immutable_meta_updates' ], 10, 5 );
    }

    /**
     * Runs a callback while allowing the first write of immutable fields (baseline_*, snapshot, etc).
     * This does NOT allow rewriting once already set; it only disables any additional, stricter checks
     * you may add later.
     */
public static function with_immutable_write( callable $fn ) {
    self::$allow_immutable_write = true;
    try {
        return $fn();
    } finally {
        self::$allow_immutable_write = false;
    }
}

    private static function is_adq_post( int $post_id ): bool {
        $post = get_post( $post_id );
        return ( $post && is_string( $post->post_type ) && str_starts_with( $post->post_type, 'adq_' ) );
    }

    private static function is_immutable_key( string $meta_key ): bool {
        if ( str_starts_with( $meta_key, 'baseline_' ) ) { return true; }
        if ( $meta_key === 'quote_json_snapshot' ) { return true; }
        if ( $meta_key === 'client_id' ) { return true; }
        if ( $meta_key === 'quote_id' ) { return true; }
        if ( $meta_key === 'project_id' ) { return true; }
        if ( $meta_key === 'door_id' ) { return true; }
        if ( $meta_key === 'revision_of' ) { return true; }
        if ( $meta_key === 'po_number' ) { return true; }
        if ( $meta_key === 'po_file_url' ) { return true; }
        if ( $meta_key === 'po_received_at' ) { return true; }
        return false;
    }

    /**
     * Blocks immutable meta keys from being changed once set.
     * Return true to short-circuit update.
     */
    public static function block_immutable_meta_updates( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
        $post_id = (int) $object_id;
        if ( self::$allow_immutable_write ) { return $check; }
        if ( $post_id <= 0 ) { return $check; }
        if ( ! self::is_adq_post( $post_id ) ) { return $check; }

        $meta_key = (string) $meta_key;
        if ( ! self::is_immutable_key( $meta_key ) ) { return $check; }

        $existing = get_post_meta( $post_id, $meta_key, true );
        if ( $existing === '' || $existing === null ) {
            // First write is allowed.
            return $check;
        }

        // Once set, immutable.
        return true;
    }
}
