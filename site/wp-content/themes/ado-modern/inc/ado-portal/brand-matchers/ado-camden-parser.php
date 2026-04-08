<?php
if (defined('ADO_CAMDEN_PARSER_LOADED')) {
    return;
}
define('ADO_CAMDEN_PARSER_LOADED', true);

function ado_camden_normalize_segment(string $segment, array $pack): string
{
    $normalized = strtoupper(trim($segment));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace($pack['noise_patterns'], ' ', $normalized);
    $normalized = preg_replace('/\\s*-\\s*/', '-', $normalized);
    $normalized = preg_replace('/\\s*\\/\\s*/', '/', $normalized);
    $normalized = preg_replace('/\\b(CM|CX|CV|CI)\\s*(?=\\d)/', '$1-', $normalized);
    $normalized = preg_replace('/\\b(CX)\\s+(WEC|WC)(?=\\d)/', '$1-$2', $normalized);
    $normalized = preg_replace('/\\b(CX)\\s+(WEC|WC)\\b/', '$1-$2', $normalized);
    $normalized = preg_replace('/\\b(WEC|WC)\\s+(\\d)/', '$1$2', $normalized);
    $normalized = preg_replace('/\\s+/', ' ', $normalized);
    return trim($normalized);
}

function ado_camden_split_option_group(string $group): array
{
    $tokens = [];
    if ($group === '') {
        return $tokens;
    }
    $group = strtoupper(preg_replace('/[^A-Z0-9]/', '', $group));
    if ($group === '') {
        return $tokens;
    }
    if (strlen($group) >= 4 && strlen($group) % 2 === 0) {
        for ($i = 0; $i < strlen($group); $i += 2) {
            $chunk = substr($group, $i, 2);
            if ($chunk !== '') {
                $tokens[] = $chunk;
            }
        }
        return $tokens;
    }
    if (preg_match_all('/[A-Z]{2,4}/', $group, $matches)) {
        foreach ((array) ($matches[0] ?? []) as $token) {
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
    }
    return $tokens;
}

function ado_camden_parse_suffix_tokens(string $suffix, array $pack): array
{
    $result = [
        'finish' => null,
        'options' => [],
        'used_tokens' => [],
    ];
    if ($suffix === '') {
        return $result;
    }
    $tokens = preg_split('/[\\s\\/-]+/', trim($suffix));
    foreach ($tokens as $token) {
        $upper = strtoupper(trim($token));
        if ($upper === '') {
            continue;
        }
        if (in_array($upper, $pack['finish_tokens'], true)) {
            $result['finish'] = $upper;
            $result['used_tokens'][] = $upper;
            continue;
        }
        $result['options'][] = $upper;
        $result['used_tokens'][] = $upper;
    }
    return $result;
}

function ado_camden_parse_segment(string $segment, array $context = []): array
{
    $pack = ado_camden_get_pack();
    $output = [
        'brand' => 'camden',
        'prefix' => null,
        'family_key' => null,
        'base_model' => null,
        'variant_code' => null,
        'kit_code' => null,
        'device_type' => null,
        'finish' => null,
        'options' => [],
        'power_supply' => false,
        'accessory' => false,
        'parse_status' => 'no_match',
        'raw_tokens_used' => [],
        'raw_tokens_ignored' => [],
        'confidence' => 'low',
        'trace' => [],
    ];

    $normalized = ado_camden_normalize_segment($segment, $pack);
    if ($normalized === '') {
        $output['trace'][] = 'normalize=empty';
        return $output;
    }

    $reject_notes = [];
    foreach ($pack['families'] as $family) {
        $pattern = (string) ($family['pattern'] ?? '');
        if ($pattern === '' || !preg_match($pattern, $normalized, $matches)) {
            continue;
        }
        $base_model = $matches['base'] ?? null;
        if ($base_model === null) {
            continue;
        }
        $variant = $matches['variant'] ?? '';
        $kit_code = $matches['kit'] ?? null;
        $option_group = $matches['option_group'] ?? '';
        $suffix = trim((string) ($matches['suffix'] ?? ''));

        $legend = (string) ($family['family_key'] ?? 'unknown');
        $variant_pattern = (string) ($family['variant_pattern'] ?? '');
        $variant_allowlist = (array) ($family['variant_allowlist'] ?? []);
        if ($variant_pattern !== '' && $variant !== '' && !preg_match($variant_pattern, $variant)) {
            $reject_notes[] = 'variant_reject=' . $variant . '@' . $legend;
            continue;
        }
        if (!empty($variant_allowlist) && $variant !== '' && !in_array($variant, $variant_allowlist, true)) {
            $reject_notes[] = 'variant_reject=' . $variant . '@' . $legend;
            continue;
        }

        $suffix_tokens = ado_camden_parse_suffix_tokens($suffix, $pack);
        $options = $suffix_tokens['options'];
        $finish = $suffix_tokens['finish'];

        if ($option_group !== '') {
            $options = array_merge($options, ado_camden_split_option_group($option_group));
        }

        $options = array_values(array_unique(array_filter($options, 'strlen')));
        $finish = $finish !== null ? $finish : null;

        $device_type = $family['device_type_map'][$base_model] ?? ($family['device_type'] ?? null);
        $power_supply = !empty($family['power_supply']);
        if (!$power_supply && in_array('PS', $options, true)) {
            $power_supply = true;
        }

        $output = array_merge($output, [
            'prefix' => substr($base_model, 0, strpos($base_model, '-') !== false ? strpos($base_model, '-') : 2),
            'family_key' => $legend,
            'base_model' => $base_model,
            'variant_code' => $variant ?: null,
            'kit_code' => $kit_code ?: null,
            'device_type' => $device_type,
            'finish' => $finish,
            'options' => $options,
            'power_supply' => $power_supply,
            'accessory' => !empty($family['accessory']),
            'parse_status' => 'parsed',
            'raw_tokens_used' => array_values(array_filter(array_merge([$base_model], $variant ? [$variant] : [], $kit_code ? [$kit_code] : [], $options), 'strlen')),
            'raw_tokens_ignored' => [],
            'confidence' => 'high',
            'trace' => [
                'family=' . $legend,
                'base=' . $base_model,
                $variant !== '' ? ('variant=' . $variant) : '',
                $kit_code ? ('kit=' . $kit_code) : '',
                $finish ? ('finish=' . $finish) : '',
                'options=' . implode(',', $options),
            ],
        ]);
        $output['trace'] = array_values(array_filter($output['trace'], 'strlen'));
        return $output;
    }

    if (!empty($reject_notes)) {
        $output['trace'] = array_merge($output['trace'], $reject_notes);
    }
    $output['trace'][] = 'no_match=' . $normalized;
    return $output;
}

function ado_camden_extract_model_candidates(string $segment): array
{
    $parts = preg_split('/\\s+(?=(?:CM|CX|CV|CI)(?:[\\s-]|$))/i', trim($segment));
    $seen = [];
    $candidates = [];
    foreach ((array) $parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $parsed = ado_camden_parse_segment($part);
        if ($parsed['parse_status'] !== 'parsed') {
            continue;
        }
        $key = sprintf('%s|%s|%s', $parsed['family_key'], $parsed['base_model'], $parsed['variant_code'] ?? '');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $candidates[] = $parsed;
    }
    return $candidates;
}
