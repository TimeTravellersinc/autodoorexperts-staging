<?php
if (defined('ADO_BRAND_MATCHER_CONTRACT_LOADED')) {
    return;
}
define('ADO_BRAND_MATCHER_CONTRACT_LOADED', true);

/**
 * Basic contract that brand-specific matchers must satisfy.
 */
interface ADO_Brand_Matcher_Interface
{
    /**
     * Match a single scoped segment to a Woo product.
     *
     * @param array $item Scoped door item payload.
     * @param string $segment Individual raw segment text.
     * @param array<mixed> $index Matcher index lookup data.
     * @return array Matching result that mirrors ado_qm_match_segment().
     */
    public function match_segment(array $item, string $segment, array $index): array;
}
