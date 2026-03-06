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

$products = [
    ['sku'=>'SCH-700-SERIES-PUSHBUTTONS','brand'=>'Schlage','category'=>'Actuators','url'=>'https://commercial.schlage.com/en/products/system-accessories/700-series-pushbuttons.html','price'=>'69.99'],
    ['sku'=>'SCH-620-631-SERIES-PUSHBUTTONS','brand'=>'Schlage','category'=>'Actuators','url'=>'https://commercial.schlage.com/en/products/system-accessories/620-631-series-heavy-duty-pushbuttons.html','price'=>'89.99'],
    ['sku'=>'SCH-672-SERIES-TOUCHBAR','brand'=>'Schlage','category'=>'Actuators','url'=>'https://commercial.schlage.com/en/products/system-accessories/672-series-request-to-exit-touchbars.html','price'=>'219.99'],
    ['sku'=>'SCH-692-SERIES-SMARTBAR','brand'=>'Schlage','category'=>'Actuators','url'=>'https://commercial.schlage.com/en/products/system-accessories/692-series-request-to-exit-smartbars.html','price'=>'259.99'],
    ['sku'=>'SCH-650-SERIES-KEYSWITCHES','brand'=>'Schlage','category'=>'Keyswitches','url'=>'https://commercial.schlage.com/en/products/system-accessories/650-series-keyswitches.html','price'=>'89.99'],
    ['sku'=>'SCH-FSS1-SERIES-DOOR-SENSORS','brand'=>'Schlage','category'=>'Sensors','url'=>'https://commercial.schlage.com/en/products/system-accessories/fss1-series-high-security-door-position-sensors.html','price'=>'49.99'],
    ['sku'=>'SCH-674-679-7764-7766-DPS','brand'=>'Schlage','category'=>'Sensors','url'=>'https://commercial.schlage.com/en/products/system-accessories/674-679-7764-7766-series-door-position-switches.html','price'=>'44.99'],
    ['sku'=>'SCH-SCAN-II-MOTION-SENSORS','brand'=>'Schlage','category'=>'Sensors','url'=>'https://commercial.schlage.com/en/products/system-accessories/scan-ii-motion-sensors.html','price'=>'79.99'],
    ['sku'=>'SCH-1910-SERIES-ELECTRIC-HORNS','brand'=>'Schlage','category'=>'Miscellaneous','url'=>'https://commercial.schlage.com/en/products/system-accessories/1910-series-electric-horns.html','price'=>'94.99'],
    ['sku'=>'SCH-740-SERIES-BREAK-GLASS','brand'=>'Schlage','category'=>'Miscellaneous','url'=>'https://commercial.schlage.com/en/products/system-accessories/740-series-emergency-break-glass-release.html','price'=>'89.99'],
    ['sku'=>'SCH-660-SERIES-REMOTE-RELEASE','brand'=>'Schlage','category'=>'Miscellaneous','url'=>'https://commercial.schlage.com/en/products/system-accessories/660-series-concealed-remote-release-buttons-or-switches.html','price'=>'49.99'],
    ['sku'=>'SCH-800-801-MONITORING-STATIONS','brand'=>'Schlage','category'=>'Miscellaneous','url'=>'https://commercial.schlage.com/en/products/system-accessories/800-801-series-remote-and-local-wall-mount-monitoring-stations.html','price'=>'129.99'],
    ['sku'=>'SCH-8200-REMOTE-DESK-CONSOLE','brand'=>'Schlage','category'=>'Miscellaneous','url'=>'https://commercial.schlage.com/en/products/system-accessories/8200-series-remote-monitor-and-control-desk-consoles.html','price'=>'189.99'],
    ['sku'=>'SCH-442S-CABINET-LOCK','brand'=>'Schlage','category'=>'Miscellaneous','url'=>'https://commercial.schlage.com/en/products/system-accessories/442s-series-cabinet-lock.html','price'=>'119.99'],
    ['sku'=>'SCH-PB405-SERIES-POWER-BOLT','brand'=>'Schlage','category'=>'Miscellaneous','url'=>'https://commercial.schlage.com/en/products/system-accessories/pb405-series-power-bolts.html','price'=>'179.99'],
];

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

