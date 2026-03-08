<?php
define('WP_USE_THEMES', false);
require '/var/www/html/wp-load.php';
require_once '/var/www/html/wp-content/plugins/autodoor-pdf-parser/autodoor-pdf-parser.php';

function parser_case_paths(): array {
    return [
        'providence' => '/var/www/html/wp-content/uploads/2026/03/Providence-Manor-Hardware-Schedule-Operators-only.pdf',
        'ado_install' => '/var/www/html/wp-content/uploads/2026/03/Hardware-Schedule-for-ADO-Install-1.pdf',
        'carleton' => '/var/www/html/wp-content/uploads/2026/02/Carleton-University-New-Student-Residence-Revised-Hardware-Schedule-January-31-2025.pdf',
        'hardware_1' => '/var/www/html/wp-content/uploads/2026/02/Hardware-Schedule-1.pdf',
        'revised_1' => '/var/www/html/wp-content/uploads/2026/02/Revised-Hardware-Schedule-1.pdf',
        'st_joseph' => '/var/www/html/wp-content/uploads/2026/02/St-Joseph-St-Thomas-Hardware-Feb-28-2023-Revised-02.pdf',
        'cheo' => '/var/www/html/wp-content/uploads/2026/02/Cheo-hardware-schedule.pdf',
        'cn_yow' => '/var/www/html/wp-content/uploads/2026/03/CN-YOW-Prelim-SHOPS-rev-1-07.23.2025-15.pdf',
        'laurier' => '/tmp/234-Laurier-Hardware-schedule-2024.07.23.pdf',
        'resubmit_23162' => '/tmp/23-162-Hardware-SD_MCR-resubmit.pdf',
    ];
}

function parser_parse_pdf(string $pdf): array {
    $dbg = new ADX_Debug();
    $extractor = new ADX_Extractor('/usr/bin/pdftotext', $dbg);
    $parser = new ADX_Parser($dbg);
    $text = $extractor->extract_text_pdftotext($pdf)['text'] ?? '';
    return $parser->adaptive_parse($text);
}

function parser_find_door(array $parsed, string $doorId): ?array {
    foreach (($parsed['doors'] ?? []) as $door) {
        if (($door['door_id'] ?? '') === $doorId) {
            return $door;
        }
    }
    return null;
}

function parser_find_raw_contains(array $door, string $needle): ?array {
    foreach (($door['items'] ?? []) as $item) {
        if (strpos((string) ($item['raw'] ?? ''), $needle) !== false) {
            return $item;
        }
    }
    return null;
}

function parser_descs(array $door): array {
    return array_values(array_map(static function (array $item): string {
        return (string) ($item['desc'] ?? '');
    }, (array) ($door['items'] ?? [])));
}

function parser_assert(bool $condition, string $message, array &$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
}

function parser_multi_qty_count(array $door): int {
    $count = 0;
    $categories = [
        'STANDARD HINGE', 'CONTINUOUS HINGE', 'FLUSH BOLT', 'LOCKSET', 'CYLINDER',
        'ELECTRIC STRIKE', 'OVERHEAD DOOR STOP', 'PLATE', 'OPERATOR', 'ACTUATOR',
        'KICK PLATE', 'CARD READER', 'SURFACE CLOSER', 'POWER SUPPLY', 'EXIT DEVICE',
        'DOOR CONTACT', 'REQUEST TO EXIT', 'TOUCHLESS ACTUATOR', 'THRESHOLD',
        'WEATHERSTRIPPING', 'GASKETING', 'WALL DOOR STOP', 'AUTO OPENER',
        'AUTO OPENER MOUNTING PLATE', 'PERM CYLINDER', 'REMOVABLE MULLION',
        'DOOR PULL', 'COORDINATOR', 'MISCELLANEOUS HARDWARE', 'ARMOR PLATE',
    ];
    $pattern = '/(?:^|\s)(\d+)\s+(?:' . implode('|', array_map(static function (string $phrase): string {
        return preg_quote($phrase, '/');
    }, $categories)) . ')\b/i';
    foreach (($door['items'] ?? []) as $item) {
        if (preg_match_all($pattern, (string) ($item['raw'] ?? ''), $m) && count($m[0]) > 1) {
            $count++;
        }
    }
    return $count;
}

