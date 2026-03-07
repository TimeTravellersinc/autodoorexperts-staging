<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ADX_Quote
 * ---------
 * Scoped JSON -> counted hits -> tiered pricing -> quote lines -> PDF/HTML estimate
 *
 * NOTE
 * ----
 * - Logs into ADX_Debug "parse" channel (ADX_Debug only has extract/split/parse/scope)
 * - Reads items[].raw line-by-line and extracts sub-quantities from compressed rows
 * - Uses bundle pricing first, component-sum fallback second
 */
class ADX_Quote {

    /** @var ADX_Debug|null */
    private $dbg;

    public function __construct($dbg = null) {
        $this->dbg = $dbg;
    }

    private function dlog($msg) {
        if ($this->dbg && method_exists($this->dbg, 'log')) {
            $this->dbg->log('parse', '[QUOTE] ' . (string)$msg);
        }
    }

    public function build_from_scoped_json($scoped_json_path, array $opts = []) {
        $this->dlog("=== QUOTE START === file=" . basename((string)$scoped_json_path));

        if (!is_readable($scoped_json_path)) {
            return ['ok' => false, 'error' => 'Scoped JSON not readable: ' . $scoped_json_path];
        }

        $json = file_get_contents($scoped_json_path);
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['result']['doors']) || !is_array($data['result']['doors'])) {
            return ['ok' => false, 'error' => 'Invalid scoped JSON shape (expected result.doors[])'];
        }

        $doors = $data['result']['doors'];
        $door_count = count($doors);
        $tier_key = $this->pick_door_count_tier($door_count);
        $guide = $this->pricing_guide();

        $this->dlog("doors_in={$door_count}");
        $this->dlog("tier_selected={$tier_key}");

        $door_recipes = [];
        $job_counts = [
            'line_hits' => [],
            'units' => [],
            'models' => [],
            'unknowns' => [],
        ];

        foreach ($doors as $door) {
            $dr = $this->build_door_recipe($door);
            $door_recipes[] = $dr;

            foreach ($dr['debug']['line_hits'] as $k => $n) {
                if (!isset($job_counts['line_hits'][$k])) { $job_counts['line_hits'][$k] = 0; }
                $job_counts['line_hits'][$k] += (int)$n;
            }
            foreach ($dr['counts'] as $k => $n) {
                if (!isset($job_counts['units'][$k])) { $job_counts['units'][$k] = 0; }
                $job_counts['units'][$k] += (int)$n;
            }
            foreach ($dr['models'] as $m) {
                if (!isset($job_counts['models'][$m])) { $job_counts['models'][$m] = 0; }
                $job_counts['models'][$m] += 1;
            }
            foreach ($dr['unknown_items'] as $u) {
                $job_counts['unknowns'][] = ['door_id' => $dr['door_id'], 'raw' => $u];
            }
        }

        $line_items = [];
        $subtotal = 0.0;
        foreach ($door_recipes as &$dr) {
            $priced = $this->price_door_recipe($dr, $guide, $tier_key, $door_count);
            $dr['pricing'] = $priced;
            $subtotal += (float)$priced['door_total'];
            $line_items[] = $this->format_quote_line($dr, $priced);
        }
        unset($dr);

        $tax_rate = isset($opts['tax_rate']) ? (float)$opts['tax_rate'] : 0.13;
        $tax = round($subtotal * $tax_rate, 2);
        $total = round($subtotal + $tax, 2);

        $result = [
            'ok' => true,
            'meta' => [
                'source_scoped_json' => $scoped_json_path,
                'door_count' => $door_count,
                'tier_key' => $tier_key,
                'generated_at' => current_time('c'),
            ],
            'counts' => $job_counts,
            'summary' => [
                'subtotal' => round($subtotal, 2),
                'tax_rate' => $tax_rate,
                'tax' => $tax,
                'total' => $total,
            ],
            'line_items' => $line_items,
            'door_recipes' => $door_recipes,
        ];

        if (!isset($opts['write_pdf']) || (bool)$opts['write_pdf']) {
            $render = $this->render_estimate_pdf_or_html($result, [
                'output_dir'   => isset($opts['output_dir']) ? $opts['output_dir'] : wp_upload_dir()['basedir'],
                'quote_number' => isset($opts['quote_number']) ? $opts['quote_number'] : ('EST-' . gmdate('Ymd-His')),
                'project_name' => isset($opts['project_name']) ? $opts['project_name'] : 'Auto Door Estimate',
                'client_name'  => isset($opts['client_name']) ? $opts['client_name'] : 'Client',
                'fpdf_path'    => isset($opts['fpdf_path']) ? $opts['fpdf_path'] : null,
            ]);
            $result['output'] = $render;
        }

        $this->dlog("QUOTE DONE subtotal=" . round($subtotal, 2) . " total=" . round($total, 2));
        return $result;
    }

    // ---------------------------
    // Door recipe builder (line-by-line raw scan)
    // ---------------------------

    private function build_door_recipe(array $door) {
        $door_id = isset($door['door_id']) ? (string)$door['door_id'] : 'UNKNOWN';
        $door_type = isset($door['door_type']) ? (string)$door['door_type'] : '';
        $items = (isset($door['items']) && is_array($door['items'])) ? $door['items'] : [];

        $recipe = [
            'door_id' => $door_id,
            'door_type' => $door_type,
            'header_line' => isset($door['header_line']) ? (string)$door['header_line'] : '',
            'counts' => [
                'operator' => 0,
                'push_button' => 0,
                'column_actuator' => 0,
                'strike' => 0,
                'relay' => 0,
                'wire' => 0,
                'mount_plate' => 0,
                'wc_kit' => 0,
                'wec_kit' => 0,
                'cx12' => 0,
                'qel_psu' => 0,
                'key_switch' => 0,
                'power_transfer' => 0,
                'door_contact' => 0,
                'card_reader' => 0,
            ],
            'models' => [],
            'unknown_items' => [],
            'debug' => [
                'line_hits' => [],
                'raw_scan' => [],
            ],
        ];

        foreach ($items as $idx => $item) {
            $raw = isset($item['raw']) ? (string)$item['raw'] : '';
            if ($raw === '') { continue; }

            $raw_n = $this->norm($raw);
            $item_qty = (isset($item['qty']) && is_numeric($item['qty'])) ? (int)$item['qty'] : 1;
            if ($item_qty <= 0) { $item_qty = 1; }

            $scan = $this->scan_raw_line($raw_n, $item_qty);

            $recipe['debug']['raw_scan'][] = [
                'idx' => $idx,
                'qty' => $item_qty,
                'raw' => $raw,
                'detected' => $scan,
            ];

            foreach ($scan['line_tags'] as $tag) {
                if (!isset($recipe['debug']['line_hits'][$tag])) { $recipe['debug']['line_hits'][$tag] = 0; }
                $recipe['debug']['line_hits'][$tag] += 1;
            }

            foreach ($scan['counts'] as $k => $v) {
                if (!isset($recipe['counts'][$k])) { $recipe['counts'][$k] = 0; }
                $recipe['counts'][$k] += (int)$v;
            }

            foreach ($scan['models'] as $m) {
                if (!in_array($m, $recipe['models'], true)) {
                    $recipe['models'][] = $m;
                }
            }

            if (empty($scan['counts']) && !$this->is_known_nonpriced_line($raw_n)) {
                $recipe['unknown_items'][] = $raw;
            }
        }

        return $recipe;
    }

    private function scan_raw_line($raw_n, $item_qty) {
        $out = [
            'line_tags' => [],
            'counts' => [],
            'models' => [],
        ];

        $line_hit = function($tag) use (&$out) {
            if (!in_array($tag, $out['line_tags'], true)) { $out['line_tags'][] = $tag; }
        };
        $add_count = function($bucket, $n) use (&$out) {
            $n = (int)$n;
            if ($n <= 0) return;
            if (!isset($out['counts'][$bucket])) { $out['counts'][$bucket] = 0; }
            $out['counts'][$bucket] += $n;
        };
        $add_model = function($m) use (&$out) {
            if (!in_array($m, $out['models'], true)) { $out['models'][] = $m; }
        };

        // Model / family signals
        if (preg_match('/\bSW[\- ]?200\b/i', $raw_n)) { $line_hit('SW200'); $add_model('SW200'); }
        if (preg_match('/\bSW[\- ]?800\b/i', $raw_n)) { $line_hit('SW800'); $add_model('SW800'); }
        if (preg_match('/\bHA[\- ]?8\b/i', $raw_n))   { $line_hit('HA8');   $add_model('HA8'); }

        foreach (['9531','9542','9553','9563'] as $m) {
            if (preg_match('/\b' . preg_quote($m, '/') . '\b/', $raw_n)) { $line_hit($m); $add_model($m); }
        }
        foreach (['9530-18','9540-18','QEL','QELRX','PS90X','CX-12','CX-33','CM45','CM60','CM75','8310','20-057-ICX','1006','6211','6223','6300','6111','CON-6W','EPT-10'] as $sig) {
            switch ($sig) {
                case 'CM45':
                    if (preg_match('/\bCM\-45\/?[34]\b/i', $raw_n)) { $line_hit('CM45'); $add_model('CM45'); }
                    break;
                case 'CM60':
                    if (preg_match('/\bCM\-60\/?2\b/i', $raw_n)) { $line_hit('CM60'); $add_model('CM60'); }
                    break;
                case 'CM75':
                    if (preg_match('/\bCM\-75\b/i', $raw_n)) { $line_hit('CM75'); $add_model('CM75'); }
                    break;
                case '8310':
                    if (preg_match('/\b8310\-\d+/i', $raw_n)) { $line_hit('8310'); $add_model('8310'); }
                    break;
                case '20-057-ICX':
                    if (preg_match('/\b20\-057\-ICX\b/i', $raw_n)) { $line_hit('20-057-ICX'); $add_model('20-057-ICX'); }
                    break;
                case 'QEL':
                    if (preg_match('/\bQEL\b/i', $raw_n)) { $line_hit('QEL'); $add_model('QEL'); }
                    break;
                case 'QELRX':
                    if (preg_match('/\bQELRX\b/i', $raw_n)) { $line_hit('QELRX'); $add_model('QELRX'); }
                    break;
                default:
                    if (preg_match('/\b' . preg_quote($sig, '/') . '\b/i', $raw_n)) { $line_hit($sig); $add_model($sig); }
                    break;
            }
        }

        if (preg_match('/\bCARD READER\b/i', $raw_n)) { $line_hit('CARD_READER'); }
        if (preg_match('/\bDOOR CONTACT\b|\bGE947W\b/i', $raw_n)) { $line_hit('DOOR_CONTACT'); }
        if (preg_match('/\bKEY ?SWITCH\b|\b653\-/i', $raw_n)) { $line_hit('KEY_SWITCH'); }
        if (preg_match('/\bPOWER SUPPLY\b/i', $raw_n)) { $line_hit('POWER_SUPPLY'); }
        if (preg_match('/\bWIRE\b|\bWIRE HARNESS\b/i', $raw_n)) { $line_hit('WIRE'); }

        // Operators (compressed + fallback)
        $op_qty = 0;
        $op_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+AUTO\s+OPENER\s+(?:9531|9542|9553|9563)\b/i');
        $op_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+OPERATOR\s+(?:9531|9542|9553|9563|SW[\- ]?200|SW[\- ]?800|HA[\- ]?8)\b/i');
        if ($op_qty === 0 && preg_match('/\b(?:9531|9542|9553|9563|SW[\- ]?200|SW[\- ]?800|HA[\- ]?8)\b/i', $raw_n)) {
            $op_qty = max(1, (int)$item_qty);
        }
        if ($op_qty > 0) { $add_count('operator', $op_qty); }

        // Mounting plates
        $mp_qty = 0;
        $mp_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+(?:AUTO\s+OPENER\s+)?MOUNT(?:ING)?\s+PLATE\b/i');
        $mp_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+PLATE\s+(?:9530\-18|9540\-18|4040XP\-18PA)\b/i');
        if ($mp_qty === 0 && preg_match('/\b(?:9530\-18|9540\-18|4040XP\-18PA)\b/i', $raw_n)) {
            $mp_qty = max(1, (int)$item_qty);
        }
        if ($mp_qty > 0) { $add_count('mount_plate', $mp_qty); }

        // Actuation / push buttons / actuators
        $act_qty = 0;
        $act_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+(?:COLUMN\s+)?ACTUATOR\s+(?:CM\-45\/?[34]|CM\-60\/?2|CM\-75[^\s]*)\b/i');
        $act_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+ACTUATOR\s+\b8310\-\d+/i');
        $act_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+ACTUATOR\s+\b20\-057\-ICX\b/i');
        if ($act_qty === 0 && preg_match('/\bACTUATOR\b/i', $raw_n)) {
            $act_qty = max(1, (int)$item_qty);
        }

        if ($act_qty > 0) {
            if (preg_match('/\bCOLUMN\s+ACTUATOR\b|\bCM\-75\b/i', $raw_n)) {
                $add_count('column_actuator', $act_qty);
            } else {
                $add_count('push_button', $act_qty);
            }
        }

        // Strikes
        $strike_qty = 0;
        $strike_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+ELECTRIC\s+STRIKE\b/i');
        if ($strike_qty === 0 && preg_match('/\b(?:1006\-|6211\-|6223\-|6300\-|6111)\b/i', $raw_n)) {
            $strike_qty = max(1, (int)$item_qty);
        }
        if ($strike_qty > 0) { $add_count('strike', $strike_qty); }

        // Relays / connector logic
        $relay_qty = 0;
        $relay_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+RELAY\b/i');
        $relay_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+RELAY\s+\bCON\-6W\b/i');
        if ($relay_qty === 0 && preg_match('/\bCON\-6W\b/i', $raw_n)) {
            $relay_qty = max(1, (int)$item_qty);
        }
        if ($relay_qty > 0) { $add_count('relay', $relay_qty); }

        // Wires / harnesses
        $wire_qty = 0;
        $wire_qty += $this->sum_subqty_matches($raw_n, '/(\d+)\s+(?:WIRE|WIRES|WIRE\s+HARNESS)\b/i');
        if ($wire_qty === 0 && preg_match('/\bWIRE\b|\bWIRE HARNESS\b/i', $raw_n)) {
            $wire_qty = max(1, (int)$item_qty);
        }
        if ($wire_qty > 0) { $add_count('wire', $wire_qty); }

        // Kits / interfaces
        if (preg_match('/\bCX\-WC\b/i', $raw_n)) {
            $q = $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+.*\bCX\-WC\b/i', $item_qty);
            $add_count('wc_kit', $q);
        }
        if (preg_match('/\bCX\-WEC\b|\bWEC10\b/i', $raw_n)) {
            $q = $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+.*(?:CX\-WEC|WEC10)\b/i', $item_qty);
            $add_count('wec_kit', $q);
        }
        if (preg_match('/\bCX\-12\b/i', $raw_n)) {
            $q = $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+.*\bCX\-12\b/i', $item_qty);
            $add_count('cx12', $q);
        }
        if (preg_match('/\bQEL\b|\bQELRX\b|\bPS90X\b/i', $raw_n)) {
            $q = max(1, $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+.*(?:QEL|QELRX|PS90X)\b/i', $item_qty));
            $add_count('qel_psu', $q);
        }
        if (preg_match('/\bKEY ?SWITCH\b|\b653\-/i', $raw_n)) {
            $q = $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+KEY\s*SWITCH\b/i', $item_qty);
            $add_count('key_switch', $q);
        }
        if (preg_match('/\bEPT\-10\b/i', $raw_n)) {
            $q = $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+.*\bEPT\-10\b/i', $item_qty);
            $add_count('power_transfer', $q);
        }
        if (preg_match('/\bDOOR CONTACT\b|\bGE947W\b/i', $raw_n)) {
            $q = $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+DOOR\s+CONTACT\b/i', $item_qty);
            $add_count('door_contact', $q);
        }
        if (preg_match('/\bCARD READER\b/i', $raw_n)) {
            $q = $this->first_subqty_or_itemqty($raw_n, '/(\d+)\s+CARD\s+READER\b/i', $item_qty);
            $add_count('card_reader', $q);
        }

        return $out;
    }

    // ---------------------------
    // Pricing
    // ---------------------------

    private function price_door_recipe(array $dr, array $guide, $tier_key, $job_door_count) {
        $bundle = $this->match_bundle_template($dr, $guide, $tier_key);

        if ($bundle) {
            return [
                'method' => 'bundle',
                'bundle_key' => $bundle['bundle_key'],
                'bundle_label' => $bundle['label'],
                'door_total' => round((float)$bundle['price'], 2),
                'parts' => $bundle['parts'],
                'review_flags' => $bundle['review_flags'],
            ];
        }

        $counts = $dr['counts'];
        $models = $dr['models'];
        $parts = [];
        $door_total = 0.0;
        $review_flags = [];

        if ($counts['operator'] > 0) {
            $op_family = $this->pick_operator_family($models);
            $comp_key = $this->operator_family_component_key($op_family);
            $unit = $this->price_component($guide, $comp_key, $tier_key);
            $amt = $counts['operator'] * $unit;
            $door_total += $amt;
            $parts[] = [
                'label' => $this->operator_label_for_door($op_family, $models),
                'qty' => $counts['operator'],
                'unit_price' => $unit,
                'line_total' => round($amt, 2),
                'bucket' => 'operator',
                'component_key' => $comp_key,
            ];
            if ($op_family === 'UNKNOWN') {
                $review_flags[] = 'Unknown operator family (generic operator pricing used)';
            }
        }

        if ($counts['push_button'] > 0) {
            $comp_key = $this->pick_push_button_component_key($models);
            $unit = $this->price_component($guide, $comp_key, $tier_key);
            $amt = $counts['push_button'] * $unit;
            $door_total += $amt;
            $parts[] = [
                'label' => $this->push_button_label($models),
                'qty' => $counts['push_button'],
                'unit_price' => $unit,
                'line_total' => round($amt, 2),
                'bucket' => 'push_button',
                'component_key' => $comp_key,
            ];
        }

        if ($counts['column_actuator'] > 0) {
            $comp_key = 'COLUMN_ACTUATOR_CM75';
            $unit = $this->price_component($guide, $comp_key, $tier_key);
            $amt = $counts['column_actuator'] * $unit;
            $door_total += $amt;
            $parts[] = [
                'label' => 'Column Actuator (CM-75 family)',
                'qty' => $counts['column_actuator'],
                'unit_price' => $unit,
                'line_total' => round($amt, 2),
                'bucket' => 'column_actuator',
                'component_key' => $comp_key,
            ];
        }

        if ($counts['strike'] > 0) {
            $comp_key = $this->pick_strike_component_key($models);
            $unit = $this->price_component($guide, $comp_key, $tier_key);
            $amt = $counts['strike'] * $unit;
            $door_total += $amt;
            $parts[] = [
                'label' => 'Electric Strike Integration',
                'qty' => $counts['strike'],
                'unit_price' => $unit,
                'line_total' => round($amt, 2),
                'bucket' => 'strike',
                'component_key' => $comp_key,
            ];
        }

        foreach ([
            ['bucket' => 'relay', 'key' => 'RELAY_CON6W', 'label' => 'Relay / CON-6W Integration'],
            ['bucket' => 'wire', 'key' => 'WIRE_MISC', 'label' => 'Wiring / Harness'],
            ['bucket' => 'mount_plate', 'key' => 'MOUNT_PLATE', 'label' => 'Mounting Plate (Install / Modify)'],
            ['bucket' => 'wc_kit', 'key' => 'WC_KIT', 'label' => 'Washroom Kit Integration (CX-WC family)'],
            ['bucket' => 'wec_kit', 'key' => 'WEC_KIT', 'label' => 'Emergency Kit Integration (CX-WEC / WEC10)'],
            ['bucket' => 'cx12', 'key' => 'CX12_INTEGRATION', 'label' => 'Security Interface (CX-12)'],
            ['bucket' => 'qel_psu', 'key' => 'QEL_PSU_INTEGRATION', 'label' => 'QEL / Power Supply Integration'],
            ['bucket' => 'key_switch', 'key' => 'KEY_SWITCH', 'label' => 'Key Switch Integration'],
            ['bucket' => 'power_transfer', 'key' => 'POWER_TRANSFER_EPT10', 'label' => 'Power Transfer (EPT-10)'],
        ] as $def) {
            $b = $def['bucket'];
            if (!empty($counts[$b])) {
                $unit = $this->price_component($guide, $def['key'], $tier_key);
                $amt = ((int)$counts[$b]) * $unit;
                $door_total += $amt;
                $parts[] = [
                    'label' => $def['label'],
                    'qty' => (int)$counts[$b],
                    'unit_price' => $unit,
                    'line_total' => round($amt, 2),
                    'bucket' => $b,
                    'component_key' => $def['key'],
                ];
            }
        }

        if (!empty($counts['door_contact'])) {
            $review_flags[] = 'Door contacts detected (often by others / optional)';
        }
        if (!empty($counts['card_reader'])) {
            $review_flags[] = 'Card readers detected (often by others / Div 28)';
        }
        if (!empty($dr['unknown_items'])) {
            $review_flags[] = 'Unclassified scoped items detected; review recommended';
        }

        return [
            'method' => 'component_sum',
            'bundle_key' => null,
            'bundle_label' => null,
            'door_total' => round($door_total, 2),
            'parts' => $parts,
            'review_flags' => $review_flags,
        ];
    }

    private function match_bundle_template(array $dr, array $guide, $tier_key) {
        $c = $dr['counts'];
        $models = $dr['models'];
        $has = function($m) use ($models) { return in_array($m, $models, true); };

        // SW200 + 2 PB + 2 wires (common)
        if ($has('SW200') && $c['operator'] >= 1 && $c['push_button'] >= 2 && $c['wire'] >= 2 && $c['cx12'] < 1) {
            $bkey = 'SW200_BASIC_2PB_2WIRE';
            if (isset($guide['bundles'][$bkey])) {
                return [
                    'bundle_key' => $bkey,
                    'label' => 'SW200 + 2 push buttons + 2 wires',
                    'price' => $this->bundle_price_for_tier($guide['bundles'][$bkey], $tier_key),
                    'parts' => [
                        ['label' => 'SW200 Auto Operator Install', 'qty' => 1],
                        ['label' => 'Push Buttons / Actuators', 'qty' => 2],
                        ['label' => 'Wires', 'qty' => 2],
                    ],
                    'review_flags' => [],
                ];
            }
        }

        // User requested generic tiered bundle
        if ($c['operator'] >= 1 && $c['push_button'] >= 2 && $c['strike'] >= 1) {
            $bkey = 'BASIC_OPERATOR_2PB_STRIKE';
            if (isset($guide['bundles'][$bkey])) {
                return [
                    'bundle_key' => $bkey,
                    'label' => 'Basic Operator + 2 Buttons + Strike',
                    'price' => $this->bundle_price_for_tier($guide['bundles'][$bkey], $tier_key),
                    'parts' => [
                        ['label' => $this->operator_label_for_door($this->pick_operator_family($models), $models), 'qty' => 1],
                        ['label' => $this->push_button_label($models), 'qty' => 2],
                        ['label' => 'Electric Strike Integration', 'qty' => 1],
                    ],
                    'review_flags' => ['Bundle pricing applied (verify specialty conditions/site complexity)'],
                ];
            }
        }

        // LCN95xx + strike + actuation package
        if (($has('9531') || $has('9542') || $has('9553') || $has('9563')) &&
            $c['operator'] >= 1 && $c['strike'] >= 1 && ($c['column_actuator'] >= 1 || $c['push_button'] >= 2)) {
            $bkey = 'LCN9500_STRIKE_ACTUATION';
            if (isset($guide['bundles'][$bkey])) {
                return [
                    'bundle_key' => $bkey,
                    'label' => 'LCN 9500 Series + Strike + Actuation Package',
                    'price' => $this->bundle_price_for_tier($guide['bundles'][$bkey], $tier_key),
                    'parts' => [
                        ['label' => $this->operator_label_for_door($this->pick_operator_family($models), $models), 'qty' => 1],
                        ['label' => 'Electric Strike Integration', 'qty' => 1],
                        ['label' => ($c['column_actuator'] >= 1 ? 'Column Actuator' : 'Push Buttons / Actuators'), 'qty' => ($c['column_actuator'] >= 1 ? max(1, $c['column_actuator']) : 2)],
                    ],
                    'review_flags' => [],
                ];
            }
        }

        return null;
    }

    private function format_quote_line(array $dr, array $priced) {
        $door_id = isset($dr['door_id']) ? $dr['door_id'] : 'UNKNOWN';
        $prefix = 'ADO installation ' . $door_id . ': ';

        if ($priced['method'] === 'bundle') {
            $parts_text = [];
            foreach ($priced['parts'] as $p) {
                $qty = isset($p['qty']) ? (int)$p['qty'] : 1;
                $label = isset($p['label']) ? (string)$p['label'] : 'Item';
                $parts_text[] = ($qty > 1 ? ($qty . ' ' . $label) : $label);
            }
            return $prefix . 'Install ' . implode(', ', $parts_text) . ' = $' . number_format((float)$priced['door_total'], 2);
        }

        $parts_text = [];
        foreach ($priced['parts'] as $p) {
            $qty = (int)$p['qty'];
            $label = (string)$p['label'];
            $amt = number_format((float)$p['line_total'], 2);
            $parts_text[] = ($qty > 1 ? ($qty . ' ' . $label) : $label) . ' ($' . $amt . ')';
        }

        if (empty($parts_text)) {
            $parts_text[] = 'Scope review required (no priceable components auto-detected)';
        }

        return $prefix . 'Install ' . implode(', ', $parts_text) . ' = $' . number_format((float)$priced['door_total'], 2);
    }

    // ---------------------------
    // File render
    // ---------------------------

    private function render_estimate_pdf_or_html(array $result, array $opts) {
        $output_dir   = rtrim((string)$opts['output_dir'], '/');
        $quote_number = (string)$opts['quote_number'];
        $project_name = (string)$opts['project_name'];
        $client_name  = (string)$opts['client_name'];
        $fpdf_path    = isset($opts['fpdf_path']) ? $opts['fpdf_path'] : null;

        if (!is_dir($output_dir)) {
            wp_mkdir_p($output_dir);
        }

        $safe_base = sanitize_file_name($quote_number . '-' . $project_name);
        if ($safe_base === '') { $safe_base = 'adx-estimate'; }

        if ($fpdf_path && file_exists($fpdf_path)) {
            require_once $fpdf_path;
        }

        if (class_exists('FPDF')) {
            $pdf_path = $output_dir . '/' . $safe_base . '.pdf';

            $pdf = new FPDF();
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();

            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 8, 'AUTO DOOR EXPERTS - ESTIMATE', 0, 1, 'L');

            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(0, 6, 'Quote #: ' . $quote_number, 0, 1, 'L');
            $pdf->Cell(0, 6, 'Client: ' . $client_name, 0, 1, 'L');
            $pdf->Cell(0, 6, 'Project: ' . $project_name, 0, 1, 'L');
            $pdf->Cell(0, 6, 'Date: ' . current_time('Y-m-d'), 0, 1, 'L');
            $pdf->Ln(2);

            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 6, 'Scope / Line Items', 0, 1, 'L');
            $pdf->SetFont('Arial', '', 9);

            foreach ($result['line_items'] as $line) {
                $pdf->MultiCell(190, 5, $this->fpdf_clean_text($line), 0, 'L');
            }

            $pdf->Ln(3);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(150, 6, 'Subtotal', 0, 0, 'R');
            $pdf->Cell(40, 6, '$' . number_format((float)$result['summary']['subtotal'], 2), 0, 1, 'R');

            $tax_label = 'Tax (' . round(((float)$result['summary']['tax_rate']) * 100, 2) . '%)';
            $pdf->Cell(150, 6, $tax_label, 0, 0, 'R');
            $pdf->Cell(40, 6, '$' . number_format((float)$result['summary']['tax'], 2), 0, 1, 'R');

            $pdf->Cell(150, 7, 'Total', 0, 0, 'R');
            $pdf->Cell(40, 7, '$' . number_format((float)$result['summary']['total'], 2), 0, 1, 'R');

            $pdf->Ln(4);
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->MultiCell(0, 4, 'Auto-generated from scoped hardware JSON. Final pricing subject to field conditions, wiring path complexity, and existing frame/door prep.');

            $pdf->Output('F', $pdf_path);

            return [
                'mode' => 'pdf',
                'path' => $pdf_path,
                'basename' => basename($pdf_path),
            ];
        }

        // HTML fallback if FPDF is not present
        $html_path = $output_dir . '/' . $safe_base . '.html';

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Estimate</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;font-size:13px;line-height:1.35;margin:30px}h1{font-size:20px;margin:0 0 6px}h2{font-size:14px;margin:16px 0 6px}.meta{margin:0 0 2px}.line{margin:0 0 6px}.totals{margin-top:16px;border-top:1px solid #ccc;padding-top:10px;max-width:420px;margin-left:auto}.totals div{display:flex;justify-content:space-between;margin:2px 0}.note{margin-top:14px;font-size:11px;color:#555}</style>';
        $html .= '</head><body>';
        $html .= '<h1>AUTO DOOR EXPERTS - ESTIMATE</h1>';
        $html .= '<div class="meta"><strong>Quote #:</strong> ' . esc_html($quote_number) . '</div>';
        $html .= '<div class="meta"><strong>Client:</strong> ' . esc_html($client_name) . '</div>';
        $html .= '<div class="meta"><strong>Project:</strong> ' . esc_html($project_name) . '</div>';
        $html .= '<div class="meta"><strong>Date:</strong> ' . esc_html(current_time('Y-m-d')) . '</div>';
        $html .= '<h2>Scope / Line Items</h2>';
        foreach ($result['line_items'] as $line) {
            $html .= '<div class="line">' . esc_html($line) . '</div>';
        }
        $html .= '<div class="totals">';
        $html .= '<div><span>Subtotal</span><strong>$' . number_format((float)$result['summary']['subtotal'], 2) . '</strong></div>';
        $html .= '<div><span>Tax</span><strong>$' . number_format((float)$result['summary']['tax'], 2) . '</strong></div>';
        $html .= '<div><span>Total</span><strong>$' . number_format((float)$result['summary']['total'], 2) . '</strong></div>';
        $html .= '</div>';
        $html .= '<div class="note">FPDF not found; this is an HTML fallback. Add FPDF to generate PDFs.</div>';
        $html .= '</body></html>';

        file_put_contents($html_path, $html);

        return [
            'mode' => 'html_fallback',
            'path' => $html_path,
            'basename' => basename($html_path),
            'warning' => 'FPDF not found; wrote HTML fallback',
        ];
    }

    private function fpdf_clean_text($s) {
        $s = (string)$s;
        $s = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $s);
        $s = preg_replace('/[^\x20-\x7E]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    // ---------------------------
    // Helpers
    // ---------------------------

    private function norm($s) {
        $s = strtoupper((string)$s);
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        $s = str_replace(["“","”","’","`"], ['"','"',"'",''], $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private function sum_subqty_matches($text, $regex) {
        $sum = 0;
        if (preg_match_all($regex, $text, $m) && !empty($m[1])) {
            foreach ($m[1] as $n) { $sum += (int)$n; }
        }
        return (int)$sum;
    }

    private function first_subqty_or_itemqty($text, $regex, $item_qty) {
        if (preg_match($regex, $text, $m) && isset($m[1])) {
            return max(1, (int)$m[1]);
        }
        return max(1, (int)$item_qty);
    }

    private function is_known_nonpriced_line($raw_n) {
        if (strpos($raw_n, 'BY DIV.28') !== false) return true;
        if (strpos($raw_n, 'BY OTHERS') !== false) return true;
        if (strpos($raw_n, 'CARD READER') !== false) return true;
        if (strpos($raw_n, 'POWER SUPPLY') !== false) return true;
        return false;
    }

    private function pick_door_count_tier($door_count) {
        $n = (int)$door_count;
        if ($n <= 2) return 'T1_1_2';
        if ($n <= 5) return 'T2_3_5';
        if ($n <= 10) return 'T3_6_10';
        if ($n <= 20) return 'T4_11_20';
        return 'T5_20P';
    }

    private function pricing_guide() {
        return [
            'components' => [
                'OPERATOR_LCN_95XX' => ['tier_unit' => ['T1_1_2'=>340,'T2_3_5'=>325,'T3_6_10'=>310,'T4_11_20'=>300,'T5_20P'=>290]],
                'OPERATOR_SW200'    => ['tier_unit' => ['T1_1_2'=>475,'T2_3_5'=>460,'T3_6_10'=>450,'T4_11_20'=>435,'T5_20P'=>420]],
                'OPERATOR_SW800'    => ['tier_unit' => ['T1_1_2'=>500,'T2_3_5'=>480,'T3_6_10'=>460,'T4_11_20'=>445,'T5_20P'=>430]],
                'OPERATOR_HA8_8100' => ['tier_unit' => ['T1_1_2'=>420,'T2_3_5'=>405,'T3_6_10'=>390,'T4_11_20'=>375,'T5_20P'=>360]],
                'OPERATOR_GENERIC'  => ['tier_unit' => ['T1_1_2'=>425,'T2_3_5'=>400,'T3_6_10'=>380,'T4_11_20'=>360,'T5_20P'=>340]],

                'ACTUATOR_8310'        => ['tier_unit' => ['T1_1_2'=>75,'T2_3_5'=>70,'T3_6_10'=>65,'T4_11_20'=>60,'T5_20P'=>55]],
                'ACTUATOR_CM45_CM60'   => ['tier_unit' => ['T1_1_2'=>40,'T2_3_5'=>35,'T3_6_10'=>30,'T4_11_20'=>28,'T5_20P'=>25]],
                'COLUMN_ACTUATOR_CM75' => ['tier_unit' => ['T1_1_2'=>95,'T2_3_5'=>90,'T3_6_10'=>85,'T4_11_20'=>80,'T5_20P'=>75]],

                'STRIKE_STD'   => ['tier_unit' => ['T1_1_2'=>60,'T2_3_5'=>55,'T3_6_10'=>50,'T4_11_20'=>50,'T5_20P'=>45]],
                'STRIKE_HEAVY' => ['tier_unit' => ['T1_1_2'=>75,'T2_3_5'=>70,'T3_6_10'=>65,'T4_11_20'=>60,'T5_20P'=>55]],

                'RELAY_CON6W'         => ['tier_unit' => ['T1_1_2'=>40,'T2_3_5'=>35,'T3_6_10'=>30,'T4_11_20'=>30,'T5_20P'=>25]],
                'WIRE_MISC'           => ['tier_unit' => ['T1_1_2'=>20,'T2_3_5'=>18,'T3_6_10'=>15,'T4_11_20'=>15,'T5_20P'=>12]],
                'MOUNT_PLATE'         => ['tier_unit' => ['T1_1_2'=>65,'T2_3_5'=>60,'T3_6_10'=>55,'T4_11_20'=>50,'T5_20P'=>45]],
                'QEL_PSU_INTEGRATION' => ['tier_unit' => ['T1_1_2'=>125,'T2_3_5'=>115,'T3_6_10'=>110,'T4_11_20'=>100,'T5_20P'=>95]],
                'CX12_INTEGRATION'    => ['tier_unit' => ['T1_1_2'=>90,'T2_3_5'=>85,'T3_6_10'=>80,'T4_11_20'=>75,'T5_20P'=>70]],
                'WC_KIT'              => ['tier_unit' => ['T1_1_2'=>325,'T2_3_5'=>310,'T3_6_10'=>295,'T4_11_20'=>285,'T5_20P'=>275]],
                'WEC_KIT'             => ['tier_unit' => ['T1_1_2'=>130,'T2_3_5'=>120,'T3_6_10'=>115,'T4_11_20'=>110,'T5_20P'=>100]],
                'KEY_SWITCH'          => ['tier_unit' => ['T1_1_2'=>85,'T2_3_5'=>80,'T3_6_10'=>75,'T4_11_20'=>75,'T5_20P'=>70]],
                'POWER_TRANSFER_EPT10'=> ['tier_unit' => ['T1_1_2'=>70,'T2_3_5'=>65,'T3_6_10'=>60,'T4_11_20'=>55,'T5_20P'=>50]],
            ],
            'bundles' => [
                'BASIC_OPERATOR_2PB_STRIKE' => ['tier_price' => ['T1_1_2'=>575,'T2_3_5'=>550,'T3_6_10'=>525,'T4_11_20'=>500,'T5_20P'=>475]],
                'SW200_BASIC_2PB_2WIRE'     => ['tier_price' => ['T1_1_2'=>560,'T2_3_5'=>550,'T3_6_10'=>540,'T4_11_20'=>520,'T5_20P'=>500]],
                'LCN9500_STRIKE_ACTUATION'  => ['tier_price' => ['T1_1_2'=>650,'T2_3_5'=>625,'T3_6_10'=>600,'T4_11_20'=>575,'T5_20P'=>550]],
            ],
        ];
    }

    private function price_component(array $guide, $component_key, $tier_key) {
        if (!isset($guide['components'][$component_key])) return 50.0;
        $comp = $guide['components'][$component_key];
        if (isset($comp['tier_unit'][$tier_key])) return (float)$comp['tier_unit'][$tier_key];

        if (!empty($comp['tier_unit']) && is_array($comp['tier_unit'])) {
            $vals = array_values($comp['tier_unit']);
            return (float)round(array_sum($vals) / count($vals), 2);
        }
        return 50.0;
    }

    private function bundle_price_for_tier(array $bundle, $tier_key) {
        if (isset($bundle['tier_price'][$tier_key])) return (float)$bundle['tier_price'][$tier_key];
        if (!empty($bundle['tier_price']) && is_array($bundle['tier_price'])) {
            $vals = array_values($bundle['tier_price']);
            return (float)round(array_sum($vals) / count($vals), 2);
        }
        return 0.0;
    }

    private function pick_operator_family(array $models) {
        if (in_array('SW200', $models, true)) return 'SW200';
        if (in_array('SW800', $models, true)) return 'SW800';
        if (in_array('HA8', $models, true)) return 'HA8_8100';
        foreach (['9531','9542','9553','9563'] as $m) {
            if (in_array($m, $models, true)) return 'LCN_95XX';
        }
        return 'UNKNOWN';
    }

    private function operator_family_component_key($family) {
        switch ($family) {
            case 'SW200': return 'OPERATOR_SW200';
            case 'SW800': return 'OPERATOR_SW800';
            case 'HA8_8100': return 'OPERATOR_HA8_8100';
            case 'LCN_95XX': return 'OPERATOR_LCN_95XX';
            default: return 'OPERATOR_GENERIC';
        }
    }

    private function pick_push_button_component_key(array $models) {
        if (in_array('8310', $models, true) || in_array('20-057-ICX', $models, true)) {
            return 'ACTUATOR_8310';
        }
        return 'ACTUATOR_CM45_CM60';
    }

    private function pick_strike_component_key(array $models) {
        if (in_array('6111', $models, true) || in_array('6300', $models, true)) return 'STRIKE_HEAVY';
        return 'STRIKE_STD';
    }

    private function operator_label_for_door($family, array $models) {
        if (in_array('SW200', $models, true)) return 'SW200 Auto Operator Install';
        if (in_array('SW800', $models, true)) return 'SW-800 Auto Operator Install';
        if (in_array('HA8', $models, true)) return 'HA-8 Auto Operator Install';
        if (in_array('9531', $models, true)) return 'LCN 9531 Auto Opener Install';
        if (in_array('9542', $models, true)) return 'LCN 9542 Auto Opener Install';
        if (in_array('9553', $models, true)) return 'LCN 9553 Auto Opener Install';
        if (in_array('9563', $models, true)) return 'LCN 9563 Auto Opener Install';

        switch ($family) {
            case 'LCN_95XX': return 'LCN 95xx Auto Opener Install';
            case 'HA8_8100': return 'HA-8 / 8100 Auto Operator Install';
            case 'SW200': return 'SW200 Auto Operator Install';
            case 'SW800': return 'SW-800 Auto Operator Install';
            default: return 'Automatic Door Operator Install';
        }
    }

    private function push_button_label(array $models) {
        if (in_array('CM45', $models, true)) return 'CM-45 Push Button / Actuator';
        if (in_array('CM60', $models, true)) return 'CM-60 Push Button / Actuator';
        if (in_array('8310', $models, true)) return '8310 Actuator';
        if (in_array('20-057-ICX', $models, true)) return '20-057-ICX Actuator';
        if (in_array('CM75', $models, true)) return 'CM-75 Column Actuator';
        return 'Push Button / Actuator';
    }
}

class AutoDoorPDFDebug_Hostinger {

    private $homePdftotext = '/home/u236173098/bin/pdftotext';
    private $did_render = false;

    /** @var ADX_Debug */
    private $dbg;

    /** @var ADX_Extractor */
    private $extractor;

    /** @var ADX_Parser */
    private $parser;

    /** @var ADX_Scope */
    private $scope;

    /** @var ADX_Quote */
    private $quote;

    public function __construct() {
        $this->dbg = new ADX_Debug();
        $this->extractor = new ADX_Extractor($this->homePdftotext, $this->dbg);
        $this->parser = new ADX_Parser($this->dbg);
        $this->scope = new ADX_Scope($this->dbg);
        $this->quote = new ADX_Quote($this->dbg);

        add_shortcode('contact-form', [$this, 'render_shortcode']);
        add_action('wp_ajax_adx_parse_pdf', [$this, 'handle_ajax']);
        add_action('wp_ajax_nopriv_adx_parse_pdf', [$this, 'handle_ajax']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        wp_enqueue_script('jquery');

        wp_register_script(
            'adx-ui',
            ADX_PARSER_URL . 'assets/js/adx-ui.js',
            ['jquery'],
            ADX_PARSER_VERSION,
            true
        );
        wp_register_style(
            'adx-ui',
            ADX_PARSER_URL . 'assets/css/adx-ui.css',
            [],
            ADX_PARSER_VERSION
        );
    }

    private function is_debug_user() {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    private function is_debug_ui_requested() {
        $flag = isset($_GET['adx_debug']) ? sanitize_text_field(wp_unslash($_GET['adx_debug'])) : '';
        return $flag === '1';
    }

    private function should_show_debug_ui() {
        return $this->is_debug_user() && $this->is_debug_ui_requested();
    }

    private function get_debug_toggle_url($enabled) {
        if ($enabled) {
            return add_query_arg('adx_debug', '1');
        }
        return remove_query_arg('adx_debug');
    }

    private function ajax_debug_enabled() {
        $raw = isset($_POST['adx_debug_mode']) ? sanitize_text_field(wp_unslash($_POST['adx_debug_mode'])) : '0';
        return $raw === '1' && $this->is_debug_user();
    }

    private function send_error($message, $debug_enabled = false, $status = 200, array $extra = []) {
        $payload = array_merge(['message' => (string) $message], $extra);
        if ($debug_enabled) {
            $payload['debug'] = $this->dbg->all();
        }
        wp_send_json_error($payload, $status);
    }

    public function render_shortcode($atts = [], $content = null) {
        $this->did_render = true;
        wp_enqueue_script('adx-ui');
        wp_enqueue_style('adx-ui');
        $show_debug = $this->should_show_debug_ui();
        $nonce = wp_create_nonce('adx_parse_pdf');
        $debug_enable_url = esc_url($this->get_debug_toggle_url(true));
        $debug_disable_url = esc_url($this->get_debug_toggle_url(false));

        wp_localize_script('adx-ui', 'ADX_UI_CONFIG', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'debugEnabled' => $show_debug ? 1 : 0,
        ]);

        ob_start(); ?>
        <div class="adx-wrap<?php echo $show_debug ? ' is-debug' : ''; ?>" style="max-width:1100px">
            <div class="adx-head">
                <h2>Upload Hardware Schedule PDF</h2>
                <p class="adx-meta">Generate parser JSON, scoped JSON, and a quote document from one upload.</p>
                <?php if ($show_debug): ?>
                    <p class="adx-dev-note">Developer mode is enabled. <a href="<?php echo $debug_disable_url; ?>">Disable debug view</a></p>
                <?php elseif ($this->is_debug_user()): ?>
                    <p class="adx-dev-note">Admin tools are hidden. <a href="<?php echo $debug_enable_url; ?>">Enable debug view</a></p>
                <?php endif; ?>
            </div>

            <form id="adx-form" class="adx-form" enctype="multipart/form-data">
                <input type="hidden" name="adx_nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="adx_debug_mode" value="<?php echo $show_debug ? '1' : '0'; ?>">

                <label class="adx-file-label" for="adx-pdf-file">Hardware schedule PDF</label>
                <input id="adx-pdf-file" class="adx-file-input" type="file" name="pdf" accept="application/pdf" required>
                <button id="adx-submit" type="submit">Generate Outputs</button>
            </form>

            <p id="adx-status" class="adx-status" aria-live="polite"></p>

            <h3>Outputs</h3>
            <div class="adx-output-grid">
                <div class="adx-output-panel">
                    <div class="adx-output-title">Parser JSON</div>
                    <p class="adx-output-copy">Full parser output for door blocks and extracted items.</p>
                    <p><a id="adx-download-parser" href="#" target="_blank" rel="noopener" style="display:none">Download parser JSON</a></p>
                    <pre id="adx-preview-parser" class="adx-preview"></pre>
                </div>
                <div class="adx-output-panel">
                    <div class="adx-output-title">Scoped JSON</div>
                    <p class="adx-output-copy">Operator-only scoped output for quoting and project tracking.</p>
                    <p><a id="adx-download-scope" href="#" target="_blank" rel="noopener" style="display:none">Download scoped JSON</a></p>
                    <pre id="adx-preview-scope" class="adx-preview"></pre>
                </div>
                <div class="adx-output-panel">
                    <div class="adx-output-title">Quote Output</div>
                    <p class="adx-output-copy">Generated estimate document and pricing summary.</p>
                    <p>
                        <a id="adx-download-quote" href="#" target="_blank" rel="noopener" style="display:none">Download quote document</a><br>
                        <a id="adx-download-quote-debug" href="#" target="_blank" rel="noopener" style="display:none">Download quote pricing debug JSON</a>
                    </p>
                    <p id="adx-quote-notice" class="adx-quote-notice"></p>
                    <pre id="adx-preview-quote" class="adx-preview"></pre>
                </div>
            </div>

            <?php if ($show_debug): ?>
            <h3>Developer Debug Channels</h3>
            <div class="adx-debug-grid">
                <div class="adx-debug-panel">
                    <div class="adx-debug-title">extract.php</div>
                    <pre id="adx-debug-extract" class="adx-debug"></pre>
                </div>
                <div class="adx-debug-panel">
                    <div class="adx-debug-title">split.php</div>
                    <pre id="adx-debug-split" class="adx-debug"></pre>
                </div>
                <div class="adx-debug-panel">
                    <div class="adx-debug-title">parse.php + quote.php</div>
                    <pre id="adx-debug-parse" class="adx-debug"></pre>
                </div>
                <div class="adx-debug-panel">
                    <div class="adx-debug-title">scope.php</div>
                    <pre id="adx-debug-scope" class="adx-debug"></pre>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_ajax() {
        $this->dbg->reset();
        $debug_enabled = $this->ajax_debug_enabled();

        $nonce = isset($_POST['adx_nonce']) ? sanitize_text_field(wp_unslash($_POST['adx_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'adx_parse_pdf')) {
            $this->send_error('Security check failed. Reload the page and try again.', $debug_enabled, 403);
        }

        $this->dbg->log('parse', "=== START adx_parse_pdf ===");
        $this->dbg->log('parse', "Build: " . ADX_PARSER_BUILD . " + QUOTE");
        $this->dbg->log('parse', "PHP: " . PHP_VERSION);
        $this->dbg->log('parse', "memory_limit: " . ini_get('memory_limit'));
        $this->dbg->log('parse', "disable_functions: " . (ini_get('disable_functions') ?: '[none]'));

        if (empty($_FILES['pdf']['tmp_name'])) {
            $this->send_error('No PDF file was uploaded.', $debug_enabled);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $uploaded = wp_handle_upload($_FILES['pdf'], ['test_form'=>false]);

        if (!is_array($uploaded) || !empty($uploaded['error'])) {
            $this->dbg->log('parse', "wp_handle_upload error: " . ($uploaded['error'] ?? 'unknown'));
            $this->send_error('Upload failed. Please try again with a valid PDF.', $debug_enabled);
        }

        $pdf = $uploaded['file'];
        $src_name = isset($_FILES['pdf']['name']) ? $_FILES['pdf']['name'] : 'schedule.pdf';

        $this->dbg->log('extract', "Stored at: $pdf");
        $this->dbg->log('extract', "File exists: " . (file_exists($pdf) ? 'YES' : 'NO'));
        $this->dbg->log('extract', "File size: " . (file_exists($pdf) ? filesize($pdf) : 0));

        @set_time_limit(240);
        @ini_set('memory_limit', '768M');

        $extract = $this->extractor->extract_text_pdftotext($pdf);

        if (empty($extract['text'])) {
            $this->send_error('Could not extract text from this PDF. Confirm it is text-based and try again.', $debug_enabled);
        }

        $text = $extract['text'];
        $this->dbg->log('extract', "Extracted chars: " . strlen($text));
        $this->dbg->log('extract', "First 600 chars:\n" . substr($text, 0, 600));

        $parsed = $this->parser->adaptive_parse($text);
        $parsed_scoped = $this->scope->apply_operator_scope_filter_to_result($parsed);

        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            $this->send_error('Uploads directory is not available on the server.', $debug_enabled);
        }

        $safe_base = sanitize_file_name(pathinfo($src_name, PATHINFO_FILENAME));
        if ($safe_base === '') $safe_base = 'schedule';

        $file_raw = wp_unique_filename($uploads['basedir'], $safe_base . '-parser.json');
        $path_raw = trailingslashit($uploads['basedir']) . $file_raw;
        $url_raw  = trailingslashit($uploads['baseurl']) . $file_raw;

        $file_scoped = wp_unique_filename($uploads['basedir'], $safe_base . '-scoped.json');
        $path_scoped = trailingslashit($uploads['basedir']) . $file_scoped;
        $url_scoped  = trailingslashit($uploads['baseurl']) . $file_scoped;

        $meta = [
            'source_pdf_name' => $src_name,
            'source_pdf_size' => isset($_FILES['pdf']['size']) ? (int) $_FILES['pdf']['size'] : null,
            'stored_pdf_path' => $pdf,
            'generated_at' => current_time('c'),
            'plugin_build' => ADX_PARSER_BUILD . '+QUOTE',
        ];

        $payload_raw = ['meta' => $meta, 'result' => $parsed];
        $payload_scoped = ['meta' => $meta, 'result' => $parsed_scoped];

        $json_raw = wp_json_encode($payload_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $json_scoped = wp_json_encode($payload_scoped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json_raw) || $json_raw === '') {
            $this->send_error('Failed to encode parser JSON.', $debug_enabled);
        }
        if (!is_string($json_scoped) || $json_scoped === '') {
            $this->send_error('Failed to encode scoped JSON.', $debug_enabled);
        }

        $ok_raw = @file_put_contents($path_raw, $json_raw);
        if ($ok_raw === false) {
            $extra = $debug_enabled ? ['path' => $path_raw] : [];
            $this->send_error(
                'Failed to write parser JSON file to uploads directory.',
                $debug_enabled,
                200,
                $extra
            );
        }

        $ok_scoped = @file_put_contents($path_scoped, $json_scoped);
        if ($ok_scoped === false) {
            $extra = $debug_enabled ? ['path' => $path_scoped] : [];
            $this->send_error(
                'Failed to write scoped JSON file to uploads directory.',
                $debug_enabled,
                200,
                $extra
            );
        }

        $this->dbg->log('parse', "Wrote parser JSON: $path_raw");
        $this->dbg->log('parse', "Wrote scoped JSON: $path_scoped");

        // Quote generation from scoped JSON
        $quote_data = null;
        $quote_notice = null;
        $quote_download_url = null;
        $quote_debug_url = null;
        $quote_preview = null;

        try {
            $fpdf_candidate = ADX_PARSER_PATH . 'includes/fpdf/fpdf.php';
            if (!file_exists($fpdf_candidate)) {
                $fpdf_candidate = null;
                $this->dbg->log('parse', "[QUOTE] FPDF not found at includes/fpdf/fpdf.php (will use HTML fallback)");
            }

            $quote_number = 'Estimate_' . gmdate('Y-m-d_His');
            $project_name = pathinfo($src_name, PATHINFO_FILENAME);

            $quote_data = $this->quote->build_from_scoped_json($path_scoped, [
                'client_name'  => 'Client',
                'project_name' => $project_name,
                'quote_number' => $quote_number,
                'output_dir'   => $uploads['basedir'],
                'fpdf_path'    => $fpdf_candidate,
                'write_pdf'    => true,
                'tax_rate'     => 0.13,
            ]);

            if (is_array($quote_data) && !empty($quote_data['ok'])) {
                if ($debug_enabled) {
                    $quote_debug_file = wp_unique_filename($uploads['basedir'], $safe_base . '-quote-pricing-debug.json');
                    $quote_debug_path = trailingslashit($uploads['basedir']) . $quote_debug_file;
                    $quote_debug_json = wp_json_encode($quote_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                    if (is_string($quote_debug_json) && $quote_debug_json !== '') {
                        @file_put_contents($quote_debug_path, $quote_debug_json);
                        $quote_debug_url = trailingslashit($uploads['baseurl']) . $quote_debug_file;
                    }
                }

                if (!empty($quote_data['output']['path'])) {
                    $quote_download_url = $this->uploads_path_to_url($quote_data['output']['path'], $uploads);
                }

                $quote_preview_lines = [];
                if (!empty($quote_data['line_items']) && is_array($quote_data['line_items'])) {
                    $quote_preview_lines = array_slice($quote_data['line_items'], 0, $debug_enabled ? 40 : 8);
                }
                $quote_preview = implode("\n", $quote_preview_lines);
                if (!empty($quote_data['summary'])) {
                    $quote_preview .= "\n\nSubtotal: $" . number_format((float)$quote_data['summary']['subtotal'], 2);
                    $quote_preview .= "\nTax: $" . number_format((float)$quote_data['summary']['tax'], 2);
                    $quote_preview .= "\nTotal: $" . number_format((float)$quote_data['summary']['total'], 2);
                }

                if (!empty($quote_data['output']['mode']) && $quote_data['output']['mode'] === 'html_fallback') {
                    $quote_notice = 'Quote generated as HTML fallback (FPDF not bundled yet).';
                } else {
                    $quote_notice = 'Quote generated successfully.';
                }
            } else {
                $quote_notice = 'Quote generation failed: ' . (is_array($quote_data) ? ($quote_data['error'] ?? 'unknown error') : 'unknown error');
                $this->dbg->log('parse', '[QUOTE] ' . $quote_notice);
            }
        } catch (\Throwable $e) {
            $quote_notice = 'Quote generation exception: ' . $e->getMessage();
            $this->dbg->log('parse', '[QUOTE] EXCEPTION ' . $e->getMessage());
        } catch (\Exception $e) {
            $quote_notice = 'Quote generation exception: ' . $e->getMessage();
            $this->dbg->log('parse', '[QUOTE] EXCEPTION ' . $e->getMessage());
        }

        $response = [
            'download_url_parser' => $url_raw,
            'download_url_scope'  => $url_scoped,
            'filename_parser' => $file_raw,
            'filename_scope'  => $file_scoped,
            'download_url_quote' => $quote_download_url,
            'quote_notice' => $quote_notice,
            'quote_summary' => is_array($quote_data) && !empty($quote_data['summary']) ? $quote_data['summary'] : null,
            'line_item_count' => is_array($quote_data) && !empty($quote_data['line_items']) && is_array($quote_data['line_items'])
                ? count($quote_data['line_items'])
                : 0,
            'preview_quote' => $quote_preview ? substr($quote_preview, 0, $debug_enabled ? 20000 : 4000) : '',
        ];

        if ($debug_enabled) {
            $response['download_url_quote_debug'] = $quote_debug_url;
            $response['preview_parser'] = substr($json_raw, 0, 20000);
            $response['preview_scope'] = substr($json_scoped, 0, 20000);
            $response['debug'] = $this->dbg->all();
        }

        wp_send_json_success($response);
    }

    private function uploads_path_to_url($path, array $uploads) {
        $basedir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        $baseurl = isset($uploads['baseurl']) ? $uploads['baseurl'] : '';
        $norm = wp_normalize_path($path);

        if ($basedir && strpos($norm, $basedir) === 0) {
            $rel = ltrim(substr($norm, strlen($basedir)), '/');
            return trailingslashit($baseurl) . str_replace('%2F', '/', rawurlencode($rel));
        }

        return null;
    }
}