function ado_fetch_meta(string $url): array {
    $response = wp_remote_get($url, ['timeout'=>45,'headers'=>['User-Agent'=>'Mozilla/5.0 (compatible; ADO-Schlage-Importer/1.0)']]);
    if (is_wp_error($response)) {
        throw new RuntimeException($response->get_error_message());
    }
    $html = (string) wp_remote_retrieve_body($response);
    $title = '';
    $desc = '';
    $img = '';
    if (preg_match('/<meta property="og:title" content="([^"]+)"/i', $html, $m)) { $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    if (preg_match('/<meta name="description" content="([^"]+)"/i', $html, $m)) { $desc = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    if (preg_match('/<meta property="og:image" content="([^"]+)"/i', $html, $m)) { $img = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    if ($img !== '' && !preg_match('#^https?://#i', $img)) {
        $parts = parse_url($url);
        $img = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $img;
    }
    $title = trim(preg_replace('/\s*\|\s*Schlage.*$/i', '', $title) ?? '');
    return ['title'=>$title,'description'=>$desc,'image_url'=>$img];
}

function ado_attach(int $product_id, string $image_url, string $referer): bool {
    if ($image_url === '') return false;
    $existing_id = attachment_url_to_postid($image_url);
    if ($existing_id > 0) { set_post_thumbnail($product_id, $existing_id); return true; }
    $response = wp_remote_get($image_url, ['timeout'=>60,'headers'=>['User-Agent'=>'Mozilla/5.0 (compatible; ADO-Schlage-Importer/1.0)','Referer'=>$referer]]);
    if (is_wp_error($response)) return false;
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 300 || $body === '') return false;
    $extension = pathinfo((string) parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $tmp_file = wp_tempnam('allegion-image.' . ($extension !== '' ? $extension : 'img'));
    if (!$tmp_file) return false;
    if (file_put_contents($tmp_file, $body) === false) { @unlink($tmp_file); return false; }
    $file = ['name'=>wp_basename((string) parse_url($image_url, PHP_URL_PATH)),'tmp_name'=>$tmp_file];
    $attachment_id = media_handle_sideload($file, $product_id);
    if (is_wp_error($attachment_id)) { @unlink($tmp_file); return false; }
    set_post_thumbnail($product_id, $attachment_id);
    return true;
}

$created = 0; $updated = 0;
foreach ($products as $entry) {
    $meta = ado_fetch_meta($entry['url']);
    if ($meta['title'] === '' || $meta['description'] === '' || $meta['image_url'] === '') {
        echo 'SKIPPED|INCOMPLETE|' . $entry['sku'] . PHP_EOL;
        continue;
    }
    $product_id = (int) wc_get_product_id_by_sku($entry['sku']);
    $is_new = $product_id <= 0;
    $product = $is_new ? new WC_Product_Simple() : wc_get_product($product_id);
    if (!$product instanceof WC_Product) { $product = new WC_Product_Simple(); $is_new = true; }
    $product->set_name($entry['brand'] . ' ' . $meta['title']);
    $product->set_sku($entry['sku']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($entry['price']);
    $product->set_short_description($meta['description']);
    $product->set_description('<p>' . esc_html($meta['description']) . '</p>' . "\n" . '<p><strong>Brand:</strong> ' . esc_html($entry['brand']) . '</p>' . "\n" . '<p><strong>Manufacturer Part Number:</strong> ' . esc_html($entry['sku']) . '</p>' . "\n" . '<p><strong>Source:</strong> <a href="' . esc_url($entry['url']) . '">Official product page</a></p>');
    $saved_id = $product->save();
    if ($saved_id <= 0) throw new RuntimeException('Failed to save ' . $entry['sku']);
    if (!ado_attach($saved_id, $meta['image_url'], $entry['url'])) {
        if ($is_new) { wp_delete_post($saved_id, true); }
        echo 'SKIPPED|IMAGE_ATTACH_FAILED|' . $entry['sku'] . PHP_EOL;
        continue;
    }
    $category_id = ado_term_id('product_cat', $entry['category']);
    $brand_id = ado_term_id('product_brand', $entry['brand']);
    $brand_attr_id = ado_term_id('pa_brand', $entry['brand']);
    wp_set_object_terms($saved_id, [$category_id], 'product_cat', false);
    wp_set_object_terms($saved_id, [$brand_id], 'product_brand', false);
    wp_set_object_terms($saved_id, [$brand_attr_id], 'pa_brand', false);
    update_post_meta($saved_id, '_manufacturer_part_number', $entry['sku']);
    update_post_meta($saved_id, 'manufacturer_part_number', $entry['sku']);
    update_post_meta($saved_id, 'mpn', $entry['sku']);
    update_post_meta($saved_id, '_ado_import_source', parse_url($entry['url'], PHP_URL_HOST));
    update_post_meta($saved_id, '_ado_import_url', $entry['url']);
    update_post_meta($saved_id, '_ado_source_image_url', $meta['image_url']);
    echo ($is_new ? 'CREATED' : 'UPDATED') . '|' . $saved_id . '|' . $entry['sku'] . '|' . $entry['price'] . PHP_EOL;
    if ($is_new) $created++; else $updated++;
}
echo 'DONE|created=' . $created . '|updated=' . $updated . PHP_EOL;
