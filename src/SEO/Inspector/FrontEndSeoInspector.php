<?php
namespace Yablonsky\PerformanceSEO\SEO\Inspector;

use Yablonsky\PerformanceSEO\Language\WordPressLanguageProvider;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;
use Yablonsky\PerformanceSEO\Resource\WordPressResourceResolver;
use Yablonsky\PerformanceSEO\SEO\PublicSeoController;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Capability-gated front-end SEO evidence inspector for editors. */
final class FrontEndSeoInspector {
    private const NONCE = 'yseo_frontend_inspector';

    /** @var WordPressResourceResolver */ private $resolver;

    public function __construct() {
        $this->resolver = new WordPressResourceResolver( new WordPressLanguageProvider() );
    }

    public function register(): void {
        add_action( 'admin_bar_menu', array( $this, 'adminBarNode' ), 95 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'wp_footer', array( $this, 'renderShell' ), 100 );
        add_action( 'wp_ajax_yseo_frontend_inspect', array( $this, 'ajaxInspect' ) );
    }

    public function adminBarNode( \WP_Admin_Bar $bar ): void {
        if ( ! $this->eligible() ) { return; }
        $bar->add_node(
            array(
                'id' => 'yseo-inspector',
                'title' => esc_html__( 'YSEO Inspector', 'yablonsky-seo' ),
                'href' => '#yseo-inspector',
                'meta' => array( 'class' => 'yseo-inspector-adminbar' ),
            )
        );
    }

    public function enqueue(): void {
        if ( ! $this->eligible() ) { return; }
        $resource = $this->resolver->resolve();
        if ( null === $resource ) { return; }
        wp_enqueue_style( 'yseo-inspector', YPS_URL . 'assets/inspector.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yseo-inspector', YPS_URL . 'assets/inspector.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
        wp_localize_script(
            'yseo-inspector',
            'YSEO_INSPECTOR',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( self::NONCE ),
                'resource' => array(
                    'type' => $resource->type(),
                    'id' => $resource->objectId(),
                    'subtype' => $resource->subtype(),
                    'language' => $resource->languageKey(),
                    'url' => $resource->url(),
                ),
                'labels' => array(
                    'loading' => esc_html__( 'Resolving current YSEO evidence…', 'yablonsky-seo' ),
                    'failed' => esc_html__( 'Inspector could not load this resource safely.', 'yablonsky-seo' ),
                    'close' => esc_html__( 'Close SEO inspector', 'yablonsky-seo' ),
                ),
            )
        );
    }

    public function renderShell(): void {
        if ( ! $this->eligible() || null === $this->resolver->resolve() ) { return; }
        echo '<aside id="yseo-inspector" class="yseo-inspector" hidden aria-label="' . esc_attr__( 'Yablonsky SEO inspector', 'yablonsky-seo' ) . '">';
        echo '<div class="yseo-inspector-head"><div><span>YSEO</span><strong>' . esc_html__( 'Front-end SEO Inspector', 'yablonsky-seo' ) . '</strong></div><button type="button" data-yseo-inspector-close aria-label="' . esc_attr__( 'Close SEO inspector', 'yablonsky-seo' ) . '">×</button></div>';
        echo '<div class="yseo-inspector-body" data-yseo-inspector-body><p>' . esc_html__( 'Open the inspector from the admin bar to resolve the current SEO document.', 'yablonsky-seo' ) . '</p></div>';
        echo '</aside>';
    }

