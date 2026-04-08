<?php
if (defined('ADO_CAMDEN_MATCHER_LOADED')) {
    return;
}
define('ADO_CAMDEN_MATCHER_LOADED', true);

final class ADO_Camden_Matcher implements ADO_Brand_Matcher_Interface
{
    /** {@inheritdoc} */
    public function match_segment(array $item, string $segment, array $index): array
    {
        $parse = ado_camden_parse_segment($segment);
        $match = ado_qm_match_segment($item, $segment, $index);
        $match['camden_parse'] = $parse;
        if (function_exists('ado_camden_resolve_parsed_model')) {
            $match['camden_resolution'] = ado_camden_resolve_parsed_model($parse, $index);
        }
        return $match;
    }
}
