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

$mhtml_path = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($mhtml_path === '' || !is_file($mhtml_path)) {
    fwrite(STDERR, "Usage: php import-hes-electric-strikes.php <hes-mhtml-path>\n");
    exit(1);
}

function ado_hes_decode_html(string $mhtml): string {
    if (!preg_match('/Content-Location: https:\/\/www\.hesinnovations\.com\/en\/products\/electric-strikes#onecms-tabs-cmp-item-products-tab\R\R(?<body>.*?)(?=\R------MultipartBoundary)/s', $mhtml, $match)) {
        throw new RuntimeException('Failed to locate main HES HTML part.');
    }

    return quoted_printable_decode((string) ($match['body'] ?? ''));
}

function ado_hes_extract_products(string $html): array {
    $pattern = '/data-config-metrics-title="(?<metric>[^"]+)"[^>]*><a[^>]*href="(?<url>https:\/\/www\.hesinnovations\.com\/en\/products\/electric-strikes\/[^"]+)"[^>]*>.*?<img alt="(?<img_alt>[^"]*)" src="(?<img>https:\/\/www\.hesinnovations\.com\/[^"]+)".*?<h5>(?<title>.*?)<\/h5>.*?List Price:\s*<\/span>\$(?<low>[\d,]+)\s*-\s*\$(?<high>[\d,]+)<\/p>.*?<p class="pbody-text[^>]*>(?<desc>.*?)<\/p>/s';
    preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

    $products = [];
    foreach ($matches as $match) {
        $title = trim(strip_tags(html_entity_decode((string) ($match['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $description = trim(strip_tags(html_entity_decode((string) ($match['desc'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $url = trim((string) ($match['url'] ?? ''));
        $image_url = trim((string) ($match['img'] ?? ''));
        $low_price = (float) str_replace(',', '', (string) ($match['low'] ?? '0'));
        $high_price = (float) str_replace(',', '', (string) ($match['high'] ?? '0'));

        if ($title === '' || $url === '') {
            continue;
        }

        if ($title === 'Electric Strike Accessories') {
            continue;
        }

        $products[] = [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'image_url' => $image_url,
            'low_price' => $low_price,
            'high_price' => $high_price,
        ];
    }

    return $products;
}

function ado_hes_attach_image(int $product_id, string $image_url): bool {
    if ($image_url === '' || str_contains($image_url, 'ic_img_placeholder.svg')) {
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
            'User-Agent' => 'Mozilla/5.0 (compatible; ADO-HES-Importer/1.0)',
            'Referer' => 'https://www.hesinnovations.com/',
        ],
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($status < 200 || $status >= 300 || $body === '') {
        return false;
    }

    $extension = pathinfo((string) parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $tmp_file = wp_tempnam('hes-image.' . ($extension !== '' ? $extension : 'img'));
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

function ado_hes_normalize_url(string $url): string {
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }
    if (str_starts_with($url, '/')) {
        return 'https://www.hesinnovations.com' . $url;
    }
    return 'https://www.hesinnovations.com/' . ltrim($url, '/');
}

function ado_hes_resolve_image_url(string $product_url, string $image_url): string {
    if ($image_url !== '' && !str_contains($image_url, 'ic_img_placeholder.svg')) {
        return ado_hes_normalize_url($image_url);
    }

    $response = wp_remote_get($product_url, [
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (compatible; ADO-HES-Importer/1.0)',
        ],
    ]);
    if (is_wp_error($response)) {
        return '';
    }

    $html = (string) wp_remote_retrieve_body($response);
    if ($html === '') {
        return '';
    }

    foreach ([
        '/<meta\s+property="og:image"\s+content="([^"]+)"/i',
        '/<meta\s+name="productImage"\s+content="([^"]+)"/i',
        '/<meta\s+name="image"\s+content="([^"]+)"/i',
    ] as $pattern) {
        if (preg_match($pattern, $html, $match)) {
            $resolved = ado_hes_normalize_url(trim((string) ($match[1] ?? '')));
            if ($resolved !== '' && !str_contains($resolved, 'ic_img_placeholder.svg')) {
                return $resolved;
            }
        }
    }

    return '';
}

function ado_hes_find_term_id(string $taxonomy, string $name): int {
    $term = term_exists($name, $taxonomy);
    if (!$term) {
        $term = wp_insert_term($name, $taxonomy);
    }
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    return (int) (is_array($term) ? ($term['term_id'] ?? 0) : $term);
}

function ado_hes_family_sku(string $url): string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $slug = basename($path);
    $slug = strtoupper((string) preg_replace('/[^A-Z0-9-]+/i', '-', $slug));
    $slug = preg_replace('/-+/', '-', $slug);
    if ($slug !== '' && str_ends_with($slug, '-')) {
        $slug .= 'ALT';
    }
    $slug = ltrim((string) $slug, '-');
    if ($slug === '') {
        throw new RuntimeException('Failed to derive HES SKU from URL: ' . $url);
    }
    return 'HES-' . $slug;
}

$mhtml = file_get_contents($mhtml_path);
if ($mhtml === false) {
    throw new RuntimeException('Failed to read MHTML file.');
}

$html = ado_hes_decode_html($mhtml);
$products = ado_hes_extract_products($html);
if (!$products) {
    throw new RuntimeException('No HES products found in MHTML.');
}

$category_term_id = ado_hes_find_term_id('product_cat', 'Strikes');
$brand_term_id = ado_hes_find_term_id('product_brand', 'HES');
$brand_attr_term_id = ado_hes_find_term_id('pa_brand', 'HES');

$created = 0;
$updated = 0;
$skipped = 0;
$imported_skus = [];

foreach ($products as $entry) {
    $title = (string) $entry['title'];
    $description = (string) $entry['description'];
    $url = (string) $entry['url'];
    $image_url = (string) $entry['image_url'];
    $low_price = (float) $entry['low_price'];
    $high_price = (float) $entry['high_price'];
    $sku = ado_hes_family_sku($url);

    $image_url = ado_hes_resolve_image_url($url, $image_url);

    if ($image_url === '') {
        echo 'SKIPPED|NO_IMAGE|' . $sku . PHP_EOL;
        $skipped++;
        continue;
    }

    $product_id = (int) wc_get_product_id_by_sku($sku);
    $is_new = $product_id <= 0;
    $product = $is_new ? new WC_Product_Simple() : wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        $product = new WC_Product_Simple();
        $is_new = true;
    }

    $display_name = 'HES ' . $title;
    $regular_price = number_format($low_price, 2, '.', '');
    $short_description = $description;
    $full_description = implode("\n", [
        '<p>' . esc_html($description) . '</p>',
        '<p><strong>Brand:</strong> HES</p>',
        '<p><strong>Category:</strong> Electric Strikes</p>',
        '<p><strong>List Price Range:</strong> $' . esc_html(number_format($low_price, 0, '.', ',')) . ' - $' . esc_html(number_format($high_price, 0, '.', ',')) . '</p>',
        '<p><strong>Source:</strong> <a href="' . esc_url($url) . '">HES product page</a></p>',
    ]);

    $product->set_name($display_name);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($regular_price);
    $product->set_short_description($short_description);
    $product->set_description($full_description);

    $saved_id = $product->save();
    if ($saved_id <= 0) {
        throw new RuntimeException('Failed to save product for ' . $sku);
    }

    if (!ado_hes_attach_image($saved_id, $image_url)) {
        if ($is_new) {
            wp_delete_post($saved_id, true);
        }
        echo 'SKIPPED|IMAGE_ATTACH_FAILED|' . $sku . PHP_EOL;
        $skipped++;
        continue;
    }

    wp_set_object_terms($saved_id, [$category_term_id], 'product_cat', false);
    wp_set_object_terms($saved_id, [$brand_term_id], 'product_brand', false);
    wp_set_object_terms($saved_id, [$brand_attr_term_id], 'pa_brand', false);

    update_post_meta($saved_id, '_manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'mpn', $sku);
    update_post_meta($saved_id, '_ado_import_source', 'hesinnovations.com');
    update_post_meta($saved_id, '_ado_import_url', $url);
    update_post_meta($saved_id, '_ado_hes_title', $title);
    update_post_meta($saved_id, '_ado_hes_list_price_low', $low_price);
    update_post_meta($saved_id, '_ado_hes_list_price_high', $high_price);
    update_post_meta($saved_id, '_ado_hes_image_url', $image_url);

    echo ($is_new ? 'CREATED' : 'UPDATED') . '|' . $saved_id . '|' . $sku . '|' . $regular_price . PHP_EOL;
    $imported_skus[] = $sku;
    if ($is_new) {
        $created++;
    } else {
        $updated++;
    }
}

echo 'DONE|created=' . $created . '|updated=' . $updated . '|skipped=' . $skipped . '|category=strikes|skus=' . implode(',', $imported_skus) . PHP_EOL;
