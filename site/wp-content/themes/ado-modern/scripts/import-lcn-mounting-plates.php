<?php
declare(strict_types=1);

$stderr = fopen('php://stderr', 'wb');
$writeErr = static function (string $message) use ($stderr): void {
    if (is_resource($stderr)) {
        fwrite($stderr, $message);
    }
};

if (PHP_SAPI !== 'cli') {
    $writeErr("Run from CLI only.\n");
    exit(1);
}

$bootstrapCandidates = [
    dirname(__DIR__, 4) . '/wp-load.php',
    '/var/www/html/wp-load.php',
];
$bootstrapped = false;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        $bootstrapped = true;
        break;
    }
}

if (!$bootstrapped) {
    $writeErr("Unable to locate wp-load.php\n");
    exit(1);
}

if (!class_exists('WooCommerce') || !function_exists('wc_get_products')) {
    $writeErr("WooCommerce is not available.\n");
    exit(1);
}

$mode = $argv[1] ?? 'report';
if (!in_array($mode, ['report', 'import', 'verify'], true)) {
    $writeErr("Usage: php import-lcn-mounting-plates.php [report|import|verify]\n");
    exit(1);
}

$sourceNote = 'Imported from existing LCN operator product';
$mountingPlateDesc = 'Optional mounting plate for standard hollow metal door frames is 1/4" thick.';

$findBrandTerm = static function (): ?WP_Term {
    $brands = get_terms([
        'taxonomy' => 'product_brand',
        'hide_empty' => false,
    ]);
    if (is_wp_error($brands)) {
        return null;
    }
    foreach ($brands as $term) {
        if (strcasecmp($term->name, 'LCN') === 0) {
            return $term;
        }
    }
    return null;
};

$productHasOperatorCategory = static function (int $productId): bool {
    $terms = get_the_terms($productId, 'product_cat');
    if (!is_array($terms)) {
        return false;
    }
    foreach ($terms as $term) {
        $name = strtolower($term->name);
        $slug = strtolower($term->slug);
        if (str_contains($name, 'operator') || str_contains($slug, 'operator')) {
            return true;
        }
    }
    return false;
};

$normalizeSeriesName = static function (string $name): string {
    $name = preg_replace('/\s+operator\s*$/i', '', trim($name)) ?? trim($name);
    $name = preg_replace('/\s+series\s*$/i', '', trim($name)) ?? trim($name);
    return trim($name);
};

$buildPlateName = static function (WC_Product $product) use ($normalizeSeriesName): string {
    $sku = strtoupper(trim((string) $product->get_sku()));
    if ($sku !== '') {
        return sprintf('%s-18 Mounting Plate', $sku);
    }
    $base = $normalizeSeriesName($product->get_name());
    return sprintf('%s-18 Mounting Plate', $base);
};

$buildPlateSku = static function (WC_Product $product): string {
    $sku = strtoupper(trim((string) $product->get_sku()));
    if ($sku !== '') {
        return $sku . '-18';
    }
    $name = preg_replace('/[^A-Z0-9]+/', '-', strtoupper($product->get_name())) ?? '';
    return trim($name, '-') . '-18';
};

$isExistingPlate = static function (WC_Product $product): bool {
    $sku = strtoupper((string) $product->get_sku());
    $name = strtoupper($product->get_name());
    return str_contains($sku, '-18') || str_contains($name, 'MOUNTING PLATE');
};

$collectSourceProducts = static function () use ($findBrandTerm, $productHasOperatorCategory, $isExistingPlate): array {
    $brand = $findBrandTerm();
    if (!$brand) {
        return [];
    }

    $products = wc_get_products([
        'status' => ['publish', 'draft', 'pending', 'private'],
        'limit' => -1,
        'return' => 'objects',
        'brand' => [$brand->slug],
    ]);

    $sources = [];
    foreach ($products as $product) {
        if (!$product instanceof WC_Product) {
            continue;
        }
        if (!$productHasOperatorCategory($product->get_id())) {
            continue;
        }
        if ($isExistingPlate($product)) {
            continue;
        }
        $sources[] = $product;
    }

    usort($sources, static function (WC_Product $a, WC_Product $b): int {
        return strcmp((string) $a->get_sku(), (string) $b->get_sku());
    });

    return $sources;
};

$findExistingPlateId = static function (string $plateSku): int {
    $existingId = (int) wc_get_product_id_by_sku($plateSku);
    if ($existingId > 0) {
        return $existingId;
    }

    $products = wc_get_products([
        'status' => ['publish', 'draft', 'pending', 'private'],
        'limit' => 1,
        'sku' => $plateSku,
        'return' => 'ids',
    ]);

    return isset($products[0]) ? (int) $products[0] : 0;
};

