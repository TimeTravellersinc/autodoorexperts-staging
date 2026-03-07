<?php
if (!defined('ABSPATH')) { exit; }

final class ADO_PDF_Beta_Pipeline {
    private ADO_PDF_Beta_Artifact_Store $store;
    private array $component_patterns;

    public function __construct(ADO_PDF_Beta_Artifact_Store $store) {
        $this->store = $store;
        $this->component_patterns = [
            'operator' => ['/\b(?:9531|9532|9533|9541|9542|9543|9551|9552|9553|9561|9562|9563|SW[\s\-]?800|SW[\s\-]?200|HA[\s\-]?8|AUTO(?:MATIC)?\s+DOOR\s+OPER|AUTO\s+OPENER|OPERATOR)\b/i'],
            'strike' => ['/\b(?:ELECTRIC(?:IFIED)?\s+STRIKE|1006(?:CS)?|6111|6211|6223|6300|9500)\b/i'],
            'actuator' => ['/\b(?:ACTUATOR|CM[\s\-]?45\/?[34]?|CM[\s\-]?60\/?2|CM[\s\-]?75|8310\-\d+|20\-057\-ICX|PUSH\s+BUTTON)\b/i'],
            'power_transfer' => ['/\b(?:EPT[\s\-]?10|POWER\s+TRANSFER)\b/i'],
            'power_supply' => ['/\b(?:POWER\s+SUPPLY|PS90X|PS902|PS904|PS210)\b/i'],
            'relay_interface' => ['/\b(?:RELAY|CON[\s\-]?6W|CX\-12|CX\-WEC|CX\-WC|WEC10|QELRX|QEL|INTERFACE|INTEGRATION\s+BOX)\b/i'],
            'mounting_plate' => ['/\b(?:MOUNT(?:ING)?\s+PLATE|ADAPTER\s+PLATE|9530\-18|9540\-18|9560\-18|4040XP\-18PA)\b/i'],
            'control_kit' => ['/\b(?:CONTROL\s+KIT|WASHROOM\s+CONTROL|NEXGEN|SE21A)\b/i'],
            'context' => ['/\b(?:BY\s+OTHERS|ACCESS\s+CONTROL|CARD\s+READER|INTERCOM|NURSE\s+CALL|NOTE|PROVIDE|VERIFY)\b/i'],
        ];
    }

    public function process_pdf(string $pdf_path, array $args = []): array {
        $upload_id = isset($args['upload_id']) ? (int) $args['upload_id'] : $this->store->next_upload_id();
        $paths = $this->store->prepare_run_dir($upload_id);
        $log = [
            'upload_id' => $upload_id,
            'pdf_path' => $pdf_path,
            'started_at' => current_time('c'),
            'diagnostics' => [],
        ];

        $extraction = $this->extract($pdf_path, $log);
        $intents = $this->interpret($extraction, $log);
        $scoped = $this->scope($intents, $log);
        $resolution = $this->resolve($scoped, $log);

        $log['finished_at'] = current_time('c');
        $log['counts'] = [
            'rows' => count((array) ($extraction['rows'] ?? [])),
            'intents' => count((array) ($intents['intents'] ?? [])),
            'quoteable' => count(array_filter((array) ($scoped['intents'] ?? []), static fn(array $i): bool => ($i['scope_status'] ?? '') === 'quoteable')),
            'review_required' => count(array_filter((array) ($resolution['items'] ?? []), static fn(array $i): bool => ($i['scope_status'] ?? '') === 'review_required')),
        ];

        $written = [
            'extraction' => $this->store->write_json($paths['dir'], 'extraction.json', $extraction),
            'intents' => $this->store->write_json($paths['dir'], 'intents.json', $intents),
            'resolution' => $this->store->write_json($paths['dir'], 'resolution.json', $resolution),
            'log' => $this->store->write_json($paths['dir'], 'upload-' . $upload_id . '.log.json', $log),
        ];

        return [
            'upload_id' => $upload_id,
            'run_dir' => $paths['dir'],
            'run_url' => $paths['url'],
            'artifacts' => $written,
            'extraction' => $extraction,
            'intents' => $intents,
            'resolution' => $resolution,
            'log' => $log,
        ];
    }

