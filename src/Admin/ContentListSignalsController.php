<?php
namespace Yablonsky\PerformanceSEO\Admin;

use Yablonsky\PerformanceSEO\Analysis\ContentOptimizationAnalyzer;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;
use Yablonsky\PerformanceSEO\SEO\Template\TemplateResolver;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;
use Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Adds lightweight local SEO/readability evidence to WordPress content lists and
 * a compact YSEO admin-bar entry. Scores are derived from saved content only and
 * are never persisted as ranking facts.
 */
final class ContentListSignalsController {
    /** @var array<int,array<string,mixed>> */
    private $analysisCache = array();
    /** @var array<int,array<string,int>> */
    private $linkStatsCache = array();

    public function register(): void {
        add_action( 'init', array( $this, 'registerColumns' ), 99 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
        add_action( 'admin_bar_menu', array( $this, 'adminBar' ), 90 );
        add_action( 'restrict_manage_posts', array( $this, 'languageFilter' ), 20, 2 );
        add_action( 'pre_get_posts', array( $this, 'applyLanguageFilter' ), 20 );
    }

    public function registerColumns(): void {
        $postTypes = get_post_types( array( 'public' => true ), 'names' );
        foreach ( $postTypes as $postType ) {
            if ( 'attachment' === $postType ) { continue; }
            add_filter( 'manage_' . $postType . '_posts_columns', array( $this, 'addColumns' ) );
            add_action( 'manage_' . $postType . '_posts_custom_column', array( $this, 'renderColumn' ), 10, 2 );
        }
    }

    /** @param array<string,string> $columns @return array<string,string> */
    public function addColumns( array $columns ): array {
        $result = array();
        foreach ( $columns as $key => $label ) {
            $result[ $key ] = $label;
            if ( 'title' === $key ) {
                $result['yseo_score'] = __( 'SEO', 'yablonsky-seo' );
                $result['yseo_readability'] = __( 'Readability', 'yablonsky-seo' );
                $result['yseo_inbound'] = __( 'Incoming internal links', 'yablonsky-seo' );
                $result['yseo_outbound'] = __( 'Outgoing internal links', 'yablonsky-seo' );
                if ( ! $this->deferLanguageUiToMultilingualOwner() ) {
                    $result['yseo_language'] = __( 'Language', 'yablonsky-seo' );
                }
            }
        }
        if ( ! isset( $result['yseo_score'] ) ) {
            $result['yseo_score'] = __( 'SEO', 'yablonsky-seo' );
            $result['yseo_readability'] = __( 'Readability', 'yablonsky-seo' );
            $result['yseo_inbound'] = __( 'Incoming internal links', 'yablonsky-seo' );
            $result['yseo_outbound'] = __( 'Outgoing internal links', 'yablonsky-seo' );
            if ( ! $this->deferLanguageUiToMultilingualOwner() ) {
                $result['yseo_language'] = __( 'Language', 'yablonsky-seo' );
            }
        }
        return $result;
    }

    public function renderColumn( string $column, int $postId ): void {
        if ( 'yseo_language' === $column ) {
            $index = new ResourceLanguageIndex();
            $resolved = $index->languageForPost( $postId, true );
            $key = (string) ( $resolved['language_key'] ?? 'auto' );
            echo '<span class="yseo-language-badge" title="' . esc_attr( (string) ( $resolved['source'] ?? '' ) ) . '">' . esc_html( $index->label( $key ) ) . '</span>';
            return;
        }
        if ( in_array( $column, array( 'yseo_inbound', 'yseo_outbound' ), true ) ) {
            $stats = $this->linkStatsForPost( $postId );
            $incoming = 'yseo_inbound' === $column;
            $value = $incoming ? (int) ( $stats['inbound_links'] ?? 0 ) : (int) ( $stats['outbound_links'] ?? 0 );
            $distinct = $incoming ? (int) ( $stats['inbound_sources'] ?? 0 ) : (int) ( $stats['outbound_targets'] ?? 0 );
            $label = $incoming ? __( 'Incoming internal links', 'yablonsky-seo' ) : __( 'Outgoing internal links', 'yablonsky-seo' );
            $detail = $incoming ? __( 'other indexed content linking to this item', 'yablonsky-seo' ) : __( 'internal links from this item to other indexed content', 'yablonsky-seo' );
            echo '<span class="yseo-list-link-count" title="' . esc_attr( $label . ': ' . $value . ' · ' . $distinct . ' ' . $detail ) . '"><span class="dashicons ' . esc_attr( $incoming ? 'dashicons-arrow-down-alt' : 'dashicons-arrow-up-alt' ) . '" aria-hidden="true"></span><strong>' . esc_html( (string) $value ) . '</strong><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
            return;
        }
        if ( ! in_array( $column, array( 'yseo_score', 'yseo_readability' ), true ) ) { return; }
        $analysis = $this->analysisForPost( $postId );
        $dimensionKey = 'yseo_score' === $column ? 'search' : 'language';
        $dimension = isset( $analysis['dimensions'][ $dimensionKey ] ) && is_array( $analysis['dimensions'][ $dimensionKey ] ) ? $analysis['dimensions'][ $dimensionKey ] : array();
        $score = max( 0, min( 100, (int) ( $dimension['score'] ?? 0 ) ) );
        $status = sanitize_key( (string) ( $dimension['status'] ?? 'attention' ) );
        $label = 'search' === $dimensionKey ? __( 'SEO score', 'yablonsky-seo' ) : __( 'Readability score', 'yablonsky-seo' );
        echo '<span class="yseo-list-score is-' . esc_attr( $status ) . '" title="' . esc_attr( $label . ': ' . $score . '/100' ) . '"><span class="yseo-list-score-dot" aria-hidden="true"></span><strong>' . esc_html( (string) $score ) . '</strong><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
    }

    public function languageFilter( string $postType, string $which = '' ): void {
        unset( $which );
        if ( 'attachment' === $postType || ! post_type_exists( $postType ) ) { return; }
        if ( $this->hasMultilingualOwner() ) { return; }
        $object = get_post_type_object( $postType );
        if ( ! $object || empty( $object->public ) ) { return; }
        $selected = sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_language_filter', FILTER_UNSAFE_RAW ) );
        $index = new ResourceLanguageIndex();
        echo '<select name="yseo_language_filter" aria-label="' . esc_attr__( 'Content language', 'yablonsky-seo' ) . '" data-yps-search-select data-yps-search-placeholder="' . esc_attr__( 'Search languages…', 'yablonsky-seo' ) . '">';
        echo '<option value="">' . esc_html__( 'All content languages', 'yablonsky-seo' ) . '</option>';
        foreach ( $index->catalog( true ) as $key => $label ) {
            echo '<option value="' . esc_attr( (string) $key ) . '" ' . selected( $selected, (string) $key, false ) . '>' . esc_html( (string) $label ) . '</option>';
        }
        echo '</select>';
    }

    public function applyLanguageFilter( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) { return; }
        $language = sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_language_filter', FILTER_UNSAFE_RAW ) );
        if ( '' === $language ) { return; }
        $catalog = ( new ResourceLanguageIndex() )->catalog( true );
        if ( ! isset( $catalog[ $language ] ) ) { return; }
        $metaQuery = $query->get( 'meta_query' );
        $metaQuery = is_array( $metaQuery ) ? $metaQuery : array();
        $metaQuery[] = array( 'key' => ResourceLanguageIndex::META_LANGUAGE, 'value' => $language, 'compare' => '=' );
        $query->set( 'meta_query', $metaQuery );
    }

