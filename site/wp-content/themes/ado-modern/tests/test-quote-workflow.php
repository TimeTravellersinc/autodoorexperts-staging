<?php
// Manual test runner for ADO quote workflow.
chdir('/var/www/html');
require_once 'wp-load.php';

if (!class_exists('WooCommerce')) {
    echo "WooCommerce not loaded.\n";
    exit(1);
}

$integration = ADO_Quote_Integration::instance();
$results = [];

$assert = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$make_product = static function (string $sku, string $name, float $price): int {
    $existing = (int) wc_get_product_id_by_sku($sku);
    if ($existing > 0) {
        return $existing;
    }
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_regular_price((string) $price);
    return (int) $product->save();
};

$client_id = username_exists('ado_test_client');
if (!$client_id) {
    $client_id = wp_create_user('ado_test_client', 'ado_test_pass_123', 'ado_test_client@example.com');
}
$client_id = (int) $client_id;
$client_user = get_user_by('id', $client_id);
if ($client_user && !in_array('client', (array) $client_user->roles, true)) {
    $client_user->set_role('client');
}

$tech_id = username_exists('ado_test_tech');
if (!$tech_id) {
    $tech_id = wp_create_user('ado_test_tech', 'ado_test_pass_123', 'ado_test_tech@example.com');
}
$tech_id = (int) $tech_id;
$tech_user = get_user_by('id', $tech_id);
if ($tech_user && !in_array('technician', (array) $tech_user->roles, true)) {
    $tech_user->set_role('technician');
}

$assert('Role restriction - client role', ado_is_client($client_id) && !ado_is_technician($client_id));
$assert('Role restriction - technician role', ado_is_technician($tech_id) && !ado_is_client($tech_id));

$make_product('9531', 'LCN 9531 Operator', 500);
$make_product('8310-813', '8310 Actuator', 120);
$make_product('9540-18', 'Mount Plate 9540-18', 55);
$make_product('CX-33', 'Relay CX-33', 70);
$make_product('CON-6W', 'CON-6W Harness', 30);
$make_product('1006-630', 'Electric Strike 1006-630', 180);
$tier_pid = $make_product('TIER-TEST', 'Tier Test Product', 100);
update_post_meta($tier_pid, '_ado_qty_tier_prices', wp_json_encode(['1' => 100, '10' => 80]));

$payload_happy = [
    'result' => [
        'doors' => [
            ['door_id' => 'A101', 'door_type' => 'single', 'desc' => 'Vestibule', 'items' => [
                ['qty' => 1, 'catalog' => '9531', 'desc' => 'Operator', 'raw' => '1 Operator 9531'],
                ['qty' => 2, 'catalog' => '8310-813', 'desc' => 'Actuator', 'raw' => '2 Actuator 8310-813'],
                ['qty' => 1, 'catalog' => '9540-18', 'desc' => 'Plate', 'raw' => '1 Plate 9540-18'],
            ]],
            ['door_id' => 'A102', 'door_type' => 'single', 'desc' => 'Corridor', 'items' => [
                ['qty' => 1, 'catalog' => '1006-630', 'desc' => 'Strike', 'raw' => '1 Electric Strike 1006-630'],
                ['qty' => 1, 'catalog' => 'CON-6W', 'desc' => 'Harness', 'raw' => '1 Harness CON-6W'],
            ]],
            ['door_id' => 'A103', 'door_type' => 'pair', 'desc' => 'Exit', 'items' => [
                ['qty' => 2, 'catalog' => 'CX-33', 'desc' => 'Relay', 'raw' => '2 Relay CX-33'],
                ['qty' => 1, 'catalog' => '9540-18', 'desc' => 'Plate', 'raw' => '1 Plate 9540-18'],
                ['qty' => 1, 'catalog' => '8310-813', 'desc' => 'Actuator', 'raw' => '1 Actuator 8310-813'],
            ]],
        ],
    ],
];

$happy = $integration->create_quote_from_payload($client_id, $payload_happy, ['name' => 'Happy Path Quote', 'debug' => true]);
$assert('Happy path quote created', !empty($happy['ok']) && !empty($happy['quote_id']), isset($happy['message']) ? (string) $happy['message'] : '');
$happy_quote_id = (int) ($happy['quote_id'] ?? 0);
if ($happy_quote_id > 0) {
    $doors = get_post_meta($happy_quote_id, '_adq_doors', true);
    $doors = is_array($doors) ? $doors : [];
    $assert('Happy path door count = 3', count($doors) === 3, 'count=' . count($doors));
}