    public function ajaxInspect(): void {
        check_ajax_referer( self::NONCE, 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Inspecting SEO evidence requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $resource = $this->resourceFromRequest();
        if ( null === $resource || ! $this->canInspect( $resource ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'The requested resource could not be inspected safely.', 'yablonsky-seo' ) ), 404 );
        }
        $projection = ( new PublicSeoController() )->projectResource( $resource );
        wp_send_json_success(
            array(
                'resource' => array( 'type' => $resource->type(), 'subtype' => $resource->subtype(), 'id' => $resource->objectId(), 'url' => $resource->url(), 'language' => $resource->languageKey() ),
                'authority' => sanitize_key( (string) get_option( 'yps_output_authority', 'active' ) ),
                'projection' => $projection,
                'evidence' => $this->evidence( $resource, $projection ),
                'schema_types' => $this->schemaTypes( $projection ),
            )
        );
    }

    private function eligible(): bool {
        return ! is_admin() && is_admin_bar_showing() && current_user_can( 'edit_posts' );
    }

    private function resourceFromRequest(): ?ResourceIdentity {
        $raw = filter_input( INPUT_POST, 'resource', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY );
        if ( ! is_array( $raw ) ) { return null; }
        $type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
        $id = absint( $raw['id'] ?? 0 );
        $subtype = sanitize_key( (string) ( $raw['subtype'] ?? '' ) );
        $language = sanitize_text_field( (string) ( $raw['language'] ?? '' ) );
        $url = esc_url_raw( (string) ( $raw['url'] ?? '' ), array( 'http', 'https' ) );
        if ( '' === $type || '' === $url || ! $this->sameOrigin( $url ) ) { return null; }
        return new ResourceIdentity( $type, $id, $subtype, $language, $url );
    }

    private function canInspect( ResourceIdentity $resource ): bool {
        $type = $resource->type();
        if ( ! in_array( $type, array( 'post', 'product', 'posts_page', 'home', 'term', 'author_archive', 'post_type_archive', 'date_archive' ), true ) ) {
            return false;
        }
        if ( in_array( $type, array( 'post', 'product', 'posts_page', 'home' ), true ) && $resource->objectId() > 0 ) {
            $post = get_post( $resource->objectId() );
            if ( ! $post instanceof \WP_Post ) { return false; }
            $actualSubtype = (string) get_post_type( $post );
            if ( in_array( $type, array( 'post', 'product' ), true ) && $actualSubtype !== $resource->subtype() ) { return false; }
            if ( 'product' === $type && 'product' !== $actualSubtype ) { return false; }
            return current_user_can( 'edit_post', $resource->objectId() );
        }
        if ( 'term' === $type && $resource->objectId() > 0 ) {
            $term = get_term( $resource->objectId(), $resource->subtype() );
            if ( ! $term instanceof \WP_Term ) { return false; }
            $taxonomy = get_taxonomy( $resource->subtype() );
            $cap = is_object( $taxonomy ) && isset( $taxonomy->cap->edit_terms ) ? (string) $taxonomy->cap->edit_terms : 'manage_categories';
            return current_user_can( $cap );
        }
        if ( 'post_type_archive' === $type ) {
            $object = get_post_type_object( $resource->subtype() );
            return is_object( $object ) && ! empty( $object->public ) && current_user_can( 'edit_posts' );
        }
        if ( 'author_archive' === $type && $resource->objectId() > 0 && ! get_userdata( $resource->objectId() ) ) {
            return false;
        }
        return current_user_can( 'edit_posts' );
    }

    /** @param array<string,mixed> $projection @return array<string,string> */
    private function evidence( ResourceIdentity $resource, array $projection ): array {
        $evidence = array();
        $id = $resource->objectId();
        if ( $id > 0 && in_array( $resource->type(), array( 'post', 'product', 'posts_page', 'home' ), true ) ) {
            $evidence['title'] = '' !== trim( (string) get_post_meta( $id, '_yps_seo_title', true ) ) ? 'authored-post-meta' : 'template-or-wordpress-fallback';
            $evidence['description'] = '' !== trim( (string) get_post_meta( $id, '_yps_meta_description', true ) ) ? 'authored-post-meta' : 'template-or-content-fallback';
            $evidence['canonical'] = '' !== trim( (string) get_post_meta( $id, '_yps_canonical', true ) ) ? 'authored-post-meta' : 'resource-url-fallback';
            $evidence['open_graph'] = '' !== trim( (string) get_post_meta( $id, '_yps_og_title', true ) ) ? 'authored-post-meta' : 'social-template-or-search-fallback';
            $evidence['twitter'] = '' !== trim( (string) get_post_meta( $id, '_yps_twitter_title', true ) ) ? 'authored-post-meta' : 'social-template-or-open-graph-fallback';
        } elseif ( 'term' === $resource->type() && $id > 0 ) {
            $evidence['title'] = '' !== trim( (string) get_term_meta( $id, '_yps_seo_title', true ) ) ? 'authored-term-meta' : 'template-or-wordpress-fallback';
            $evidence['description'] = '' !== trim( (string) get_term_meta( $id, '_yps_meta_description', true ) ) ? 'authored-term-meta' : 'template-or-term-fallback';
            $evidence['canonical'] = '' !== trim( (string) get_term_meta( $id, '_yps_canonical', true ) ) ? 'authored-term-meta' : 'resource-url-fallback';
            $evidence['open_graph'] = '' !== trim( (string) get_term_meta( $id, '_yps_og_title', true ) ) ? 'authored-term-meta' : 'social-template-or-search-fallback';
            $evidence['twitter'] = '' !== trim( (string) get_term_meta( $id, '_yps_twitter_title', true ) ) ? 'authored-term-meta' : 'social-template-or-open-graph-fallback';
        } else {
            $evidence = array( 'title' => 'archive-template-or-wordpress-fallback', 'description' => 'archive-template-or-content-fallback', 'canonical' => 'resource-url-fallback', 'open_graph' => 'social-template-or-search-fallback', 'twitter' => 'social-template-or-open-graph-fallback' );
        }
        $evidence['robots'] = ! empty( $projection['robots']['index'] ) ? 'index' : 'noindex';
        $evidence['schema'] = 'resolved-local-schema-graph';
        return $evidence;
    }

    /** @param array<string,mixed> $projection @return array<int,string> */
    private function schemaTypes( array $projection ): array {
        $schema = isset( $projection['schema']['@graph'] ) && is_array( $projection['schema']['@graph'] ) ? $projection['schema']['@graph'] : array();
        $types = array();
        foreach ( $schema as $node ) {
            if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) { continue; }
            foreach ( (array) $node['@type'] as $type ) {
                $type = sanitize_text_field( (string) $type );
                if ( '' !== $type ) { $types[] = $type; }
            }
        }
        $types = array_values( array_unique( $types ) );
        sort( $types, SORT_STRING );
        return $types;
    }

    private function sameOrigin( string $url ): bool {
        $home = wp_parse_url( home_url( '/' ) );
        $target = wp_parse_url( $url );
        if ( ! is_array( $home ) || ! is_array( $target ) ) { return false; }
        return strtolower( (string) ( $home['host'] ?? '' ) ) === strtolower( (string) ( $target['host'] ?? '' ) );
    }
}