$syncMeta = static function (int $sourceId, int $targetId, string $targetSku, string $targetName) use ($sourceNote, $mountingPlateDesc): void {
    global $wpdb;

    $metaRows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value
         FROM {$wpdb->postmeta}
         WHERE post_id = %d",
        $sourceId
    ));

    $skipKeys = [
        '_sku',
        '_thumbnail_id',
        '_product_image_gallery',
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        'total_sales',
    ];

    foreach ($metaRows as $row) {
        $metaKey = (string) $row->meta_key;
        if (in_array($metaKey, $skipKeys, true)) {
            continue;
        }
        delete_post_meta($targetId, $metaKey);
        add_post_meta($targetId, $metaKey, maybe_unserialize($row->meta_value));
    }

    update_post_meta($targetId, '_sku', $targetSku);

    $metaOverrides = [
        '_ado_model' => $targetSku,
        '_manufacturer_part_number' => $targetSku,
        'manufacturer_part_number' => $targetSku,
        'mpn' => $targetSku,
        'manufacturer_sku' => $targetSku,
        '_yoast_wpseo_title' => $targetName,
        '_yoast_wpseo_metadesc' => $mountingPlateDesc,
        '_yoast_wpseo_opengraph-description' => $mountingPlateDesc,
        '_yoast_wpseo_twitter-description' => $mountingPlateDesc,
        '_source_product_note' => $sourceNote,
        '_lcn_mounting_plate_source_product_id' => $sourceId,
    ];

    foreach ($metaOverrides as $metaKey => $metaValue) {
        update_post_meta($targetId, $metaKey, $metaValue);
    }
};

$sourceProducts = $collectSourceProducts();

if ($mode === 'verify') {
    $samples = ['9500-18', '8000-18', 'SW800-18'];
    $rows = [];
    foreach ($samples as $sku) {
        $productId = (int) wc_get_product_id_by_sku($sku);
        $product = $productId > 0 ? wc_get_product($productId) : null;
        $rows[] = [
            'sku' => $sku,
            'id' => $productId,
            'name' => $product instanceof WC_Product ? $product->get_name() : '',
            'image_id' => $product instanceof WC_Product ? $product->get_image_id() : null,
            'gallery_count' => $product instanceof WC_Product ? count($product->get_gallery_image_ids()) : null,
            'short_description' => $product instanceof WC_Product ? $product->get_short_description() : '',
        ];
    }
    echo wp_json_encode(['rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$reportRows = [];
foreach ($sourceProducts as $product) {
    $plateSku = $buildPlateSku($product);
    $plateName = $buildPlateName($product);
    $reportRows[] = [
        'source_id' => $product->get_id(),
        'source_sku' => (string) $product->get_sku(),
        'source_name' => $product->get_name(),
        'plate_sku' => $plateSku,
        'plate_name' => $plateName,
        'existing_id' => $findExistingPlateId($plateSku),
    ];
}

if ($mode === 'report') {
    echo wp_json_encode([
        'count' => count($reportRows),
        'rows' => $reportRows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-content/plugins/woocommerce/includes/admin/class-wc-admin-duplicate-product.php';

$created = [];
$updated = [];

foreach ($sourceProducts as $product) {
    $sourceId = $product->get_id();
    $plateSku = $buildPlateSku($product);
    $plateName = $buildPlateName($product);
    $existingId = $findExistingPlateId($plateSku);

    if ($existingId > 0) {
        $plateProduct = wc_get_product($existingId);
        if (!$plateProduct instanceof WC_Product) {
            continue;
        }
        $updated[] = $existingId;
    } else {
        $duplicate = new WC_Admin_Duplicate_Product();
        $plateProduct = $duplicate->product_duplicate($product);
        if (!$plateProduct instanceof WC_Product) {
            continue;
        }
        $existingId = $plateProduct->get_id();
        $created[] = $existingId;
    }

    $plateProduct->set_name($plateName);
    $plateProduct->set_slug(sanitize_title($plateName));
    $plateProduct->set_sku($plateSku);
    $plateProduct->set_short_description($mountingPlateDesc);
    $plateProduct->set_description($mountingPlateDesc);
    $plateProduct->set_image_id(0);
    $plateProduct->set_gallery_image_ids([]);
    $plateProduct->save();

    delete_post_thumbnail($existingId);
    delete_post_meta($existingId, '_product_image_gallery');

    $catIds = wp_get_post_terms($sourceId, 'product_cat', ['fields' => 'ids']);
    if (is_array($catIds) && $catIds !== []) {
        wp_set_post_terms($existingId, array_map('intval', $catIds), 'product_cat', false);
    }

    $brandIds = wp_get_post_terms($sourceId, 'product_brand', ['fields' => 'ids']);
    if (is_array($brandIds) && $brandIds !== []) {
        wp_set_post_terms($existingId, array_map('intval', $brandIds), 'product_brand', false);
    }

    $tagIds = wp_get_post_terms($sourceId, 'product_tag', ['fields' => 'ids']);
    if (is_array($tagIds)) {
        wp_set_post_terms($existingId, array_map('intval', $tagIds), 'product_tag', false);
    }

    $status = get_post_status($sourceId) ?: 'publish';
    wp_update_post([
        'ID' => $existingId,
        'post_title' => $plateName,
        'post_name' => sanitize_title($plateName),
        'post_excerpt' => $mountingPlateDesc,
        'post_content' => $mountingPlateDesc,
        'post_status' => $status,
    ]);

    $syncMeta($sourceId, $existingId, $plateSku, $plateName);
    clean_post_cache($existingId);
}

echo wp_json_encode([
    'created' => $created,
    'updated' => $updated,
    'count' => count($created) + count($updated),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
