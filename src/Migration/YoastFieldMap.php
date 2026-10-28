<?php
namespace Yablonsky\PerformanceSEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastFieldMap {
    /** @return array<string,string> */
    public static function postFields(): array {
        return array(
            '_yoast_wpseo_focuskw'                       => '_yps_focus_keyphrase',
            '_yoast_wpseo_focuskeywords'                 => '_yps_focus_keyphrases_json',
            '_yoast_wpseo_keywordsynonyms'               => '_yps_keyphrase_synonyms_json',
            '_yoast_wpseo_title'                         => '_yps_seo_title',
            '_yoast_wpseo_metadesc'                      => '_yps_meta_description',
            '_yoast_wpseo_meta-robots-noindex'           => '_yps_robots_noindex',
            '_yoast_wpseo_meta-robots-nofollow'          => '_yps_robots_nofollow',
            '_yoast_wpseo_meta-robots-adv'               => '_yps_robots_advanced',
            '_yoast_wpseo_canonical'                     => '_yps_canonical',
            '_yoast_wpseo_redirect'                      => '_yps_redirect_target',
            '_yoast_wpseo_bctitle'                       => '_yps_breadcrumb_title',
            '_yoast_wpseo_is_cornerstone'                => '_yps_is_cornerstone',
            '_yoast_wpseo_opengraph-title'               => '_yps_og_title',
            '_yoast_wpseo_opengraph-description'         => '_yps_og_description',
            '_yoast_wpseo_opengraph-image'               => '_yps_og_image',
            '_yoast_wpseo_opengraph-image-id'            => '_yps_og_image_id',
            '_yoast_wpseo_twitter-title'                 => '_yps_twitter_title',
            '_yoast_wpseo_twitter-description'           => '_yps_twitter_description',
            '_yoast_wpseo_twitter-image'                 => '_yps_twitter_image',
            '_yoast_wpseo_twitter-image-id'              => '_yps_twitter_image_id',
            '_yoast_wpseo_schema_page_type'              => '_yps_schema_page_type',
            '_yoast_wpseo_schema_article_type'           => '_yps_schema_article_type',
        );
    }

    /** @return array<int,string> */
    public static function ignoredDerivedPostFields(): array {
        return array(
            '_yoast_wpseo_linkdex',
            '_yoast_wpseo_content_score',
            '_yoast_wpseo_inclusive_language_score',
            '_yoast_wpseo_estimated-reading-time-minutes',
            '_yoast_wpseo_wordproof_timestamp',
        );
    }

    public static function targetForPostKey( string $sourceKey ): string {
        $map = self::postFields();
        if ( isset( $map[ $sourceKey ] ) ) {
            return $map[ $sourceKey ];
        }
        $prefix = '_yoast_wpseo_primary_';
        if ( 0 === strpos( $sourceKey, $prefix ) ) {
            $taxonomy = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', substr( $sourceKey, strlen( $prefix ) ) ) );
            return '' === $taxonomy ? '' : '_yps_primary_' . $taxonomy;
        }
        return '';
    }

    public static function isIgnoredDerivedPostKey( string $sourceKey ): bool {
        return in_array( $sourceKey, self::ignoredDerivedPostFields(), true );
    }

    /** @return array<string,string> */
    public static function taxonomyFields(): array {
        return array(
            'wpseo_title'                   => '_yps_seo_title',
            'wpseo_desc'                    => '_yps_meta_description',
            'wpseo_canonical'               => '_yps_canonical',
            'wpseo_bctitle'                 => '_yps_breadcrumb_title',
            'wpseo_noindex'                 => '_yps_robots_noindex',
            'wpseo_focuskw'                 => '_yps_focus_keyphrase',
            'wpseo_focuskeywords'           => '_yps_focus_keyphrases_json',
            'wpseo_keywordsynonyms'         => '_yps_keyphrase_synonyms_json',
            'wpseo_is_cornerstone'          => '_yps_is_cornerstone',
            'wpseo_opengraph-title'         => '_yps_og_title',
            'wpseo_opengraph-description'   => '_yps_og_description',
            'wpseo_opengraph-image'         => '_yps_og_image',
            'wpseo_opengraph-image-id'      => '_yps_og_image_id',
            'wpseo_twitter-title'           => '_yps_twitter_title',
            'wpseo_twitter-description'     => '_yps_twitter_description',
            'wpseo_twitter-image'           => '_yps_twitter_image',
        );
    }

    /** @return array<int,string> */
    public static function ignoredDerivedTaxonomyFields(): array {
        return array(
            'wpseo_linkdex',
            'wpseo_content_score',
            'wpseo_inclusive_language_score',
        );
    }
}