$results = [];
$failures = [];
$paths = parser_case_paths();

foreach ($paths as $name => $path) {
    $parsed = parser_parse_pdf($path);
    $doors = $parsed['doors'] ?? [];
    $results[$name] = [
        'pdf' => $path,
        'door_count' => count($doors),
        'first_doors' => array_map(static function (array $door): array {
            return [
                'door_id' => (string) ($door['door_id'] ?? ''),
                'item_count' => count((array) ($door['items'] ?? [])),
                'items' => array_slice(array_map(static function (array $item): array {
                    return [
                        'qty' => $item['qty'] ?? null,
                        'desc' => (string) ($item['desc'] ?? ''),
                        'raw' => (string) ($item['raw'] ?? ''),
                    ];
                }, (array) ($door['items'] ?? [])), 0, 10),
            ];
        }, array_slice($doors, 0, 2)),
    ];

    parser_assert(count($doors) > 0, "{$name}: parser returned zero doors", $failures);
}

$providence = parser_find_door(parser_parse_pdf($paths['providence']), 'D-C.0.008.2');
parser_assert($providence !== null, 'providence: missing door D-C.0.008.2', $failures);
if ($providence) {
    parser_assert(parser_multi_qty_count($providence) === 0, 'providence: merged qty-led rows returned', $failures);
    foreach ([
        '1 Cylinder 20-740-XP 626 Everest 29 T 626',
        '1 Electric Strike 6223- CON 12VDC-630 630',
        '1 Plate 9560-18 628 628',
        '1 Operator 9563 REGARM2 628 HDR2 72" 628',
        '2 Actuator 8310-813',
        '4 Card Reader BY DIV.28',
    ] as $needle) {
        parser_assert(parser_find_raw_contains($providence, $needle) !== null, "providence: missing row containing {$needle}", $failures);
    }
}

$ado = parser_find_door(parser_parse_pdf($paths['ado_install']), 'P2-01.1');
parser_assert($ado !== null, 'ado_install: missing door P2-01.1', $failures);
if ($ado) {
    parser_assert(parser_find_raw_contains($ado, '2 Actuator CM45/2 x CM43CBL C32D C32D') !== null, 'ado_install: missing clean actuator row', $failures);
    parser_assert(parser_find_raw_contains($ado, '1 Electric Strike 1600-CLB 24VDC 630 630') !== null, 'ado_install: missing clean electric strike row', $failures);
    foreach (($ado['items'] ?? []) as $item) {
        $raw = (string) ($item['raw'] ?? '');
        parser_assert(stripos($raw, 'Note:') === false, 'ado_install: note contamination returned', $failures);
        parser_assert(stripos($raw, 'Revision:') === false, 'ado_install: revision contamination returned', $failures);
    }
}

$resubmit = parser_parse_pdf($paths['resubmit_23162']);
foreach (['STCD100', 'STCD101'] as $doorId) {
    $door = parser_find_door($resubmit, $doorId);
    parser_assert($door !== null, "23-162: missing door {$doorId}", $failures);
}
$stcd101 = parser_find_door($resubmit, 'STCD101');
if ($stcd101) {
    foreach ([
        '1 Surface Closer 9542 LONG 628 HDR 628',
        '2 Actuator 8310-810DA',
        '1 Electric Strike 1006-630 E-630 630',
        '1 Floor Door Stop S102 C26D C26D',
    ] as $needle) {
        parser_assert(parser_find_raw_contains($stcd101, $needle) !== null, "23-162: missing clean row containing {$needle}", $failures);
    }
    foreach (($stcd101['items'] ?? []) as $item) {
        $raw = (string) ($item['raw'] ?? '');
        foreach (['Pemko on door shop drawings', 'Gasketing are shown as', 'supplied by STC 52 rated', 'secure document standards'] as $bad) {
            parser_assert(stripos($raw, $bad) === false, "23-162: contamination returned in row [{$raw}]", $failures);
        }
    }
}

