<?php
if (!defined('ABSPATH')) { exit; }

final class ADO_PDF_Beta_Artifact_Store {
    private string $root_dir;
    private string $root_url;

    public function __construct() {
        $uploads = wp_upload_dir();
        $this->root_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . 'ado-pdf-beta';
        $this->root_url = trailingslashit((string) ($uploads['baseurl'] ?? '')) . 'ado-pdf-beta';
    }

    public function ensure_root(): void {
        if ($this->root_dir === '') {
            return;
        }
        wp_mkdir_p($this->root_dir);
    }

    public function next_upload_id(): int {
        $next = (int) get_option('ado_pdf_beta_next_upload_id', 1);
        update_option('ado_pdf_beta_next_upload_id', $next + 1, false);
        return $next;
    }

    public function prepare_run_dir(int $upload_id): array {
        $this->ensure_root();
        $dir = trailingslashit($this->root_dir) . 'upload-' . $upload_id;
        wp_mkdir_p($dir);
        return [
            'dir' => $dir,
            'url' => trailingslashit($this->root_url) . 'upload-' . $upload_id,
        ];
    }

    public function write_json(string $dir, string $filename, array $payload): string {
        $path = trailingslashit($dir) . $filename;
        wp_mkdir_p(dirname($path));
        file_put_contents($path, (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }
}
