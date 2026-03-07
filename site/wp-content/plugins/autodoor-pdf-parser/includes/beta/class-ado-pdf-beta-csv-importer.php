<?php
if (!defined('ABSPATH')) { exit; }

final class ADO_PDF_Beta_CSV_Importer {
    public static function import_alias_csv(string $csv_path): array {
        global $wpdb;
        if (!file_exists($csv_path)) {
            return ['ok' => false, 'message' => 'CSV not found'];
        }
        $handle = fopen($csv_path, 'r');
        if (!$handle) {
            return ['ok' => false, 'message' => 'Failed to open CSV'];
        }
        $header = fgetcsv($handle);
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header ?: [], $row ?: []);
            if (!is_array($data)) {
                continue;
            }
            $component_type = sanitize_key((string) ($data['component_type'] ?? ''));
            $normalized_key = strtoupper(preg_replace('/[^A-Z0-9]+/', '', (string) ($data['normalized_key'] ?? '')) ?: '');
            $product_id = (int) ($data['product_id'] ?? 0);
            if ($component_type === '' || $normalized_key === '' || $product_id <= 0) {
                continue;
            }
            $wpdb->insert($wpdb->prefix . 'ado_pdf_beta_aliases', [
                'component_type' => $component_type,
                'normalized_key' => $normalized_key,
                'product_id' => $product_id,
                'match_type' => 'csv_alias',
                'source' => 'csv_import',
                'created_at' => current_time('mysql'),
            ]);
            $count++;
        }
        fclose($handle);
        return ['ok' => true, 'imported' => $count];
    }
}