    public function enqueueAssets( string $hook ): void {
        if ( 'edit.php' !== $hook ) { return; }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin-editor', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
    }

    public function adminBar( \WP_Admin_Bar $bar ): void {
        if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) { return; }
        $authority = 'active' === (string) get_option( 'yps_output_authority', 'active' );
        $title = 'YSEO · ' . ( $authority ? __( 'LIVE', 'yablonsky-seo' ) : __( 'SHADOW', 'yablonsky-seo' ) );
        $bar->add_node(
            array(
                'id'    => 'yseo-status',
                'title' => esc_html( $title ),
                'href'  => admin_url( 'admin.php?page=yablonsky-seo' ),
                'meta'  => array( 'class' => $authority ? 'yseo-adminbar-live' : 'yseo-adminbar-shadow' ),
            )
        );
        $bar->add_node( array( 'parent' => 'yseo-status', 'id' => 'yseo-dashboard', 'title' => esc_html__( 'Dashboard', 'yablonsky-seo' ), 'href' => admin_url( 'admin.php?page=yablonsky-seo' ) ) );
        $bar->add_node( array( 'parent' => 'yseo-status', 'id' => 'yseo-settings', 'title' => esc_html__( 'SEO Settings', 'yablonsky-seo' ), 'href' => admin_url( 'admin.php?page=yablonsky-seo-settings' ) ) );

