<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ADQ_Audit {

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'adq_audit';
    }

    public static function install(): void {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            object_type     VARCHAR(40)  NOT NULL,
            object_id       BIGINT UNSIGNED NOT NULL,
            field_name      VARCHAR(80)  NOT NULL,
            old_value       LONGTEXT,
            new_value       LONGTEXT      NOT NULL,
            changed_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            changed_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address      VARCHAR(45),
            context         LONGTEXT,
            INDEX idx_object (object_type, object_id),
            INDEX idx_changed_by (changed_by),
            INDEX idx_changed_at (changed_at)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Writes an audit entry. Returns true on success; false on failure.
     *
     * Blueprint requirement: write audit BEFORE applying the transition.
     */
    public static function log(
        string $object_type,
        int $object_id,
        string $field_name,
        $old_value,
        $new_value,
        int $changed_by,
        array $context = []
    ): bool {
        global $wpdb;

        $table = self::table_name();

        $row = [
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'field_name'  => $field_name,
            'old_value'   => maybe_serialize( $old_value ),
            'new_value'   => maybe_serialize( $new_value ),
            'changed_by'  => $changed_by,
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
            'context'     => wp_json_encode( $context, JSON_UNESCAPED_SLASHES ),
        ];

        $ok = (bool) $wpdb->insert( $table, $row, [ '%s','%d','%s','%s','%s','%d','%s','%s' ] );
        return $ok;
    }
}
