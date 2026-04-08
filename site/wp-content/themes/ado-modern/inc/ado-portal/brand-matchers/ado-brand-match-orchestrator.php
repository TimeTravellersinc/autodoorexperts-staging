<?php
if (defined('ADO_BRAND_MATCH_ORCHESTRATOR_LOADED')) {
    return;
}
define('ADO_BRAND_MATCH_ORCHESTRATOR_LOADED', true);

final class ADO_Brand_Match_Orchestrator
{
    /**
     * Coordinates detection, matcher selection, and tracing for a segment.
     *
     * @return array<string, mixed>
     */
    public static function match_segment(array $item, string $segment, array $index): array
    {
        $context = [
            'item' => $item,
            'segment' => $segment,
            'index' => $index,
        ];
        $detection = ado_brand_detect_segment($context);
        $matcher = null;
        if (!empty($detection['primary_brand'])) {
            $matcher = ado_brand_matcher_instance_for_brand((string) $detection['primary_brand']);
        }
        if ($matcher === null && !empty($detection['candidate_brands'])) {
            $matcher = ado_brand_matcher_instance_for_brands($detection['candidate_brands']);
        }
        if ($matcher === null) {
            $matcher = new ADO_Generic_Brand_Matcher();
        }

        $match = $matcher->match_segment($item, $segment, $index);
        $trace = array_values((array) ($match['trace'] ?? []));
        $trace[] = 'orchestrator_detector=' . json_encode($detection['trace']);
        $trace[] = 'orchestrator_matcher=' . get_class($matcher);
        $match['trace'] = $trace;

        return [
            'match' => $match,
            'brand_detector' => $detection,
            'matcher_class' => get_class($matcher),
        ];
    }
}
