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

$url = isset($argv[1]) ? trim((string) $argv[1]) : '';
$category_name = isset($argv[2]) ? trim((string) $argv[2]) : '';

if ($url === '' || $category_name === '') {
    fwrite(STDERR, "Usage: php import-camden-link.php <camden-url> <approved-category-name>\n");
    exit(1);
}

$allowed_categories = [
    'Actuators',
    'Automatic Door Operators',
    'Emergency Kits',
    'escutcheons',
    'Keyswitches',
    'Miscellaneous',
    'PSUs',
    'QELs',
    'Relays',
    'Sensors',
    'Strikes',
    'Washroom Kits',
    'Wires',
];

if (!in_array($category_name, $allowed_categories, true)) {
    fwrite(STDERR, "Unsupported category: {$category_name}\n");
    exit(1);
}

function ado_camden_fetch_html(string $url): string {
    $response = wp_remote_get($url, [
        'timeout' => 45,
        'redirection' => 5,
        'user-agent' => 'AutoDoorExperts Camden Importer/1.0',
    ]);
    if (is_wp_error($response)) {
        throw new RuntimeException($response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ' for ' . $url);
    }
    return (string) wp_remote_retrieve_body($response);
}

function ado_camden_decode(string $value): string {
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\xc2\xa0", '’', '“', '”'], [' ', "'", '"', '"'], $value);
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function ado_camden_title_parts(string $html): array {
    $titles = [];
    if (preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
        foreach ((array) $matches[1] as $raw) {
            $text = ado_camden_decode(strip_tags((string) $raw));
            if ($text !== '' && !in_array($text, $titles, true)) {
                $titles[] = $text;
            }
        }
    }
    return $titles;
}

function ado_camden_extract_models(string $html): array {
    if (!preg_match('/var\s+hModelPrices\s*=\s*\{(?<block>.*?)\};/is', $html, $match)) {
        return [];
    }

    $block = (string) $match['block'];
    $pattern = "/'~(?<uid>\d+)'\s*:\s*\{(?:(?!\},'~|\},'option-|\}\s*;).)*'model_number':'(?<model>[^']+)'(?:(?!\},'~|\},'option-|\}\s*;).)*'subsection':'(?<subsection>[^']*)'(?:(?!\},'~|\},'option-|\}\s*;).)*'title':'(?<title>[^']*)'/is";
    preg_match_all($pattern, $block, $matches, PREG_SET_ORDER);

    $models = [];
    foreach ($matches as $row) {
        $models[] = [
            'uid' => (string) ($row['uid'] ?? ''),
            'model_number' => ado_camden_decode((string) ($row['model'] ?? '')),
            'subsection' => ado_camden_decode((string) ($row['subsection'] ?? '')),
            'title' => ado_camden_decode((string) ($row['title'] ?? '')),
        ];
    }
    return $models;
}

function ado_camden_extract_model_image_urls(string $html, array $models): array {
    $images = [];
    $models_by_uid = [];
    foreach ($models as $model) {
        $uid = (string) ($model['uid'] ?? '');
        if ($uid === '') {
            continue;
        }
        $models_by_uid[$uid] = strtoupper((string) ($model['model_number'] ?? ''));
    }
    if (!preg_match_all('/<tbody\s+id="eModel-~(?<uid>\d+)"[^>]*>(?<body>.*?)<\/tbody>/is', $html, $matches, PREG_SET_ORDER)) {
        return $images;
    }

    foreach ($matches as $match) {
        $uid = (string) ($match['uid'] ?? '');
        $body = (string) ($match['body'] ?? '');
        $model_number = (string) ($models_by_uid[$uid] ?? '');
        if ($model_number === '') {
            continue;
        }
        if (!preg_match_all('/<img[^>]+src="(?<src>\/pipelines\/resource\/[^"]+\.(?:png|jpg|jpeg|webp))"[^>]*>/i', $body, $img_matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($img_matches as $img_match) {
            $tag = (string) ($img_match[0] ?? '');
            $src = trim((string) ($img_match['src'] ?? ''));
            if ($src === '' || str_ends_with($src, 'blank.gif')) {
                continue;
            }
            $tag_upper = strtoupper(html_entity_decode(strip_tags($tag), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $alt_match = '';
            if (preg_match('/alt="([^"]*)"/i', $tag, $alt)) {
                $alt_match = strtoupper(ado_camden_decode((string) ($alt[1] ?? '')));
            }
            $title_match = '';
            if (preg_match('/title="([^"]*)"/i', $tag, $title)) {
                $title_match = strtoupper(ado_camden_decode((string) ($title[1] ?? '')));
            }
            $context = $alt_match . ' ' . $title_match . ' ' . strtoupper(ado_camden_decode(strip_tags($body)));
            if (strpos($context, $model_number) === false) {
                continue;
            }
            $images[$uid] = 'https://www.camdencontrols.com' . $src;
            break;
        }
    }

    return $images;
}

function ado_camden_attach_image(int $product_id, string $image_url): bool {
    if ($image_url === '') {
        return false;
    }

    $existing_id = attachment_url_to_postid($image_url);
    if ($existing_id > 0) {
        set_post_thumbnail($product_id, $existing_id);
        return true;
    }

    $tmp_file = download_url($image_url, 60);
    if (is_wp_error($tmp_file)) {
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

function ado_camden_find_term_id(string $taxonomy, string $name): int {
    $term = term_exists($name, $taxonomy);
    if (!$term) {
        $term = wp_insert_term($name, $taxonomy);
    }
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    return (int) (is_array($term) ? ($term['term_id'] ?? 0) : $term);
}

function ado_camden_category_price_stats(string $category_slug): array {
    $products = wc_get_products([
        'limit' => -1,
        'status' => ['publish', 'draft', 'private'],
        'category' => [$category_slug],
    ]);

    $prices = [];
    foreach ($products as $product) {
        if (!$product instanceof WC_Product) {
            continue;
        }
        $price = (float) $product->get_regular_price();
        if ($price > 0) {
            $prices[] = $price;
        }
    }

    sort($prices, SORT_NUMERIC);
    if (!$prices) {
        return ['min' => 0.0, 'median' => 0.0, 'max' => 0.0];
    }

    $middle = (int) floor(count($prices) / 2);
    $median = $prices[$middle];

    return [
        'min' => (float) min($prices),
        'median' => (float) $median,
        'max' => (float) max($prices),
    ];
}

function ado_camden_price_for_model(string $category_slug, array $model, array $stats): float {
    $sku = strtoupper((string) ($model['model_number'] ?? ''));
    $text = strtoupper(trim(($model['subsection'] ?? '') . ' ' . ($model['title'] ?? '')));

    if ($category_slug === 'keyswitches') {
        if (str_contains($sku, 'CYL')) {
            $price = str_contains($sku, 'KD') ? 26.99 : 24.99;
            return round($price, 2);
        }
        if ($sku === 'CM-307') {
            return 29.99;
        }
        if ($sku === 'CM-308') {
            return 39.99;
        }
        if ($sku === 'CM-1000/31' || $sku === 'CM-1000/37') {
            return 24.99;
        }
        if (str_contains($text, 'GASKET')) {
            return str_contains($text, 'NARROW') ? 18.99 : 16.99;
        }
        if (str_contains($text, 'LOCK RING')) {
            return 12.99;
        }
        if (str_contains($text, 'MOUNT BOX') || str_contains($text, 'MOUNTING BOX') || str_contains($text, 'SURFACE MOUNT BOX')) {
            return 24.99;
        }
        if (str_contains($text, 'FACEPLATE')) {
            return str_contains($text, 'STAINLESS') ? 24.99 : 19.99;
        }
        if (str_contains($text, 'CONTACT SWITCH')) {
            $price = 24.99;
            if (str_contains($text, 'HEAVY DUTY')) {
                $price = 29.99;
            }
            if (str_contains($text, 'SPDT')) {
                $price += 5.00;
            }
            if (str_contains($text, 'DPDT')) {
                $price += 10.00;
            }
            if (str_contains($text, 'MAINTAINED')) {
                $price += 2.00;
            }
            return round($price, 2);
        }
        if (str_contains($sku, 'CI-1050CP') || str_contains($text, 'COVER')) {
            return 89.99;
        }
        if (str_contains($text, 'VESTIBULE')) {
            return 104.99;
        }
        if (str_contains($text, 'KEY SWITCH') || str_contains($text, 'PUSH PLATE')) {
            return 79.99;
        }
        $base = $stats['median'] > 0 ? $stats['median'] : 59.99;
        return round($base, 2);
    }

    if ($category_slug === 'actuators' && str_contains($text, 'COLUMN')) {
        $price = 109.99;
        if (str_contains($text, '6"') || str_contains($text, '6”')) {
            $price += 10.00;
        }
        if (str_contains($sku, 'VR') || str_contains($text, 'HANDS-FREE SENSOR')) {
            $price += 20.00;
        }
        if (str_contains($sku, 'K') || str_contains($text, 'KINETIC')) {
            $price += 15.00;
        }
        if (str_contains($sku, 'SS') || str_contains($text, 'STAINLESS STEEL')) {
            $price += 20.00;
        }
        return round($price, 2);
    }

    if ($category_slug === 'miscellaneous' && str_contains($text, 'AURA')) {
        $price = 59.99;
        if (str_contains($sku, '/')) {
            $price = 74.99;
        }
        if (str_contains($text, 'FLUSH')) {
            $price += 5.00;
        }
        if (str_contains($text, 'ROUND')) {
            $price += 5.00;
        }
        if (str_contains($text, 'BLUE')) {
            $price += 2.00;
        }
        return round($price, 2);
    }

    if ($category_slug === 'miscellaneous') {
        if (str_contains($sku, 'CM-TX-')) {
            return 39.99;
        }
        if (str_contains($text, 'KEYPAD')) {
            $price = 149.99;
            if (str_contains($text, 'WIEGAND')) {
                $price += 10.00;
            }
            if (str_contains($text, 'WIRELESS')) {
                $price += 20.00;
            }
            if (str_contains($text, 'VANDAL')) {
                $price += 10.00;
            }
            if (str_contains($text, 'BATTERY')) {
                $price += 10.00;
            }
            return round($price, 2);
        }
        if (str_contains($text, 'RECEIVER')) {
            $price = 89.99;
            if (str_contains($text, 'DUAL RELAY') || str_contains($text, 'FULL FUNCTION')) {
                $price = 119.99;
            }
            return round($price, 2);
        }
        if (str_contains($text, 'TRANSMITTER')) {
            return 39.99;
        }
        if (str_contains($text, 'BATTER')) {
            return 14.99;
        }
    }

    if ($category_slug === 'wires') {
        $price = 29.99;
        if (str_contains($text, 'CONCEALED') || str_contains($text, 'MORTISE')) {
            $price = 59.99;
        }
        if (str_contains($text, '3/8')) {
            $price += 6.00;
        }
        if (str_contains($text, 'STAINLESS')) {
            $price += 8.00;
        }
        if (str_contains($text, 'DURANODIC')) {
            $price += 4.00;
        }
        if (str_contains($text, '24')) {
            $price += 4.00;
        } elseif (str_contains($text, '36')) {
            $price += 8.00;
        } elseif (str_contains($text, '18')) {
            $price += 2.00;
        }
        return round($price, 2);
    }

    if (preg_match('/^CM-9600/i', $sku) || preg_match('/^CM-9610/i', $sku)) {
        if (str_contains($sku, '/C')) {
            return 24.99;
        }
        $price = 94.99;
        if (preg_match('/^CM-9610/i', $sku) || str_contains($text, 'NARROW')) {
            $price += 5.00;
        }
        return round($price, 2);
    }

    if ($category_slug !== 'actuators') {
        $base = $stats['median'] > 0 ? $stats['median'] : 99.99;
        return round($base, 2);
    }

    if (preg_match('/^CM-9800/i', $sku)) {
        $price = str_contains($sku, '/7') ? 129.99 : 119.99;
        return round($price, 2);
    }

    if (preg_match('/^CM-9/i', $sku)) {
        $price = 64.99;
        if (str_contains($text, 'NARROW')) {
            $price += 5.00;
        }
        if (str_contains($text, 'MAINTAINED')) {
            $price += 5.00;
        }
        if (str_contains($text, 'PTE')) {
            $price += 4.00;
        }
        return round($price, 2);
    }

    if (preg_match('/^CM-[3456]/i', $sku) || str_contains($text, 'PUSHBUTTON') || str_contains($text, 'PUSH/PULL') || str_contains($text, 'FACEPLATE')) {
        $price = 34.99;
        if (preg_match('/^CM-6/i', $sku) || str_contains($text, 'KEY TO RELEASE')) {
            $price = 99.99;
            if (str_contains($text, 'N/O AND N/C') || str_contains($text, 'N/O \\& N/C')) {
                $price += 5.00;
            }
            return round($price, 2);
        }
        if (str_contains($text, 'NARROW') || str_ends_with($sku, 'N')) {
            $price = 39.99;
        }
        if (str_contains($text, 'DOUBLE GANG') || str_ends_with($sku, 'W')) {
            $price = 44.99;
        }
        if (str_contains($text, 'ILLUMINATED') || preg_match('/^CM-3/i', $sku)) {
            $price += 8.00;
        }
        if (preg_match('/^CM-5/i', $sku)) {
            $price += 5.00;
        }
        if (str_contains($text, 'N/O AND N/C') || str_contains($text, 'N/O \\& N/C')) {
            $price += 5.00;
        }
        if (str_contains($text, 'MAINTAINED')) {
            $price += 8.00;
        }
        if (str_contains($text, 'TURN TO RELEASE')) {
            $price += 10.00;
        }
        if (str_contains($text, 'PNEUMATIC') || str_contains($text, 'TIME DELAY') || str_contains($text, 'TIMER')) {
            $price += 10.00;
        }
        if (str_contains($text, 'RED BUTTON')) {
            $price += 4.00;
        }
        if (str_contains($text, 'LED')) {
            $price += 8.00;
        }
        if (str_contains($text, 'LIFT COVER')) {
            $price += 15.00;
        }
        if (str_contains($text, 'HEAVY DUTY')) {
            $price += 5.00;
        }
        return round($price, 2);
    }

    $price = 34.99;

    if (str_contains($sku, '26CB')) {
        $price = 39.99;
    }
    if (str_contains($sku, '35') && !str_contains($sku, '35N')) {
        $price = 44.99;
    }
    if (str_contains($sku, '35N')) {
        $price = 32.99;
    }
    if (str_ends_with($sku, 'H')) {
        $price += 5.00;
    }
    if (str_contains($text, 'OBC COMPLIANT')) {
        $price += 3.00;
    }
    if (str_contains($text, 'BLUE')) {
        $price += 2.00;
    }
    if (str_contains($text, 'BACK PLATE')) {
        $price -= 3.00;
    }
    if (str_contains($sku, 'K')) {
        $price += 12.00;
    }
    if (str_contains($text, 'WIRELESS')) {
        $price += 3.00;
    }

    if (str_contains($sku, 'K')) {
        $price = min($price, 54.99);
        $price = max($price, 47.99);
    } else {
        $price = min($price, 49.99);
        $price = max($price, 29.99);
    }

    return round($price, 2);
}

$html = ado_camden_fetch_html($url);
$titles = ado_camden_title_parts($html);
$series_title = $titles[0] ?? '';
$series_subtitle = $titles[1] ?? '';
$models = ado_camden_extract_models($html);
$image_urls = ado_camden_extract_model_image_urls($html, $models);

if (!$models) {
    fwrite(STDERR, "No models found on page.\n");
    exit(1);
}

$category_slug = sanitize_title($category_name);
$category_term_id = ado_camden_find_term_id('product_cat', $category_name);
$actuator_term_id = ado_camden_find_term_id('product_cat', 'Actuators');
$brand_term_id = ado_camden_find_term_id('product_brand', 'Camden');
$brand_attr_term_id = ado_camden_find_term_id('pa_brand', 'Camden');
$price_stats = ado_camden_category_price_stats($category_slug);

$created = 0;
$updated = 0;
$imported_skus = [];

foreach ($models as $model) {
    $sku = trim((string) ($model['model_number'] ?? ''));
    if ($sku === '') {
        continue;
    }
    $image_url = (string) ($image_urls[(string) ($model['uid'] ?? '')] ?? '');
    if ($image_url === '') {
        echo 'SKIPPED|NO_IMAGE|' . $sku . PHP_EOL;
        continue;
    }

    $product_id = (int) wc_get_product_id_by_sku($sku);
    $is_new = $product_id <= 0;
    $product = $is_new ? new WC_Product_Simple() : wc_get_product($product_id);
    if (!$product instanceof WC_Product_Simple && !$product instanceof WC_Product) {
        $product = new WC_Product_Simple();
        $is_new = true;
    }

    $subsection = trim((string) ($model['subsection'] ?? ''));
    $variant_title = trim((string) ($model['title'] ?? ''));
    $display_name = trim('Camden ' . $sku . ' - ' . ($variant_title !== '' ? $variant_title : $series_subtitle));
    $short_description = trim($variant_title);

    $description_parts = [];
    if ($series_title !== '') {
        $description_parts[] = '<p><strong>Series:</strong> ' . esc_html($series_title) . '</p>';
    }
    if ($series_subtitle !== '') {
        $description_parts[] = '<p><strong>Type:</strong> ' . esc_html($series_subtitle) . '</p>';
    }
    if ($subsection !== '') {
        $description_parts[] = '<p><strong>Configuration:</strong> ' . esc_html($subsection) . '</p>';
    }
    if ($variant_title !== '') {
        $description_parts[] = '<p>' . esc_html($variant_title) . '</p>';
    }
    $description_parts[] = '<p><strong>Brand:</strong> Camden</p>';
    $description_parts[] = '<p><strong>Manufacturer Part Number:</strong> ' . esc_html($sku) . '</p>';
    $description_parts[] = '<p><strong>Source:</strong> <a href="' . esc_url($url) . '">Camden Controls product page</a></p>';

    $regular_price = number_format(ado_camden_price_for_model($category_slug, $model, $price_stats), 2, '.', '');

    $product->set_name($display_name);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($regular_price);
    $product->set_short_description($short_description);
    $product->set_description(implode("\n", $description_parts));

    $saved_id = $product->save();
    if ($saved_id <= 0) {
        throw new RuntimeException('Failed to save product for ' . $sku);
    }

    if (!ado_camden_attach_image($saved_id, $image_url)) {
        if ($is_new) {
            wp_delete_post($saved_id, true);
        }
        echo 'SKIPPED|IMAGE_ATTACH_FAILED|' . $sku . PHP_EOL;
        continue;
    }

    $category_term_ids = [$category_term_id];
    if ($category_slug === 'actuators' && str_contains($sku, '/C')) {
        $wire_term_id = ado_camden_find_term_id('product_cat', 'Wires');
        if ($wire_term_id > 0) {
            $category_term_ids = [$wire_term_id];
        }
    }
    if (
        ($category_slug === 'keyswitches' && !str_contains($sku, 'CYL') && str_contains(strtoupper($variant_title), 'PUSH PLATE'))
        || ($category_slug === 'actuators' && str_contains(strtoupper($variant_title), 'KEY TO RELEASE'))
    ) {
        $keyswitch_term_id = ado_camden_find_term_id('product_cat', 'Keyswitches');
        if ($keyswitch_term_id > 0) {
            $category_term_ids[] = $keyswitch_term_id;
        }
    }

    if ($category_slug === 'keyswitches' && !str_contains($sku, 'CYL') && str_contains(strtoupper($variant_title), 'PUSH PLATE')) {
        $category_term_ids[] = $actuator_term_id;
    }
    wp_set_object_terms($saved_id, array_values(array_unique($category_term_ids)), 'product_cat', false);
    wp_set_object_terms($saved_id, [$brand_term_id], 'product_brand', false);
    wp_set_object_terms($saved_id, [$brand_attr_term_id], 'pa_brand', false);

    update_post_meta($saved_id, '_manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'manufacturer_part_number', $sku);
    update_post_meta($saved_id, 'manufacturer_sku', $sku);
    update_post_meta($saved_id, 'mpn', $sku);
    update_post_meta($saved_id, '_ado_model', $sku);
    update_post_meta($saved_id, '_ado_import_source', 'camdencontrols.com');
    update_post_meta($saved_id, '_ado_import_url', $url);
    update_post_meta($saved_id, '_ado_camden_series_title', $series_title);
    update_post_meta($saved_id, '_ado_camden_series_subtitle', $series_subtitle);
    update_post_meta($saved_id, '_ado_camden_subsection', $subsection);

    update_post_meta($saved_id, '_ado_camden_image_url', $image_url);

    echo ($is_new ? 'CREATED' : 'UPDATED') . '|' . $saved_id . '|' . $sku . '|' . $regular_price . PHP_EOL;
    $imported_skus[] = $sku;
    if ($is_new) {
        $created++;
    } else {
        $updated++;
    }
}

echo 'DONE|created=' . $created . '|updated=' . $updated . '|category=' . $category_slug . '|skus=' . implode(',', $imported_skus) . PHP_EOL;