$carleton = parser_find_door(parser_parse_pdf($paths['carleton']), '101');
parser_assert($carleton !== null, 'carleton: missing door 101', $failures);
if ($carleton) {
    foreach ([
        'Standard Hinge',
        'Lockset',
        'Perm Cylinder',
        'Electric Strike',
        'Card Reader',
        'Power Supply',
        'Auto Opener',
        'Auto Opener Mounting Plate',
        'Kick Plate',
        'Door Contact',
    ] as $desc) {
        parser_assert(in_array($desc, parser_descs($carleton), true), "carleton: missing desc {$desc}", $failures);
    }
    parser_assert(parser_find_raw_contains($carleton, '1 Card Reader CARD READER BY OTHERS 1/PROX + KEYPAD/SWIPE') !== null, 'carleton: card reader continuation regressed', $failures);
    parser_assert(parser_find_raw_contains($carleton, '1 Door Contact GE947W DOOR CONTACT') !== null, 'carleton: door contact continuation regressed', $failures);
}

$stJoseph = parser_parse_pdf($paths['st_joseph']);
$firstStJoseph = ($stJoseph['doors'] ?? [])[0] ?? null;
parser_assert($firstStJoseph !== null, 'st_joseph: missing first parsed door', $failures);
if ($firstStJoseph) {
    foreach ([
        'Standard Hinge',
        'Exit Device',
        'Electric Strike',
        'Power Supply',
        'Cylinder',
        'Removable Mullion',
        'Surface Closer',
    ] as $desc) {
        parser_assert(in_array($desc, parser_descs($firstStJoseph), true), "st_joseph: missing desc {$desc}", $failures);
    }
}

$laurier = parser_parse_pdf($paths['laurier']);
$firstLaurier = ($laurier['doors'] ?? [])[0] ?? null;
parser_assert($firstLaurier !== null, 'laurier: missing first parsed door', $failures);
if ($firstLaurier) {
    parser_assert(parser_find_raw_contains($firstLaurier, '4 Touchless Actuator 8310-810DA') !== null, 'laurier: missing Touchless Actuator row', $failures);
    foreach ([
        'Touchless Actuator',
        'Card Reader',
        'Request To Exit',
        'Door Contact',
    ] as $desc) {
        parser_assert(in_array($desc, parser_descs($firstLaurier), true), "laurier: missing desc {$desc}", $failures);
    }
}

$hardware1 = parser_parse_pdf($paths['hardware_1']);
$revised1 = parser_parse_pdf($paths['revised_1']);
$cheo = parser_parse_pdf($paths['cheo']);
$cnYow = parser_parse_pdf($paths['cn_yow']);

parser_assert(count($hardware1['doors'] ?? []) === 25, 'hardware_1: door count changed', $failures);
parser_assert(count(($hardware1['doors'][0]['items'] ?? [])) === 14, 'hardware_1: first door item count changed', $failures);
parser_assert(count($revised1['doors'] ?? []) > 0, 'revised_1: parse failed', $failures);
parser_assert(count($cheo['doors'] ?? []) === 2, 'cheo: door count changed', $failures);
parser_assert(count(($cheo['doors'][0]['items'] ?? [])) === 6, 'cheo: first door item count changed', $failures);

$cnDoors = (array) ($cnYow['doors'] ?? []);
parser_assert(!empty($cnDoors), 'cn_yow: missing parsed doors', $failures);
foreach ($cnDoors as $door) {
    foreach (($door['items'] ?? []) as $item) {
        $raw = (string) ($item['raw'] ?? '');
        parser_assert(stripos($raw, 'Canadian North Office') === false, "cn_yow: footer contamination in row [{$raw}]", $failures);
    }
}

$payload = [
    'ok' => count($failures) === 0,
    'failures' => $failures,
    'results' => $results,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['ok'] ? 0 : 1);
