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

if (!function_exists('wc_get_product_id_by_sku')) {
    fwrite(STDERR, "WooCommerce not available.\n");
    exit(1);
}

$skus = array_slice($argv, 1);
if (!$skus) {
    fwrite(STDERR, "Usage: php verify-hes-products.php <sku> [<sku>...]\n");
    exit(1);
}

$failures = 0;
foreach ($skus as $sku_arg) {
    $sku = trim((string) $sku_arg);
    $product_id = (int) wc_get_product_id_by_sku($sku);
    if ($product_id <= 0) {
        echo "FAIL|{$sku}|missing-product\n";
        $failures++;
        continue;
    }

    $product = wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        echo "FAIL|{$sku}|invalid-product\n";
        $failures++;
        continue;
    }

    $brand_slugs = wp_get_post_terms($product_id, 'product_brand', ['fields' => 'slugs']);
    $category_slugs = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
    $thumb_id = (int) get_post_thumbnail_id($product_id);
    $price = (string) $product->get_regular_price();
    $source = (string) get_post_meta($product_id, '_ado_import_url', true);

    $issues = [];
    if (!in_array('hes', $brand_slugs, true)) {
        $issues[] = 'brand';
    }
    if (!in_array('strikes', $category_slugs, true)) {
        $issues[] = 'category';
    }
    if ($thumb_id <= 0) {
        $issues[] = 'image';
    }
    if ($price === '' || (float) $price <= 0.0) {
        $issues[] = 'price';
    }
    if ($source === '' || !str_contains($source, 'hesinnovations.com')) {
        $issues[] = 'source-url';
    }

    if ($issues) {
        echo "FAIL|{$sku}|" . implode(',', $issues) . PHP_EOL;
        $failures++;
        continue;
    }

    echo "OK|{$sku}|{$product_id}|{$price}\n";
}

exit($failures > 0 ? 1 : 0);
