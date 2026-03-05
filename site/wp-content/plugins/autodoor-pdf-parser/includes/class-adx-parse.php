<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ADX_Parser (rewritten to mimic the earlier AutoDoorPDFDebug_Hostinger core parsing style)
 * - Same overall flow: normalize -> preclean -> detect format -> split sections -> infer schema per section -> extract items
 * - Keeps ADX_Debug injection, but uses a dbg() helper mirroring the prior timestamp/memory style.
 * - Supports: heading blocks, heading->door sub-splitting, door blocks, hardware groups (basic)
 * - Supports schema inference: explicit header -> implicit columns -> token mode
 * - Supports wrapped-line joining in token mode (model-ish continuation lines)
 *
 * FIX (2026-02-22):
 * - Some “doors” have multiple door headers merged into one physical line by pdftotext.
 *   Example: D-C.0.010.4 line also contains "1 Single door D-C.0.010.5".
 *   Solution: inject newlines before embedded door headers BEFORE splitting.
 */
class ADX_Parser {

    /** @var ADX_Debug */
    private $dbg;

    // Document profile (computed per parse)
    private $doc_profile = [
        'door_id_style' => 'alpha_numeric',
        'known_door_ids' => [],
    ];

    public function __construct(ADX_Debug $dbg) {
        $this->dbg = $dbg;
    }

    // ---------------------------
    // DEBUG HELPER (mimics prior)
    // ---------------------------
    private function dbg($channel, $msg) {
        $ts  = sprintf('%.3f', microtime(true));
        $mem = (int) round(memory_get_usage(true) / (1024*1024));
        $this->dbg->log($channel, "[$ts][{$mem}MB] " . $msg);
    }