    private function extract(string $pdf_path, array &$log): array {
        $pages = $this->extract_native_text($pdf_path, $log);
        $quality = $this->score_extraction_quality($pages);
        if ($quality < 0.25) {
            $ocr_pages = $this->extract_ocr_text($pdf_path, $log);
            if ($ocr_pages) {
                $pages = $ocr_pages;
                $log['diagnostics'][] = ['stage' => 'extract', 'message' => 'ocr_fallback_used'];
            }
        }

        $rows = [];
        foreach ($pages as $page_no => $page_text) {
            $lines = preg_split("/\R/", str_replace("\r", "\n", (string) $page_text)) ?: [];
            foreach ($lines as $line_no => $line) {
                $normalized = $this->normalize_line($line);
                if ($normalized === '') {
                    continue;
                }
                foreach ($this->split_compound_line($normalized) as $segment_index => $segment) {
                    $rows[] = [
                        'row_id' => 'r-' . substr(md5(($page_no + 1) . '|' . ($line_no + 1) . '|' . $segment), 0, 12),
                        'page' => $page_no + 1,
                        'line_number' => $line_no + 1,
                        'segment_index' => $segment_index,
                        'raw_line' => trim((string) $line),
                        'normalized_line' => $segment,
                        'provenance' => ['page' => $page_no + 1, 'line_number' => $line_no + 1],
                    ];
                }
            }
        }

        return [
            'meta' => [
                'source_pdf' => $pdf_path,
                'quality_score' => $quality,
                'page_count' => count($pages),
            ],
            'rows' => $rows,
        ];
    }

    private function interpret(array $extraction, array &$log): array {
        $intents = [];
        foreach ((array) ($extraction['rows'] ?? []) as $row) {
            $line = (string) ($row['normalized_line'] ?? '');
            $door_id = $this->infer_door_id($line);
            foreach ($this->component_patterns as $component_type => $patterns) {
                foreach ($patterns as $pattern) {
                    if (!preg_match($pattern, $line, $match)) {
                        continue;
                    }
                    $model_raw = trim((string) ($match[0] ?? ''));
                    $intents[] = [
                        'intent_id' => 'i-' . substr(md5(($row['row_id'] ?? '') . '|' . $component_type . '|' . $model_raw), 0, 12),
                        'door_id' => $door_id,
                        'component_type' => $component_type,
                        'model_raw' => $model_raw,
                        'model_normalized' => strtoupper(preg_replace('/[^A-Z0-9]+/', '', $model_raw) ?: ''),
                        'qty' => $this->extract_qty($line),
                        'attributes' => [
                            'line_tokens' => $this->extract_attributes($line),
                        ],
                        'confidence' => $component_type === 'context' ? 0.5 : 0.8,
                        'source' => [
                            'page' => $row['page'],
                            'line_number' => $row['line_number'],
                            'raw_line' => $row['raw_line'],
                        ],
                        'source_row_id' => $row['row_id'],
                    ];
                    break;
                }
            }
        }

        $log['diagnostics'][] = ['stage' => 'interpret', 'message' => 'generated_intents', 'count' => count($intents)];
        return ['intents' => $intents];
    }

    private function scope(array $intents, array &$log): array {
        $out = [];
        foreach ((array) ($intents['intents'] ?? []) as $intent) {
            $line = strtoupper((string) ($intent['source']['raw_line'] ?? ''));
            $status = 'review_required';
            $reason = 'needs_review';
            if (strpos($line, 'BY OTHERS') !== false || strpos($line, 'BY OWNER') !== false) {
                $status = 'excluded';
                $reason = 'by_others';
            } elseif (($intent['component_type'] ?? '') === 'context') {
                $status = 'excluded';
                $reason = 'context_only';
            } elseif (preg_match('/\b(?:OPERATOR|AUTO(?:MATIC)?\s+DOOR|ELECTRIC|QEL|ACTUATOR|POWER\s+SUPPLY|POWER\s+TRANSFER|RELAY|MOUNT(?:ING)?\s+PLATE)\b/i', $line)) {
                $status = 'quoteable';
                $reason = 'ado_scope_signal';
            }
            $intent['scope_status'] = $status;
            $intent['scope_reason'] = ['code' => $reason];
            $out[] = $intent;
        }
        $log['diagnostics'][] = ['stage' => 'scope', 'message' => 'scoped_intents', 'count' => count($out)];
        return ['intents' => $out];
    }

