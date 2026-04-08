<?php
if (defined('ADO_GENERIC_BRAND_MATCHER_LOADED')) {
    return;
}
define('ADO_GENERIC_BRAND_MATCHER_LOADED', true);

final class ADO_Generic_Brand_Matcher implements ADO_Brand_Matcher_Interface
{
    /** {@inheritdoc} */
    public function match_segment(array $item, string $segment, array $index): array
    {
        return ado_qm_match_segment($item, $segment, $index);
    }
}
