<?php
if (defined('ADO_BRAND_DETECTOR_LOADED')) {
    return;
}
define('ADO_BRAND_DETECTOR_LOADED', true);

function ado_brand_canonical_key(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return 'unknown';
    }
    $clean = preg_replace('/[^A-Z0-9]+/', '', strtoupper($label));
    if ($clean === '') {
        return 'unknown';
    }
    $patterns = [
        'CAMDEN' => 'camden',
        'VONDUPRIN' => 'von_duprin',
        'VON' => 'von_duprin',
        'DUPRIN' => 'von_duprin',
        'CAM' => 'camden',
        'CAMDEN' => 'camden',
        'CAMDENDC' => 'camden',
        'LCN' => 'lcn',
        'HES' => 'hes',
        'UNKNOWN' => 'unknown',
    ];
    foreach ($patterns as $needle => $canonical) {
        if ($needle === '') {
            continue;
        }
        if (strpos($clean, $needle) !== false) {
            return $canonical;
        }
    }
    return strtolower($clean);
}

function ado_brand_group_by_canonical(array $brand_index): array
{
    $groups = [];
    foreach ($brand_index as $label => $data) {
        $canonical = ado_brand_canonical_key((string) $label);
        if (!isset($groups[$canonical])) {
            $groups[$canonical] = [
                'labels' => [],
                'brand_data' => [],
                'score' => 0,
                'signals' => [],
            ];
        }
        $groups[$canonical]['labels'][] = (string) $label;
        $groups[$canonical]['brand_data'][] = (array) $data;
    }
    if (!isset($groups['unknown'])) {
        $groups['unknown'] = [
            'labels' => [],
            'brand_data' => [],
            'score' => 0,
            'signals' => [],
        ];
    }
    return $groups;
}

function ado_brand_collect_fragments(string $text): array
{
    $results = [];
    foreach (ado_qm_extract_fragments_from_text($text) as $fragment) {
        $anchor = ado_qm_alpha_prefix($fragment);
        $signature = ado_qm_model_signature($fragment);
        $results[] = [
            'text' => $fragment,
            'anchor' => $anchor,
            'signature' => $signature,
        ];
    }
    return $results;
}

/**
 * Detects a likely brand for a scoped segment.
 *
 * @param array<mixed> $context Must include 'item', 'segment', and optionally 'index'.
 * @return array<string, mixed>
 */
