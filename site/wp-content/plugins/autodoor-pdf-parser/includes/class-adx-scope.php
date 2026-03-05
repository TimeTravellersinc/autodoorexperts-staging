<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ADX_Scope — Operator Scope Filter (Deep Trace)
 * Version: 3.0.3
 *
 * PURPOSE
 * -------
 * PASS 1 (Door gate):
 *   Scan ALL item['raw'] lines in a door for operator signals.
 *   If none -> DROP door.
 *   If any  -> KEEP door.
 *
 * PASS 2 (Item filter):
 *   For kept doors, keep items matching KEEP_SIGNALS.
 *   If not matching KEEP_SIGNALS, drop only if DISCARD_SIGNALS match.
 *   Otherwise fallback KEEP (safe).
 *
 * DEBUG
 * -----
 * Logs every decision so you can follow:
 *   DOOR START -> PASS1 per line -> PASS1 RESULT -> PASS2 per line -> DOOR SUMMARY
 */
class ADX_Scope {

    /** @var ADX_Debug */
    private $dbg;

    /**
     * Intelephense-safe cache shape (never null).
     *
     * @var array{
     *   op_raw: array<string,string>,
     *   keep_raw: array<string,string>,
     *   discard_raw: array<string,string>
     * }
     */
    private $cache = [
        'op_raw'      => [],
        'keep_raw'    => [],
        'discard_raw' => [],
    ];

    // ----------------------------
    // Runtime debug controls
    // ----------------------------
    private $LOG_LIMIT_DOORS = 9999;     // total doors to log (processing continues beyond this)
    private $LOG_LIMIT_ITEMS = 999999;   // total item lines to log (processing continues beyond this)

    // Per-door throttles (prevents one huge door from flooding)
    private $LOG_PASS1_LINES_PER_DOOR = 120;
    private $LOG_PASS2_LINES_PER_DOOR = 180;

    // How many hit examples to show per door summary
    private $MAX_HIT_EXAMPLES = 8;

    public function __construct(ADX_Debug $dbg) {
        $this->dbg = $dbg;
    }

    // ================================================================
    // Debug helpers
    // ================================================================

    private function dlog($msg) {
        $ts  = sprintf('%.3f', microtime(true));
        $mem = (int) round(memory_get_usage(true) / (1024 * 1024));
        $this->dbg->log('scope', "[{$ts}][{$mem}MB] {$msg}");
    }

