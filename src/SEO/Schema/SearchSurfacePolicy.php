<?php
namespace Yablonsky\PerformanceSEO\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Machine-readable distinction between valid Schema.org semantics and consumer-surface support.
 *
 * YSEO must never infer a rich-result or discovery promise merely because a Schema.org type is valid.
 * The surface map is intentionally consumer-neutral so future Google, Bing, Apple, AI or specialist
 * surfaces can be added without changing the contract shape.
 */
final class SearchSurfacePolicy {
    public const CONTRACT = 'yablonsky.seo-schema-surface-policy/v1';
    public const REVISION = '2026-09-14';

    /** @return array<string,array<string,mixed>> */
    public static function exceptions(): array {
        $items = array(
            'claimreview' => array(
                'schema_type'     => 'ClaimReview',
                'schema_org'      => 'valid',
                'surfaces'        => array(
                    'google_search'              => 'phasing_out',
                    'google_fact_check_explorer' => 'supported',
                ),
                'generation_mode' => 'specialized_only',
                'auto_recommend'  => false,
                'documentation'   => 'https://developers.google.com/search/docs/appearance/structured-data/factcheck',
            ),
            'faqpage' => array(
                'schema_type'     => 'FAQPage',
                'schema_org'      => 'valid',
                'surfaces'        => array(
                    'google_search' => 'limited',
                ),
                'generation_mode' => 'visible_content_only',
                'auto_recommend'  => false,
                'documentation'   => 'https://developers.google.com/search/docs/appearance/structured-data/faqpage',
            ),
            'howto' => array(
                'schema_type'     => 'HowTo',
                'schema_org'      => 'valid',
                'surfaces'        => array(
                    'google_search' => 'deprecated',
                ),
                'generation_mode' => 'visible_content_only',
                'auto_recommend'  => false,
                'documentation'   => 'https://developers.google.com/search/blog/2023/08/howto-faq-changes',
            ),
        );

        $filtered = apply_filters( 'yablonsky_seo_schema_surface_policy_v1', $items );
        return is_array( $filtered ) ? $filtered : $items;
    }

    /** @return array<string,mixed> */
    public static function document(): array {
        return array(
            'contract'   => self::CONTRACT,
            'revision'   => self::REVISION,
            'principle'  => 'schema_org_validity_is_separate_from_consumer_surface_support',
            'exceptions' => self::exceptions(),
        );
    }
}