function ado_brand_detect_segment(array $context): array
{
    $item = (array) ($context['item'] ?? []);
    $segment = trim((string) ($context['segment'] ?? ''));
    $catalog = trim((string) ($item['catalog'] ?? ''));
    $description = trim((string) ($item['desc'] ?? ''));
    $index = (array) ($context['index'] ?? []);
    $brand_index = (array) ($index['brands'] ?? []);
    $anchor_map = (array) ($index['anchors'] ?? []);
    $text_sources = [
        'segment' => $segment,
        'catalog' => $catalog,
        'description' => $description,
    ];

    $groups = ado_brand_group_by_canonical($brand_index);
    $fragments = [];
    foreach (['segment', 'catalog'] as $source_key) {
        $source_text = $text_sources[$source_key] ?? '';
        if ($source_text === '') {
            continue;
        }
        foreach (ado_brand_collect_fragments($source_text) as $fragment) {
            $fragment['source'] = $source_key;
            $fragments[] = $fragment;
        }
    }

    foreach ($groups as &$group) {
        $group['score'] = 0;
        $group['signals'] = [];
    }
    unset($group);

    foreach ($text_sources as $source_key => $text) {
        if ($text === '') {
            continue;
        }
        foreach ($groups as $canonical => &$group) {
            $search_terms = array_unique(array_filter(array_merge(
                $group['labels'],
                [$canonical, str_replace('_', ' ', $canonical)]
            )));
            foreach ($search_terms as $term) {
                if ($term === '') {
                    continue;
                }
                if (stripos($text, $term) === false) {
                    continue;
                }
                $group['score'] += 12;
                $group['signals'][] = sprintf('%s=text_%s', $source_key, strtolower(str_replace(' ', '_', $term)));
                break;
            }
        }
        unset($group);
    }

    $seed_signals = [
        'camden' => [
            ['pattern' => '/\\bCM[-\\/]/i', 'label' => 'seed_cm', 'weight' => 10],
            ['pattern' => '/\\bCAMDEN\\b/i', 'label' => 'seed_camden', 'weight' => 12],
        ],
        'lcn' => [
            ['pattern' => '/\\bLCN\\b/i', 'label' => 'seed_lcn', 'weight' => 11],
            ['pattern' => '/\\b9500IQ\\b/i', 'label' => 'seed_9500iq', 'weight' => 14],
        ],
        'hes' => [
            ['pattern' => '/\\bHES\\b/i', 'label' => 'seed_hes', 'weight' => 11],
            ['pattern' => '/\\b1006\\b/', 'label' => 'seed_1006', 'weight' => 12],
        ],
        'von_duprin' => [
            ['pattern' => '/\\bVON\\b.*\\bDUPRIN\\b/i', 'label' => 'seed_von_duprin', 'weight' => 11],
            ['pattern' => '/\\b6211\\b/', 'label' => 'seed_6211', 'weight' => 7],
        ],
    ];
    $blob = implode(' ', array_filter($text_sources, 'strlen'));
    foreach ($seed_signals as $canonical => $patterns) {
        if (!isset($groups[$canonical])) {
            $groups[$canonical] = [
                'labels' => [],
                'brand_data' => [],
                'score' => 0,
                'signals' => [],
            ];
        }
        foreach ($patterns as $entry) {
            $pattern = $entry['pattern'] ?? '';
            $label = $entry['label'] ?? 'seed';
            $weight = isset($entry['weight']) ? (int) $entry['weight'] : 8;
            if ($pattern === '' || $blob === '' || !preg_match($pattern, $blob)) {
                continue;
            }
            $groups[$canonical]['score'] += max(1, $weight);
            $groups[$canonical]['signals'][] = $label;
        }
    }

    foreach ($fragments as $fragment) {
        $anchor = $fragment['anchor'] ?? '';
        if ($anchor !== '' && isset($anchor_map[$anchor])) {
            $anchor_brand = ado_brand_canonical_key((string) $anchor_map[$anchor]);
            $groups[$anchor_brand]['score'] += 20;
            $groups[$anchor_brand]['signals'][] = 'anchor=' . $anchor;
        }
        $normalized = ado_qm_compact($fragment['text'] ?? '');
        if ($normalized === '') {
            continue;
        }
        foreach ($groups as $canonical => &$group) {
            foreach ($group['brand_data'] as $brand_data) {
                foreach ((array) ($brand_data['families'] ?? []) as $family) {
                    $family_models = (array) ($family['models'] ?? []);
                    if ($family_models === [] || !isset($family_models[$normalized])) {
                        continue;
                    }
                    $group['score'] += 9;
                    $group['signals'][] = 'family=' . $canonical;
                    continue 3;
                }
            }
        }
        unset($group);
    }

    $ranked = [];
    foreach ($groups as $canonical => $group) {
        $ranked[] = [
            'canonical' => $canonical,
            'score' => max(0, (int) ($group['score'] ?? 0)),
            'signals' => array_values(array_unique($group['signals'])),
        ];
    }
    usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['canonical'], $b['canonical']));
    if (!$ranked) {
        $ranked = [
            [
                'canonical' => 'unknown',
                'score' => 0,
                'signals' => ['fallback'],
            ],
        ];
    }
    $top_score = (int) ($ranked[0]['score'] ?? 0);
    if ($top_score === 0) {
        foreach ($ranked as $idx => $entry) {
            if ($entry['canonical'] === 'unknown') {
                array_splice($ranked, $idx, 1);
                array_unshift($ranked, $entry);
                break;
            }
        }
    }

    $candidate_brands = array_column($ranked, 'canonical');
    $primary_brand = $candidate_brands[0] ?? 'unknown';
    if ($primary_brand === '') {
        $primary_brand = 'unknown';
    }
    $confidence = 'low';
    if ($top_score >= 25) {
        $confidence = 'high';
    } elseif ($top_score > 0) {
        $confidence = 'medium';
    }
    if ($primary_brand === 'unknown') {
        $confidence = 'low';
    }

    $trace = [];
    if ($segment !== '') {
        $trace[] = 'segment=' . $segment;
    }
    if ($catalog !== '') {
        $trace[] = 'catalog=' . $catalog;
    }
    if ($description !== '') {
        $trace[] = 'description=' . $description;
    }
    $trace_parts = [];
    foreach ($ranked as $entry) {
        $trace_parts[] = sprintf('%s:%d[%s]', $entry['canonical'], $entry['score'], implode(',', $entry['signals']));
    }
    $trace[] = 'candidates=' . implode(',', $trace_parts);

    return [
        'primary_brand' => $primary_brand,
        'candidate_brands' => $candidate_brands,
        'confidence' => $confidence,
        'trace' => $trace,
        'scored_brands' => $ranked,
    ];
}
