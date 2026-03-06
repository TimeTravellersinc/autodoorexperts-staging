<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$bootstrap = [dirname(__DIR__) . '/site/wp-load.php', '/var/www/html/wp-load.php'];
$loaded = false;
foreach ($bootstrap as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        $loaded = true;
        break;
    }
}
if (!$loaded || !function_exists('wc_get_product_id_by_sku')) {
    fwrite(STDERR, "WooCommerce bootstrap failed.\n");
    exit(1);
}

$checks = [
    'PS902' => 'schlage',
    'PS904' => 'schlage',
    'PS906' => 'schlage',
    'PS914' => 'von-duprin',
];
$fail = 0;
foreach ($checks as $sku => $brand) {
    $id = (int) wc_get_product_id_by_sku($sku);
    if ($id <= 0) {
        echo 'FAIL|' . $sku . '|missing-product' . PHP_EOL;
        $fail++;
        continue;
    }
    $product = wc_get_product($id);
    $brands = wp_get_post_terms($id, 'product_brand', ['fields' => 'slugs']);
    $cats = wp_get_post_terms($id, 'product_cat', ['fields' => 'slugs']);
    $thumb = (int) get_post_thumbnail_id($id);
    $price = (string) $product->get_regular_price();
    $source = (string) get_post_meta($id, '_ado_import_url', true);
    $issues = [];
    if (!in_array($brand, $brands, true)) {
        $issues[] = 'brand';
    }
    if (!in_array('psus', $cats, true)) {
        $issues[] = 'category';
    }
    if ($thumb <= 0) {
        $issues[] = 'image';
    }
    if ($price === '' || (float) $price <= 0.0) {
        $issues[] = 'price';
    }
    if ($source === '') {
        $issues[] = 'source';
    }
    if ($issues) {
        echo 'FAIL|' . $sku . '|' . implode(',', $issues) . PHP_EOL;
        $fail++;
        continue;
    }
    echo 'OK|' . $sku . '|' . $id . '|' . $price . PHP_EOL;
}
exit($fail > 0 ? 1 : 0);
