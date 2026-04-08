<?php
if (defined('ADO_BRAND_MATCHER_REGISTRY_LOADED')) {
    return;
}
define('ADO_BRAND_MATCHER_REGISTRY_LOADED', true);

/**
 * @return array<string, class-string<ADO_Brand_Matcher_Interface>>
 */
function &ado_brand_matcher_registry_storage(): array
{
    static $registry = [];
    return $registry;
}

/**
 * Register a matcher implementation for a specific brand key.
 */
function ado_register_brand_matcher(string $brand_key, string $class_name): void
{
    $brand_key = trim(strtolower($brand_key));
    if ($brand_key === '' || !class_exists($class_name)) {
        return;
    }
    if (!is_subclass_of($class_name, ADO_Brand_Matcher_Interface::class, true)) {
        return;
    }
    $storage = &ado_brand_matcher_registry_storage();
    $storage[$brand_key] = $class_name;
}

/**
 * Instantiate the matcher bound to a brand key.
 */
function ado_brand_matcher_instance_for_brand(string $brand_key): ?ADO_Brand_Matcher_Interface
{
    $storage = ado_brand_matcher_registry_storage();
    $brand_key = trim(strtolower($brand_key));
    if ($brand_key === '' || empty($storage[$brand_key])) {
        return null;
    }
    $class = $storage[$brand_key];
    if (!class_exists($class)) {
        return null;
    }
    return new $class();
}

/**
 * Try each provided brand key in order and return the first matcher that exists.
 */
function ado_brand_matcher_instance_for_brands(array $brand_keys): ?ADO_Brand_Matcher_Interface
{
    foreach ($brand_keys as $brand_key) {
        $matcher = ado_brand_matcher_instance_for_brand((string) $brand_key);
        if ($matcher !== null) {
            return $matcher;
        }
    }
    return null;
}
