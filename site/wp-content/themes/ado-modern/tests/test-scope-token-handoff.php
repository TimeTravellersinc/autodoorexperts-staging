<?php
// Manual test runner for the staged scope_token handoff (scoped JSON -> transient -> quote).
chdir('/var/www/html');
require_once 'wp-load.php';

if (!class_exists('WooCommerce')) {
    echo "WooCommerce not loaded.\n";
    exit(1);
}

$results = [];
$assert = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$client_id = (int) (username_exists('ado_test_client') ?: 0);
if ($client_id <= 0) {
    $client_id = (int) wp_create_user('ado_test_client', 'ado_test_pass_123', 'ado_test_client@example.com');
}
$client_user = get_user_by('id', $client_id);
if ($client_user && !in_array('client', (array) $client_user->roles, true)) {
    $client_user->set_role('client');
}

$other_id = (int) (username_exists('ado_test_client_2') ?: 0);
if ($other_id <= 0) {
    $other_id = (int) wp_create_user('ado_test_client_2', 'ado_test_pass_123', 'ado_test_client_2@example.com');
}
$other_user = get_user_by('id', $other_id);
if ($other_user && !in_array('client', (array) $other_user->roles, true)) {
    $other_user->set_role('client');
}

$uploads = wp_upload_dir();
$base_dir = (string) ($uploads['basedir'] ?? '');
$base_url = (string) ($uploads['baseurl'] ?? '');
$assert('Uploads configured', $base_dir !== '' && $base_url !== '', 'basedir=' . $base_dir . ',baseurl=' . $base_url);
if ($base_dir === '' || $base_url === '') {
    foreach ($results as $r) {
        echo ($r['ok'] ? '[PASS] ' : '[FAIL] ') . $r['name'] . ($r['detail'] !== '' ? ' :: ' . $r['detail'] : '') . "\n";
    }
    exit(1);
}

$payload = [
    'result' => [
        'doors' => [
            ['door_id' => 'TOK-A1', 'items' => [
                ['qty' => 1, 'catalog' => '9531', 'desc' => 'Operator', 'raw' => '1 Operator 9531'],
            ]],
        ],
    ],
];

$filename = 'token-handoff-scoped-' . gmdate('YmdHis') . '.json';
$path = rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
$url = rtrim($base_url, '/') . '/' . $filename;
$written = file_put_contents($path, wp_json_encode($payload, JSON_UNESCAPED_SLASHES));
$assert('Scoped fixture written', is_int($written) && $written > 0, $path);

$staged = ado_quote_stage_scoped_payload($client_id, $url);
$assert('Stage returns token', !empty($staged['ok']) && !empty($staged['scope_token']), isset($staged['message']) ? (string) $staged['message'] : '');
$token = (string) ($staged['scope_token'] ?? '');

$created_other = ado_quote_create_from_scope_token($other_id, $token, 'Other user should fail');
$assert('Token is user-bound', empty($created_other['ok']), isset($created_other['message']) ? (string) $created_other['message'] : '');

$created = ado_quote_create_from_scope_token($client_id, $token, 'Token Handoff Quote');
$assert('Create from token works', !empty($created['ok']) && !empty($created['quote_id']), isset($created['message']) ? (string) $created['message'] : '');
$quote_id = (int) ($created['quote_id'] ?? 0);
$assert('Quote created', $quote_id > 0, 'quote_id=' . $quote_id);

$created_again = ado_quote_create_from_scope_token($client_id, $token, 'Duplicate should fail');
$assert('Token is consumed', empty($created_again['ok']), isset($created_again['message']) ? (string) $created_again['message'] : '');

if (file_exists($path)) {
    @unlink($path);
}

foreach ($results as $r) {
    echo ($r['ok'] ? '[PASS] ' : '[FAIL] ') . $r['name'] . ($r['detail'] !== '' ? ' :: ' . $r['detail'] : '') . "\n";
}

$failed = array_filter($results, static fn($r) => empty($r['ok']));
exit($failed ? 1 : 0);