        $postId = $this->currentAdminPostId();
        if ( $postId < 1 ) { return; }
        $analysis = $this->analysisForPost( $postId );
        $seo = (int) ( $analysis['dimensions']['search']['score'] ?? 0 );
        $readability = (int) ( $analysis['dimensions']['language']['score'] ?? 0 );
        /* translators: 1: SEO score, 2: readability score. */
        $signal = sprintf( __( 'Current content: SEO %1$d · Readability %2$d', 'yablonsky-seo' ), $seo, $readability );
        $bar->add_node( array( 'parent' => 'yseo-status', 'id' => 'yseo-current-signals', 'title' => esc_html( $signal ), 'href' => get_edit_post_link( $postId ) ?: '' ) );
    }

    private function hasMultilingualOwner(): bool {
        if ( false !== has_filter( 'yablonsky_seo_resource_language_v1' ) || false !== has_filter( 'yablonsky_performance_seo_resource_language_v1' ) ) { return true; }
        $active = (array) get_option( 'active_plugins', array() );
        foreach ( $active as $pluginFile ) {
            $pluginFile = strtolower( (string) $pluginFile );
            if ( false !== strpos( $pluginFile, 'yablonsky' ) && false !== strpos( $pluginFile, 'multilingual' ) ) { return true; }
        }
        return false;
    }

    private function deferLanguageUiToMultilingualOwner(): bool {
        return $this->hasMultilingualOwner();
    }

    /** @return array<string,mixed> */
    private function analysisForPost( int $postId ): array {
        if ( isset( $this->analysisCache[ $postId ] ) ) { return $this->analysisCache[ $postId ]; }
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post ) { return array(); }
        $permalink = get_permalink( $postId );
        $permalink = is_string( $permalink ) ? $permalink : home_url( '/' );
        $resourceType = 'product' === $post->post_type ? 'product' : 'post';
        $resource = new ResourceIdentity( $resourceType, $postId, (string) $post->post_type, '', $permalink );
        $resolver = new TemplateResolver();
        $rawSeoTitle = (string) get_post_meta( $postId, '_yps_seo_title', true );
        $rawMetaDescription = (string) get_post_meta( $postId, '_yps_meta_description', true );
        $values = array(
            'focus_keyphrase' => (string) get_post_meta( $postId, '_yps_focus_keyphrase', true ),
            'focus_keyphrases' => get_post_meta( $postId, '_yps_focus_keyphrases_json', true ),
            'seo_title' => $rawSeoTitle,
            'effective_seo_title' => '' !== trim( $rawSeoTitle ) ? $resolver->resolve( $rawSeoTitle, $resource ) : '',
            'meta_description' => $rawMetaDescription,
            'effective_meta_description' => '' !== trim( $rawMetaDescription ) ? $resolver->resolve( $rawMetaDescription, $resource ) : '',
            'noindex' => (string) get_post_meta( $postId, '_yps_robots_noindex', true ),
            'og_image' => (string) get_post_meta( $postId, '_yps_og_image', true ),
            'schema_entity_name' => (string) get_post_meta( $postId, '_yps_schema_entity_name', true ),
            'schema_entity_type' => (string) get_post_meta( $postId, '_yps_schema_entity_type', true ),
            'schema_page_type' => (string) get_post_meta( $postId, '_yps_schema_page_type', true ),
        );
        $this->analysisCache[ $postId ] = ( new ContentOptimizationAnalyzer() )->analyzePost( $post, $values );
        return $this->analysisCache[ $postId ];
    }

    /** @return array<string,int> */
    private function linkStatsForPost( int $postId ): array {
        if ( isset( $this->linkStatsCache[ $postId ] ) ) { return $this->linkStatsCache[ $postId ]; }
        $repository = new InternalLinkRepository();
        $repository->ensureSchema();
        $this->linkStatsCache[ $postId ] = $repository->statsForPost( $postId );
        return $this->linkStatsCache[ $postId ];
    }

    private function currentAdminPostId(): int {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) { return 0; }
        $postId = filter_input( INPUT_GET, 'post', FILTER_VALIDATE_INT );
        if ( is_int( $postId ) && $postId > 0 ) { return $postId; }
        global $post;
        return $post instanceof \WP_Post ? (int) $post->ID : 0;
    }
}