    private function resolve(array $scoped, array &$log): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ado_pdf_beta_aliases';
        $items = [];
        foreach ((array) ($scoped['intents'] ?? []) as $intent) {
            $normalized = (string) ($intent['model_normalized'] ?? '');
            $component_type = (string) ($intent['component_type'] ?? '');
            $scope_status = (string) ($intent['scope_status'] ?? 'review_required');
            $candidates = [];
            $chosen = null;
            $method = 'none';

            if ($normalized !== '') {
                $mapping = $wpdb->get_row($wpdb->prepare(
                    "SELECT product_id, match_type FROM {$table} WHERE component_type=%s AND normalized_key=%s ORDER BY id DESC LIMIT 1",
                    $component_type,
                    $normalized
                ), ARRAY_A);
                if (is_array($mapping) && (int) ($mapping['product_id'] ?? 0) > 0) {
                    $chosen = (int) $mapping['product_id'];
                    $method = (string) ($mapping['match_type'] ?? 'alias_table');
                }
            }

            if ($chosen === null && function_exists('ado_qm_get_index')) {
                $index = ado_qm_get_index();
                $matches = (array) ($index['global_models'][$normalized] ?? []);
                if (count($matches) === 1) {
                    $chosen = (int) $matches[0];
                    $method = 'exact_mapping_table';
                } else {
                    $candidates = $this->build_candidates_from_index($normalized, $component_type, $index);
                    if (!empty($candidates[0]['score']) && (float) $candidates[0]['score'] >= 0.85 && $this->component_matches_product_family($component_type, (array) ($index['products'][(int) $candidates[0]['product_id']] ?? []))) {
                        $chosen = (int) $candidates[0]['product_id'];
                        $method = 'fuzzy_auto';
                    }
                }
            }

            $item = $intent;
            $item['resolver'] = [
                'method' => $method,
                'selected_product_id' => $chosen,
                'candidates' => array_slice($candidates, 0, 3),
            ];
            if ($scope_status !== 'quoteable' || ($chosen === null && $scope_status !== 'excluded')) {
                $item['scope_status'] = $scope_status === 'excluded' ? 'excluded' : 'review_required';
            }
            $items[] = $item;
        }
        $log['diagnostics'][] = ['stage' => 'resolve', 'message' => 'resolved_items', 'count' => count($items)];
        return ['items' => $items];
    }

    private function extract_native_text(string $pdf_path, array &$log): array {
        $tmp = wp_tempnam($pdf_path);
        $pages = [];
        if ($tmp && $this->command_exists('pdftotext')) {
            $cmd = 'pdftotext -layout -enc UTF-8 ' . escapeshellarg($pdf_path) . ' ' . escapeshellarg($tmp);
            exec($cmd . ' 2>&1', $output, $rc);
            $log['diagnostics'][] = ['stage' => 'extract', 'message' => 'pdftotext', 'rc' => $rc];
            if ($rc === 0 && file_exists($tmp)) {
                $text = (string) file_get_contents($tmp);
                $pages = preg_split("/\f/", $text) ?: [];
            }
            @unlink($tmp);
        }
        return array_values(array_filter(array_map('strval', $pages), static fn(string $page): bool => trim($page) !== ''));
    }

    private function extract_ocr_text(string $pdf_path, array &$log): array {
        if (!$this->command_exists('pdftoppm') || !$this->command_exists('tesseract')) {
            $log['diagnostics'][] = ['stage' => 'extract', 'message' => 'ocr_unavailable'];
            return [];
        }
        $tmp_base = wp_tempnam($pdf_path);
        if (!$tmp_base) {
            return [];
        }
        @unlink($tmp_base);
        $img_prefix = $tmp_base . '-page';
        exec('pdftoppm -png ' . escapeshellarg($pdf_path) . ' ' . escapeshellarg($img_prefix) . ' 2>&1', $output, $rc);
        if ($rc !== 0) {
            return [];
        }
        $pages = [];
        foreach (glob($img_prefix . '-*.png') ?: [] as $image_path) {
            $out_base = $image_path . '-ocr';
            exec('tesseract ' . escapeshellarg($image_path) . ' ' . escapeshellarg($out_base) . ' 2>&1', $t_output, $t_rc);
            if ($t_rc === 0 && file_exists($out_base . '.txt')) {
                $pages[] = (string) file_get_contents($out_base . '.txt');
                @unlink($out_base . '.txt');
            }
            @unlink($image_path);
        }
        return $pages;
    }

    private function score_extraction_quality(array $pages): float {
        $text = implode("\n", $pages);
        $len = strlen($text);
        if ($len === 0) {
            return 0.0;
        }
        $printable = preg_match_all('/[A-Za-z0-9]/', $text);
        return $printable > 0 ? min(1.0, $printable / max(1, $len)) : 0.0;
    }

    private function normalize_line(string $line): string {
        $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $line = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", 'â€“', 'â€”'], '-', $line);
        $line = preg_replace('/\s+/', ' ', $line);
        $line = trim((string) $line);
        return str_replace(['0PERAT0R', '0PERATOR'], ['OPERATOR', 'OPERATOR'], strtoupper($line));
    }

    private function split_compound_line(string $line): array {
        $parts = preg_split('/\s+(?=\d+\s+(?:AUTO|ACTUATOR|MOUNT|POWER|RELAY|ELECTRIC|OPERATOR))/i', $line) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), 'strlen'));
        return $parts ?: [$line];
    }

    private function infer_door_id(string $line): string {
        if (preg_match('/\b(?:DOOR\s+)?([A-Z]?[A-Z0-9]+(?:[.\-_\/][A-Z0-9]+)*)\b/i', $line, $m)) {
            return strtoupper((string) ($m[1] ?? 'UNKNOWN'));
        }
        return 'UNKNOWN';
    }

    private function extract_qty(string $line): int {
        return preg_match('/^\s*(\d+)\b/', $line, $m) ? max(1, (int) $m[1]) : 1;
    }

    private function extract_attributes(string $line): array {
        $attrs = [];
        foreach (['QEL', 'QELRX', 'PS90X', 'CX-12', 'CX-WC', 'CX-WEC', 'CON-6W', 'EPT-10'] as $token) {
            if (stripos($line, $token) !== false) {
                $attrs[] = $token;
            }
        }
        return $attrs;
    }

    private function build_candidates_from_index(string $normalized, string $component_type, array $index): array {
        $pool = [];
        foreach ((array) ($index['numeric_heads'][substr($normalized, 0, 3)] ?? []) as $product_id) {
            $pool[] = (int) $product_id;
        }
        if (!$pool) {
            $pool = array_slice(array_keys((array) ($index['products'] ?? [])), 0, 500);
        }
        $rows = [];
        foreach (array_values(array_unique(array_map('intval', $pool))) as $product_id) {
            $product = (array) ($index['products'][$product_id] ?? []);
            if (!$product) {
                continue;
            }
            $best = 0;
            foreach ((array) ($product['model_map'] ?? []) as $product_model => $meta) {
                similar_text($normalized, (string) $product_model, $pct);
                $best = max($best, round($pct / 100, 4));
            }
            if ($best < 0.45) {
                continue;
            }
            $rows[] = [
                'product_id' => $product_id,
                'title' => (string) ($product['title'] ?? ''),
                'sku' => (string) ($product['sku'] ?? ''),
                'score' => $best,
                'component_type' => $component_type,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => (($b['score'] <=> $a['score']) ?: ($a['product_id'] <=> $b['product_id'])));
        return array_slice($rows, 0, 3);
    }

    private function component_matches_product_family(string $component_type, array $product): bool {
        $title = strtoupper((string) ($product['title'] ?? ''));
        $map = [
            'operator' => ['OPERATOR', 'AUTO', '9500', '9530', '9540', '9550', '9560', 'SW'],
            'strike' => ['STRIKE', 'HES', '6111', '6211', '6223', '6300'],
            'actuator' => ['ACTUATOR', 'PUSH', 'CM-', '8310'],
            'power_transfer' => ['EPT', 'TRANSFER'],
            'power_supply' => ['POWER', 'PS'],
            'relay_interface' => ['RELAY', 'CX-', 'QEL', 'CON-6W'],
            'mounting_plate' => ['PLATE', 'MOUNT'],
            'control_kit' => ['CONTROL', 'KIT', 'NEXGEN'],
        ];
        foreach ((array) ($map[$component_type] ?? []) as $needle) {
            if (strpos($title, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function command_exists(string $command): bool {
        $where = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where' : 'command -v';
        exec($where . ' ' . escapeshellarg($command) . ' 2>NUL', $output, $rc);
        return $rc === 0;
    }
}
