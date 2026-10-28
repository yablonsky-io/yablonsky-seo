<?php
namespace Yablonsky\PerformanceSEO\Integrations\WordPress;

use Yablonsky\PerformanceSEO\Analysis\ContentOptimizationAnalyzer;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;
use Yablonsky\PerformanceSEO\SEO\PublicSeoController;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Optional WordPress 6.9+ Abilities API surface for agents and automation. */
final class AbilitiesController {
    private const CATEGORY = 'yablonsky-seo';

    public function register(): void {
        add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
        add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
    }

    public function registerCategory(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) { return; }
        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label'       => __( 'Yablonsky SEO', 'yablonsky-seo' ),
                'description' => __( 'SEO analysis, metadata projection and sitemap diagnostics from Yablonsky SEO.', 'yablonsky-seo' ),
            )
        );
    }

    public function registerAbilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        $postInput = array(
            'type'                 => 'object',
            'properties'           => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
            'required'             => array( 'post_id' ),
            'additionalProperties' => false,
        );
        wp_register_ability(
            'yablonsky-seo/get-resource-seo',
            array(
                'label'               => __( 'Get resource SEO', 'yablonsky-seo' ),
                'description'         => __( 'Returns the canonical Yablonsky SEO projection for one published WordPress post, page or product.', 'yablonsky-seo' ),
                'category'            => self::CATEGORY,
                'input_schema'        => $postInput,
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'getResourceSeo' ),
                'permission_callback' => array( $this, 'canReadPost' ),
                'meta'                => array( 'public' => true, 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            )
        );
        wp_register_ability(
            'yablonsky-seo/get-resource-analysis',
            array(
                'label'               => __( 'Get resource SEO analysis', 'yablonsky-seo' ),
                'description'         => __( 'Returns local SEO, readability, AEO and related analysis evidence for one WordPress resource.', 'yablonsky-seo' ),
                'category'            => self::CATEGORY,
                'input_schema'        => $postInput,
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'getResourceAnalysis' ),
                'permission_callback' => array( $this, 'canEditPost' ),
                'meta'                => array( 'public' => true, 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            )
        );
        wp_register_ability(
            'yablonsky-seo/get-sitemap-status',
            array(
                'label'               => __( 'Get sitemap status', 'yablonsky-seo' ),
                'description'         => __( 'Returns bounded Yablonsky SEO sitemap materialization and cache diagnostics.', 'yablonsky-seo' ),
                'category'            => self::CATEGORY,
                'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'getSitemapStatus' ),
                'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ); },
                'meta'                => array( 'public' => true, 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            )
        );
    }

    /** @param mixed $input @return array<string,mixed>|\WP_Error */
    public function getResourceSeo( $input ) {
        // Defense in depth for PHP callers invoking the callback outside WP_Ability::execute().
        if ( ! $this->canReadPost( $input ) ) { return new \WP_Error( 'yseo_resource_not_found', __( 'The requested SEO resource was not found.', 'yablonsky-seo' ) ); }
        $post = $this->postFromInput( $input );
        if ( ! $post instanceof \WP_Post ) { return new \WP_Error( 'yseo_resource_not_found', __( 'The requested SEO resource was not found.', 'yablonsky-seo' ) ); }
        $resource = $this->identity( $post );
        return ( new PublicSeoController() )->projectResource( $resource );
    }

    /** @param mixed $input @return array<string,mixed>|\WP_Error */
    public function getResourceAnalysis( $input ) {
        if ( ! $this->canEditPost( $input ) ) { return new \WP_Error( 'yseo_resource_not_found', __( 'The requested SEO resource was not found.', 'yablonsky-seo' ) ); }
        $post = $this->postFromInput( $input );
        if ( ! $post instanceof \WP_Post ) { return new \WP_Error( 'yseo_resource_not_found', __( 'The requested SEO resource was not found.', 'yablonsky-seo' ) ); }
        $seoValues = array(
            'focus_keyphrase'  => (string) get_post_meta( $post->ID, '_yps_focus_keyphrase', true ),
            'seo_title'        => (string) get_post_meta( $post->ID, '_yps_seo_title', true ),
            'meta_description' => (string) get_post_meta( $post->ID, '_yps_meta_description', true ),
            'canonical'        => (string) get_post_meta( $post->ID, '_yps_canonical', true ),
            'noindex'          => $this->truthy( get_post_meta( $post->ID, '_yps_robots_noindex', true ) ),
        );
        return ( new ContentOptimizationAnalyzer() )->analyzePost( $post, $seoValues );
    }

    /** @param mixed $input @return array<string,mixed>|\WP_Error */
    public function getSitemapStatus( $input ) {
        unset( $input );
        // WP_Ability::execute() checks permission_callback, but direct PHP callers
        // must not bypass the same administrator-only diagnostics boundary.
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'yseo_sitemap_status_forbidden', __( 'Insufficient permissions.', 'yablonsky-seo' ) );
        }
        $repo = new SitemapRepository();
        return array_merge( $repo->diagnostics(), array( 'index_url' => home_url( '/sitemap_index.xml' ) ) );
    }

    /** @param mixed $input */
    public function canReadPost( $input ): bool {
        $post = $this->postFromInput( $input );
        if ( ! $post instanceof \WP_Post ) { return false; }
        // Public Abilities must not expose SEO projections for password-protected posts.
        // A valid WordPress password cookie is not a public machine-read permission.
        if ( 'publish' === $post->post_status && '' === (string) $post->post_password ) { return true; }
        return current_user_can( 'edit_post', $post->ID );
    }

    /** @param mixed $input */
    public function canEditPost( $input ): bool {
        $post = $this->postFromInput( $input );
        return $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID );
    }

    /** @param mixed $input */
    private function postFromInput( $input ): ?\WP_Post {
        $postId = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
        $post = $postId > 0 ? get_post( $postId ) : null;
        if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) { return null; }
        $type = get_post_type_object( (string) $post->post_type );
        return $type && ! empty( $type->public ) ? $post : null;
    }

    private function identity( \WP_Post $post ): ResourceIdentity {
        $language = ( new ResourceLanguageResolver() )->forPost( $post->ID );
        return new ResourceIdentity(
            'product' === $post->post_type ? 'product' : 'post',
            $post->ID,
            (string) $post->post_type,
            (string) ( $language['language_key'] ?? 'auto' ),
            (string) get_permalink( $post->ID )
        );
    }

    /** @param mixed $value */
    private function truthy( $value ): bool {
        return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on', 'noindex' ), true );
    }
}
