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

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

if (!function_exists('wc_get_product_id_by_sku')) {
    fwrite(STDERR, "WooCommerce not available.\n");
    exit(1);
}

$products = [
    [
        'sku' => 'PS902',
        'brand' => 'Schlage',
        'category' => 'PSUs',
        'source_url' => 'https://commercial.schlage.com/en/products/power-supplies/ps902.html',
        'title' => 'PS902 2-Amp Power Supply',
        'description' => 'Get consistent output to your access control system with the highly configurable PS902 2-amp power supply.',
        'image_url' => 'https://commercial.schlage.com/content/dam/schlage-commercial/product-images/power-supplies/SCH_PS902_900-2RS_LGR__1000x1000_HO.png',
        'price' => '229.99',
    ],
    [
        'sku' => 'PS904',
        'brand' => 'Schlage',
        'category' => 'PSUs',
        'source_url' => 'https://commercial.schlage.com/en/products/power-supplies/ps904.html',
        'title' => 'PS904 4-Amp Power Supply',
        'description' => 'View the PS904 4-amp power supply. It delivers a clean and consistent output to your access control system, protecting downstream devices.',
        'image_url' => 'https://commercial.schlage.com/content/dam/schlage-commercial/product-images/power-supplies/SCH_PS904__1000x1000_HO.png',
        'price' => '279.99',
    ],
    [
        'sku' => 'PS906',
        'brand' => 'Schlage',
        'category' => 'PSUs',
        'source_url' => 'https://commercial.schlage.com/en/products/power-supplies/ps906.html',
        'title' => 'PS906 6-Amp Power Supply',
        'description' => 'Meet your access control system needs with the factory and field configurable PS906 6-amp power supply. Designed to deliver a clean output.',
        'image_url' => 'https://commercial.schlage.com/content/dam/schlage-commercial/product-images/power-supplies/SCH_PS906__1000x1000_HO.png',
        'price' => '339.99',
    ],
    [
        'sku' => 'PS914',
        'brand' => 'Von Duprin',
        'category' => 'PSUs',
        'source_url' => 'https://www.vonduprin.com/en/products/power-supplies/ps914-power-supply.html',
        'title' => 'PS914 Power Supply for Panic Exits',
        'description' => 'The Von Duprin PS914 4-amp power supply is designed for use with panic exits requiring high in-rush current.',
        'image_url' => 'https://www.vonduprin.com/content/dam/allegion-us-2/product-page-images/VonDuprin/WP_10164_SC_PS914_Power_Supply.jpg',
        'price' => '369.99',
    ],
];

function ado_find_term_id(string $taxonomy, string $name): int {
    $term = term_exists($name, $taxonomy);
    if (!$term) {
        $term = wp_insert_term($name, $taxonomy);
    }
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    return (int) (is_array($term) ? ($term['term_id'] ?? 0) : $term);
}

function ado_attach_image(int $product_id, string $image_url): bool {
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
            'User-Agent' => 'Mozilla/5.0 (compatible; ADO-Schlage-Importer/1.0)',
            'Referer' => 'https://commercial.schlage.com/',
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
    $tmp_file = wp_tempnam('allegion-image.' . ($extension !== '' ? $extension : 'img'));
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
    $sku = $entry['sku'];
    $product_id = (int) wc_get_product_id_by_sku($sku);
    $is_new = $product_id <= 0;
    $product = $is_new ? new WC_Product_Simple() : wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        $product = new WC_Product_Simple();
        $is_new = true;
    }

    $product->set_name($entry['brand'] . ' ' . $entry['title']);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($entry['price']);
    $product->set_short_description($entry['description']);
    $product->set_description('<p>' . esc_html($entry['description']) . '</p>' . "\n" .
        '<p><strong>Brand:</strong> ' . esc_html($entry['brand']) . '</p>' . "\n" .
        '<p><strong>Category:</strong> Power Supplies</p>' . "\n" .
        '<p><strong>Manufacturer Part Number:</strong> ' . esc_html($sku) . '</p>' . "\n" .
        '<p><strong>Source:</strong> <a href="' . esc_url($entry['source_url']) . '">Official product page</a></p>');

    $saved_id = $product->save();
    if ($saved_id <= 0) {
        throw new RuntimeException('Failed to save product ' . $sku);
    }

    if (!ado_attach_image($saved_id, $entry['image_url'])) {
        if ($is_new) {
            wp_delete_post($saved_id, true);
        }
        echo 'SKIPPED|IMAGE_ATTACH_FAILED|' . $sku . PHP_EOL;
        continue;
    }

    $category_id = ado_find_term_id('product_cat', $entry['category']);
    $brand_id = ado_find_term_id('product_brand', $entry['brand']);
    $brand_attr_id = ado_find_term_id('pa_brand', $entry['brand']);
    wp_set_object_terms($saved_id, [$category_id], 'product_cat', false);
    wp_set_object_terms($saved_id, [$brand_id], 'product_brand', false);
    wp_set_object_terms($saved_id, [$brand_attr_id], 'pa_brand', false);

    update_post_meta($saved_id, '_manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'mpn', $sku);
    update_post_meta($saved_id, '_ado_import_source', parse_url($entry['source_url'], PHP_URL_HOST));
    update_post_meta($saved_id, '_ado_import_url', $entry['source_url']);
    update_post_meta($saved_id, '_ado_source_image_url', $entry['image_url']);

    echo ($is_new ? 'CREATED' : 'UPDATED') . '|' . $saved_id . '|' . $sku . '|' . $entry['price'] . PHP_EOL;
    if ($is_new) {
        $created++;
    } else {
        $updated++;
    }
}

echo 'DONE|created=' . $created . '|updated=' . $updated . PHP_EOL;
