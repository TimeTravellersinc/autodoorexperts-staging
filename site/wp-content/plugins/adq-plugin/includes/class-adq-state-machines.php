<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ADQ_State_Machines {

    private static bool $in_internal_transition = false;

    public static function init(): void {
        // Auto-seed when quote reaches po_received (Blueprint Stage 3 -> Stage 4).
        add_action( 'adq_quote_status_changed', [ __CLASS__, 'maybe_seed_project_on_po_received' ], 10, 4 );
    }

    /**
     * Quote status transition with validation + audit-first write.
     *
     * @param int $changed_by 0 for system transitions
     */
    public static function transition_quote_status(
        int $quote_id,
        string $to_status,
        int $changed_by,
        array $context = []
    ) {
        $quote = get_post( $quote_id );
        if ( ! $quote || 'adq_quote' !== $quote->post_type ) {
            return new WP_Error( 'adq_bad_quote', 'Invalid quote', [ 'status' => 400 ] );
        }

        $from_status = (string) get_post_meta( $quote_id, 'quote_status', true );
        if ( $from_status === '' ) { $from_status = 'draft'; }

        $to_status = sanitize_key( $to_status );

        $allowed = self::allowed_quote_transitions();

        if ( ! isset( $allowed[ $from_status ] ) || ! in_array( $to_status, $allowed[ $from_status ], true ) ) {
            return new WP_Error(
                'adq_illegal_transition',
                "Illegal quote transition: {$from_status} → {$to_status}",
                [ 'status' => 400 ]
            );
        }

        // Reject terminal rewrites.
        if ( in_array( $from_status, [ 'converted', 'void', 'declined' ], true ) ) {
            return new WP_Error( 'adq_terminal', 'Quote is terminal', [ 'status' => 400 ] );
        }

        // Audit first.
        $ok = ADQ_Audit::log(
            'adq_quote',
            $quote_id,
            'quote_status',
            $from_status,
            $to_status,
            $changed_by,
            $context
        );
        if ( ! $ok ) {
            return new WP_Error( 'adq_audit_failed', 'Audit log write failed', [ 'status' => 500 ] );
        }

        // Apply.
        self::$in_internal_transition = true;
        try {
            update_post_meta( $quote_id, 'quote_status', $to_status );
        } finally {
            self::$in_internal_transition = false;
        }

        do_action( 'adq_quote_status_changed', $quote_id, $from_status, $to_status, $changed_by );

        return true;
    }

public static function maybe_seed_project_on_po_received( int $quote_id, string $from, string $to, int $changed_by ): void {
	if ( $to !== 'po_received' ) { return; }

	// Seed exactly once.
	$existing = (int) get_post_meta( $quote_id, 'converted_project_id', true );
	if ( $existing > 0 ) { return; }

	$res = ADQ_Project_Seeder::create_project_from_quote( $quote_id );
	if ( is_wp_error( $res ) ) {
		ADQ_Audit::log(
			'adq_quote',
			$quote_id,
			'project_seed_error',
			'',
			$res->get_error_message(),
			0,
			[ 'reason' => 'seeding failed after po_received' ]
		);
		return;
	}

	$proj_id = (int) $res;

	// Persist the link (blueprint: quote tracks converted project).
	ADQ_Immutability::with_immutable_write( static function () use ( $quote_id, $proj_id ): void {
		update_post_meta( $quote_id, 'converted_project_id', $proj_id );
	} );

	// Mark quote as converted (system).
	self::transition_quote_status(
		$quote_id,
		'converted',
		0,
		[ 'reason' => 'automatic transition', 'project_id' => $proj_id ]
	);
}

    private static function allowed_quote_transitions(): array {
        return [
            'draft' => [ 'sent', 'void', 'extraction_failed' ],
            'extraction_failed' => [ 'draft' ],
            'sent' => [ 'approved', 'declined', 'revised', 'void' ],
            'approved' => [ 'po_received', 'void' ],
            'po_received' => [ 'converted' ],
            'converted' => [],
            'declined' => [ 'revised' ],
            'revised' => [],
            'void' => [],
        ];
    }
}
