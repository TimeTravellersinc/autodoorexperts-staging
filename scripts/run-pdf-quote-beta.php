<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$wp_load_candidates = [
    $root . '/site/wp-load.php',
    $root . '/wp-load.php',
    '/var/www/html/wp-load.php',
];
$wp_load = null;
foreach ($wp_load_candidates as $candidate) {
    if (file_exists($candidate)) {
        $wp_load = $candidate;
        break;
    }
}
if ($wp_load === null) {
    fwrite(STDERR, "Unable to locate wp-load.php\n");
    exit(1);
}
require_once $wp_load;

if (!isset($GLOBALS['ado_pdf_beta_manager']) || !($GLOBALS['ado_pdf_beta_manager'] instanceof ADO_PDF_Beta_Manager)) {
    fwrite(STDERR, "Beta manager not available.\n");
    exit(1);
}

$pipeline = $GLOBALS['ado_pdf_beta_manager']->get_pipeline();
$pdfs = array_slice($argv, 1);
if (!$pdfs) {
    $pdfs = [
        'C:/Users/marcr.TIME_MACHINE/Downloads/Hardware Schedule for ADO Install (1).pdf',
        'C:/Users/marcr.TIME_MACHINE/Downloads/Hardware Schedule.pdf',
        'C:/Users/marcr.TIME_MACHINE/Downloads/Revised Hardware Schedule (1).pdf',
        'C:/Users/marcr.TIME_MACHINE/Downloads/Carleton University New Student Residence - Revised Hardware Schedule - January 31, 2025.pdf',
        'C:/Users/marcr.TIME_MACHINE/Downloads/Providence Manor- Hardware Schedule Operators only.pdf',
    ];
}

$report = [];
foreach ($pdfs as $pdf_path) {
    if (!file_exists($pdf_path)) {
        $report[] = ['pdf' => $pdf_path, 'ok' => false, 'message' => 'missing'];
        continue;
    }
    $run1 = $pipeline->process_pdf($pdf_path);
    $run2 = $pipeline->process_pdf($pdf_path);
    $report[] = [
        'pdf' => $pdf_path,
        'ok' => true,
        'upload_ids' => [(int) $run1['upload_id'], (int) $run2['upload_id']],
        'intent_counts' => [
            count((array) ($run1['intents']['intents'] ?? [])),
            count((array) ($run2['intents']['intents'] ?? [])),
        ],
        'stable_intent_count' => count((array) ($run1['intents']['intents'] ?? [])) === count((array) ($run2['intents']['intents'] ?? [])),
        'artifacts' => $run1['artifacts'],
    ];
}

echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
