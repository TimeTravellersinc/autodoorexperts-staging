<?php
chdir('/var/www/html');
require_once 'wp-load.php';

function camden_resolver_assert(string $message, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function camden_resolver_make_index(array $products): array
{
    $index = [
        'products' => [],
        'brands' => [
            'CAMDEN' => [
                'products' => [],
            ],
        ],
    ];
    foreach ($products as $product) {
        $id = (int) $product['id'];
        $product['title_norm'] = ado_qm_normalize_text((string) $product['title']);
        $product['model_map'] = (array) ($product['model_map'] ?? []);
        $product['brand'] = 'CAMDEN';
        $index['products'][$id] = $product;
        $index['brands']['CAMDEN']['products'][] = $id;
    }
    return $index;
}

$parsed = ado_camden_parse_segment('CM-60/2');
camden_resolver_assert('parsed CM-60/2 expected', ($parsed['parse_status'] ?? '') === 'parsed');

// A. leaf preferred over series
$leaf_index = camden_resolver_make_index([
    [
        'id' => 101,
        'title' => 'Camden CM-60/2 Push Plate Switch',
        'model_map' => [
            ado_qm_compact('CM-60/2') => ['display' => 'CM-60/2', 'signature' => 'CM-60'],
        ],
    ],
    [
        'id' => 102,
        'title' => 'Camden CM-40-41-60 Series Push Plates',
        'model_map' => [
            ado_qm_compact('CM-40-41-60') => ['display' => 'CM-40-41-60', 'signature' => 'CM-40'],
        ],
    ],
]);
$leaf_result = ado_camden_resolve_parsed_model($parsed, $leaf_index);
camden_resolver_assert('leaf candidate should be first', ($leaf_result['candidate_products'][0]['id'] ?? 0) === 101);
camden_resolver_assert('leaf result should be exact', ($leaf_result['resolution_status'] ?? '') === 'exact');

// B. family fallback becomes review
$series_only_index = camden_resolver_make_index([
    [
        'id' => 201,
        'title' => 'Camden CM-40-41-60 Series Push Plates',
        'model_map' => [
            ado_qm_compact('CM-40-41-60') => ['display' => 'CM-40-41-60', 'signature' => 'CM-40'],
        ],
    ],
]);
$series_result = ado_camden_resolve_parsed_model($parsed, $series_only_index);
camden_resolver_assert('series-only should be review', ($series_result['resolution_status'] ?? '') === 'review');
camden_resolver_assert('series-only should carry series reason', ($series_result['reason_code'] ?? '') === 'CAMDEN_SERIES_REVIEW');

// C. accessory demotion
$accessory_index = camden_resolver_make_index([
    [
        'id' => 301,
        'title' => 'Camden CM-60/2 Push Plate Switch',
        'model_map' => [
            ado_qm_compact('CM-60/2') => ['display' => 'CM-60/2', 'signature' => 'CM-60'],
        ],
    ],
    [
        'id' => 302,
        'title' => 'Camden CM-60 Faceplate Accessory',
        'model_map' => [
            ado_qm_compact('CM-60') => ['display' => 'CM-60', 'signature' => 'CM-60'],
        ],
    ],
]);
$accessory_result = ado_camden_resolve_parsed_model($parsed, $accessory_index);
camden_resolver_assert('accessory should not outrank main device', ($accessory_result['candidate_products'][0]['id'] ?? 0) === 301);

// D. unresolved stays unresolved
$empty_index = ['products' => [], 'brands' => []];
$empty_result = ado_camden_resolve_parsed_model($parsed, $empty_index);
camden_resolver_assert('empty index unresolved', ($empty_result['resolution_status'] ?? '') === 'unresolved');

// E. trace quality
camden_resolver_assert('trace should include candidate_count', in_array('candidate_count=2', (array) ($leaf_result['trace'] ?? []), true));
camden_resolver_assert('trace should include top_reasons', (bool) array_filter((array) ($leaf_result['trace'] ?? []), function (string $entry): bool {
    return strpos($entry, 'top_reasons=') === 0;
}));

// Live index shape check (non-fatal)
$live_index = ado_qm_get_index();
$live_result = ado_camden_resolve_parsed_model($parsed, $live_index);
camden_resolver_assert('live result has status', isset($live_result['resolution_status']));
camden_resolver_assert('live result has trace', isset($live_result['trace']));

echo "Camden resolver smoke test passed.\n";
