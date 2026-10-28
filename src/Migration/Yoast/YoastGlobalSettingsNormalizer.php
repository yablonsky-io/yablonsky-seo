<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

use Yablonsky\PerformanceSEO\Migration\Contract\FieldClassification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastGlobalSettingsNormalizer {
    /**
     * @param array<string,mixed> $options
     * @return array{values:array<string,mixed>,records:array<int,array<string,mixed>>,counts:array<string,int>,unsupported:array<int,string>}
     */
    public function normalize( array $options ): array {
        $values = array();
        $records = array();
        $counts = FieldClassification::zeroCounts();
        $unsupported = array();

        foreach ( array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt' ) as $optionName ) {
            $option = $options[ $optionName ] ?? array();
            if ( ! is_array( $option ) ) {
                if ( null !== $option && false !== $option && '' !== $option ) {
                    ++$counts[ FieldClassification::INVALID_SOURCE ];
                    $records[] = $this->record( $optionName, '', '', $option, FieldClassification::INVALID_SOURCE, 'invalid-option-shape' );
                }
                continue;
            }
            foreach ( $option as $key => $value ) {
                $key = (string) $key;
                $mapped = $this->mapKey( $optionName, $key );
                $classification = $mapped['classification'];
                $transform = $mapped['transform'];

                // An unknown source key with no effective value carries no semantic
                // state that can be lost. Keep it in the audit trail, but classify it
                // as default-equivalent rather than blocking an otherwise lossless
                // migration. Explicit false/0 values remain meaningful and therefore
                // continue to block when their semantics are unknown.
                if ( FieldClassification::UNSUPPORTED === $classification && ! $this->hasMeaningfulValue( $value ) ) {
                    $classification = FieldClassification::DEFAULT_EQUIVALENT;
                    $transform = 'empty-source-default-equivalent';
                }

                ++$counts[ $classification ];
                if ( FieldClassification::MAPPED === $classification || FieldClassification::DEFAULT_EQUIVALENT === $classification || FieldClassification::REBUILT === $classification ) {
                    $canonical = $mapped['canonical'];
                    if ( '' !== $canonical ) {
                        $values[ $canonical ] = $value;
                    }
                } elseif ( FieldClassification::UNSUPPORTED === $classification && $this->hasMeaningfulValue( $value ) ) {
                    if ( count( $unsupported ) < 100 ) {
                        $unsupported[] = $optionName . ':' . $key;
                    }
                }
                $records[] = $this->record( $optionName, $key, $mapped['canonical'], $value, $classification, $transform );
            }
        }
        ksort( $values, SORT_STRING );
        return array(
            'values'      => $values,
            'records'     => $records,
            'counts'      => $counts,
            'unsupported' => array_values( array_unique( $unsupported ) ),
        );
    }

    /** @return array{canonical:string,classification:string,transform:string} */
    private function mapKey( string $optionName, string $key ): array {
        if ( 'wpseo_titles' === $optionName ) {
            return $this->mapTitlesKey( $key );
        }
        if ( 'wpseo_social' === $optionName ) {
            return $this->mapSocialKey( $key );
        }
        if ( 'wpseo_llmstxt' === $optionName ) {
            return array( 'canonical' => 'ai_discovery.llms_txt.' . $this->slug( $key ), 'classification' => FieldClassification::MAPPED, 'transform' => 'namespace-preserve' );
        }
        if ( 'wpseo' === $optionName ) {
            return $this->mapCoreKey( $key );
        }
        return array( 'canonical' => '', 'classification' => FieldClassification::UNSUPPORTED, 'transform' => 'unclassified-option' );
    }

    /** @return array{canonical:string,classification:string,transform:string} */
    private function mapTitlesKey( string $key ): array {
        $direct = array(
            'separator'                      => 'templates.separator',
            'title-home-wpseo'               => 'templates.home.title',
            'metadesc-home-wpseo'            => 'templates.home.description',
            'title-author-wpseo'             => 'templates.author.title',
            'metadesc-author-wpseo'          => 'templates.author.description',
            'title-archive-wpseo'            => 'templates.date_archive.title',
            'metadesc-archive-wpseo'         => 'templates.date_archive.description',
            'title-search-wpseo'             => 'templates.search.title',
            'title-404-wpseo'                => 'templates.404.title',
            'noindex-author-wpseo'           => 'robots.author.noindex',
            'noindex-author-noposts-wpseo'   => 'robots.author_empty.noindex',
            'noindex-archive-wpseo'          => 'robots.date_archive.noindex',
            'disable-author'                 => 'archives.author.disabled',
            'disable-date'                   => 'archives.date.disabled',
            'disable-post_format'            => 'archives.post_format.disabled',
            'disable-attachment'             => 'archives.attachment.disabled',
            'rssbefore'                      => 'rss.before',
            'rssafter'                       => 'rss.after',
            'stripcategorybase'              => 'urls.strip_category_base',
            'website_name'                   => 'identity.website.name',
            'alternate_website_name'         => 'identity.website.alternate_name',
            'company_or_person'              => 'identity.owner.type',
            'company_or_person_user_id'      => 'identity.owner.user_id',
            'company_name'                   => 'identity.organization.name',
            'company_alternate_name'         => 'identity.organization.alternate_name',
            'company_logo'                   => 'identity.organization.logo_url',
            'company_logo_id'                => 'identity.organization.logo_id',
            'person_name'                    => 'identity.person.name',
            'person_logo'                    => 'identity.person.logo_url',
            'person_logo_id'                 => 'identity.person.logo_id',
            'open_graph_frontpage_title'     => 'social.frontpage.title',
            'open_graph_frontpage_desc'      => 'social.frontpage.description',
            'open_graph_frontpage_image'     => 'social.frontpage.image_url',
            'open_graph_frontpage_image_id'  => 'social.frontpage.image_id',
        );
        if ( isset( $direct[ $key ] ) ) {
            return array( 'canonical' => $direct[ $key ], 'classification' => FieldClassification::MAPPED, 'transform' => 'direct-global-map' );
        }
        if ( 0 === strpos( $key, 'breadcrumbs-' ) ) {
            return array( 'canonical' => 'breadcrumbs.' . $this->slug( substr( $key, 12 ) ), 'classification' => FieldClassification::MAPPED, 'transform' => 'prefix-map' );
        }
        if ( 0 === strpos( $key, 'org-' ) ) {
            return array( 'canonical' => 'identity.organization.' . $this->slug( substr( $key, 4 ) ), 'classification' => FieldClassification::MAPPED, 'transform' => 'prefix-map' );
        }
        if ( preg_match( '/^taxonomy-(.+)-ptparent$/', $key, $matches ) ) {
            return array( 'canonical' => 'taxonomy.' . $this->slug( $matches[1] ) . '.parent_post_type', 'classification' => FieldClassification::MAPPED, 'transform' => 'taxonomy-parent-map' );
        }
        if ( preg_match( '/^(publishing_principles|ownership_funding_info|actionable_feedback_policy|corrections_policy|ethics_policy|diversity_policy|diversity_staffing_report)_id$/', $key ) ) {
            return array( 'canonical' => 'identity.organization.policy.' . $this->slug( $key ), 'classification' => FieldClassification::MAPPED, 'transform' => 'policy-id-map' );
        }
        $patterns = array(
            '/^title-(.+)$/'              => 'templates.$1.title',
            '/^metadesc-(.+)$/'           => 'templates.$1.description',
            '/^noindex-(.+)$/'            => 'robots.$1.noindex',
            '/^schema-page-type-(.+)$/'   => 'schema.$1.page_type',
            '/^schema-article-type-(.+)$/' => 'schema.$1.article_type',
            '/^social-title-(.+)$/'       => 'social.$1.title',
            '/^social-description-(.+)$/' => 'social.$1.description',
            '/^social-image-url-(.+)$/'   => 'social.$1.image_url',
            '/^social-image-id-(.+)$/'    => 'social.$1.image_id',
            '/^bctitle-ptarchive-(.+)$/'  => 'breadcrumbs.ptarchive.$1.title',
            '/^post_types-(.+)-maintax$/' => 'taxonomy.$1.primary_default',
        );
        foreach ( $patterns as $pattern => $template ) {
            if ( preg_match( $pattern, $key, $matches ) ) {
                return array( 'canonical' => str_replace( '$1', $this->slug( $matches[1] ), $template ), 'classification' => FieldClassification::MAPPED, 'transform' => 'pattern-map' );
            }
        }
        if ( 0 === strpos( $key, 'display-metabox-' ) || in_array( $key, array( 'forcerewritetitle', 'company_logo_meta', 'person_logo_meta' ), true ) ) {
            return array( 'canonical' => '', 'classification' => FieldClassification::IGNORED_DERIVED, 'transform' => 'ui-or-internal-setting' );
        }
        return array( 'canonical' => '', 'classification' => FieldClassification::UNSUPPORTED, 'transform' => 'unclassified-title-setting' );
    }

    /** @return array{canonical:string,classification:string,transform:string} */
    private function mapSocialKey( string $key ): array {
        $direct = array(
            'facebook_site'         => 'social.global.facebook_site',
            'instagram_url'         => 'social.global.instagram_url',
            'linkedin_url'          => 'social.global.linkedin_url',
            'myspace_url'           => 'social.global.myspace_url',
            'og_default_image'      => 'social.global.og_default_image',
            'og_default_image_id'   => 'social.global.og_default_image_id',
            'og_frontpage_title'    => 'social.frontpage.title',
            'og_frontpage_desc'     => 'social.frontpage.description',
            'og_frontpage_image'    => 'social.frontpage.image_url',
            'og_frontpage_image_id' => 'social.frontpage.image_id',
            'opengraph'             => 'social.open_graph.enabled',
            'pinterest_url'         => 'social.global.pinterest_url',
            'pinterestverify'       => 'verification.pinterest',
            'twitter'               => 'social.twitter.enabled',
            'twitter_site'          => 'social.twitter.site',
            'twitter_card_type'     => 'social.twitter.card_type',
            'youtube_url'           => 'social.global.youtube_url',
            'wikipedia_url'         => 'social.global.wikipedia_url',
            'other_social_urls'     => 'social.global.other_urls',
            'mastodon_url'          => 'social.global.mastodon_url',
        );
        if ( isset( $direct[ $key ] ) ) {
            return array( 'canonical' => $direct[ $key ], 'classification' => FieldClassification::MAPPED, 'transform' => 'social-26-8-map' );
        }
        $ignored = array( 'facebook_connect_key', 'facebook_connect_secret' );
        if ( in_array( $key, $ignored, true ) ) {
            return array( 'canonical' => '', 'classification' => FieldClassification::IGNORED_DERIVED, 'transform' => 'credential-or-legacy-integration' );
        }
        if ( preg_match( '/(verify|verification)/i', $key ) ) {
            return array( 'canonical' => 'verification.' . $this->slug( $key ), 'classification' => FieldClassification::MAPPED, 'transform' => 'verification-map' );
        }
        if ( preg_match( '/(url|site|image|opengraph|twitter|facebook|instagram|linkedin|youtube|wikipedia|pinterest|mastodon)/i', $key ) ) {
            return array( 'canonical' => 'social.global.' . $this->slug( $key ), 'classification' => FieldClassification::MAPPED, 'transform' => 'social-map' );
        }
        return array( 'canonical' => '', 'classification' => FieldClassification::UNSUPPORTED, 'transform' => 'unclassified-social-setting' );
    }

    /** @return array{canonical:string,classification:string,transform:string} */
    private function mapCoreKey( string $key ): array {
        $outputRelevant = array(
            'disableadvanced_meta'           => 'editor.advanced_metadata.disabled',
            'baiduverify'                    => 'verification.baidu',
            'googleverify'                   => 'verification.google',
            'msverify'                       => 'verification.microsoft',
            'yandexverify'                   => 'verification.yandex',
            'ahrefsverify'                   => 'verification.ahrefs',
            'site_type'                      => 'identity.site_type',
            'has_multiple_authors'           => 'identity.has_multiple_authors',
            'enable_xml_sitemap'             => 'sitemap.enabled',
            'enable_schema'                  => 'schema.enabled',
            'enable_llms_txt'                => 'ai_discovery.llms_txt.enabled',
            'enable_index_now'               => 'discovery.indexnow.enabled',
            'enable_headless_rest_endpoints' => 'api.headless_rest.enabled',
            'enable_enhanced_slack_sharing'  => 'social.slack.enhanced_sharing',
        );
        if ( isset( $outputRelevant[ $key ] ) ) {
            return array( 'canonical' => $outputRelevant[ $key ], 'classification' => FieldClassification::MAPPED, 'transform' => 'core-output-map' );
        }

        $rebuiltFromWordPress = array(
            'permalink_structure',
            'home_url',
            'dynamic_permalinks',
            'category_base_url',
            'tag_base_url',
            'custom_taxonomy_slugs',
            'index_now_key',
        );
        if ( in_array( $key, $rebuiltFromWordPress, true ) ) {
            return array( 'canonical' => '', 'classification' => FieldClassification::REBUILT, 'transform' => 'rebuild-from-wordpress-or-yps-runtime' );
        }

        $cleanupMap = array(
            'remove_feed_global'              => 'crawl_cleanup.feeds.global',
            'remove_feed_global_comments'     => 'crawl_cleanup.feeds.global_comments',
            'remove_feed_post_comments'       => 'crawl_cleanup.feeds.post_comments',
            'remove_feed_authors'             => 'crawl_cleanup.feeds.authors',
            'remove_feed_categories'          => 'crawl_cleanup.feeds.categories',
            'remove_feed_tags'                => 'crawl_cleanup.feeds.tags',
            'remove_feed_custom_taxonomies'   => 'crawl_cleanup.feeds.custom_taxonomies',
            'remove_feed_post_types'          => 'crawl_cleanup.feeds.post_types',
            'remove_feed_search'              => 'crawl_cleanup.feeds.search',
            'remove_atom_rdf_feeds'           => 'crawl_cleanup.feeds.atom_rdf',
            'remove_shortlinks'               => 'crawl_cleanup.head.shortlinks',
            'remove_rest_api_links'           => 'crawl_cleanup.head.rest_api_links',
            'remove_rsd_wlw_links'            => 'crawl_cleanup.head.rsd_wlw_links',
            'remove_oembed_links'             => 'crawl_cleanup.head.oembed_links',
            'remove_generator'                => 'crawl_cleanup.head.generator',
            'remove_emoji_scripts'            => 'crawl_cleanup.head.emoji_scripts',
            'remove_powered_by_header'        => 'crawl_cleanup.headers.powered_by',
            'remove_pingback_header'          => 'crawl_cleanup.headers.pingback',
            'clean_campaign_tracking_urls'    => 'crawl_cleanup.urls.campaign_tracking',
            'clean_permalinks'                => 'crawl_cleanup.urls.permalinks',
            'clean_permalinks_extra_variables' => 'crawl_cleanup.urls.extra_variables',
            'search_cleanup'                  => 'crawl_cleanup.search.enabled',
            'search_cleanup_emoji'            => 'crawl_cleanup.search.emoji',
            'search_cleanup_patterns'         => 'crawl_cleanup.search.patterns',
            'search_character_limit'          => 'crawl_cleanup.search.character_limit',
            'redirect_search_pretty_urls'     => 'crawl_cleanup.search.redirect_pretty_urls',
            'deny_search_crawling'            => 'crawler_controls.search.deny',
            'deny_wp_json_crawling'           => 'crawler_controls.wp_json.deny',
            'deny_adsbot_crawling'            => 'crawler_controls.adsbot.deny',
            'deny_ccbot_crawling'             => 'crawler_controls.ccbot.deny',
            'deny_google_extended_crawling'   => 'crawler_controls.google_extended.deny',
            'deny_gptbot_crawling'            => 'crawler_controls.gptbot.deny',
        );
        if ( isset( $cleanupMap[ $key ] ) ) {
            return array( 'canonical' => $cleanupMap[ $key ], 'classification' => FieldClassification::MAPPED, 'transform' => 'crawl-policy-map' );
        }

        $knownNonOutput = array(
            'tracking',
            'toggled_tracking',
            'license_server_version',
            'ms_defaults_set',
            'ignore_search_engines_discouraged_notice',
            'indexing_first_time',
            'indexing_started',
            'indexing_reason',
            'indexables_indexing_completed',
            'version',
            'previous_version',
            'ryte_indexability',
            'environment_type',
            'content_analysis_active',
            'keyword_analysis_active',
            'inclusive_language_analysis_active',
            'enable_admin_bar_menu',
            'enable_cornerstone_content',
            'enable_text_link_counter',
            'enable_ai_generator',
            'ai_enabled_pre_default',
            'show_onboarding_notice',
            'first_activated_on',
            'myyoast-oauth',
            'semrush_integration_active',
            'semrush_tokens',
            'semrush_country_code',
            'enable_metabox_insights',
            'enable_link_suggestions',
            'algolia_integration_active',
            'import_cursors',
            'workouts_data',
            'configuration_finished_steps',
            'dismiss_configuration_workout_notice',
            'dismiss_premium_deactivated_notice',
            'importing_completed',
            'wincher_integration_active',
            'wincher_tokens',
            'wincher_automatically_add_keyphrases',
            'wincher_website_id',
            'first_time_install',
            'should_redirect_after_install_free',
            'activation_redirect_timestamp_free',
            'least_readability_ignore_list',
            'least_seo_score_ignore_list',
            'most_linked_ignore_list',
            'least_linked_ignore_list',
            'indexables_page_reading_list',
            'indexables_overview_state',
            'last_known_public_post_types',
            'last_known_public_taxonomies',
            'last_known_no_unindexed',
            'new_post_types',
            'new_taxonomies',
            'show_new_content_type_notification',
            'site_kit_configuration_permanently_dismissed',
            'site_kit_connected',
            'site_kit_tracking_setup_widget_loaded',
            'site_kit_tracking_first_interaction_stage',
            'site_kit_tracking_last_interaction_stage',
            'site_kit_tracking_setup_widget_temporarily_dismissed',
            'site_kit_tracking_setup_widget_permanently_dismissed',
            'google_site_kit_feature_enabled',
            'ai_free_sparks_started_on',
            'last_updated_on',
            'default_seo_title',
            'default_seo_meta_desc',
            'first_activated_by',
            'enable_task_list',
        );
        if ( in_array( $key, $knownNonOutput, true ) ) {
            return array( 'canonical' => '', 'classification' => FieldClassification::IGNORED_DERIVED, 'transform' => 'known-non-output-setting' );
        }
        $internalPatterns = array(
            '/version/i', '/tracking/i', '/activated/i', '/onboarding/i', '/notice/i', '/ryte/i', '/indexing/i',
            '/semrush/i', '/wincher/i', '/zapier/i', '/workout/i', '/configuration/i', '/dismissed/i', '/timestamp/i',
            '/ai_generator/i', '/keyword_analysis/i', '/content_analysis/i', '/inclusive_language/i', '/text_link_counter/i',
            '/cornerstone/i', '/admin_bar/i', '/usage_tracking/i',
        );
        foreach ( $internalPatterns as $pattern ) {
            if ( preg_match( $pattern, $key ) ) {
                return array( 'canonical' => '', 'classification' => FieldClassification::IGNORED_DERIVED, 'transform' => 'internal-editor-telemetry-setting' );
            }
        }
        return array( 'canonical' => '', 'classification' => FieldClassification::UNSUPPORTED, 'transform' => 'unclassified-core-setting' );
    }

    /** @param mixed $value */
    private function hasMeaningfulValue( $value ): bool {
        if ( is_array( $value ) ) {
            return ! empty( $value );
        }
        if ( is_bool( $value ) ) {
            return true;
        }
        return null !== $value && '' !== (string) $value;
    }

    /** @param mixed $value @return array<string,mixed> */
    private function record( string $option, string $key, string $canonical, $value, string $classification, string $transform ): array {
        return array(
            'source_option'  => $option,
            'source_key'     => $key,
            'canonical_key'  => $canonical,
            'classification' => $classification,
            'transform'      => $transform,
            'value'          => $value,
        );
    }

    private function slug( string $value ): string {
        $value = strtolower( preg_replace( '/[^a-z0-9_\-]+/i', '_', $value ) ?? $value );
        return trim( str_replace( '-', '_', $value ), '_' );
    }
}