    // ---------------------------
    // Normalizers / scrubbers
    // ---------------------------
    private function normalize_text($t) {
        $t = str_replace("\r\n", "\n", (string)$t);
        $t = str_replace("\r", "\n", $t);
        $t = str_replace("\t", " ", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        $t = str_replace(["–","—","“","”","’"], ["-","-","\"","\"","'"], $t);
        return $t ?? '';
    }

    private function normalize_line($ln) {
        $ln = str_replace("\t", " ", (string)$ln);
        $ln = str_replace(["–","—"], "-", $ln);
        return rtrim($ln);
    }

    private function scrub_page_markers($s) {
        $s = (string)$s;
        $s = preg_replace('/\bPage\s+\d+\s+of\s+\d+\b/i', ' ', $s);
        $s = preg_replace('/(?<!\d)\d+\s+of\s+\d+(?!\d)/i', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    // -------------------------------------------------
    // NEW: fix "two door headers on one line" artifacts
    // -------------------------------------------------
    private function inject_newlines_before_door_headers($text) {
        $text = (string)$text;

        // Insert newline before "N SINGLE DOOR ..." / "N PAIR OF DOORS ..." / "N ELEVATION ..."
        // ONLY when it is NOT already at the beginning of a line.
        // This fixes pdftotext cases where multiple headers are merged onto one line.
        $before = $text;
        $text = preg_replace(
            '/([^\n])\s+(\d+\s+(SINGLE\s+DOOR|PAIR\s+OF\s+DOORS|ELEVATION)\s+[A-Z0-9][A-Z0-9\.\-_\/]+)/i',
            "$1\n$2",
            $text
        );

        // optional light debug: count occurrences by diffing header starts
        if ($text !== $before) {
            $added = 0;
            if (preg_match_all('/\n\d+\s+(SINGLE\s+DOOR|PAIR\s+OF\s+DOORS|ELEVATION)\s+/i', $text, $mm)) {
                $added = (int)count($mm[0]);
            }
            $this->dbg('split', "inject_newlines_before_door_headers: applied (door_header_linebreaks_detected={$added})");
        }

        return $text ?? '';
    }

    // ===========================
    // PUBLIC ENTRY
    // ===========================
    public function adaptive_parse($text) {
        $this->dbg('parse', "=== adaptive_parse: begin ===");

        $text = $this->normalize_text($text);
        $text = preg_replace('/\s+Page\s+\d+\s+of\s+\d+\s+/i', "\n", $text);

        // NEW: undo pdftotext "two door headers on one line" merges (must happen before preclean/splitting)
        $text = $this->inject_newlines_before_door_headers($text);

        $pre  = $this->preclean_for_structure($text);
        $text = $pre['text'];
        $this->dbg('split', "Preclean: removed_lines={$pre['removed_lines']}, repeat_candidates={$pre['repeat_candidates']}");

        $det = $this->detect_format_and_splitter($text);

        $this->doc_profile = $this->compute_doc_profile($text);
        $this->dbg('split', "Doc door_id_style: " . ($this->doc_profile['door_id_style'] ?? 'alpha_numeric'));
        $this->dbg('split', "Doc known_door_ids: " . count($this->doc_profile['known_door_ids'] ?? []));

        $this->dbg('split', "Detected format: " . ($det['format'] ?? 'unknown'));
        $this->dbg('split', "Splitter: " . ($det['splitter'] ?? '(none)'));
        $this->dbg('split', "Split scores: " . wp_json_encode($det['scores'] ?? []));

        $sections = $this->split_sections($text, $det);
        $this->dbg('split', "Sections found: " . count($sections));

        // debug: duplicate door blocks
        $doorKeyCount = [];
        foreach ($sections as $s) {
            if (($s['type'] ?? '') === 'door_block') {
                $k = (string)($s['key'] ?? '');
                if ($k !== '') $doorKeyCount[$k] = ($doorKeyCount[$k] ?? 0) + 1;
            }
        }
        $dupes = array_filter($doorKeyCount, function($c){ return $c > 1; });
        if (!empty($dupes)) {
            $this->dbg('split', "WARNING: duplicate door_block keys detected: " . wp_json_encode($dupes));
        }

        $doors = [];
        $schema_stats = [
            'seen_headers' => 0,
            'mode_counts' => ['columns'=>0,'tokens'=>0],
            'implicit_columns_hits' => 0,
            'implicit_columns_rejects' => 0,
            'implicit_columns_refined' => 0,
            'implicit_fraction_rejects' => 0,
            'token_note_continuations' => 0,
            'token_finish_extractions' => 0,
            'token_catalog_extractions' => 0,
            'token_mfg_extractions' => 0,
            'door_blocks_parsed' => 0,
            'door_blocks_empty' => 0,
        ];

        foreach ($sections as $sec) {
            $type = $sec['type'] ?? 'unknown';
            $key  = $sec['key'] ?? '';
            $body = (string)($sec['body'] ?? '');
            $this->dbg('parse', "parse_section: type={$type} key={$key} body_chars=" . strlen($body));

            $parsed = $this->parse_section($sec, $schema_stats);
            if (!empty($parsed)) {
                if (isset($parsed[0]) && is_array($parsed[0]) && array_key_exists('door_id', $parsed[0])) {
                    // multi-door list style return
                    foreach ($parsed as $d) $doors[] = $d;
                } else {
                    // single door return
                    $doors[] = $parsed;
                }
            }
        }

        if (count($doors) === 0) {
            $this->dbg('parse', "No doors from sections; using fallback scan");
            $doors = $this->fallback_scan_doors($text);
        }

        $this->dbg('parse', "=== adaptive_parse: done doors=" . count($doors) . " ===");

        return [
            'format' => $det['format'] ?? 'unknown',
            'door_count' => count($doors),
            'doors' => $doors,
            'schema_stats' => $schema_stats,
            'doc_profile' => [
                'door_id_style' => $this->doc_profile['door_id_style'] ?? null,
                'known_door_ids_count' => count($this->doc_profile['known_door_ids'] ?? []),
            ],
        ];
    }

    // ===========================
    // PRE-CLEAN
    // ===========================
    private function preclean_for_structure($text) {
        $lines = preg_split("/\R/", $this->normalize_text($text));
        $lines = array_map([$this, 'normalize_line'], $lines);

        $counts = [];
        foreach ($lines as $ln) {
            $t = trim($ln);
            if ($t === '') continue;
            if (strlen($t) < 6) continue;
            $u = strtoupper($t);
            $counts[$u] = ($counts[$u] ?? 0) + 1;
        }

        $repeat = [];
        $repeatCandidates = 0;
        foreach ($counts as $u => $c) {
            if ($c >= 3) { $repeat[$u] = $c; $repeatCandidates++; }
        }

        $removed = 0;
        $out = [];
        foreach ($lines as $ln) {
            $t = trim($ln);
            if ($t === '') { $out[] = $ln; continue; }

            if (preg_match('/^PAGE\s+\d+(\s+OF\s+\d+)?$/i', $t)) { $removed++; continue; }

            $u = strtoupper($t);
            if (isset($repeat[$u]) && $this->is_header_footer_candidate($t) && !$this->looks_like_item_row($t)) {
                $removed++;
                continue;
            }

            if (preg_match('/\b(TEL|PHONE|FAX)\b/i', $t) && preg_match('/\d{3}[\)\-\s]\d{3}[\-\s]\d{4}/', $t)) {
                $removed++; continue;
            }

            $out[] = $ln;
        }

        return [
            'text' => trim(implode("\n", $out)),
            'removed_lines' => $removed,
            'repeat_candidates' => $repeatCandidates,
        ];
    }

    private function is_header_footer_candidate($line) {
        $t = trim((string)$line);
        if ($t === '') return false;

        if (preg_match('/\b(ISSUED|REVISED|PROJECT|CONSULTANT|ARCHITECT|ENGINEER|ADDENDUM|SHEET|SCHEDULE)\b/i', $t)) return true;
        if (preg_match('/\b(PAGE)\b/i', $t)) return true;

        $letters = preg_match_all('/[A-Z]/i', $t);
        $digits  = preg_match_all('/\d/', $t);
        if ($letters >= 12 && $digits <= 2 && strlen($t) <= 90) return true;

        return false;
    }

    private function looks_like_item_row($trimmedLine) {
        $t = trim((string)$trimmedLine);
        if ($t === '') return false;
        if (preg_match('/^\s*\d{1,3}\s+/', $t)) return true;
        if (preg_match('/\S\s{2,}\S/', $t) && preg_match('/\d/', $t)) return true;
        return false;
    }

    // ===========================
    // DOC PROFILE
    // ===========================
    private function compute_doc_profile($text) {
        $known = [];
        $styleCounts = ['numeric_only'=>0,'dotted'=>0,'alpha_numeric'=>0];

        if (preg_match_all('/^\s*(\d+)\s+(SINGLE\s+DOOR|PAIR\s+OF\s+DOORS|ELEVATION)\s+([A-Z0-9][A-Z0-9\.\-_\/]+)\s*(.*)$/im', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $id = strtoupper(trim($row[3] ?? ''));
                if ($id !== '') $known[$id] = true;
            }
        }
        if (preg_match_all('/\b(Single door|Pair of doors|Elevation)\s+([A-Z0-9\.\-_\/]+)\b/i', $text, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $row) {
                $id = strtoupper(trim($row[2] ?? ''));
                if ($id !== '') $known[$id] = true;
            }
        }

        $i = 0;
        foreach ($known as $id => $_) {
            if ($i++ > 80) break;
            if (preg_match('/^\d+$/', $id)) $styleCounts['numeric_only']++;
            else if (strpos($id, '.') !== false) $styleCounts['dotted']++;
            else $styleCounts['alpha_numeric']++;
        }

        arsort($styleCounts);
        $style = key($styleCounts);
        if (!$style) $style = 'alpha_numeric';

        return ['door_id_style'=>$style, 'known_door_ids'=>$known];
    }

    // ===========================
    // DETECT FORMAT
    // ===========================
    private function detect_format_and_splitter($text) {
        $scores = ['heading'=>0,'hardware_group'=>0,'door_blocks'=>0];

        $scores['heading'] = preg_match_all('/\bHeading\s*#\s*[A-Z0-9\-]+\b/i', $text, $m1);
        $scores['hardware_group'] = preg_match_all('/\bHardware\s+Group\s+No\.\s*[A-Z0-9]+\b/i', $text, $m2);
        $scores['door_blocks'] = preg_match_all('/^\s*\d+\s+(SINGLE\s+DOOR|PAIR\s+OF\s+DOORS|ELEVATION)\s+([A-Z0-9][A-Z0-9\.\-_\/]+)(?:\s{2,}|\s+|$)/im', $text, $m3);

        arsort($scores);
        $bestFormat = key($scores);
        $bestScore  = current($scores);

        if (!is_int($bestScore) || $bestScore <= 0) {
            return ['format'=>'unknown', 'splitter'=>null, 'scores'=>$scores];
        }
        if ($bestFormat === 'heading') return ['format'=>'heading', 'splitter'=>'Heading #', 'scores'=>$scores];
        if ($bestFormat === 'hardware_group') return ['format'=>'hardware_group', 'splitter'=>'Hardware Group No.', 'scores'=>$scores];
        return ['format'=>'door_blocks', 'splitter'=>'Door header lines (Single/Pair/Elevation)', 'scores'=>$scores];
    }

    // ===========================
    // SPLITTING (with door sub-splitting inside headings)
    // ===========================
    private function split_sections($text, $det) {
        $format = $det['format'] ?? 'unknown';

        if ($format === 'heading') {
            $s = $this->split_by_heading($text);
            if (!empty($s)) {
                $s2 = $this->expand_heading_blocks_into_door_blocks($s);
                return !empty($s2) ? $s2 : $s;
            }
        }

        if ($format === 'hardware_group') {
            $s = $this->split_by_hardware_group($text);
            if (!empty($s)) return $s;
        }

        if ($format === 'door_blocks') {
            $s = $this->split_by_door_blocks($text);
            if (!empty($s)) return $s;
        }

        // fallback order
        $s = $this->split_by_door_blocks($text); if (!empty($s)) return $s;

        $s = $this->split_by_heading($text);
        if (!empty($s)) {
            $s2 = $this->expand_heading_blocks_into_door_blocks($s);
            return !empty($s2) ? $s2 : $s;
        }

        $s = $this->split_by_hardware_group($text); if (!empty($s)) return $s;

        return [];
    }

    private function split_by_heading($text) {
        $pattern = '/\bHeading\s*#\s*([A-Z0-9\-]+)\b(.*?)(?=\bHeading\s*#\s*[A-Z0-9\-]+\b|\z)/is';
        preg_match_all($pattern, $text, $m, PREG_SET_ORDER);

        $blocks = [];
        foreach ($m as $row) {
            $blocks[] = ['type'=>'heading','key'=>trim($row[1]),'body'=>trim($row[2] ?? '')];
        }

        $this->dbg('split', "split_by_heading: blocks=" . count($blocks));
        return $blocks;
    }

    // door sub-splitting to prevent multi-door headings from merging doors
    private function expand_heading_blocks_into_door_blocks($headingBlocks) {
        $out = [];
        $headings = 0;
        $expanded = 0;
        $kept = 0;

        $lineEnd = function(string $text, int $start): int {
            $p = strpos($text, "\n", $start);
            return ($p === false) ? strlen($text) : ($p + 1);
        };

        foreach ($headingBlocks as $hb) {
            $headings++;
            $headingKey = (string)($hb['key'] ?? '');
            $body       = (string)($hb['body'] ?? '');

            if (trim($body) === '') { $out[] = $hb; $kept++; continue; }

            // Fix merged headers inside heading bodies (pdftotext sometimes glues them together)
            $body = $this->inject_newlines_before_door_headers($body);

            $doorHits = $this->find_loose_door_header_hits($body);
            $hitCount = count($doorHits);

            if ($hitCount <= 0) { $out[] = $hb; $kept++; continue; }

            $this->dbg('split', "expand_heading: heading={$headingKey} door_headers={$hitCount}");
            $expanded += $hitCount;

            $i = 0;
            while ($i < $hitCount) {
                $group = [$i];
                $last = $i;

                $lastEnd = $lineEnd($body, (int)$doorHits[$last]['offset']);

                // Group consecutive door headers when no hardware lines appear between them.
                while (($last + 1) < $hitCount) {
                    $nextOffset = (int)$doorHits[$last + 1]['offset'];
                    if ($nextOffset < $lastEnd) break;

                    $between = substr($body, $lastEnd, $nextOffset - $lastEnd);
                    if (trim($between) !== '') break;

                    $last++;
                    $group[] = $last;
                    $lastEnd = $lineEnd($body, (int)$doorHits[$last]['offset']);
                }

                $tailEnd = (($last + 1) < $hitCount) ? (int)$doorHits[$last + 1]['offset'] : strlen($body);
                if ($tailEnd < $lastEnd) $tailEnd = $lastEnd;

                $sharedTail = substr($body, $lastEnd, $tailEnd - $lastEnd);
                $sharedTail = ltrim($sharedTail, "\r\n");
                $groupSize = count($group);
                $tailForEachDoor = $this->normalize_group_shared_tail_for_each_door($sharedTail, $groupSize);

                if ($groupSize > 1) {
                    $this->dbg('split', "expand_heading_group: heading={$headingKey} group_size={$groupSize} grouped_with=" . implode(',', array_map(function($idx) use ($doorHits) { return $doorHits[$idx]['door_id']; }, $group)));
                }

                foreach ($group as $gi) {
                    $start = (int)$doorHits[$gi]['offset'];
                    $end   = $tailEnd;

                    $hdrEnd = $lineEnd($body, $start);
                    $headerLine = substr($body, $start, max(0, $hdrEnd - $start));
                    $block = rtrim($headerLine);
                    if ($tailForEachDoor !== '') $block .= "\n" . $tailForEachDoor;

                    $block = $this->strip_dimension_lines_after_header($block);

                    $out[] = [
                        'type' => 'door_block',
                        'key'  => $doorHits[$gi]['door_id'],
                        'meta' => [
                            'seq' => $doorHits[$gi]['seq'],
                            'door_type' => $doorHits[$gi]['door_type'],
                            'door_id' => $doorHits[$gi]['door_id'],
                            'desc' => $doorHits[$gi]['desc'],
                            'header_line' => $doorHits[$gi]['line'],
                            'heading' => $headingKey,
                            'source_from_heading' => true,
                            'slice_start' => $start,
                            'slice_end' => $end,
                            'group_size' => count($group),
                            'grouped_with' => array_map(function($idx) use ($doorHits) { return $doorHits[$idx]['door_id']; }, $group),
                        ],
                        'body' => trim($block),
                    ];
                }

                $i = $last + 1;
            }
        }

        $this->dbg('split', "Heading->DoorBlocks: headings={$headings}, kept_as_heading={$kept}, expanded_doors={$expanded}");
        return $out;
    }

    /**
     * If multiple door headers share one hardware tail, quantities in the tail are often
     * aggregate totals for the whole group. Normalize per-door by dividing leading qty
     * when group size > 1.
     */
    private function normalize_group_shared_tail_for_each_door($sharedTail, $groupSize) {
        $tail = trim((string)$sharedTail);
        if ($tail === '' || (int)$groupSize <= 1) return $tail;

        $lines = preg_split("/\R/", $this->normalize_text($tail));
        $out = [];

        foreach ($lines as $ln) {
            $line = trim((string)$ln);
            if ($line === '') continue;

            // Normalize common item row form: "<qty> <desc...>"
            if (preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
                $qty = (int)$m[1];
                $rest = $m[2];

                if ($qty >= $groupSize) {
                    $perDoor = ($qty % $groupSize === 0)
                        ? (int)($qty / $groupSize)
                        : (int)max(1, round($qty / $groupSize));
                    $line = $perDoor . ' ' . $rest;
                }
            }

            $out[] = $line;
        }

        return trim(implode("\n", $out));
    }

    private function find_loose_door_header_hits($text) {
        $hits = [];
        $pattern = '/^\s*(\d+)\s+(Single\s+door|Pair\s+of\s+doors|Elevation)\s+([A-Z0-9][A-Z0-9\.\-_\/]+)\s*(?:,|\s)\s*(.*)$/im';
        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) return $hits;

        $count = count($m[0]);
        for ($i=0; $i<$count; $i++) {
            $seq  = trim((string)$m[1][$i][0]);
            $type = strtolower(trim((string)$m[2][$i][0]));
            $did  = strtoupper(trim((string)$m[3][$i][0]));
            $tail = trim((string)($m[4][$i][0] ?? ''));

            $hits[] = [
                'offset' => (int)$m[0][$i][1],
                'seq' => $seq,
                'door_type' => $type,
                'door_id' => $did,
                'desc' => ($tail !== '' ? $this->scrub_page_markers($tail) : null),
                'line' => trim((string)$m[0][$i][0]),
            ];
        }
        return $hits;
    }

    private function split_by_hardware_group($text) {
        $pattern = '/\bHardware\s+Group\s+No\.\s*([A-Z0-9]+)\b(.*?)(?=\bHardware\s+Group\s+No\.\s*[A-Z0-9]+\b|\z)/is';
        preg_match_all($pattern, $text, $m, PREG_SET_ORDER);

        $groups = [];
        foreach ($m as $row) {
            $groups[] = ['type'=>'hardware_group', 'key'=>trim($row[1]), 'body'=>trim($row[2] ?? '')];
        }

        $this->dbg('split', "split_by_hardware_group: groups=" . count($groups));
        return $groups;
    }

private function split_by_door_blocks($text) {
    $pattern = '/^\s*(\d+)\s+(SINGLE\s+DOOR|PAIR\s+OF\s+DOORS|ELEVATION)\s+([A-Z0-9][A-Z0-9\.\-_\/]+)\s*(.*)$/im';
    preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE);

    if (empty($m[0])) return [];

    $hits = [];
    $count = count($m[0]);

    for ($i=0; $i<$count; $i++) {
        $hits[] = [
            'offset' => (int)$m[0][$i][1],
            'seq' => trim($m[1][$i][0]),
            'dtype' => trim($m[2][$i][0]),
            'did' => strtoupper(trim($m[3][$i][0])),
            'tail' => trim($m[4][$i][0] ?? ''),
            'line' => trim($m[0][$i][0]),
        ];
    }

    $sections = [];
    $group = [];

    for ($i=0; $i<$count; $i++) {

        $current = $hits[$i];
        $nextOffset = ($i+1 < $count) ? $hits[$i+1]['offset'] : strlen($text);

        $blockText = substr($text, $current['offset'], $nextOffset - $current['offset']);

        // Detect if this header is immediately followed by another header
        $afterHeader = trim(preg_replace('/^\s*' . preg_quote($current['line'], '/') . '/i', '', $blockText));
        $startsWithAnotherHeader = false;

        if ($i+1 < $count) {
            $between = substr($text, $current['offset'] + strlen($current['line']),
                              $hits[$i+1]['offset'] - ($current['offset'] + strlen($current['line'])));
            if (trim($between) === '') {
                $startsWithAnotherHeader = true;
            }
        }

        if ($startsWithAnotherHeader) {
            // Collect header into group
            $group[] = $current;
            continue;
        }

        // If we reach here, this is the last header in a group
        if (!empty($group)) {
            $group[] = $current;

            // Apply ONE shared hardware tail to entire group (avoid leaking other door headers into the body)
            $hardwareEnd = ($i+1 < $count) ? $hits[$i+1]['offset'] : strlen($text);

            $itemsStart = $current['offset'] + strlen($current['line']);
            $itemsBlock = substr($text, $itemsStart, $hardwareEnd - $itemsStart);
            $itemsBlock = ltrim($itemsBlock, "\r\n");
            $groupSize = count($group);
            $itemsBlockForEachDoor = $this->normalize_group_shared_tail_for_each_door($itemsBlock, $groupSize);

            foreach ($group as $g) {
                $doorBlock = $g['line'] . "\n" . $itemsBlockForEachDoor;
                $doorBlock = $this->strip_dimension_lines_after_header($doorBlock);

                $sections[] = [
                    'type' => 'door_block',
                    'key'  => $g['did'],
                    'meta' => [
                        'seq' => $g['seq'],
                        'door_type' => strtolower($g['dtype']),
                        'door_id' => $g['did'],
                        'desc' => ($g['tail'] !== '' ? $this->scrub_page_markers($g['tail']) : null),
                        'header_line' => $g['line'],
                        'slice_start' => $group[0]['offset'],
                        'slice_end' => $hardwareEnd,
                        'source_from_heading' => false,
                        'group_size' => $groupSize,
                        'grouped_with' => array_column($group, 'did'),
                    ],
                    'body' => trim($doorBlock),
                ];
            }

            $group = [];

        } else {
            // Normal single door block
            $body = $this->strip_dimension_lines_after_header($blockText);

            $sections[] = [
                'type' => 'door_block',
                'key'  => $current['did'],
                'meta' => [
                    'seq' => $current['seq'],
                    'door_type' => strtolower($current['dtype']),
                    'door_id' => $current['did'],
                    'desc' => ($current['tail'] !== '' ? $this->scrub_page_markers($current['tail']) : null),
                    'header_line' => $current['line'],
                    'slice_start' => $current['offset'],
                    'slice_end' => $nextOffset,
                    'source_from_heading' => false,
                ],
                'body' => trim($body),
            ];
        }
    }

    $this->dbg('split', "split_by_door_blocks (group-aware): blocks=" . count($sections));
    return $sections;
}

    private function strip_dimension_lines_after_header($block) {
        $lines = preg_split("/\R/", $this->normalize_text($block));
        if (count($lines) <= 1) return $block;

        $out = [];
        $out[] = $lines[0];
        $skipping = true;

        for ($i=1; $i<count($lines); $i++) {
            $ln = trim($lines[$i]);

            if ($skipping) {
                if ($ln === '') continue;

                if (preg_match('/^\d+\s*mm\b/i', $ln)) continue;
                if (preg_match('/^\d+\s*mm\s*x\s*\d+\s*mm(\s*x\s*\d+\s*mm)?/i', $ln)) continue;
                if (preg_match('/^\d+["”]\s*x\s*\d+["”]/', $ln)) continue;

                $skipping = false;
            }

            $out[] = $lines[$i];
        }

        return implode("\n", $out);
    }

    // ===========================
    // SECTION PARSER DISPATCH
    // ===========================
    private function parse_section($sec, &$schema_stats) {
        $type = $sec['type'] ?? 'unknown';

        if ($type === 'door_block') {
            $schema_stats['door_blocks_parsed']++;
            $door = $this->parse_door_block_section($sec, $schema_stats);
            if (empty($door)) $schema_stats['door_blocks_empty']++;
            return $door;
        }

        if ($type === 'heading') {
            return $this->parse_heading_section($sec, $schema_stats);
        }

        if ($type === 'hardware_group') {
            return $this->parse_hardware_group_section($sec, $schema_stats);
        }

        return [];
    }

    private function parse_door_block_section($sec, &$schema_stats) {
        $meta = $sec['meta'] ?? [];
        $body = (string)($sec['body'] ?? '');
        $doorId = (string)($meta['door_id'] ?? ($sec['key'] ?? ''));

        $lines = preg_split("/\R/", $body);
        $header = !empty($lines) ? array_shift($lines) : '';
        $body_wo_header = implode("\n", $lines);

        $schema = $this->infer_item_schema_from_block($body_wo_header, $schema_stats);
        $this->dbg('parse', "door_block: door_id={$doorId} schema_mode=" . ($schema['mode'] ?? 'unknown') .
            " slice=" . (($meta['slice_start'] ?? '?') . "-" . ($meta['slice_end'] ?? '?')));

        $items = $this->extract_items_structured($body_wo_header, $schema, $schema_stats, $doorId, null);

        return [
            'source_section' => (($meta['source_from_heading'] ?? false) ? 'heading_door_block' : 'door_block'),
            'door_id' => $doorId,
            'door_type' => $meta['door_type'] ?? 'unknown',
            'desc' => $meta['desc'] ?? null,
            'heading' => $meta['heading'] ?? null,
            'header_line' => $meta['header_line'] ?? $header,
            'schema' => $schema,
            'items' => $items,
        ];
    }

    private function parse_heading_section($sec, &$schema_stats) {
        $heading = (string)($sec['key'] ?? '');
        $body    = (string)($sec['body'] ?? '');

        $doorHeader = $this->extract_door_header($body);
        $doorId = $doorHeader['door_id'] ?? $heading;

        $schema = $this->infer_item_schema_from_block($body, $schema_stats);
        $this->dbg('parse', "heading_section: heading={$heading} door_id={$doorId} schema_mode=" . ($schema['mode'] ?? 'unknown'));

        $items = $this->extract_items_structured($body, $schema, $schema_stats, $doorId, null);

        return [
            'source_section' => 'heading',
            'heading' => $heading,
            'door_id' => $doorId,
            'door_type' => $doorHeader['door_type'] ?? 'unknown',
            'desc' => $doorHeader['desc'] ?? null,
            'header_line' => $doorHeader['header_line'] ?? null,
            'schema' => $schema,
            'items' => $items,
        ];
    }

    private function parse_hardware_group_section($sec, &$schema_stats) {
        $group = (string)($sec['key'] ?? '');
        $body  = (string)($sec['body'] ?? '');

        $schema = $this->infer_item_schema_from_block($body, $schema_stats);
        $this->dbg('parse', "hardware_group: group={$group} schema_mode=" . ($schema['mode'] ?? 'unknown'));

        $items = $this->extract_items_structured($body, $schema, $schema_stats, null, $group);

        // Keep conservative (like your “maintainable build” note): don’t expand door list here
        return [[
            'source_section' => 'hardware_group',
            'group_no' => $group,
            'door_id' => null,
            'door_type' => 'unknown',
            'desc' => null,
            'schema' => $schema,
            'items' => $items,
        ]];
    }

    // ===========================
    // SCHEMA INFERENCE (explicit header -> implicit columns -> tokens)
    // ===========================
    private function infer_item_schema_from_block($body, &$schema_stats) {
        $lines = preg_split("/\R/", $body);
        $lines = array_map([$this, 'normalize_line'], $lines);

        $sample = [];
        foreach ($lines as $ln) {
            $t = trim($ln);
            if ($t === '') continue;
            $sample[] = $ln;
            if (count($sample) >= 200) break;
        }

        $headerInfo = $this->detect_table_header($sample);
        if ($headerInfo['found']) {
            $schema_stats['seen_headers']++;
            $schema_stats['mode_counts']['columns']++;
            return [
                'mode' => 'columns',
                'header_line' => $headerInfo['header_line'],
                'columns' => $headerInfo['columns'],
                'col_model' => ['type'=>'explicit_header'],
                'notes' => ['Detected table header; slicing using measured column starts from header token positions.'],
            ];
        }

        $implicit = $this->detect_implicit_columns($sample);
        if ($implicit['found']) {
            $schema_stats['implicit_columns_hits']++;
            $schema_stats['mode_counts']['columns']++;

            $refined = $this->refine_implicit_columns_by_scoring($sample, $implicit['columns']);
            if ($refined['refined']) $schema_stats['implicit_columns_refined']++;

            return [
                'mode' => 'columns',
                'header_line' => null,
                'columns' => $refined['columns'],
                'col_model' => [
                    'type' => 'implicit',
                    'confidence' => $implicit['confidence'],
                    'refined' => $refined['refined'],
                ],
                'notes' => [
                    'No header line matched, but layout appears fixed-width (implicit columns).',
                    'confidence=' . $implicit['confidence'],
                    $refined['refined'] ? 'Offsets refined via row scoring.' : 'Offsets not refined.',
                ],
            ];
        } else {
            $schema_stats['implicit_columns_rejects']++;
        }

        $tokenSignals = $this->infer_token_signals($sample);
        $schema_stats['mode_counts']['tokens']++;

        return [
            'mode' => 'tokens',
            'token_rules' => [
                'has_uom' => $tokenSignals['has_uom'],
                'finish_expected' => $tokenSignals['finish_expected'],
                'catalog_expected' => $tokenSignals['catalog_expected'],
                'mfg_expected' => $tokenSignals['mfg_expected'],
            ],
            'notes' => [
                'No table header / implicit columns detected; using token parsing.',
                'qtyLines=' . $tokenSignals['qtyLines'] . ', qtyUomLines=' . $tokenSignals['qtyUomLines'],
            ],
        ];
    }

    private function detect_table_header($sampleLines) {
        foreach ($sampleLines as $ln) {
            $u = strtoupper($ln);
            $hasQty  = (strpos($u, 'QTY') !== false || strpos($u, 'QUANTITY') !== false);
            $hasDesc = (strpos($u, 'DESC') !== false || strpos($u, 'DESCRIPTION') !== false);
            if (!$hasQty || !$hasDesc) continue;

            $posQty  = $this->strpos_first_token($u, ['QTY','QUANTITY']);
            $posDesc = $this->strpos_first_token($u, ['DESCRIPTION','DESC']);
            if ($posQty === null || $posDesc === null) continue;
            if ($posDesc <= $posQty) continue;

            $cols = [];
            $cols['qty'] = $posQty;
            $cols['description'] = $posDesc;

            $posCat = $this->strpos_first_token($u, ['CATALOG NUMBER','CATALOG','CAT NO','CAT#','CAT.']);
            $posMfg = $this->strpos_first_token($u, ['MFG','MANUFACTURER']);
            $posFin = $this->strpos_first_token($u, ['FINISH','FIN']);

            if ($posCat !== null) $cols['catalog'] = $posCat;
            if ($posMfg !== null) $cols['mfg'] = $posMfg;
            if ($posFin !== null) $cols['finish'] = $posFin;

            asort($cols);
            return ['found'=>true,'header_line'=>trim($ln),'columns'=>$cols];
        }
        return ['found'=>false,'header_line'=>null,'columns'=>[]];
    }

    private function detect_implicit_columns($sampleLines) {
        $candidates = [];
        foreach ($sampleLines as $ln) {
            $t = trim($ln);
            if ($t === '') continue;
            if ($this->should_exclude_line($t)) continue;
            if ($this->is_obviously_not_item_row($t)) continue;
            if (preg_match('/^\s*\d+\s*\/\s*\d+/', $ln)) continue;

            if (preg_match('/^\s*(\d{1,3})\s+/', $ln, $m)) {
                $lead = (int)$m[1];
                if ($this->is_finish_code_lead($lead, $t)) continue;
                if ($this->is_suspicious_qty_lead($lead, $t)) continue;
                if (preg_match('/\S\s{2,}\S/', $ln)) $candidates[] = $ln;
            }
        }

        if (count($candidates) < 8) return ['found'=>false,'columns'=>[],'confidence'=>0];

        $boundaryBuckets = [[], [], []];
        foreach ($candidates as $ln) {
            if (preg_match_all('/\s{2,}/', $ln, $mm, PREG_OFFSET_CAPTURE)) {
                $runs = $mm[0];
                $starts = [];
                foreach ($runs as $r) {
                    $runStart = (int)$r[1];
                    $runLen   = strlen($r[0]);
                    $next = $runStart + $runLen;
                    if ($next > 0 && $next < strlen($ln)) $starts[] = $next;
                }

                $starts = array_values(array_unique($starts));
                sort($starts);

                $starts = array_values(array_filter($starts, function($p){ return $p >= 4; }));
                for ($i=0; $i<3; $i++) if (isset($starts[$i])) $boundaryBuckets[$i][] = $starts[$i];
            }
        }

        $med = []; $stableCount = 0;
        for ($i=0; $i<3; $i++) {
            if (count($boundaryBuckets[$i]) < 6) continue;
            sort($boundaryBuckets[$i]);
            $mid = (int) floor(count($boundaryBuckets[$i]) / 2);
            $median = $boundaryBuckets[$i][$mid];
            $med[$i] = $median;

            $near = 0;
            foreach ($boundaryBuckets[$i] as $v) if (abs($v - $median) <= 3) $near++;
            if (($near / max(1,count($boundaryBuckets[$i]))) >= 0.72) $stableCount++;
        }

        if ($stableCount < 2) return ['found'=>false,'columns'=>[],'confidence'=>0];

        $descStart = isset($med[0]) ? $med[0] : 6;
        $catStart  = isset($med[1]) ? $med[1] : null;
        $finStart  = isset($med[2]) ? $med[2] : null;

        $cols = ['qty'=>0,'description'=>$descStart];
        if ($catStart !== null && $catStart > $descStart) $cols['catalog'] = $catStart;
        if ($finStart !== null) {
            if (!isset($cols['catalog']) || $finStart > $cols['catalog']) $cols['finish'] = $finStart;
        }

        asort($cols);
        $confidence = (int) round(100 * ($stableCount / 3));
        return ['found'=>true,'columns'=>$cols,'confidence'=>$confidence];
    }

    private function refine_implicit_columns_by_scoring($sampleLines, $cols) {
        $base = $cols; $bestCols = $cols; $bestScore = -999999; $refined = false;

        $descBase = (int)($cols['description'] ?? 6);
        $catBase  = isset($cols['catalog']) ? (int)$cols['catalog'] : null;
        $finBase  = isset($cols['finish']) ? (int)$cols['finish'] : null;

        for ($d=-4; $d<=4; $d++) for ($c=-4; $c<=4; $c++) for ($f=-4; $f<=4; $f++) {
            $try = $base;
            $try['description'] = max(4, $descBase + $d);
            if ($catBase !== null) $try['catalog'] = max($try['description']+2, $catBase + $c);
            if ($finBase !== null) {
                $minFin = isset($try['catalog']) ? ($try['catalog']+2) : ($try['description']+2);
                $try['finish'] = max($minFin, $finBase + $f);
            }
            asort($try);

            $score = $this->score_columns_on_samples($sampleLines, $try);
            if ($score > $bestScore) { $bestScore = $score; $bestCols = $try; }
        }

        if ($bestCols !== $cols) $refined = true;
        return ['columns'=>$bestCols,'refined'=>$refined];
    }

    private function score_columns_on_samples($sampleLines, $cols) {
        $score = 0; $n = 0;

        foreach ($sampleLines as $ln) {
            $t = trim($ln);
            if ($t === '') continue;
            if ($this->should_exclude_line($t)) continue;
            if ($this->is_obviously_not_item_row($t)) continue;
            if (!preg_match('/^\s*\d{1,3}\s+/', $ln)) continue;

            $n++;
            $slices = $this->slice_columns_from_line($ln, $cols);

            $qtyCol = trim((string)($slices['qty'] ?? ''));
            $descCol = trim((string)($slices['description'] ?? ''));
            $catCol  = trim((string)($slices['catalog'] ?? ''));
            $finCol  = trim((string)($slices['finish'] ?? ''));

            list($qty, $uom, $descRemainder) = $this->parse_qty_uom_from_slices($qtyCol, $descCol);
            $desc = trim(($descRemainder !== '' ? $descRemainder : $descCol));

            if ($qty !== null && $qty >= 1 && $qty <= 200) $score += 4; else $score -= 3;
            if ($desc !== '' && preg_match('/[A-Za-z]/', $desc)) $score += 3; else $score -= 2;

            if ($finCol !== '') {
                if (preg_match('/\b(US\d{2}[A-Z]?|C\d{2}[A-Z]?|6\d{2}|689|630)\b/i', $finCol)) $score += 2;
                if (strlen($finCol) > 25 && preg_match('/[A-Za-z]{4,}/', $finCol)) $score -= 2;
            }
            if ($catCol !== '') {
                if (preg_match('/[A-Z0-9]{2,}[-\/][A-Z0-9]/i', $catCol)) $score += 2;
                if (strlen($catCol) > 28 && preg_match('/[A-Za-z]{4,}/', $catCol)) $score -= 2;
            }

            if ($n >= 50) break;
        }
        return $score;
    }

    // token signal inference (compact)
    private function infer_token_signals($sample) {
        $uomSet = $this->default_uom_set();
        $qtyLines = 0; $qtyUomLines = 0; $finishHits = 0; $modelHits = 0; $mfgHits = 0; $considered = 0;

        foreach ($sample as $ln) {
            $t = trim($ln);
            if ($t === '') continue;
            if ($this->should_exclude_line($t)) continue;
            if ($this->is_obviously_not_item_row($t)) continue;
            if (preg_match('/^\s*\d+\s*\/\s*\d+/', $ln)) continue;

            $considered++;

            // (kept intentionally simple; you can plug your bank matchers later)
            if (preg_match('/\b[A-Z]{1,6}[A-Z0-9]*[-\/][A-Z0-9][A-Z0-9\-\/]*\b/i', $t)) $modelHits++;
            if (preg_match('/\b(MFG|MANUFACTURER|SCHLAGE|LCN|HES|SARGENT|VON\s+DUPRIN|ASSA\s+ABLOY|ALLEGION)\b/i', $t)) $mfgHits++;
            if (preg_match('/\b(US\d{2}[A-Z]?|C\d{2}[A-Z]?|6\d{2}|689|630)\b/i', $t)) $finishHits++;

            if (preg_match('/^\s*(\d+)\s+(\S+)\s+(.+)$/', $ln, $m)) {
                $lead = (int)$m[1];
                if ($this->is_finish_code_lead($lead, $m[3] ?? '')) continue;
                if ($this->is_suspicious_qty_lead($lead, $m[3] ?? '')) continue;

                $qtyLines++;
                $maybeUom = strtoupper(trim($m[2]));
                if (isset($uomSet[$maybeUom])) $qtyUomLines++;
                continue;
            }
            if (preg_match('/^\s*(\d+)\s+(.+)$/', $ln, $m2)) {
                $lead = (int)$m2[1];
                if ($this->is_finish_code_lead($lead, $m2[2] ?? '')) continue;
                if ($this->is_suspicious_qty_lead($lead, $m2[2] ?? '')) continue;
                $qtyLines++;
                continue;
            }
        }

        $hasUom = false;
        if ($qtyLines >= 4) $hasUom = ($qtyUomLines / max(1,$qtyLines)) >= 0.60;

        $finish_expected  = ($considered >= 10) ? (($finishHits / max(1,$considered)) >= 0.18) : ($finishHits >= 3);
        $catalog_expected = ($considered >= 10) ? (($modelHits / max(1,$considered)) >= 0.10) : ($modelHits >= 2);
        $mfg_expected     = ($considered >= 10) ? (($mfgHits / max(1,$considered)) >= 0.10) : ($mfgHits >= 2);

        return [
            'has_uom' => $hasUom,
            'finish_expected' => $finish_expected,
            'catalog_expected' => $catalog_expected,
            'mfg_expected' => $mfg_expected,
            'qtyLines' => $qtyLines,
            'qtyUomLines' => $qtyUomLines,
        ];
    }

    // ===========================
    // ITEM EXTRACTION (STRUCTURED)
    // ===========================
    private function extract_items_structured($body, $schema, &$schema_stats, $doorIdForDebug = null, $groupForDebug = null) {
        $lines = preg_split("/\R/", $body);
        $lines = array_map([$this, 'normalize_line'], $lines);

        if (($schema['mode'] ?? '') === 'columns' && !empty($schema['header_line'])) {
            $header = strtoupper(trim((string)$schema['header_line']));
            $idx = null;
            for ($i=0; $i<count($lines); $i++) {
                if (strtoupper(trim($lines[$i])) === $header) { $idx = $i; break; }
            }
            if ($idx !== null) $lines = array_slice($lines, $idx + 1);
        }

        if (($schema['mode'] ?? '') === 'columns') {
            return $this->extract_items_columns_reconstruct($lines, $schema, $schema_stats);
        }

        // tokens
        $rawItems = $this->collect_wrapped_item_lines($lines, $schema_stats, $doorIdForDebug, $groupForDebug);

        $items = [];
        foreach ($rawItems as $raw) {
            $raw = $this->normalize_item_spacing($raw);
            if ($raw === '') continue;

            $raw = $this->scrub_page_markers($raw);

            $it = $this->parse_item_tokens($raw, $schema, $schema_stats);
            if (!$this->token_item_is_reasonable($it)) continue;

            if (isset($it['desc'])) $it['desc'] = $this->scrub_page_markers($it['desc']);
            if (isset($it['raw']))  $it['raw']  = $this->scrub_page_markers($it['raw']);

            $items[] = $it;
        }

        // de-dupe by raw
        $seen = []; $out = [];
        foreach ($items as $it) {
            $k = (string)($it['raw'] ?? '');
            if ($k === '') continue;
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $it;
        }

        return $out;
    }

    private function extract_items_columns_reconstruct($lines, $schema, &$schema_stats) {
        $cols = $schema['columns'] ?? [];
        if (empty($cols) || !isset($cols['qty']) || !isset($cols['description'])) {
            // fallback to token collection
            $rawItems = $this->collect_wrapped_item_lines($lines, $schema_stats, null, null);
            $items = [];
            foreach ($rawItems as $raw) {
                $raw = $this->normalize_item_spacing($raw);
                if ($raw === '') continue;
                $raw = $this->scrub_page_markers($raw);
                $it = $this->parse_item_tokens($raw, ['token_rules'=>['has_uom'=>true]], $schema_stats);
                if (!$this->token_item_is_reasonable($it)) continue;
                $items[] = $it;
            }
            return $items;
        }

        $rows = [];
        $current = null;

        foreach ($lines as $ln) {
            $t = trim($ln);
            if ($t === '') {
                if ($current !== null) { $rows[] = $current; $current = null; }
                continue;
            }

            $t = $this->scrub_page_markers($t);
            if ($this->should_exclude_line($t)) {
                if ($current !== null) { $rows[] = $current; $current = null; }
                continue;
            }

            $slices = $this->slice_columns_from_line($ln, $cols);

            $qtyCol = trim((string)($slices['qty'] ?? ''));
            $descCol = trim((string)($slices['description'] ?? ''));
            $catCol  = trim((string)($slices['catalog'] ?? ''));
            $mfgCol  = trim((string)($slices['mfg'] ?? ''));
            $finCol  = trim((string)($slices['finish'] ?? ''));

            if (preg_match('/^\s*\d+\s*\/\s*\d+/', $ln)) {
                $schema_stats['implicit_fraction_rejects']++;
                if ($current !== null) {
                    if ($descCol !== '') $current['desc_parts'][] = $descCol;
                    if ($catCol !== '')  $current['catalog_parts'][] = $catCol;
                    if ($mfgCol !== '')  $current['mfg_parts'][] = $mfgCol;
                    if ($finCol !== '')  $current['finish_parts'][] = $finCol;
                    $current['raw_lines'][] = $ln;
                }
                continue;
            }

            list($qty, $uom, $descRemainder) = $this->parse_qty_uom_from_slices($qtyCol, $descCol);
            $descFinal = trim(($descRemainder !== '' ? $descRemainder : $descCol));
            $blob = trim($descFinal . ' ' . $catCol . ' ' . $mfgCol . ' ' . $finCol);
            $blob = $this->normalize_item_spacing($blob);

            $isNew = ($qty !== null) && $this->is_plausible_qty($qty, $uom, $blob);

            if ($isNew) {
                if ($current !== null) $rows[] = $current;

                $current = [
                    'qty' => $qty,
                    'uom' => $uom,
                    'desc_parts' => [$descFinal],
                    'catalog_parts' => [$catCol],
                    'mfg_parts' => [$mfgCol],
                    'finish_parts' => [$finCol],
                    'raw_lines' => [$ln],
                ];
                continue;
            }

            if ($current !== null) {
                if ($descCol !== '')   $current['desc_parts'][] = $descCol;
                if ($catCol !== '')    $current['catalog_parts'][] = $catCol;
                if ($mfgCol !== '')    $current['mfg_parts'][] = $mfgCol;
                if ($finCol !== '')    $current['finish_parts'][] = $finCol;
                $current['raw_lines'][] = $ln;
            }
        }

        if ($current !== null) $rows[] = $current;

        $items = [];
        foreach ($rows as $r) {
            $desc = $this->normalize_item_spacing(implode(' ', array_filter($r['desc_parts'])));
            $catalog = $this->normalize_item_spacing(implode(' ', array_filter($r['catalog_parts'])));
            $mfg = $this->normalize_item_spacing(implode(' ', array_filter($r['mfg_parts'])));
            $finish = $this->normalize_item_spacing(implode(' ', array_filter($r['finish_parts'])));
            $raw = $this->normalize_item_spacing(implode(' ', $r['raw_lines']));

            $raw = $this->scrub_page_markers($raw);
            $desc = $this->scrub_page_markers($desc);
            $catalog = $this->scrub_page_markers($catalog);
            $mfg = $this->scrub_page_markers($mfg);
            $finish = $this->scrub_page_markers($finish);

            $items[] = [
                'qty' => $r['qty'],
                'uom' => $r['uom'],
                'desc' => ($desc !== '' ? $desc : null),
                'catalog' => ($catalog !== '' ? $catalog : null),
                'mfg' => ($mfg !== '' ? $mfg : null),
                'finish' => ($finish !== '' ? $finish : null),
                'raw' => $raw,
            ];
        }

        return $items;
    }

    // ===========================
    // WRAPPED ITEM LINE JOIN (token-mode)
    // ===========================
    private function collect_wrapped_item_lines($lines, &$schema_stats, $doorIdForDebug = null, $groupForDebug = null) {
        $out = [];
        $buf = '';
        $pending = '';

        foreach ($lines as $ln) {
            $t = trim($ln);
            $t = $this->scrub_page_markers($t);

            if ($t === '') {
                if ($buf !== '') { $out[] = trim($buf); $buf = ''; }
                $pending = '';
                continue;
            }

            if ($this->should_exclude_line($t)) {
                if ($buf !== '') { $out[] = trim($buf); $buf = ''; }
                $pending = '';
                continue;
            }

            // If a high-number "model fragment" landed on its own line, stash it and attach to the next real item row.
            if ($buf === '' && $pending === '' && preg_match('/^\s*(9\d{3}|8\d{3})\b\s+/', $t) && preg_match('/[A-Za-z]/', $t)) {
                $pending = $t;
                $schema_stats['token_model_fragments'] = ($schema_stats['token_model_fragments'] ?? 0) + 1;
                continue;
            }

            // Stitch split words like "Actuat" + "or 8310-836T" -> "Actuator 8310-836T"
            if ($buf !== '' && preg_match('/\bACTUAT$/i', $buf) && preg_match('/^or\b/i', $t)) {
                $buf = preg_replace('/\bACTUAT$/i', 'ACTUATOR', $buf);
                $t = preg_replace('/^or\b\s*/i', '', $t);
                if ($t !== '') $buf .= ' ' . $t;
                continue;
            }

            if (preg_match('/^\s*\d+\s*\/\s*\d+/', $t)) {
                if ($buf !== '') $buf .= ' ' . $t;
                continue;
            }

            $startsWithQty = (bool) preg_match('/^\s*\d{1,4}\s+/', $t);

            // Attach catalog/model-ish continuation line
            if (!$startsWithQty && $buf !== '') {
                if (preg_match('/\b[A-Z]{1,6}[A-Z0-9]*[-\/][A-Z0-9][A-Z0-9\-\/]*\b/i', $t)) {
                    $buf .= ' ' . $t;
                    $schema_stats['token_note_continuations']++;
                    continue;
                }
            }

            if (preg_match('/^\s*(\d{1,4})\s+(.+)$/', $t, $m)) {
                $lead = (int)$m[1];
                $rest = $m[2];

                if ($this->looks_like_note_continuation($rest)) {
                    if ($buf !== '') {
                        $buf .= ' ' . $t;
                        $schema_stats['token_note_continuations']++;
                    }
                    continue;
                }

                if ($lead >= 1 && $lead <= 200 && !$this->is_finish_code_lead($lead, $rest) && !$this->is_suspicious_qty_lead($lead, $rest)) {
                    if ($pending !== '') { $t = trim($t . ' ' . $pending); $pending = ''; }

                    if ($buf !== '') $out[] = trim($buf);
                    $buf = $t;
                    continue;
                }
            }

            if ($pending !== '') { $t = trim($t . ' ' . $pending); $pending = ''; }

            if ($buf !== '') $buf .= ' ' . $t;
            else $buf = $t;
        }

        if ($pending !== '' && $buf !== '') { $buf .= ' ' . $pending; $pending = ''; }
        if ($buf !== '') $out[] = trim($buf);
        return $out;
    }

    private function looks_like_note_continuation($textAfterQty) {
        $u = strtoupper(trim((string)$textAfterQty));
        if ($u === '') return false;

        if (preg_match('/^(MAX\b|MIN\b|STANDARD\b|TYP\.?\b|TYPICAL\b|PLEASE\b|REFERENCE\b|CONFIRM\b|PROVIDE\b|VERIFY\b)/', $u)) return true;
        if (preg_match('/\b(WILL\s+FOLLOW|SEE\s+DETAIL|SEE\s+NOTES|PER\s+DETAIL|AS\s+PER)\b/', $u)) return true;
        if (preg_match('/\b(VERIFY|CONFIRM|NOTE|NOTES|TYP)\b/', $u) && !preg_match('/[A-Z0-9]{2,}[-\/][A-Z0-9]/', $u)) return true;

        return false;
    }

    private function normalize_item_spacing($s) {
        $s = trim((string)$s);
        $s = preg_replace('/\s+/', ' ', $s);
        return $s ?? '';
    }

    private function token_item_is_reasonable($it) {
        $qty = isset($it['qty']) ? (int)$it['qty'] : null;
        $desc = trim((string)($it['desc'] ?? ''));
        $raw  = trim((string)($it['raw'] ?? ''));

        if ($qty === null || $qty < 1 || $qty > 200) return false;
        if (preg_match('/^\s*\d+\s*\/\s*\d+/', $raw)) return false;
        // Reject dimension/header lines mis-read as item qty rows (e.g. "102 x 2134 x 44 - HM DR x HM FR ...")
        if (preg_match('/^\s*\d{2,4}\s*[xX]\s*\d{2,4}(\s*[xX]\s*[_\d]+)?\b/', $raw)) return false;
        if (preg_match('/\bHM\s+DR\s*[xX]\s*HM\s+FR\b/i', $raw)) return false;
        if ($desc === '') return false;
        if (!preg_match('/[A-Za-z]/', $desc)) return false;

        return true;
    }

    private function parse_item_tokens($raw, $schema, &$schema_stats) {
        $uomSet = $this->default_uom_set();
        $rules = $schema['token_rules'] ?? [];
        $hasUom = (bool)($rules['has_uom'] ?? false);
        $finish_expected = (bool)($rules['finish_expected'] ?? false);
        $catalog_expected = (bool)($rules['catalog_expected'] ?? false);
        $mfg_expected = (bool)($rules['mfg_expected'] ?? false);

        $qty = null; $uom = null; $desc = null;

        if ($hasUom && preg_match('/^\s*(\d+)\s+(\S+)\s+(.+)$/', $raw, $m)) {
            $qty = (int)$m[1];
            $maybeUom = strtoupper(trim($m[2]));
            if (isset($uomSet[$maybeUom])) { $uom = $maybeUom; $desc = trim($m[3]); }
            else { $desc = trim($m[2] . ' ' . $m[3]); }
        } elseif (preg_match('/^\s*(\d+)\s+(.+)$/', $raw, $m2)) {
            $qty = (int)$m2[1];
            $desc = trim($m2[2]);
        }

        $finish = null; $catalog = null; $mfg = null;

        if ($finish_expected && $desc) {
            if (preg_match('/^(US\d{2}[A-Z]?|C\d{2}[A-Z]?|6\d{2}|689|630)\b\s*(.+)$/i', $desc, $fm)) {
                $finish = strtoupper($fm[1]);
                $desc = trim($fm[2]);
                $schema_stats['token_finish_extractions']++;
            } else if (preg_match('/\b(US\d{2}[A-Z]?|C\d{2}[A-Z]?|6\d{2}|689|630)\b$/i', $desc, $fm2)) {
                $finish = strtoupper($fm2[1]);
                $desc = trim(preg_replace('/\b(US\d{2}[A-Z]?|C\d{2}[A-Z]?|6\d{2}|689|630)\b$/i', '', $desc));
                $schema_stats['token_finish_extractions']++;
            }
        }

        if ($catalog_expected) {
            if ($desc && preg_match('/\b[A-Z]{1,6}[A-Z0-9]*[-\/][A-Z0-9][A-Z0-9\-\/]*\b/i', $desc, $cm)) {
                $catalog = strtoupper($cm[0]);
                $schema_stats['token_catalog_extractions']++;
            }
        }

        if ($mfg_expected && $desc) {
            if (preg_match('/\b(LCN|SCHLAGE|HES|SARGENT|VON\s+DUPRIN|ALLEGION|ASSA\s+ABLOY)\b/i', $desc, $mm)) {
                $mfg = strtoupper(str_replace('  ', ' ', trim($mm[0])));
                $schema_stats['token_mfg_extractions']++;
            }
        }

        return [
            'qty' => $qty,
            'uom' => $uom,
            'desc' => $desc,
            'catalog' => $catalog,
            'mfg' => $mfg,
            'finish' => $finish,
            'raw' => $raw,
        ];
    }

    // ===========================
    // FILTERS / REJECTIONS
    // ===========================
    private function should_exclude_line($trimmedLine) {
        $trimmedLine = $this->scrub_page_markers($trimmedLine);
        $u = strtoupper(trim($trimmedLine));
        if ($u === '') return true;

        if (preg_match('/^PAGE\s+\d+/i', $trimmedLine)) return true;
        if (preg_match('/^HEADING\s*#\s*[A-Z0-9\-]+/i', $trimmedLine)) return true;
        if (preg_match('/^(NOTE:|GENERAL\s+NOTES|MODE\s+OF\s+OPERATION|SPECIFIC\s+NOTES)/i', $trimmedLine)) return true;

        if (preg_match('/^(DOOR\s+TYPE:|FRAME\s+TYPE:|HEAD:|SILL:|JAMB:)/i', $trimmedLine)) return true;
        if (preg_match('/^PROVIDE\s+EACH\s+DOOR\s+/i', $trimmedLine)) return true;
        if (preg_match('/^FOR\s+USE\s+ON\s+DOOR/i', $trimmedLine)) return true;

        // Only exclude dimension-only lines (NOT real item rows like "Kick Plate ... 8\" x 36\"")
        if (!preg_match('/[A-Za-z]/', $trimmedLine)) {
            if (preg_match('/\b\d+\/\d+\s*MM\b/i', $trimmedLine)) return true;
            if (preg_match('/\b\d+\s*MM\s*x\s*\d+\s*MM\b/i', $trimmedLine)) return true;
            if (preg_match('/\b\d+["”]\s*x\s*\d+["”]/', $trimmedLine)) return true;
        }


        if (preg_match('/^\d+\s+(SINGLE\s+DOOR|PAIR\s+OF\s+DOORS|ELEVATION)\s+/i', $trimmedLine)) return true;

        return false;
    }

    private function is_obviously_not_item_row($trimmedLine) {
        $trimmedLine = $this->scrub_page_markers($trimmedLine);
        if (preg_match('/^\d+\s*mm\b/i', $trimmedLine)) return true;
        if (preg_match('/^\d+\s*mm\s*x\s*\d+\s*mm(\s*x\s*\d+\s*mm)?/i', $trimmedLine)) return true;
        if (preg_match('/^\s*(ISSUED|REVISED|PROJECT|CONSULTANT|ARCHITECT)\b/i', $trimmedLine)) return true;
        if (preg_match('/^\s*\d+\s*\/\s*\d+/', $trimmedLine)) return true;
        return false;
    }

    private function is_finish_code_lead($leadInt, $rest) {
        if ($leadInt >= 600 && $leadInt <= 699) {
            $r = trim((string)$rest);
            if ($r === '') return true;
            if (preg_match('/^[-–—]/', $r)) return true;
            if (preg_match('/^[A-Za-z]/', $r)) return true;
            if (preg_match('/^(US\d{2}[A-Z]?|C\d{2}[A-Z]?|AL|ANCLR)\b/i', $r)) return true;
        }
        return false;
    }

    private function is_suspicious_qty_lead($leadInt, $rest) {
        if ($leadInt > 250) return true;
        if (in_array($leadInt, [9540, 9542, 9553], true)) return true;
        if ($this->is_finish_code_lead($leadInt, $rest)) return true;

        $r = strtoupper(trim((string)$rest));
        if (preg_match('/^(LONG\d|HDR\d|C(10|32)[A-Z0-9]|US26D|C26D|C32D)\b/', $r)) return true;

        return false;
    }

    // ===========================
    // COLUMN SLICING HELPERS
    // ===========================
    private function strpos_first_token($hayUpper, $tokens) {
        $best = null;
        foreach ($tokens as $tok) {
            $p = strpos($hayUpper, strtoupper($tok));
            if ($p === false) continue;
            if ($best === null || $p < $best) $best = $p;
        }
        return $best;
    }

    private function default_uom_set() {
        $uoms = ['EA','EACH','SET','SETS','PR','PAIR','PAIRS','LOT','ROLL','BOX','PKG','PACK','FT','LF','SF'];
        $set = [];
        foreach ($uoms as $u) $set[$u] = true;
        return $set;
    }

    private function parse_qty_uom_from_slices($qtyCol, $descCol) {
        $uomSet = $this->default_uom_set();

        $qtyColNorm = trim(preg_replace('/\s+/', ' ', (string)$qtyCol));
        if ($qtyColNorm !== '') {
            if (preg_match('/^(\d+)\s+([A-Za-z]+)\b(.*)$/', $qtyColNorm, $m)) {
                $qty = (int)$m[1];
                $u = strtoupper($m[2]);
                $uom = isset($uomSet[$u]) ? $u : null;
                return [$qty, $uom, ''];
            }
            if (preg_match('/^(\d+)\b/', $qtyColNorm, $m2)) return [(int)$m2[1], null, ''];
        }

        $descNorm = trim(preg_replace('/\s+/', ' ', (string)$descCol));
        if (preg_match('/^(\d+)\s+([A-Za-z]+)\s+(.+)$/', $descNorm, $m3)) {
            $qty = (int)$m3[1];
            $u = strtoupper($m3[2]);
            if (isset($uomSet[$u])) return [$qty, $u, trim($m3[3])];
        }
        if (preg_match('/^(\d+)\s+(.+)$/', $descNorm, $m4)) return [(int)$m4[1], null, trim($m4[2])];

        return [null, null, ''];
    }

    private function is_plausible_qty($qty, $uom, $rowBlob) {
        if ($qty < 1) return false;
        if ($qty > 200) return false;
        if (preg_match('/^\s*\d+\s*\/\s*\d+/', (string)$rowBlob)) return false;

        if ($uom === null) {
            $u = strtoupper(trim((string)$rowBlob));
            if (preg_match('/^(MS\b|AA\b|ANCLR\b|C32D\b|US26D\b)/', $u)) return false;
            if (preg_match('/^\d{3,5}\b/', $u)) return false;
        }
        return true;
    }

    private function slice_columns_from_line($raw, $cols) {
        $starts = $cols;
        asort($starts);
        $keys = array_keys($starts);
        $vals = array_values($starts);

        $fields = [];
        $raw = (string)$raw;
        for ($i=0; $i<count($keys); $i++) {
            $k = $keys[$i];
            $start = (int)$vals[$i];
            $end = ($i+1 < count($vals)) ? (int)$vals[$i+1] : strlen($raw);
            $fields[$k] = ($start >= strlen($raw)) ? '' : rtrim(substr($raw, $start, $end - $start));
        }
        return $fields;
    }

    // ===========================
    // DOOR HEADER EXTRACTION + FALLBACK
    // ===========================
    private function extract_door_header($body) {
        $lines = preg_split("/\R/", $body);
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;

            if (preg_match('/\b\d+\s+(Single door|Pair of doors|Elevation)\s+([A-Z0-9\.\-_\/]+)(?:,|\s)(.*)$/i', $ln, $m)) {
                $type = strtolower(trim($m[1]));
                $id   = strtoupper(trim($m[2]));
                $rest = trim($m[3] ?? '');
                return ['door_type'=>$type,'door_id'=>$id,'desc'=>($rest !== '' ? $rest : null), 'header_line'=>$ln];
            }
        }
        return [];
    }

    private function fallback_scan_doors($text) {
        $doors = [];
        if (preg_match_all('/\b(Single door|Pair of doors|Elevation)\s+([A-Z0-9\.\-_\/]+)(?:,|\s)(.*)$/im', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $doors[] = [
                    'source_section' => 'fallback',
                    'door_id' => strtoupper(trim($row[2])),
                    'door_type' => strtolower(trim($row[1])),
                    'desc' => trim($row[3] ?? '') ?: null,
                    'items' => [],
                ];
            }
        }
        $this->dbg('parse', "fallback_scan_doors: doors=" . count($doors));
        return $doors;
    }
}
