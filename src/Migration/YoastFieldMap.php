<?php
namespace Yablonsky\PerformanceSEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastFieldMap {
    /** @return array<string,string> */
    public static function postFields(): array {
        return array(
            '_yoast_wpseo_focuskw'                       => '_yablseo_focus_keyphrase',
            '_yoast_wpseo_focuskeywords'                 => '_yablseo_focus_keyphrases_json',
            '_yoast_wpseo_keywordsynonyms'               => '_yablseo_keyphrase_synonyms_json',
            '_yoast_wpseo_title'                         => '_yablseo_seo_title',
            '_yoast_wpseo_metadesc'                      => '_yablseo_meta_description',
            '_yoast_wpseo_meta-robots-noindex'           => '_yablseo_robots_noindex',
            '_yoast_wpseo_meta-robots-nofollow'          => '_yablseo_robots_nofollow',
            '_yoast_wpseo_meta-robots-adv'               => '_yablseo_robots_advanced',
            '_yoast_wpseo_canonical'                     => '_yablseo_canonical',
            '_yoast_wpseo_redirect'                      => '_yablseo_redirect_target',
            '_yoast_wpseo_bctitle'                       => '_yablseo_breadcrumb_title',
            '_yoast_wpseo_is_cornerstone'                => '_yablseo_is_cornerstone',
            '_yoast_wpseo_opengraph-title'               => '_yablseo_og_title',
            '_yoast_wpseo_opengraph-description'         => '_yablseo_og_description',
            '_yoast_wpseo_opengraph-image'               => '_yablseo_og_image',
            '_yoast_wpseo_opengraph-image-id'            => '_yablseo_og_image_id',
            '_yoast_wpseo_twitter-title'                 => '_yablseo_twitter_title',
            '_yoast_wpseo_twitter-description'           => '_yablseo_twitter_description',
            '_yoast_wpseo_twitter-image'                 => '_yablseo_twitter_image',
            '_yoast_wpseo_twitter-image-id'              => '_yablseo_twitter_image_id',
            '_yoast_wpseo_schema_page_type'              => '_yablseo_schema_page_type',
            '_yoast_wpseo_schema_article_type'           => '_yablseo_schema_article_type',
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
            return '' === $taxonomy ? '' : '_yablseo_primary_' . $taxonomy;
        }
        return '';
    }

    public static function isIgnoredDerivedPostKey( string $sourceKey ): bool {
        return in_array( $sourceKey, self::ignoredDerivedPostFields(), true );
    }

    /** @return array<string,string> */
    public static function taxonomyFields(): array {
        return array(
            'wpseo_title'                   => '_yablseo_seo_title',
            'wpseo_desc'                    => '_yablseo_meta_description',
            'wpseo_canonical'               => '_yablseo_canonical',
            'wpseo_bctitle'                 => '_yablseo_breadcrumb_title',
            'wpseo_noindex'                 => '_yablseo_robots_noindex',
            'wpseo_focuskw'                 => '_yablseo_focus_keyphrase',
            'wpseo_focuskeywords'           => '_yablseo_focus_keyphrases_json',
            'wpseo_keywordsynonyms'         => '_yablseo_keyphrase_synonyms_json',
            'wpseo_is_cornerstone'          => '_yablseo_is_cornerstone',
            'wpseo_opengraph-title'         => '_yablseo_og_title',
            'wpseo_opengraph-description'   => '_yablseo_og_description',
            'wpseo_opengraph-image'         => '_yablseo_og_image',
            'wpseo_opengraph-image-id'      => '_yablseo_og_image_id',
            'wpseo_twitter-title'           => '_yablseo_twitter_title',
            'wpseo_twitter-description'     => '_yablseo_twitter_description',
            'wpseo_twitter-image'           => '_yablseo_twitter_image',
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