    private function clip($s, $n = 220) {
        $s = (string)$s;
        $s = str_replace(["\r","\n","\t"], [" "," "," "], $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return (strlen($s) <= $n) ? $s : substr($s, 0, $n) . '…';
    }

    private function uniq(array $arr): array {
        $arr = array_map('strval', $arr);
        $arr = array_filter($arr, function($v){ return $v !== ''; });
        return array_values(array_unique($arr));
    }

    // ================================================================
    // Signal tables (original strings)
    // ================================================================

    private function operator_signals(): array {
        static $s = null;
        if ($s !== null) return $s;

        $s = [
            // LCN 9100/9500 operator model numbers
            '9131', '9142',
            '9531', '9532', '9533',
            '9541', '9542', '9543',
            '9551', '9552', '9553',
            '9561', '9562', '9563',

            // Operator mounting plates (only appear with operators)
            '9530-18', '9540-18', '9560-18',
            '9530-36', '9540-36',

            // Stanley SW family
            'SW-800', 'SW800', 'SW 800',
            'SW-850', 'SW850',

            // Horton HA family (avoid "HA 8" false positive with NHA kickplate dims)
            'HA-8', 'HA8',
            'HA8LP', 'HA-8LP',
            'HA-8S', 'HA8S',

            // Electrified / power-open exit devices (door-level signal)
            'QEL',
            'QEL-',
            'QELRX-',
            'QELLX-',
            'QELLRX-',

            // Textual operator labels
            'auto opener',
            'automatic door oper',
            'automatic surface door',
            'low energy oper',
            'power operator',
            'door operator',
            'auto door',
        ];

        return $s;
    }

    private function keep_signals(): array {
        static $s = null;
        if ($s !== null) return $s;

        $s = [
            // --- Operator models ---
            '9131', '9142',
            '9531', '9532', '9533',
            '9541', '9542', '9543',
            '9551', '9552', '9553',
            '9561', '9562', '9563',
            '9530-18', '9540-18', '9560-18',
            '9530-36', '9540-36',
            'SW-800', 'SW800', 'SW 800',
            'SW-850', 'SW850',
            'HA-8', 'HA8', 'HA8LP', 'HA-8LP',
            'auto opener',
            'automatic door oper',
            'automatic surface door',
            'low energy oper',
            'power operator',
            'door operator',
            'auto door',

            // --- Actuator / push plate family ---
            '8310-',
            '852T',
            '869S',
            '869F',

            // --- Push buttons / column actuators ---
            'CM-45/',
            'CM-46/',
            'CM-75',
            'column actuator',

            // --- Electrified / latch retract exit devices ---
            'QEL',

            // --- Electrified strikes ---
            'electric strike',
            'electrified strike',
            '1006-',
            '1006CS',
            '6111-',
            '6211-',
            '6223-',
            '6300-',
            '9500-',
            '1500C',
            'FSE CON',

            // --- Power supplies ---
            'PS902',
            'PS904',
            'PS210',
            '900-4RL',
            '900-8F',
            '900-BB',
            '900-KL',
            'power supply',

            // --- Power transfer hinges ---
            'EPT-10',
            'EPT10',
            'EPT 10',
            'power transfer',

            // --- Electrified exit devices ---
            'QEL-',
            'QELRX-',
            'LX-RX-',
            'latch retract',
            'electrified exit',
            '-BE-F',
            '-BE-R',

            // --- Wire harnesses / connectors ---
            'CON-6W',
            'CON-44',
            'CON-192',
            'wire harness',
            'pigtail',
            '2007M',

            // --- CX control accessories ---
            'CX-',

            // --- Mounting plates / brackets ---
            'mounting plate',
            'mounting bracket',
            'mounting kit',
            'adapter plate',

            // --- Switches and sensors ---
            'push button',
            'key switch',
            'motion sensor',
            'position switch',
            'on/off',
            'door contact',

            // --- Integration / control boxes ---
            'relay',
            'logic relay',
            'integration box',
            'junction box',
            'nexgen',
            'tapeswitch',
            'washroom control',

            // --- Access control peripherals (kept if operator door) ---
            'card reader',
            'intercom',
            'nurse call',

            // --- Misc ---
            'SE21A',
        ];

        return $s;
    }

    private function discard_signals(): array {
        static $s = null;
        if ($s !== null) return $s;

        $s = [
            // Hinges (mechanical)
            'standard hinge',
            'butt hinge',
            'continuous hinge',
            'pivot hinge',
            'swing clear hinge',
            'full mortise hinge',

            // Locks / cylinders (mechanical)
            'lockset',
            'latchset',
            'deadbolt',
            'dead lock',
            'deadlock',
            'flush bolt',
            'dust proof strike',
            'floor bolt',
            'surface bolt',

            // Cylinders / cores
            'removable core',
            'permanent core',
            'perm cylinder',
            'ic core',

            // Stops / coordinators
            'overhead door stop',
            'overhead door holder',
            'wall door stop',
            'floor stop',
            'door stop',
            'floor door stop',
            'coordinator',

            // Gasketing / seals / thresholds
            'gasketing',
            'weatherseal',
            'soundseal',
            'mullion gasketing',
            'threshold',
            'door sweep',
            'astragal',

            // Architectural trim / pulls
            'kick plate',
            'mop plate',
            'push plate',
            'door pull',
            'pull handle',
            'door handle',

            // Signage / misc
            'coat hook',
            'balance of hardware',
            'by door and frame supplier',
            'by door supplier',
            'pull station',
            'fire alarm',

            // Mechanical closers (not operators)
            'surface closer 1461',
            'surface closer 4040xp',
            'surface closer 4021',
            '1461 regarm',
            '4040xp regarm',
            '4040xp eda',
            '4021 long',

            // Plain key cylinders (mechanical)
            'cylinder 20-7',
            'cylinder 20-06',
        ];

        return $s;
    }

    // ================================================================
    // Cache builder (Intelephense-safe)
    // ================================================================

    private function ensure_signal_caches() {
        if (empty($this->cache['op_raw'])) {
            foreach ($this->operator_signals() as $sig) {
                $this->cache['op_raw'][$sig] = strtoupper($sig);
            }
            $this->dlog("CACHE op_signals=" . count($this->cache['op_raw']));
        }

        if (empty($this->cache['keep_raw'])) {
            foreach ($this->keep_signals() as $sig) {
                $this->cache['keep_raw'][$sig] = strtoupper($sig);
            }
            $this->dlog("CACHE keep_signals=" . count($this->cache['keep_raw']));
        }

        if (empty($this->cache['discard_raw'])) {
            foreach ($this->discard_signals() as $sig) {
                $this->cache['discard_raw'][$sig] = strtoupper($sig);
            }
            $this->dlog("CACHE discard_signals=" . count($this->cache['discard_raw']));
        }
    }

    // ================================================================
    // Matching utilities
    // ================================================================

    /**
     * Returns [hits[], checks_int]
     */
    private function find_signal_hits($raw, array $bankUpperByOrig): array {
        $rawU = strtoupper((string)$raw);
        if ($rawU === '') return [[], 0];

        $hits = [];
        $checks = 0;

        foreach ($bankUpperByOrig as $orig => $sigU) {
            $checks++;
            if ($sigU === '') continue;
            if (strpos($rawU, $sigU) !== false) {
                $hits[] = (string)$orig;
            }
        }

        return [$hits, $checks];
    }

    /**
     * PASS 1: determine if door has operator evidence.
     * Returns [bool hasOp, signalsHit[], hitExamples[]]
     */
    private function door_has_operator(array $items, string $doorId, int &$loggedItemsThisDoor, int &$loggedItemsTotal): array {
        $doorHits = [];
        $examples = [];

        $i = 0;
        foreach ($items as $it) {
            $i++;
            $raw = (string)($it['raw'] ?? '');

            if ($raw === '') {
                if ($loggedItemsThisDoor < $this->LOG_PASS1_LINES_PER_DOOR && $loggedItemsTotal < $this->LOG_LIMIT_ITEMS) {
                    $this->dlog("PASS1 door={$doorId} line#{$i} raw=EMPTY -> no_check");
                    $loggedItemsThisDoor++;
                    $loggedItemsTotal++;
                }
                continue;
            }

            list($hits, $checks) = $this->find_signal_hits($raw, $this->cache['op_raw']);

            if ($loggedItemsThisDoor < $this->LOG_PASS1_LINES_PER_DOOR && $loggedItemsTotal < $this->LOG_LIMIT_ITEMS) {
                if (!empty($hits)) {
                    $this->dlog("PASS1 door={$doorId} line#{$i} OP_HIT checks={$checks} hits=" . implode(',', array_slice($hits, 0, 8)) .
                        ' raw="' . $this->clip($raw, 140) . '"');
                } else {
                    $this->dlog("PASS1 door={$doorId} line#{$i} no_hit checks={$checks} raw=\"" . $this->clip($raw, 140) . '"');
                }
                $loggedItemsThisDoor++;
                $loggedItemsTotal++;
            }

            if (!empty($hits)) {
                $doorHits = array_merge($doorHits, $hits);

                foreach ($hits as $h) {
                    if (count($examples) >= $this->MAX_HIT_EXAMPLES) break;
                    $examples[] = 'sig=' . $h . ' raw="' . $this->clip($raw, 120) . '"';
                }
            }
        }

        $doorHits = $this->uniq($doorHits);
        return [!empty($doorHits), $doorHits, $examples];
    }

    /**
     * PASS 2: item decision in operator doors.
     * Returns [bool keep, string reason, hits[]]
     */
    private function item_decision(string $raw): array {
        list($keepHits, $keepChecks) = $this->find_signal_hits($raw, $this->cache['keep_raw']);
        if (!empty($keepHits)) {
            return [true, "keep_signal(checked={$keepChecks})", $keepHits];
        }

        list($discardHits, $discChecks) = $this->find_signal_hits($raw, $this->cache['discard_raw']);
        if (!empty($discardHits)) {
            // Electric/electrified exception for cylinder-ish lines
            $rawU = strtoupper($raw);
            $looksCylinderish = (strpos($rawU, 'CYLINDER') !== false) || (strpos($rawU, 'CORE') !== false);
            $isElectricish    = (strpos($rawU, 'ELECTRIC') !== false) || (strpos($rawU, 'ELECTRIFIED') !== false);

            if ($looksCylinderish && $isElectricish) {
                return [true, "electric_cylinder_exception(discard_checked={$discChecks})", ['ELECTRIC/ELECTRIFIED + CYLINDER/CORE']];
            }

            return [false, "discard_signal(checked={$discChecks})", $discardHits];
        }

        if ($this->is_safe_fallback_line($raw)) {
            return [true, "fallback_keep_safe(discard_checked={$discChecks})", []];
        }

        return [false, "fallback_drop(discard_checked={$discChecks})", []];
    }

    /**
     * Purpose of fallback:
     * Keep electrified/operator-adjacent lines that may be misspelled or not in keep/discard banks,
     * while dropping obvious drawing/meta/noise lines.
     */
    private function is_safe_fallback_line(string $raw): bool {
        $u = strtoupper(trim($raw));
        if ($u === '') return false;

        // Hard noise rejects: dimensions, drafting notes, and external-div placeholders.
        if (preg_match('/\bSIZE\s+TO\s+SUIT\b/i', $u)) return false;
        if (preg_match('/\bBY\s+DIV\.?\s*\.?\s*28\b/i', $u)) return false;
        if (preg_match('/\bBY\s+OTHERS\b/i', $u)) return false;
        if (preg_match('/\bPLAM\b|\bDR\s+X\s+HM\s+FR\b/i', $u)) return false;
        if (preg_match('/^\s*\d+\s*[Xx]\s*\d+(\s*[Xx]\s*[_\d]+)?\b/', $u)) return false;
        if (!preg_match('/[A-Z]/', $u)) return false;

        // Fallback positives: keep electrified/integration context even with typos.
        if (preg_match('/\bINTER?GRATION\s+BOX\b/i', $u)) return true; // catches "Intergration"
        if (preg_match('/\bELECTRONIC\s+LOCKING\s+DEVICE\b/i', $u)) return true;
        if (preg_match('/\bPOWER\s+TRANSFER\b|\bEPT[\-\s]?10\b/i', $u)) return true;
        if (preg_match('/\bCARD\s+READER\b|\bINTERCOM\b|\bNURSE\s+CALL\b/i', $u)) return true;
        if (preg_match('/\bRELAY\b|\bCON[\-\s]?6W\b|\bWIRE\s+HARNESS\b/i', $u)) return true;
        if (preg_match('/\bCYLINDER\s+20\-057\-ICX\b/i', $u)) return true;

        return false;
    }

    // ================================================================
    // Main entry point
    // ================================================================

    public function apply_operator_scope_filter_to_result(array $result): array {
        if (empty($result['doors']) || !is_array($result['doors'])) {
            $this->dlog('SCOPE: no doors in result, returning unchanged');
            return $result;
        }

        $this->ensure_signal_caches();

        $doorsIn = $result['doors'];
        $doorsInCnt = count($doorsIn);

        $this->dlog("=== SCOPE START === doors_in={$doorsInCnt}");
        $this->dlog("LIMITS doors={$this->LOG_LIMIT_DOORS} items={$this->LOG_LIMIT_ITEMS} pass1_per_door={$this->LOG_PASS1_LINES_PER_DOOR} pass2_per_door={$this->LOG_PASS2_LINES_PER_DOOR}");

        $outDoors     = [];
        $itemsBefore  = 0;
        $itemsAfter   = 0;
        $droppedDoors = 0;

        $loggedDoorsTotal = 0;
        $loggedItemsTotal = 0;

        $doorIndex = 0;
        foreach ($doorsIn as $door) {
            $doorIndex++;

            $doorId = (string)($door['door_id'] ?? '?');
            $items  = (is_array($door['items'] ?? null)) ? $door['items'] : [];
            $itemCount = count($items);
            $itemsBefore += $itemCount;

            if ($loggedDoorsTotal < $this->LOG_LIMIT_DOORS) {
                $this->dlog("--- DOOR START --- idx={$doorIndex} door={$doorId} items={$itemCount}");
            }

            // PASS 1
            $loggedItemsThisDoor = 0;
            list($doorHasOperator, $doorOperatorHits, $hitExamples) = $this->door_has_operator($items, $doorId, $loggedItemsThisDoor, $loggedItemsTotal);

            if (!$doorHasOperator) {
                $droppedDoors++;
                if ($loggedDoorsTotal < $this->LOG_LIMIT_DOORS) {
                    $this->dlog("PASS1 RESULT door={$doorId} DROP reason=no_operator_signal scanned_items={$itemCount}");
                    $this->dlog("--- DOOR END --- idx={$doorIndex} door={$doorId} verdict=DROP");
                }
                $loggedDoorsTotal++;
                continue;
            }

            if ($loggedDoorsTotal < $this->LOG_LIMIT_DOORS) {
                $this->dlog("PASS1 RESULT door={$doorId} KEEP reason=operator_signal hits=" . implode(',', $doorOperatorHits));
                if (!empty($hitExamples)) {
                    foreach ($hitExamples as $ex) {
                        $this->dlog("PASS1 HIT_EXAMPLE door={$doorId} {$ex}");
                    }
                }
            }

            // PASS 2
            $keptItems = [];
            $pass2LoggedThisDoor = 0;

            $lineNo = 0;
            foreach ($items as $it) {
                $lineNo++;
                $raw = (string)($it['raw'] ?? '');

                if ($raw === '') {
                    if ($pass2LoggedThisDoor < $this->LOG_PASS2_LINES_PER_DOOR && $loggedItemsTotal < $this->LOG_LIMIT_ITEMS) {
                        $this->dlog("PASS2 door={$doorId} line#{$lineNo} raw=EMPTY -> DROP reason=empty_raw");
                        $pass2LoggedThisDoor++;
                        $loggedItemsTotal++;
                    }
                    continue;
                }

                list($keep, $reason, $hits) = $this->item_decision($raw);

                if ($pass2LoggedThisDoor < $this->LOG_PASS2_LINES_PER_DOOR && $loggedItemsTotal < $this->LOG_LIMIT_ITEMS) {
                    $verdict = $keep ? 'KEEP' : 'DROP';
                    $hitStr  = !empty($hits) ? (' hits=' . implode(',', array_slice($hits, 0, 8))) : '';
                    $this->dlog("PASS2 door={$doorId} line#{$lineNo} {$verdict} reason={$reason}{$hitStr} raw=\"" . $this->clip($raw, 140) . '"');
                    $pass2LoggedThisDoor++;
                    $loggedItemsTotal++;
                }

                if ($keep) {
                    $it['_scope_kept']    = true;
                    $it['_scope_reason']  = $reason;
                    $it['_scope_signals'] = $hits;
                    $keptItems[] = $it;
                }
            }

            $itemsAfter += count($keptItems);

            // Safety: don't silently output empty operator doors
            if (empty($keptItems) && !empty($items)) {
                $this->dlog("PASS2 WARN door={$doorId} all items were dropped; restoring originals for safety");
                $keptItems = $items;
                foreach ($keptItems as &$it2) {
                    $it2['_scope_kept']    = true;
                    $it2['_scope_reason']  = 'restored_after_empty_pass2';
                    $it2['_scope_signals'] = [];
                }
                unset($it2);
                $itemsAfter += count($keptItems);
            }

            // Attach door-level trace
            $door['_scope_operator_signals'] = $doorOperatorHits;
            $door['_scope_pass1_examples']   = $hitExamples;
            $door['items'] = array_values($keptItems);

            $outDoors[] = $door;

            if ($loggedDoorsTotal < $this->LOG_LIMIT_DOORS) {
                $this->dlog("DOOR SUMMARY door={$doorId} items_before={$itemCount} items_after=" . count($keptItems) .
                            " op_hits=" . implode(',', $doorOperatorHits));
                $this->dlog("--- DOOR END --- idx={$doorIndex} door={$doorId} verdict=KEEP");
            }

            $loggedDoorsTotal++;
        }

        $doorsOut = count($outDoors);

        $this->dlog("=== SCOPE END ===");
        $this->dlog("doors_in={$doorsInCnt} doors_out={$doorsOut} doors_dropped={$droppedDoors}");
        $this->dlog("items_in={$itemsBefore} items_out={$itemsAfter} items_dropped=" . ($itemsBefore - $itemsAfter));

        $result['doors']       = array_values($outDoors);
        $result['door_count']  = $doorsOut;
        $result['scope_stats'] = [
            'doors_before'        => $doorsInCnt,
            'doors_after'         => $doorsOut,
            'doors_dropped'       => $droppedDoors,
            'items_before'        => $itemsBefore,
            'items_after'         => $itemsAfter,
            'items_dropped'       => $itemsBefore - $itemsAfter,

            // Legacy keys (UI compatibility)
            'pass1_kept_doors'    => $doorsOut,
            'pass1_dropped_doors' => $droppedDoors,
            'pass2_items_before'  => $itemsBefore,
            'pass2_items_after'   => $itemsAfter,
            'pass2_dropped_items' => $itemsBefore - $itemsAfter,
        ];

        return $result;
    }
}