$payload_unmatched = $payload_happy;
$payload_unmatched['result']['doors'][0]['items'][] = ['qty' => 2, 'catalog' => 'UNKNOWN-XYZ', 'desc' => 'Unknown', 'raw' => '2 Unknown UNKNOWN-XYZ'];
$unmatched = $integration->create_quote_from_payload($client_id, $payload_unmatched, ['name' => 'Unmatched Quote', 'debug' => true]);
$unmatched_quote_id = (int) ($unmatched['quote_id'] ?? 0);
$unmatched_items = $unmatched_quote_id > 0 ? get_post_meta($unmatched_quote_id, '_adq_unmatched_items', true) : [];
$unmatched_items = is_array($unmatched_items) ? $unmatched_items : [];
$assert('Unmatched items stored', count($unmatched_items) >= 1, 'count=' . count($unmatched_items));

$q1 = $integration->create_quote_from_payload($client_id, $payload_happy, ['name' => 'Multi Quote 1']);
$q2 = $integration->create_quote_from_payload($client_id, $payload_happy, ['name' => 'Multi Quote 2']);
$q3 = $integration->create_quote_from_payload($client_id, $payload_happy, ['name' => 'Multi Quote 3']);
$quotes_for_user = $integration->get_user_quotes($client_id);
$assert('Multiple quotes per client', count($quotes_for_user) >= 3, 'count=' . count($quotes_for_user));

$payload_split = [
    'result' => [
        'doors' => [
            ['door_id' => 'B201,B202', 'items' => [
                ['qty' => 3, 'catalog' => '8310-813', 'desc' => 'Actuator', 'raw' => '3 Actuator 8310-813'],
            ]],
        ],
    ],
];
$split = $integration->create_quote_from_payload($client_id, $payload_split, ['name' => 'Split Door Quote', 'debug' => true]);
$split_id = (int) ($split['quote_id'] ?? 0);
$split_doors = $split_id > 0 ? get_post_meta($split_id, '_adq_doors', true) : [];
$split_doors = is_array($split_doors) ? $split_doors : [];
$assert('Split door list expands doors', count($split_doors) === 2, 'count=' . count($split_doors));

$payload_tier = ['result' => ['doors' => [['door_id' => 'T100', 'items' => [['qty' => 10, 'catalog' => 'TIER-TEST', 'desc' => 'Tier product', 'raw' => '10 Tier product TIER-TEST']]]]]];
$tier = $integration->create_quote_from_payload($client_id, $payload_tier, ['name' => 'Tier Quote', 'debug' => true]);
$tier_id = (int) ($tier['quote_id'] ?? 0);
$tier_totals = $tier_id > 0 ? get_post_meta($tier_id, '_adq_totals', true) : [];
$tier_totals = is_array($tier_totals) ? $tier_totals : [];
$tier_subtotal = (float) ($tier_totals['subtotal'] ?? 0);
$assert('Quantity tier pricing applied', abs($tier_subtotal - 800.0) < 0.01, 'subtotal=' . $tier_subtotal);

if ($happy_quote_id > 0) {
    $loaded = $integration->load_quote_to_cart($happy_quote_id, $client_id);
    $assert('Quote loads to cart', !empty($loaded['ok']));
    if (!empty($loaded['ok'])) {
        $order = wc_create_order(['customer_id' => $client_id]);
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;
            if (!$product) {
                continue;
            }
            $item_id = $order->add_product($product, (int) ($cart_item['quantity'] ?? 1));
            $item = $order->get_item($item_id);
            if ($item instanceof WC_Order_Item_Product) {
                $integration->copy_cart_meta_to_order_item($item, '', $cart_item, $order);
            }
        }
        $integration->attach_quote_to_order($order);
        $order->calculate_totals();
        $order->save();

        $project_doors = $order->get_meta('_ado_project_doors');
        $project_doors = is_array($project_doors) ? $project_doors : [];
        $assert('Checkout conversion preserves project doors', count($project_doors) >= 1, 'count=' . count($project_doors));

        $status = (string) get_post_meta($happy_quote_id, '_adq_status', true);
        $assert('Quote status moves to ordered', $status === 'ordered', 'status=' . $status);
    }
}

$passed = 0;
foreach ($results as $row) {
    $ok = !empty($row['ok']);
    if ($ok) {
        $passed++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $row['name'];
    if (!empty($row['detail'])) {
        echo ' :: ' . $row['detail'];
    }
    echo PHP_EOL;
}
echo 'Summary: ' . $passed . '/' . count($results) . " passed\n";

