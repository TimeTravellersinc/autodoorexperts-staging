<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
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
    exit(1);
}

$manifest_path = dirname(__DIR__) . '/meta/vonduprin/electric-strikes.json';
$manifest = json_decode((string) file_get_contents($manifest_path), true);
$checks = is_array($manifest['products'] ?? null) ? $manifest['products'] : [];

$fail = 0;
foreach ($checks as $row) {
    $sku = (string) ($row['sku'] ?? '');
    $brand = sanitize_title((string) ($row['brand'] ?? ''));
    $cat = sanitize_title((string) ($row['category'] ?? ''));
    $source_url = (string) ($row['source_url'] ?? '');
    $price_expected = number_format((float) ($row['price'] ?? 0), 2, '.', '');

    $id = (int) wc_get_product_id_by_sku($sku);
    if ($id <= 0) {
        echo 'FAIL|' . $sku . '|missing' . PHP_EOL;
        $fail++;
        continue;
    }
    $product = wc_get_product($id);
    $brands = wp_get_post_terms($id, 'product_brand', ['fields' => 'slugs']);
    $cats = wp_get_post_terms($id, 'product_cat', ['fields' => 'slugs']);
    $thumb = (int) get_post_thumbnail_id($id);
    $price = number_format((float) $product->get_regular_price(), 2, '.', '');
    $source = (string) get_post_meta($id, '_ado_import_url', true);
    $issues = [];
    if (!in_array($brand, $brands, true)) {
        $issues[] = 'brand';
    }
    if (!in_array($cat, $cats, true)) {
        $issues[] = 'category';
    }
    if ($thumb <= 0) {
        $issues[] = 'image';
    }
    if ($price === '' || $price !== $price_expected) {
        $issues[] = 'price';
    }
    if ($source === '' || $source !== $source_url) {
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
