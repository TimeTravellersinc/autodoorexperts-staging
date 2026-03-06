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
if (!$loaded) {
    fwrite(STDERR, "Unable to locate wp-load.php\n");
    exit(1);
}
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
if (!function_exists('wc_get_product_id_by_sku')) {
    fwrite(STDERR, "WooCommerce not available.\n");
    exit(1);
}

$manifest_path = dirname(__DIR__) . '/meta/vonduprin/electric-strikes.json';
if (!is_file($manifest_path)) {
    fwrite(STDERR, "Manifest missing: {$manifest_path}\n");
    exit(1);
}
$manifest = json_decode((string) file_get_contents($manifest_path), true);
if (!is_array($manifest) || !isset($manifest['products']) || !is_array($manifest['products'])) {
    fwrite(STDERR, "Invalid manifest JSON.\n");
    exit(1);
}
$products = $manifest['products'];

function ado_term_id(string $taxonomy, string $name): int {
    $term = term_exists($name, $taxonomy);
    if (!$term) {
        $term = wp_insert_term($name, $taxonomy);
    }
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    return (int) (is_array($term) ? ($term['term_id'] ?? 0) : $term);
}

function ado_find_existing_product_id(string $sku, string $source_url): int {
    $product_id = (int) wc_get_product_id_by_sku($sku);
    if ($product_id > 0) {
        return $product_id;
    }
    $query = new WP_Query([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_ado_import_url',
            'value' => $source_url,
        ]],
    ]);
    return !empty($query->posts) ? (int) $query->posts[0] : 0;
}

function ado_attach(int $product_id, string $image_url, string $referer): bool {
    if ($image_url === '') {
        return false;
    }
    $existing_id = attachment_url_to_postid($image_url);
    if ($existing_id > 0) {
        set_post_thumbnail($product_id, $existing_id);
        return true;
    }
    $response = wp_remote_get($image_url, [
        'timeout' => 60,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (compatible; ADO-VonDuprin-Importer/1.0)',
            'Referer' => $referer,
        ],
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 300 || $body === '') {
        return false;
    }
    $extension = pathinfo((string) parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $tmp_file = wp_tempnam('vonduprin-image.' . ($extension !== '' ? $extension : 'img'));
    if (!$tmp_file) {
        return false;
    }
    if (file_put_contents($tmp_file, $body) === false) {
        @unlink($tmp_file);
        return false;
    }
    $file = [
        'name' => wp_basename((string) parse_url($image_url, PHP_URL_PATH)),
        'tmp_name' => $tmp_file,
    ];
    $attachment_id = media_handle_sideload($file, $product_id);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp_file);
        return false;
    }
    set_post_thumbnail($product_id, $attachment_id);
    return true;
}

$created = 0;
$updated = 0;
foreach ($products as $entry) {
    $sku = trim((string) ($entry['sku'] ?? ''));
    $brand = trim((string) ($entry['brand'] ?? ''));
    $category = trim((string) ($entry['category'] ?? ''));
    $source_url = trim((string) ($entry['source_url'] ?? ''));
    $title = trim((string) ($entry['title'] ?? ''));
    $description = trim((string) ($entry['description'] ?? ''));
    $image_url = trim((string) ($entry['image_url'] ?? ''));
    $price = number_format((float) ($entry['price'] ?? 0), 2, '.', '');

    if ($sku === '' || $brand === '' || $category === '' || $source_url === '' || $title === '' || $description === '' || $image_url === '' || (float) $price <= 0) {
        echo 'SKIPPED|INCOMPLETE|' . $sku . PHP_EOL;
        continue;
    }

    $product_id = ado_find_existing_product_id($sku, $source_url);
    $is_new = $product_id <= 0;
    $product = $is_new ? new WC_Product_Simple() : wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        $product = new WC_Product_Simple();
        $is_new = true;
    }

    $product->set_name($brand . ' ' . $title);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($price);
    $product->set_short_description($description);
    $product->set_description(
        '<p>' . esc_html($description) . '</p>' . "\n" .
        '<p><strong>Brand:</strong> ' . esc_html($brand) . '</p>' . "\n" .
        '<p><strong>Manufacturer Part Number:</strong> ' . esc_html($sku) . '</p>' . "\n" .
        '<p><strong>Source:</strong> <a href="' . esc_url($source_url) . '">Official product page</a></p>'
    );

    $saved_id = $product->save();
    if ($saved_id <= 0) {
        throw new RuntimeException('Failed to save ' . $sku);
    }

    if (!ado_attach($saved_id, $image_url, $source_url)) {
        if ($is_new) {
            wp_delete_post($saved_id, true);
        }
        echo 'SKIPPED|IMAGE_ATTACH_FAILED|' . $sku . PHP_EOL;
        continue;
    }

    $category_id = ado_term_id('product_cat', $category);
    $brand_id = ado_term_id('product_brand', $brand);
    $brand_attr_id = ado_term_id('pa_brand', $brand);
    wp_set_object_terms($saved_id, [$category_id], 'product_cat', false);
    wp_set_object_terms($saved_id, [$brand_id], 'product_brand', false);
    wp_set_object_terms($saved_id, [$brand_attr_id], 'pa_brand', false);

    update_post_meta($saved_id, '_manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'mpn', $sku);
    update_post_meta($saved_id, '_ado_import_source', (string) parse_url($source_url, PHP_URL_HOST));
    update_post_meta($saved_id, '_ado_import_url', $source_url);
    update_post_meta($saved_id, '_ado_source_image_url', $image_url);

    echo ($is_new ? 'CREATED' : 'UPDATED') . '|' . $saved_id . '|' . $sku . '|' . $price . PHP_EOL;
    if ($is_new) {
        $created++;
    } else {
        $updated++;
    }
}

echo 'DONE|created=' . $created . '|updated=' . $updated . PHP_EOL;
