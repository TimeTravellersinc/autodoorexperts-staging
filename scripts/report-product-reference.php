<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$bootstrap = [
    dirname(__DIR__) . '/site/wp-load.php',
    '/var/www/html/wp-load.php',
];

$loaded = false;
foreach ($bootstrap as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Unable to locate wp-load.php\n");
    exit(1);
}

if (!function_exists('wc_get_products')) {
    fwrite(STDERR, "WooCommerce not available.\n");
    exit(1);
}

$category = isset($argv[1]) ? sanitize_title((string) $argv[1]) : '';
$brand = isset($argv[2]) ? sanitize_title((string) $argv[2]) : '';

$query = [
    'limit' => -1,
    'orderby' => 'title',
    'order' => 'ASC',
    'status' => ['publish', 'draft', 'private'],
];

if ($category !== '') {
    $query['category'] = [$category];
}

$products = wc_get_products($query);

foreach ($products as $product) {
    if (!$product instanceof WC_Product) {
        continue;
    }

    $product_id = $product->get_id();
    $brands = wp_get_post_terms($product_id, 'product_brand', ['fields' => 'slugs']);
    if ($brand !== '' && !in_array($brand, $brands, true)) {
        continue;
    }

    $cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
    $name = str_replace('|', '/', $product->get_name());
    $sku = str_replace('|', '/', (string) $product->get_sku());
    $price = (string) $product->get_regular_price();
    echo implode('|', [
        (string) $product_id,
        $sku,
        $name,
        $price,
        implode(',', $brands),
        implode(',', $cats),
    ]) . PHP_EOL;
}
