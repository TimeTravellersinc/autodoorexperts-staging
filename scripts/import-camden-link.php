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

    if ($sku === 'CM-LP1') {
        return 14.99;
    }

    if ($sku === 'CM-RX-90') {
        return 89.99;
    }

    if ($sku === 'CM-RQEPW' || $sku === 'CM-RQEPK') {
        return 14.99;
    }

    if ($sku === 'CM-RQE70A' || $sku === 'CM-RQE70ABK') {
        return str_contains($sku, 'BK') ? 94.99 : 89.99;
    }

    if ($sku === 'CX-LED1') {
        return 24.99;
    }

    if ($sku === 'CX-LED2') {
        return 29.99;
    }

    if (preg_match('/^CX-DA[123]00$/i', $sku)) {
        $price = 59.99;
        if (str_contains($text, 'WITH RELAY')) {
            $price += 15.00;
        }
        if (str_contains($text, 'RESET KEY')) {
            $price += 10.00;
        }
        if (str_contains($text, 'DOUBLE GANG')) {
            $price += 5.00;
        }
        return $price;
    }

    if (preg_match('/^CX-DA40[01]$/i', $sku)) {
        $price = 99.99;
        if (str_contains($text, 'TAMPER')) {
            $price += 10.00;
        }
        return $price;
    }

    $restroom_annunciator_prices = [
        'CM-AF500' => 49.99,
        'CM-AF550R' => 84.99,
        'CM-AF142SOFE' => 74.99,
    ];
    if (isset($restroom_annunciator_prices[$sku])) {
        return $restroom_annunciator_prices[$sku];
    }

    if (preg_match('/^CM-AF14/i', $sku)) {
        $price = 64.99;
        if (str_contains($text, 'MULTI-COLOR')) {
            $price += 10.00;
        }
        if (str_contains($text, 'SOUNDER')) {
            $price += 5.00;
        }
        return $price;
    }

    if (preg_match('/^CM-AF5/i', $sku)) {
        $price = 49.99;
        if (str_contains($text, 'SOUNDER')) {
            $price += 10.00;
        }
        if (str_contains($text, 'DOUBLE GANG')) {
            $price += 20.00;
        }
        if (str_contains($text, 'PUSH/PULL') || str_contains($text, 'MUSHROOM PUSH BUTTON')) {
            $price += 10.00;
        }
        if (str_contains($text, 'MUSHROOM PUSH BUTTON') && !str_contains($text, 'PUSH/PULL')) {
            $price += 5.00;
        }
        return $price;
    }

    if ($sku === 'CX-1000/77') {
        return 99.99;
    }

    $cx_emf_prices = [
        'CX-EMF-2' => 89.99,
        'CX-EMF-2M' => 109.99,
        'CX-EMF-2ABM' => 129.99,
        'CX-EMF-2PS' => 149.99,
    ];
    if (isset($cx_emf_prices[$sku])) {
        return $cx_emf_prices[$sku];
    }

    $cx_33_prices = [
        'CX-33' => 94.99,
        'CX-33PS' => 149.99,
    ];
    if (isset($cx_33_prices[$sku])) {
        return $cx_33_prices[$sku];
    }

    if ($sku === 'CX-12PLUS') {
        return 84.99;
    }

    $cx_irb_prices = [
        'CX-IRB' => 19.99,
        'CX-IRB-6' => 99.99,
    ];
    if (isset($cx_irb_prices[$sku])) {
        return $cx_irb_prices[$sku];
    }

    if ($sku === 'CX-SA1') {
        return 84.99;
    }

    if ($sku === 'CX-1000/74') {
        return 89.99;
    }

    if ($sku === 'CX-1085M') {
        return 54.99;
    }

    $washroom_prices = [
        'CM-45/4' => 36.99,
        'CM-45/8B' => 41.99,
        'CM-2520/48' => 84.99,
        'CM-400R/8' => 44.99,
        'CM-45/455SE1' => 94.99,
        'CM-45/454SE1' => 89.99,
        'CM-45/8B55SE1' => 99.99,
        'CM-45/8B54SE1' => 94.99,
        'CM-2520/4855SE1' => 109.99,
        'CM-2520/4854SE1' => 104.99,
        'CX-MDA' => 24.99,
        'CX-MDC' => 29.99,
        'CX-MDH' => 24.99,
    ];
    if (isset($washroom_prices[$sku])) {
        return $washroom_prices[$sku];
    }

    $wec_prices = [
        'CM-450R/12' => 69.99,
        'CM-450R/12FE' => 74.99,
        'CM-AF501SO' => 59.99,
        'CM-AF540SO' => 79.99,
        'CM-AF141SO' => 69.99,
        'CM-AF142SO' => 79.99,
        'CM-AF142SOFE' => 74.99,
        'CM-SE21A' => 24.99,
        'CX-LRS12' => 84.99,
        'CX-LRS24' => 84.99,
        'CM-8010R/13' => 49.99,
        'CM-1205/14-60KD' => 104.99,
        'CM-SE21' => 19.99,
        'CM-SE21F' => 19.99,
    ];
    if (isset($wec_prices[$sku])) {
        return $wec_prices[$sku];
    }

    $strike_prices = [
        'CX-ED1689L' => 159.99,
        'CX-ED1799L' => 189.99,
        'CX-ED1689L-4' => 199.99,
        'CX-ED1689L-4-BK' => 209.99,
        'CX-ED1799L-8' => 239.99,
        'CX-ED1799L-8-BK' => 249.99,
        'CX-STR-TMPKIT' => 39.99,
    ];
    if (isset($strike_prices[$sku])) {
        return $strike_prices[$sku];
    }
    if (preg_match('/^CX-EMP-/i', $sku)) {
        $price = 24.99;
        if (str_contains($sku, 'W')) {
            $price += 3.00;
        }
        if (str_contains($sku, '-BK')) {
            $price += 2.00;
        }
        if (preg_match('/-(110|210|310|410)/', $sku)) {
            $price += 2.00;
        }
        return round($price, 2);
    }
    $ed1079_prices = [
        'CX-ED1079' => 179.99,
        'CX-ED1079L' => 194.99,
        'CX-ED1079D' => 189.99,
        'CX-ED1079DL' => 204.99,
        'CX-ED1079-BK' => 189.99,
        'CX-ED1079L-BK' => 204.99,
        'CX-ED1079D-BK' => 199.99,
        'CX-ED1079DL-BK' => 214.99,
        'CX-ESP1B' => 24.99,
        'CX-ESP2B' => 24.99,
        'CX-ESP3B' => 26.99,
        'CX-ESP4B' => 27.99,
        'CX-ESP1B-BK' => 26.99,
        'CX-ESP2B-BK' => 26.99,
        'CX-ESP3B-BK' => 28.99,
        'CX-ESP4B-BK' => 29.99,
        'CX-ED-LIP1' => 19.99,
        'CX-ED-LIP2' => 24.99,
    ];
    if (isset($ed1079_prices[$sku])) {
        return $ed1079_prices[$sku];
    }
    $ed1579_prices = [
        'CX-ED1579L' => 229.99,
        'CX-ED1579L-BK' => 239.99,
        'CX-EMP-1' => 24.99,
        'CX-EMP-2' => 24.99,
        'CX-EMP-3' => 24.99,
        'CX-EMP-4' => 26.99,
        'CX-EMP-5' => 26.99,
        'CX-EMP-6' => 26.99,
        'CX-EMP-1-BK' => 26.99,
        'CX-EMP-2-BK' => 26.99,
        'CX-EMP-3-BK' => 26.99,
        'CX-EMP-4-BK' => 28.99,
        'CX-EMP-5-BK' => 28.99,
        'CX-EMP-6-BK' => 28.99,
    ];
    if (isset($ed1579_prices[$sku])) {
        return $ed1579_prices[$sku];
    }
    $series20_prices = [
        'CX-EPD-2000L' => 159.99,
        'CX-EPD-2010L' => 179.99,
        'CX-EPD-2020L' => 179.99,
        'CX-EPD-2030L' => 184.99,
        'CX-EPD-2040L' => 189.99,
        'CX-ESP1' => 22.99,
        'CX-ESP2' => 22.99,
        'CX-ESP3' => 24.99,
    ];
    if (isset($series20_prices[$sku])) {
        return $series20_prices[$sku];
    }
    $ed1309_prices = [
        'CX-ED1379' => 129.99,
        'CX-ED1309' => 99.99,
        'CX-ESF-1' => 22.99,
        'CX-ESF-2' => 22.99,
        'CX-ESF-2BR' => 24.99,
        'CX-ESF-3' => 24.99,
        'CX-ESF-3BR' => 26.99,
        'CX-ESF-4' => 24.99,
        'CX-ESF-1BZ' => 24.99,
        'CX-ESF-2BZ' => 24.99,
        'CX-ESF-2BR-BZ' => 26.99,
        'CX-ESF-3BZ' => 26.99,
        'CX-ESF-3BR-BZ' => 28.99,
        'CX-ESF-4BZ' => 26.99,
    ];
    if (isset($ed1309_prices[$sku])) {
        return $ed1309_prices[$sku];
    }
    $ed1410_prices = [
        'CX-ED1410' => 174.99,
        'CX-ED1410-BK' => 184.99,
        'CX-ED1420' => 184.99,
        'CX-ED1420-BK' => 194.99,
    ];
    if (isset($ed1410_prices[$sku])) {
        return $ed1410_prices[$sku];
    }
    $ed2079_prices = [
        'CX-ED2079' => 179.99,
        'CX-ED2079-BK' => 189.99,
        'CX-ED2071' => 174.99,
        'CX-ED2071-BK' => 184.99,
        'CX-ED2079-1' => 169.99,
        'CX-ED2079-1-BK' => 179.99,
    ];
    if (isset($ed2079_prices[$sku])) {
        return $ed2079_prices[$sku];
    }
    $ed1959_prices = [
        'CX-ED1959-MB' => 289.99,
        'CX-ED1959RM-MB' => 309.99,
    ];
    if (isset($ed1959_prices[$sku])) {
        return $ed1959_prices[$sku];
    }
    $epd1289_prices = [
        'CX-EPD1289L' => 249.99,
        'CX-EPD1289L-BK' => 259.99,
        'CX-EPD1289-SPC' => 19.99,
        '60-35E014' => 19.99,
        'CX-RIM-SB' => 54.99,
    ];
    if (isset($epd1289_prices[$sku])) {
        return $epd1289_prices[$sku];
    }

    if (preg_match('/^CX-247/i', $sku)) {
        $price = 89.99;
        if (str_contains($sku, 'H-')) {
            $price += 15.00;
        }
        return $price;
    }

    $kinetic_prices = [
        'CM-40K' => 49.99,
        'CM-41K' => 47.99,
        'CM-45K' => 49.99,
        'CM-46K' => 49.99,
        'CM-46CBK' => 51.99,
        'CM-60K' => 49.99,
        'CM-7436K' => 124.99,
        'CM-7536K' => 134.99,
        'CM-7536SSK' => 154.99,
        'CM-8436K' => 124.99,
        'CM-8536K' => 134.99,
    ];
    if (isset($kinetic_prices[$sku])) {
        return $kinetic_prices[$sku];
    }

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
        if (str_contains($sku, 'RX-9') || str_contains($text, 'RECEIVER')) {
            $price = 89.99;
            if (str_contains($sku, 'RX-91')) {
                $price = 79.99;
            }
            if (str_contains($sku, 'RX-92') || str_contains($text, 'DUAL RELAY') || str_contains($text, 'FULL FUNCTION')) {
                $price = 119.99;
            }
            return round($price, 2);
        }
        if (str_contains($sku, 'TXLF-B')) {
            return 14.99;
        }
        if (str_contains($sku, 'TXLF-4')) {
            return 34.99;
        }
        if (str_contains($sku, 'TXLF-2')) {
            return str_contains($sku, 'LP') ? 29.99 : 27.99;
        }
        if (str_contains($sku, 'TXLF-1')) {
            return str_contains($sku, 'LP') ? 24.99 : 22.99;
        }
        if (str_contains($sku, 'TX-99')) {
            return 39.99;
        }
        if (str_contains($sku, 'TX-9')) {
            return 34.99;
        }
        if (str_contains($text, 'TRANSMITTER')) {
            return 39.99;
        }
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

    if ($category_slug === 'sensors') {
        $price = 24.99;
        if (str_contains($text, 'SPDT')) {
            $price += 5.00;
        }
        if (str_contains($text, 'RECESSED')) {
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

    if (preg_match('/^CM-33[1236]/i', $sku) || preg_match('/^CM-32[45]/i', $sku) || str_contains($text, 'SUREWAVE')) {
        if ($sku === 'CM-LP1') {
            return 14.99;
        }
        $price = 94.99;
        if (str_contains($text, '2 RELAYS')) {
            $price += 15.00;
        }
        if (str_contains($text, 'BATTERY POWERED')) {
            $price += 20.00;
        }
        if (str_contains($text, 'WIRELESS')) {
            $price += 10.00;
        }
        if (str_contains($text, 'ECONOMICAL')) {
            $price -= 10.00;
        }
        return round($price, 2);
    }

    if (preg_match('/^CM-22[12]/i', $sku) || str_contains($text, 'VALUEWAVE') || str_contains($text, 'TOUCHLESS SWITCH')) {
        $price = 79.99;
        if (str_contains($text, 'NARROW')) {
            $price += 5.00;
        }
        if (str_contains($text, 'DOUBLE GANG')) {
            $price += 10.00;
        }
        if (str_contains($sku, 'M/')) {
            $price += 15.00;
        }
        if (str_contains($text, 'OVERRIDE')) {
            $price += 5.00;
        }
        if (str_contains($text, 'WAVE TO EXIT')) {
            $price += 2.00;
        }
        return round($price, 2);
    }

    if (preg_match('/^CM-70[123]/i', $sku) || str_contains($text, 'PULL STATION')) {
        $price = 59.99;
        if (str_contains($text, 'ALARM')) {
            $price += 10.00;
        }
        if (str_contains($text, 'N/C')) {
            $price += 5.00;
        }
        return round($price, 2);
    }

    if (preg_match('/^CM-8[1-5]0$/i', $sku) || str_contains($text, 'ROCKER SWITCH')) {
        $price = 34.99;
        if (str_contains($text, 'MAINTAINED')) {
            $price += 3.00;
        }
        if (str_contains($text, 'NEMA')) {
            $price += 8.00;
        }
        if (str_contains($text, 'REMOTE DOOR RELEASE')) {
            $price += 10.00;
        }
        return round($price, 2);
    }

    if ($category_slug === 'actuators') {
        if (str_contains($text, 'SCREW') || str_contains($text, 'PLUG')) {
            if (str_contains($text, 'BOX OF 100')) {
                return 19.99;
            }
            return 4.99;
        }
        if (str_contains($text, 'LED')) {
            $price = 12.99;
            if (str_contains($text, 'BI-COLORED')) {
                $price = 16.99;
            } elseif (str_contains($text, '12/24')) {
                $price = 14.99;
            }
            return round($price, 2);
        }
        if (str_contains($text, 'GASKET')) {
            return str_contains($text, 'NARROW') ? 18.99 : 16.99;
        }
        if (str_contains($text, 'FACEPLATE')) {
            return str_contains($text, 'JAMB') ? 22.99 : 19.99;
        }
        if (str_contains($text, 'CONTACT BLOCK') || str_contains($text, 'SWITCH BLOCK') || str_contains($text, 'CONTACT FOR')) {
            $price = 24.99;
            if (str_contains($text, 'N/O AND N/C') || str_contains($text, 'N/O \\& N/C') || str_contains($text, 'DPDT')) {
                $price = 34.99;
            }
            return round($price, 2);
        }
        if (str_contains($text, 'BUTTON')) {
            $price = 22.99;
            if (str_contains($text, 'ILLUMINATED')) {
                $price += 5.00;
            }
            if (str_contains($text, 'PUSH/PULL')) {
                $price += 5.00;
            }
            if (str_contains($text, 'LOCKING') || str_contains($text, 'WITH KEYS')) {
                $price += 25.00;
            }
            if (str_contains($text, '2-3/8')) {
                $price += 5.00;
            }
            if (str_contains($text, 'ROUND EXTENDED') || str_contains($text, 'EXTENDED')) {
                $price += 7.00;
            }
            if (str_contains($text, 'STEEL')) {
                $price += 8.00;
            }
            if (str_contains($text, 'MAINTAINED')) {
                $price += 5.00;
            }
            return round($price, 2);
        }
    }

    if (preg_match('/^CM-7/i', $sku)) {
        $price = 54.99;
        if (str_contains($text, 'NARROW')) {
            $price += 5.00;
        }
        if (str_contains($text, 'N/O AND N/C') || str_contains($text, 'N/O \\& N/C')) {
            $price += 5.00;
        }
        if (str_contains($text, 'PNEUMATIC') || str_contains($text, 'TIME DELAY') || str_contains($text, 'TIMER')) {
            $price += 10.00;
        }
        return round($price, 2);
    }

    if (preg_match('/^CM-8/i', $sku)) {
        $price = 59.99;
        if (str_contains($text, 'NARROW')) {
            $price += 5.00;
        }
        if (str_contains($text, 'N/O AND N/C') || str_contains($text, 'N/O \\& N/C')) {
            $price += 5.00;
        }
        if (str_contains($text, 'PNEUMATIC') || str_contains($text, 'TIME DELAY') || str_contains($text, 'TIMER')) {
            $price += 10.00;
        }
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
    $variant_title = trim((string) ($model['title'] ?? ''));
    if ($variant_title === '\\') {
        $variant_title = '';
    }
    if (str_contains(strtoupper($variant_title), 'DISCONTINUED')) {
        echo 'SKIPPED|DISCONTINUED|' . $sku . PHP_EOL;
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
    $title_for_display = $variant_title !== '' ? $variant_title : ($subsection !== '' ? $subsection : $series_subtitle);
    $display_name = trim('Camden ' . $sku . ' - ' . $title_for_display);
    $short_description = trim($title_for_display);

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
    if ($category_slug === 'actuators' && $sku === 'CM-LP1') {
        $misc_term_id = ado_camden_find_term_id('product_cat', 'Miscellaneous');
        if ($misc_term_id > 0) {
            $category_term_ids = [$misc_term_id];
        }
    }
    if ($category_slug === 'actuators' && $sku === 'CM-30U') {
        $misc_term_id = ado_camden_find_term_id('product_cat', 'Miscellaneous');
        if ($misc_term_id > 0) {
            $category_term_ids = [$misc_term_id];
        }
    }
    if ($category_slug === 'actuators' && $sku === 'CM-RX-90') {
        $misc_term_id = ado_camden_find_term_id('product_cat', 'Miscellaneous');
        if ($misc_term_id > 0) {
            $category_term_ids = [$misc_term_id];
        }
    }
    if ($category_slug === 'sensors' && in_array($sku, ['CM-RQEPW', 'CM-RQEPK'], true)) {
        $misc_term_id = ado_camden_find_term_id('product_cat', 'Miscellaneous');
        if ($misc_term_id > 0) {
            $category_term_ids = [$misc_term_id];
        }
    }
    if ($category_slug === 'relays' && $sku === 'CX-EMF-2PS') {
        $psu_term_id = ado_camden_find_term_id('product_cat', 'PSUs');
        if ($psu_term_id > 0) {
            $category_term_ids = [$psu_term_id];
        }
    }
    if ($category_slug === 'relays' && $sku === 'CX-33PS') {
        $psu_term_id = ado_camden_find_term_id('product_cat', 'PSUs');
        if ($psu_term_id > 0) {
            $category_term_ids = [$psu_term_id];
        }
    }
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
    if ($category_slug === 'actuators' && in_array($sku, ['CM-1000/21', 'CM-2000/21'], true)) {
        $keyswitch_term_id = ado_camden_find_term_id('product_cat', 'Keyswitches');
        if ($keyswitch_term_id > 0) {
            $category_term_ids[] = $keyswitch_term_id;
        }
    }
    if ($category_slug === 'washroom-kits') {
        $relay_term_id = ado_camden_find_term_id('product_cat', 'Relays');
        $psu_term_id = ado_camden_find_term_id('product_cat', 'PSUs');
        $sensor_term_id = ado_camden_find_term_id('product_cat', 'Sensors');
        $misc_term_id = ado_camden_find_term_id('product_cat', 'Miscellaneous');

        if ($sku === 'CX-33PS' && $psu_term_id > 0) {
            $category_term_ids[] = $psu_term_id;
        } elseif ($sku === 'CX-EMF-2' && $relay_term_id > 0) {
            $category_term_ids[] = $relay_term_id;
        } elseif (in_array($sku, ['CX-MDA', 'CX-MDC', 'CX-MDH'], true) && $sensor_term_id > 0) {
            $category_term_ids[] = $sensor_term_id;
        } elseif (in_array($sku, ['CM-AF500', 'CM-AF550R'], true) && $misc_term_id > 0) {
            $category_term_ids[] = $misc_term_id;
        } else {
            $category_term_ids[] = $actuator_term_id;
        }
    }
    if ($category_slug === 'washroom-kits') {
        $relay_term_id = ado_camden_find_term_id('product_cat', 'Relays');
        $keyswitch_term_id = ado_camden_find_term_id('product_cat', 'Keyswitches');
        $misc_term_id = ado_camden_find_term_id('product_cat', 'Miscellaneous');

        if (in_array($sku, ['CX-LRS12', 'CX-LRS24'], true) && $relay_term_id > 0) {
            $category_term_ids[] = $relay_term_id;
        } elseif ($sku === 'CM-1205/14-60KD' && $keyswitch_term_id > 0) {
            $category_term_ids[] = $keyswitch_term_id;
        } elseif (in_array($sku, ['CM-AF501SO', 'CM-AF540SO', 'CM-AF141SO', 'CM-AF142SO', 'CM-AF142SOFE', 'CM-SE21A', 'CM-SE21', 'CM-SE21F'], true) && $misc_term_id > 0) {
            $category_term_ids[] = $misc_term_id;
        } elseif (in_array($sku, ['CM-450R/12', 'CM-450R/12FE', 'CM-8010R/13'], true) && $actuator_term_id > 0) {
            $category_term_ids[] = $actuator_term_id;
        }
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
