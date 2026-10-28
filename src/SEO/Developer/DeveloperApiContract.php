<?php
namespace Yablonsky\PerformanceSEO\SEO\Developer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Machine-readable stable public contract for YSEO Developer API v1. */
final class DeveloperApiContract {
    /** @return array<string,mixed> */
    public static function manifest(): array {
        return array(
            'schema' => 'yablonsky.seo.developer-api.v1',
            'api_version' => 1,
            'read_only' => true,
            'public_read_authentication' => 'none',
            'write_operations' => false,
            'rest' => array(
                'head_by_url' => '/wp-json/yablonsky-seo/v1/head?url=<same-origin-url>',
                'typed_surface' => '/wp-json/yablonsky-seo/v1/surface?type=<home|posts_page|singular|term|author_archive|post_type_archive>&id=<id>&subtype=<subtype>',
                'capabilities' => '/wp-json/yablonsky-seo/v1/capabilities',
                'health' => '/wp-json/yablonsky-seo/v1/health',
                'contract' => '/wp-json/yablonsky-seo/v1/contract',
                'machine_policy' => '/wp-json/yablonsky-seo/v1/machine-policy',
                'native_fields' => array( 'yseo_head', 'yseo_head_json' ),
            ),
            'php_surface' => array(
                'root' => 'yablonsky_seo()->meta',
                'methods' => array( 'for_current_page', 'for_post', 'for_singular', 'for_url', 'for_term', 'for_author', 'for_post_type_archive', 'for_home', 'for_posts_page' ),
            ),
            'projection' => array( 'resource', 'site', 'title', 'description', 'canonical', 'robots', 'open_graph', 'twitter', 'images', 'alternates', 'schema' ),
            'projection_notes' => array(
                'site_identity' => array( 'site_icon', 'organization_logo', 'owner' ),
                'image_roles' => array( 'preferred', 'open_graph', 'twitter', 'favicon', 'organization_logo' ),
                'raw_json_presentation' => 'machine_first',
            ),
            'response_headers' => array( 'ETag', 'X-YSEO-API-Version', 'X-YSEO-Contract-SHA256', 'Vary' ),
            'xml_surfaces' => array(
                'sitemap_index' => '/sitemap_index.xml',
                'child_pattern' => '/<resource>-sitemap<n>.xml',
                'schema_map' => '/schemamap.xml',
                'content_type' => 'application/xml',
                'xsl_html_presentation' => false,
                'css_xml_presentation' => false,
                'hreflang_transport' => 'html_head',
                'machine_first_pure_xml' => true,
            ),
            'machine_use_permissions' => array(
                'dimensions' => array( 'search', 'answers', 'agent', 'training' ),
                'values' => array( 'allow', 'disallow', 'inherit' ),
                'external_authorities' => array( 'provider_console', 'robots_txt', 'meta_or_x_robots', 'cdn_waf', 'future_ai_prefs' ),
            ),
            'hooks' => array(
                'yablonsky_seo_title_v1',
                'yablonsky_seo_description_v1',
                'yablonsky_seo_canonical_v1',
                'yablonsky_seo_robots_v1',
                'yablonsky_seo_open_graph_v1',
                'yablonsky_seo_twitter_v1',
                'yablonsky_seo_document_v1',
                'yablonsky_seo_head_projection_v1',
                'yablonsky_seo_schema_graph_v1',
                'yablonsky_seo_social_template_value_v1',
                'yablonsky_seo_analysis_content_v1',
                'yablonsky_seo_breadcrumb_items_v1',
                'yablonsky_seo_sitemap_images_v1',
                'yablonsky_seo_resource_language_v1',
                'yablonsky_seo_multilingual_provider_v1',
                'yablonsky_seo_crawler_permission_policy_v1',
            ),
        );
    }

    public static function fingerprint(): string {
        $encoded = wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
    }
}
