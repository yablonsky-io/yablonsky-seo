<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PortableSourceRegistry {
    /** @var array<string,PortableSourceProfile> */ private $profiles = array();

    public function __construct() {
        $this->register( new PortableSourceProfile(
            'rank-math',
            'Rank Math',
            'meta',
            array(
                'rank_math_title'                => '_yps_seo_title',
                'rank_math_description'          => '_yps_meta_description',
                'rank_math_canonical_url'        => '_yps_canonical',
                'rank_math_focus_keyword'        => '_yps_focus_keyphrase',
                'rank_math_facebook_title'       => '_yps_og_title',
                'rank_math_facebook_description' => '_yps_og_description',
                'rank_math_twitter_title'        => '_yps_twitter_title',
                'rank_math_twitter_description'  => '_yps_twitter_description',
                'rank_math_pillar_content'       => '_yps_is_cornerstone',
            ),
            array(
                'rank_math_title'                => '_yps_seo_title',
                'rank_math_description'          => '_yps_meta_description',
                'rank_math_canonical_url'        => '_yps_canonical',
                'rank_math_focus_keyword'        => '_yps_focus_keyphrase',
                'rank_math_facebook_title'       => '_yps_og_title',
                'rank_math_facebook_description' => '_yps_og_description',
                'rank_math_twitter_title'        => '_yps_twitter_title',
                'rank_math_twitter_description'  => '_yps_twitter_description',
            ),
            array( 'official-source:rankmath/seo-by-rank-math', 'storage:rank_math_* post/term meta' )
        ) );

        $this->register( new PortableSourceProfile(
            'seopress',
            'SEOPress',
            'meta',
            array(
                '_seopress_titles_title'             => '_yps_seo_title',
                '_seopress_titles_desc'              => '_yps_meta_description',
                '_seopress_robots_canonical'         => '_yps_canonical',
                '_seopress_analysis_target_kw'       => '_yps_focus_keyphrase',
                '_seopress_social_fb_title'          => '_yps_og_title',
                '_seopress_social_fb_desc'           => '_yps_og_description',
                '_seopress_social_fb_img'            => '_yps_og_image',
                '_seopress_social_twitter_title'     => '_yps_twitter_title',
                '_seopress_social_twitter_desc'      => '_yps_twitter_description',
                '_seopress_social_twitter_img'       => '_yps_twitter_image',
            ),
            array(
                '_seopress_titles_title'             => '_yps_seo_title',
                '_seopress_titles_desc'              => '_yps_meta_description',
                '_seopress_robots_canonical'         => '_yps_canonical',
                '_seopress_social_fb_title'          => '_yps_og_title',
                '_seopress_social_fb_desc'           => '_yps_og_description',
                '_seopress_social_fb_img'            => '_yps_og_image',
                '_seopress_social_twitter_title'     => '_yps_twitter_title',
                '_seopress_social_twitter_desc'      => '_yps_twitter_description',
                '_seopress_social_twitter_img'       => '_yps_twitter_image',
            ),
            array( 'official-source:wp-seopress/wp-seopress-public', 'storage:_seopress_* post/term meta' )
        ) );

        $this->register( new PortableSourceProfile(
            'tsf',
            'The SEO Framework',
            'meta',
            array(
                '_genesis_title'            => '_yps_seo_title',
                '_genesis_description'      => '_yps_meta_description',
                '_genesis_canonical_uri'    => '_yps_canonical',
                '_social_image_url'         => '_yps_og_image',
                '_open_graph_title'         => '_yps_og_title',
                '_open_graph_description'   => '_yps_og_description',
                '_twitter_title'            => '_yps_twitter_title',
                '_twitter_description'      => '_yps_twitter_description',
            ),
            array(),
            array( 'official-source:sybrew/the-seo-framework', 'storage:verified post meta; term storage intentionally not assumed' )
        ) );


        $this->register( new PortableSourceProfile(
            'surerank',
            'SureRank',
            'meta',
            array(
                'surerank_settings_page_title'           => '_yps_seo_title',
                'surerank_settings_page_description'     => '_yps_meta_description',
                'surerank_settings_canonical_url'        => '_yps_canonical',
                'surerank_settings_focus_keyword'        => '_yps_focus_keyphrase',
                'surerank_settings_facebook_title'       => '_yps_og_title',
                'surerank_settings_facebook_description' => '_yps_og_description',
                'surerank_settings_facebook_image_url'   => '_yps_og_image',
                'surerank_settings_twitter_title'        => '_yps_twitter_title',
                'surerank_settings_twitter_description'  => '_yps_twitter_description',
                'surerank_settings_twitter_image_url'    => '_yps_twitter_image',
            ),
            array(
                'surerank_settings_page_title'           => '_yps_seo_title',
                'surerank_settings_page_description'     => '_yps_meta_description',
                'surerank_settings_canonical_url'        => '_yps_canonical',
                'surerank_settings_focus_keyword'        => '_yps_focus_keyphrase',
                'surerank_settings_facebook_title'       => '_yps_og_title',
                'surerank_settings_facebook_description' => '_yps_og_description',
                'surerank_settings_facebook_image_url'   => '_yps_og_image',
                'surerank_settings_twitter_title'        => '_yps_twitter_title',
                'surerank_settings_twitter_description'  => '_yps_twitter_description',
                'surerank_settings_twitter_image_url'    => '_yps_twitter_image',
            ),
            array(
                'official-source:wporg/surerank-1.10.0',
                'storage:surerank_settings_* post/term meta',
                'verified:inc/api/post.php + inc/api/term.php + inc/functions/defaults.php',
            )
        ) );

        $this->register( new PortableSourceProfile(
            'slim-seo',
            'Slim SEO',
            'slim-array',
            array(),
            array(),
            array( 'official-source:wpmetabox/slim-seo', 'storage:serialized slim_seo post/term meta manual overrides' )
        ) );

        $this->register( new PortableSourceProfile(
            'aioseo',
            'All in One SEO',
            'aioseo-table',
            array(),
            array(),
            array( 'storage:*_aioseo_posts runtime-schema-inspected', 'source remains authoritative; read-only projection only' )
        ) );
    }

    private function register( PortableSourceProfile $profile ): void { $this->profiles[ $profile->id() ] = $profile; }
    public function get( string $id ): ?PortableSourceProfile { return $this->profiles[ sanitize_key( $id ) ] ?? null; }
    /** @return array<string,PortableSourceProfile> */ public function all(): array { return $this->profiles; }
}
