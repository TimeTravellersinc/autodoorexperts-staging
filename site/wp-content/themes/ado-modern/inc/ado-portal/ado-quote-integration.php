<?php
if (defined('ADO_QUOTE_INTEGRATION_LOADED')) {
    return;
}
define('ADO_QUOTE_INTEGRATION_LOADED', true);

final class ADO_Quote_Integration
{
    public const CPT = 'adq_quote';

    private static ?ADO_Quote_Integration $instance = null;

    public static function instance(): ADO_Quote_Integration
    {
        if (!(self::$instance instanceof ADO_Quote_Integration)) {
            self::$instance = new ADO_Quote_Integration();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('template_redirect', [$this, 'maybe_hydrate_checkout_quote'], 1);
        add_filter('woocommerce_get_item_data', [$this, 'cart_item_door_meta'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'copy_cart_meta_to_order_item'], 10, 4);
        add_action('woocommerce_checkout_create_order', [$this, 'attach_quote_to_order'], 20, 1);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_quantity_tier_pricing'], 20, 1);
    }

    public function register_post_type(): void
    {
        if (post_type_exists(self::CPT)) {
            return;
        }
        register_post_type(self::CPT, [
            'labels' => ['name' => 'ADO Quotes', 'singular_name' => 'ADO Quote'],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'woocommerce',
            'supports' => ['title', 'author'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function scope_url_to_path(string $scope_url): string
    {
        $scope_url = trim($scope_url);
        if ($scope_url === '') {
            return '';
        }
        $uploads = wp_upload_dir();
        $base_url = (string) ($uploads['baseurl'] ?? '');
        $base_dir = (string) ($uploads['basedir'] ?? '');
        if ($base_url === '' || $base_dir === '' || strpos($scope_url, $base_url) !== 0) {
            return '';
        }
        $rel = ltrim((string) substr($scope_url, strlen($base_url)), '/');
        return trailingslashit($base_dir) . $rel;
    }

    public function create_quote_from_scope_url(int $user_id, string $scope_url, string $quote_name = '', bool $debug = false): array
    {
        $scope_path = $this->scope_url_to_path($scope_url);
        if ($scope_path === '' || !file_exists($scope_path)) {
            return ['ok' => false, 'message' => 'Scoped JSON file not found.'];
        }
        $payload = json_decode((string) file_get_contents($scope_path), true);
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Scoped JSON payload is invalid.'];
        }
        return $this->create_quote_from_payload($user_id, $payload, [
            'name' => $quote_name,
            'scope_url' => $scope_url,
            'scope_path' => $scope_path,
            'debug' => $debug,
        ]);
    }

    public function create_quote_from_payload(int $user_id, array $payload, array $args = []): array
    {
        $doors_raw = (array) ($payload['result']['doors'] ?? []);
        if (!$doors_raw) {
            return ['ok' => false, 'message' => 'Scoped JSON contains no doors.'];
        }

        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            $name = 'Quote ' . wp_date('Y-m-d H:i');
        }

        $mapped = $this->map_payload($payload, !empty($args['debug']));
        $lines = (array) ($mapped['lines'] ?? []);
        if (!$lines) {
            return [
                'ok' => false,
                'message' => 'No products matched scoped JSON.',
                'unmatched' => array_values((array) ($mapped['unmatched'] ?? [])),
                'debug_log' => array_values((array) ($mapped['debug_log'] ?? [])),
            ];
        }

        $quote_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $name,
            'post_author' => $user_id,
        ], true);
        if (is_wp_error($quote_id) || (int) $quote_id <= 0) {
            return ['ok' => false, 'message' => 'Failed to create quote record.'];
        }
        $quote_id = (int) $quote_id;

        $snapshot_json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        $snapshot_json = is_string($snapshot_json) ? $snapshot_json : '';
        $created_at = current_time('mysql');

        update_post_meta($quote_id, '_adq_user_id', $user_id);
        update_post_meta($quote_id, '_adq_status', 'draft');
        update_post_meta($quote_id, '_adq_created_at', $created_at);
        update_post_meta($quote_id, '_adq_updated_at', $created_at);
        update_post_meta($quote_id, '_adq_scope_url', (string) ($args['scope_url'] ?? ''));
        update_post_meta($quote_id, '_adq_scope_path', (string) ($args['scope_path'] ?? ''));
        update_post_meta($quote_id, '_adq_scoped_json_snapshot', $snapshot_json);
        update_post_meta($quote_id, '_adq_doors', array_values((array) ($mapped['doors'] ?? [])));
        update_post_meta($quote_id, '_adq_cart_snapshot', array_values($lines));
        update_post_meta($quote_id, '_adq_unmatched_items', array_values((array) ($mapped['unmatched'] ?? [])));
        update_post_meta($quote_id, '_adq_match_log', array_values((array) ($mapped['debug_log'] ?? [])));
        update_post_meta($quote_id, '_adq_totals', $this->calculate_snapshot_totals($lines));

        return [
            'ok' => true,
            'quote_id' => $quote_id,
            'message' => 'Quote created from scoped JSON.',
            'unmatched_count' => count((array) ($mapped['unmatched'] ?? [])),
            'debug_log' => array_values((array) ($mapped['debug_log'] ?? [])),
        ];
    }

    public function rerun_matching(int $quote_id, bool $debug = false): array
    {
        $snapshot_json = (string) get_post_meta($quote_id, '_adq_scoped_json_snapshot', true);
        if ($snapshot_json === '') {
            return ['ok' => false, 'message' => 'Quote has no scoped snapshot.'];
        }
        $payload = json_decode($snapshot_json, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Quote scoped snapshot is invalid.'];
        }
        $mapped = $this->map_payload($payload, $debug);
        $lines = (array) ($mapped['lines'] ?? []);
        if (!$lines) {
            return ['ok' => false, 'message' => 'No products matched after rerun.'];
        }
        update_post_meta($quote_id, '_adq_cart_snapshot', array_values($lines));
        update_post_meta($quote_id, '_adq_doors', array_values((array) ($mapped['doors'] ?? [])));
        update_post_meta($quote_id, '_adq_unmatched_items', array_values((array) ($mapped['unmatched'] ?? [])));
        update_post_meta($quote_id, '_adq_match_log', array_values((array) ($mapped['debug_log'] ?? [])));
        update_post_meta($quote_id, '_adq_updated_at', current_time('mysql'));
        update_post_meta($quote_id, '_adq_totals', $this->calculate_snapshot_totals($lines));
        return ['ok' => true, 'message' => 'Matching rerun completed.', 'debug_log' => (array) ($mapped['debug_log'] ?? [])];
    }

    public function get_quote(int $quote_id): ?WP_Post
    {
        $post = get_post($quote_id);
        return ($post instanceof WP_Post && $post->post_type === self::CPT) ? $post : null;
    }

    public function quote_belongs_to_user(int $quote_id, int $user_id): bool
    {
        return (int) get_post_meta($quote_id, '_adq_user_id', true) === $user_id;
    }

    public function get_user_quotes(int $user_id, array $statuses = []): array
    {
        $query = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [['key' => '_adq_user_id', 'value' => (string) $user_id, 'compare' => '=']],
        ]);
        $rows = [];
        foreach ((array) $query->posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }
            $status = (string) get_post_meta((int) $post->ID, '_adq_status', true);
            if ($statuses && !in_array($status, $statuses, true)) {
                continue;
            }
            $rows[] = $post;
        }
        return $rows;
    }

    public function load_quote_to_cart(int $quote_id, int $user_id): array
    {
        if (!function_exists('WC') || !WC()->cart || !WC()->session) {
            return ['ok' => false, 'message' => 'WooCommerce cart is unavailable.'];
        }
        if (!$this->quote_belongs_to_user($quote_id, $user_id) && !current_user_can('manage_woocommerce')) {
            return ['ok' => false, 'message' => 'Quote access denied.'];
        }
        $snapshot = get_post_meta($quote_id, '_adq_cart_snapshot', true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        if (!$snapshot) {
            return ['ok' => false, 'message' => 'Quote is empty.'];
        }
        WC()->cart->empty_cart();
        $added = 0;
        foreach ($snapshot as $line) {
            if (!is_array($line)) {
                continue;
            }
            $pid = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $meta = [
                'adq_quote_id' => $quote_id,
                'adq_door_id' => (string) ($line['door_id'] ?? ''),
                'adq_door_number' => (string) ($line['door_number'] ?? ''),
                'adq_door_label' => (string) ($line['door_label'] ?? ''),
                'adq_model' => (string) ($line['model'] ?? ''),
                'adq_source_desc' => (string) ($line['description'] ?? ''),
                'adq_source_raw' => (string) ($line['raw_line'] ?? ''),
            ];
            $key = WC()->cart->add_to_cart($pid, $qty, 0, [], $meta);
            if ($key) {
                $added++;
            }
        }
        if ($added <= 0) {
            return ['ok' => false, 'message' => 'No quote lines could be loaded.'];
        }
        WC()->session->set('adq_active_quote_id', $quote_id);
        WC()->session->set('ado_last_quote_draft_id', (string) $quote_id);
        WC()->session->set('ado_last_scope_url', (string) get_post_meta($quote_id, '_adq_scope_url', true));
        WC()->session->set('ado_last_scope_path', (string) get_post_meta($quote_id, '_adq_scope_path', true));
        return ['ok' => true, 'cart_url' => wc_get_cart_url(), 'checkout_url' => wc_get_checkout_url()];
    }

    public function maybe_hydrate_checkout_quote(): void
    {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }
        if (!function_exists('WC') || !WC()->cart || !WC()->session) {
            return;
        }

        $quote_id = isset($_GET['ado_quote_id']) ? (int) $_GET['ado_quote_id'] : 0;
        if ($quote_id <= 0) {
            return;
        }
        if (!is_user_logged_in()) {
            wc_add_notice('Please sign in to continue this quote checkout.', 'error');
            return;
        }

        $user_id = (int) get_current_user_id();
        if (!$this->quote_belongs_to_user($quote_id, $user_id) && !current_user_can('manage_woocommerce')) {
            wc_add_notice('Quote access denied.', 'error');
            return;
        }
        if ((string) get_post_meta($quote_id, '_adq_status', true) === 'ordered') {
            return;
        }

        if ($this->cart_matches_quote($quote_id)) {
            WC()->session->set('adq_active_quote_id', $quote_id);
            return;
        }

        $loaded = $this->load_quote_to_cart($quote_id, $user_id);
        if (empty($loaded['ok'])) {
            wc_add_notice((string) ($loaded['message'] ?? 'Failed to load quote into checkout.'), 'error');
            return;
        }

        $this->update_quote_status($quote_id, 'submitted');
    }

    private function cart_matches_quote(int $quote_id): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        $cart = WC()->cart->get_cart();
        if (!$cart) {
            return false;
        }
        foreach ($cart as $row) {
            if (!is_array($row)) {
                return false;
            }
            if ((int) ($row['adq_quote_id'] ?? 0) !== $quote_id) {
                return false;
            }
        }
        return true;
    }

    public function update_quote_status(int $quote_id, string $status): void
    {
        update_post_meta($quote_id, '_adq_status', sanitize_key($status));
        update_post_meta($quote_id, '_adq_updated_at', current_time('mysql'));
    }

    public function cart_item_door_meta(array $item_data, array $cart_item): array
    {
        if (!empty($cart_item['adq_door_label'])) {
            $item_data[] = ['name' => 'Door', 'value' => (string) $cart_item['adq_door_label']];
        }
        if (!empty($cart_item['adq_model'])) {
            $item_data[] = ['name' => 'Model', 'value' => (string) $cart_item['adq_model']];
        }
        return $item_data;
    }

    public function copy_cart_meta_to_order_item(WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order): void
    {
        foreach ([
            'adq_door_id' => '_adq_door_id',
            'adq_door_number' => '_adq_door_number',
            'adq_door_label' => '_adq_door_label',
            'adq_model' => '_adq_model',
            'adq_source_desc' => '_adq_source_desc',
            'adq_source_raw' => '_adq_source_raw',
        ] as $k => $meta_key) {
            if (!empty($values[$k])) {
                $item->add_meta_data($meta_key, (string) $values[$k], true);
            }
        }
    }

    public function attach_quote_to_order(WC_Order $order): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        $quote_id = (int) WC()->session->get('adq_active_quote_id');
        if ($quote_id <= 0) {
            return;
        }
        $quote = $this->get_quote($quote_id);
        if (!$quote) {
            return;
        }

        $doors = get_post_meta($quote_id, '_adq_doors', true);
        $unmatched = get_post_meta($quote_id, '_adq_unmatched_items', true);
        $scope_snapshot = (string) get_post_meta($quote_id, '_adq_scoped_json_snapshot', true);
        $scope_path = (string) get_post_meta($quote_id, '_adq_scope_path', true);
        $scope_url = (string) get_post_meta($quote_id, '_adq_scope_url', true);

        $order->update_meta_data('_ado_quote_id', $quote_id);
        $order->update_meta_data('_ado_project_doors', is_array($doors) ? $doors : []);
        $order->update_meta_data('_ado_unmatched_items', is_array($unmatched) ? $unmatched : []);
        if ($scope_snapshot !== '') {
            $order->update_meta_data('_ado_scoped_json_snapshot', $scope_snapshot);
        }
        if ($scope_path !== '') {
            $order->update_meta_data('_ado_scoped_json_path', $scope_path);
        }
        if ($scope_url !== '') {
            $order->update_meta_data('_ado_scoped_json_url', $scope_url);
        }

        $this->update_quote_status($quote_id, 'ordered');
        update_post_meta($quote_id, '_adq_order_id', $order->get_id());
        WC()->session->__unset('adq_active_quote_id');
    }

    public function apply_quantity_tier_pricing(WC_Cart $cart): void
    {
        if ((is_admin() && !defined('DOING_AJAX')) || $cart->is_empty()) {
            return;
        }
        $qty_total = 0;
        foreach ($cart->get_cart() as $row) {
            $qty_total += max(0, (int) ($row['quantity'] ?? 0));
        }
        if ($qty_total <= 0) {
            return;
        }

        foreach ($cart->get_cart() as $row) {
            $product = isset($row['data']) && $row['data'] instanceof WC_Product ? $row['data'] : null;
            if (!$product) {
                continue;
            }
            $base = (float) $product->get_regular_price();
            if ($base <= 0) {
                $base = (float) $product->get_price('edit');
            }
            if ($base <= 0) {
                continue;
            }
            $tier = $this->resolve_tier_price_for_product($product, $qty_total, $base);
            if ($tier > 0 && abs($tier - (float) $product->get_price('edit')) > 0.0001) {
                $product->set_price((string) $tier);
            }
        }
    }

    public function calculate_snapshot_totals(array $snapshot): array
    {
        $qty_total = 0;
        foreach ($snapshot as $line) {
            $qty_total += max(0, (int) ($line['qty'] ?? 0));
        }
        $subtotal = 0.0;
        foreach ($snapshot as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $base = (float) $product->get_regular_price();
            if ($base <= 0) {
                $base = (float) $product->get_price('edit');
            }
            if ($base < 0) {
                $base = 0;
            }
            $unit = $this->resolve_tier_price_for_product($product, $qty_total, $base);
            $subtotal += ($unit * $qty);
        }
        return ['currency' => get_woocommerce_currency(), 'qty_total' => $qty_total, 'subtotal' => round($subtotal, 2)];
    }

    private function resolve_tier_price_for_product(WC_Product $product, int $qty_total, float $base_price): float
    {
        $map = [];
        $json = (string) $product->get_meta('_ado_qty_tier_prices', true);
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $min => $price) {
                    $min_i = (int) $min;
                    $price_f = (float) $price;
                    if ($min_i > 0 && $price_f >= 0) {
                        $map[$min_i] = $price_f;
                    }
                }
            }
        }
        if (!$map) {
            $all_meta = get_post_meta($product->get_id());
            foreach ((array) $all_meta as $key => $vals) {
                if (!is_string($key) || strpos($key, '_ado_tier_price_') !== 0) {
                    continue;
                }
                $min = (int) preg_replace('/\D+/', '', (string) substr($key, strlen('_ado_tier_price_')));
                $price = isset($vals[0]) ? (float) $vals[0] : -1.0;
                if ($min > 0 && $price >= 0) {
                    $map[$min] = $price;
                }
            }
        }
        if (!$map) {
            return max(0.0, $base_price);
        }
        krsort($map, SORT_NUMERIC);
        foreach ($map as $min => $price) {
            if ($qty_total >= (int) $min) {
                return max(0.0, (float) $price);
            }
        }
        return max(0.0, $base_price);
    }

    private function map_payload(array $payload, bool $debug): array
    {
        $doors_raw = (array) ($payload['result']['doors'] ?? []);
        $doors = [];
        $line_map = [];
        $unmatched = [];
        $debug_log = [];

        foreach ($doors_raw as $idx => $door_raw) {
            if (!is_array($door_raw)) {
                continue;
            }
            foreach ($this->expand_door_record($door_raw, (int) $idx, $debug_log, $debug) as $door) {
                $doors[] = $this->door_meta_from_scope($door);
                foreach ((array) ($door['items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 1;
                    if ($qty <= 0) {
                        $qty = 1;
                    }
                    $attempt_log = [];
                    $match = $this->match_item_to_product($item, $attempt_log);
                    $pid = (int) ($match['product_id'] ?? 0);

                    if ($debug) {
                        $debug_log[] = [
                            'door_number' => (string) ($door['door_number'] ?? ''),
                            'raw_line' => (string) ($item['raw'] ?? ''),
                            'attempts' => $attempt_log,
                            'matched_product_id' => $pid,
                            'matched_by' => (string) ($match['matched_by'] ?? 'none'),
                        ];
                    }

                    if ($pid <= 0) {
                        $unmatched[] = [
                            'door_id' => (string) ($door['door_id'] ?? ''),
                            'door_number' => (string) ($door['door_number'] ?? ''),
                            'raw_line' => (string) ($item['raw'] ?? ''),
                            'model' => (string) ($item['catalog'] ?? ''),
                            'description' => (string) ($item['desc'] ?? ''),
                            'qty' => $qty,
                        ];
                        continue;
                    }

                    $key = (string) ($door['door_id'] ?? '') . '|' . $pid;
                    if (!isset($line_map[$key])) {
                        $line_map[$key] = [
                            'product_id' => $pid,
                            'qty' => 0,
                            'door_id' => (string) ($door['door_id'] ?? ''),
                            'door_number' => (string) ($door['door_number'] ?? ''),
                            'door_label' => (string) ($door['door_label'] ?? ''),
                            'model' => (string) ($item['catalog'] ?? ''),
                            'description' => (string) ($item['desc'] ?? ''),
                            'raw_line' => (string) ($item['raw'] ?? ''),
                        ];
                    }
                    $line_map[$key]['qty'] += $qty;
                }
            }
        }

        return ['doors' => array_values($doors), 'lines' => array_values($line_map), 'unmatched' => $unmatched, 'debug_log' => $debug_log];
    }

    private function door_meta_from_scope(array $door): array
    {
        $number = trim((string) ($door['door_number'] ?? ''));
        $type = trim((string) ($door['door_type'] ?? ''));
        $desc = trim((string) ($door['desc'] ?? ''));
        $location = trim((string) ($door['heading'] ?? ''));
        $label = 'Door ' . ($number !== '' ? $number : 'Unknown');
        if ($type !== '') {
            $label .= ' - ' . $type;
        }
        if ($desc !== '') {
            $label .= ' - ' . $desc;
        }
        return [
            'door_id' => (string) ($door['door_id'] ?? ''),
            'door_number' => $number,
            'door_label' => $label,
            'desc' => $desc,
            'location' => $location,
            'door_type' => $type,
            'has_operator' => $this->door_has_operator((array) ($door['items'] ?? [])),
        ];
    }

    private function door_has_operator(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = strtoupper((string) ($item['raw'] ?? ''));
            $catalog = strtoupper((string) ($item['catalog'] ?? ''));
            if (strpos($raw, ' OPERATOR ') !== false || strpos($catalog, 'OPERATOR') !== false) {
                return true;
            }
        }
        return false;
    }

    private function expand_door_record(array $door, int $index, array &$debug_log, bool $debug): array
    {
        $door_raw = trim((string) ($door['door_id'] ?? ''));
        if ($door_raw === '') {
            return [];
        }
        $numbers = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $door_raw))));
        if (!$numbers) {
            $numbers = [$door_raw];
        }
        if (count($numbers) <= 1) {
            $single = $door;
            $single['door_number'] = $numbers[0];
            $single['door_id'] = $this->stable_door_id($numbers[0], $index, 0);
            $single['door_label'] = 'Door ' . $numbers[0];
            return [$single];
        }

        $split = [];
        $count = count($numbers);
        foreach ($numbers as $part_idx => $number) {
            $copy = $door;
            $copy['door_number'] = $number;
            $copy['door_id'] = $this->stable_door_id($number, $index, $part_idx);
            $copy['door_label'] = 'Door ' . $number;
            $copy['items'] = [];
            foreach ((array) ($door['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 1;
                if ($qty <= 0) {
                    $qty = 1;
                }
                $new_qty = (int) floor($qty / $count);
                if ($part_idx < ($qty % $count)) {
                    $new_qty++;
                }
                if ($new_qty <= 0) {
                    continue;
                }
                $copy_item = $item;
                $copy_item['qty'] = $new_qty;
                $copy['items'][] = $copy_item;
            }
            $split[] = $copy;
        }
        if ($debug) {
            $debug_log[] = ['door_number' => $door_raw, 'raw_line' => '', 'attempts' => ['Split comma-separated door IDs with equal qty fallback.'], 'matched_product_id' => 0, 'matched_by' => 'split_fallback'];
        }
        return $split;
    }

    private function stable_door_id(string $door_number, int $idx, int $part): string
    {
        return 'door_' . substr(md5(strtoupper(trim($door_number)) . '|' . $idx . '|' . $part), 0, 12);
    }

    private function match_item_to_product(array $item, array &$attempt_log): array
    {
        $tokens = $this->extract_model_tokens($item);
        $attempt_log[] = 'tokens=' . implode(', ', array_slice($tokens, 0, 10));

        foreach ($tokens as $token) {
            foreach ($this->token_variants($token) as $cand) {
                $attempt_log[] = 'sku_exact:' . $cand;
                $pid = (int) wc_get_product_id_by_sku($cand);
                if ($pid > 0) {
                    return ['product_id' => $pid, 'matched_by' => 'sku_exact'];
                }
            }
        }

        global $wpdb;
        foreach ($tokens as $token) {
            foreach ($this->token_variants($token) as $cand) {
                foreach (['_manufacturer_part_number', 'manufacturer_part_number', '_ado_model', '_ado_catalog'] as $meta_key) {
                    $attempt_log[] = 'meta:' . $meta_key . ':' . $cand;
                    $pid = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND UPPER(TRIM(meta_value))=%s ORDER BY post_id DESC LIMIT 1",
                        $meta_key,
                        strtoupper(trim($cand))
                    ));
                    if ($pid > 0 && get_post_type($pid) === 'product') {
                        return ['product_id' => $pid, 'matched_by' => 'meta_exact'];
                    }
                }
            }
        }

        foreach ($tokens as $token) {
            $attempt_log[] = 'title_fuzzy:' . $token;
            $pid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND UPPER(post_title) LIKE UPPER(%s) ORDER BY ID DESC LIMIT 1",
                '%' . $wpdb->esc_like($token) . '%'
            ));
            if ($pid > 0) {
                return ['product_id' => $pid, 'matched_by' => 'title_fuzzy'];
            }
        }

        $attempt_log[] = 'unmatched';
        return ['product_id' => 0, 'matched_by' => 'none'];
    }

    private function extract_model_tokens(array $item): array
    {
        $tokens = [];
        foreach (['catalog', 'model', 'desc', 'raw', 'description'] as $field) {
            $value = strtoupper(trim((string) ($item[$field] ?? '')));
            if ($value === '') {
                continue;
            }
            if (in_array($field, ['catalog', 'model'], true)) {
                $tokens[] = $value;
            }
            if (preg_match_all('/\b[A-Z0-9]{2,}(?:\s*[-\/]\s*[A-Z0-9]{1,})+\b/', $value, $m)) {
                foreach ((array) $m[0] as $found) {
                    $tokens[] = strtoupper(trim((string) $found));
                }
            }
        }
        return array_values(array_unique(array_filter($tokens)));
    }

    private function token_variants(string $token): array
    {
        $base = strtoupper(trim($token));
        if ($base === '') {
            return [];
        }
        $base = str_replace(['–', '—'], '-', $base);
        $vars = [$base];
        $vars[] = str_replace(' ', '', $base);
        $vars[] = preg_replace('/\s+/', ' ', $base);
        $vars[] = preg_replace('/\s*-\s*/', '-', $base);
        $vars[] = preg_replace('/\.0\b/', '', $base);
        if (strpos($base, '/') !== false) {
            foreach ((array) preg_split('/\s*\/\s*/', $base) as $part) {
                $vars[] = strtoupper(trim((string) $part));
            }
        }
        $clean = [];
        foreach ($vars as $var) {
            $var = strtoupper(trim((string) $var));
            if ($var !== '') {
                $clean[] = $var;
            }
        }
        return array_values(array_unique($clean));
    }
}

ADO_Quote_Integration::instance();
