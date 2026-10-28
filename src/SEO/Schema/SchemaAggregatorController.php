<?php
namespace Yablonsky\PerformanceSEO\SEO\Schema;

use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPresentation;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageResolver;
use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;
use Yablonsky\PerformanceSEO\SEO\Freshness\FreshnessResolver;
use Yablonsky\PerformanceSEO\SEO\PublicSeoController;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exposes the same YSEO schema graph as bounded, cacheable machine-readable collections.
 * The root schemamap is discovery only; the canonical HTML page remains SEO authority.
 */
final class SchemaAggregatorController {
    private const REST_NS = 'yablonsky-seo/v1';
    private const CACHE_GROUP = 'yablonsky-seo-schema-aggregator';
    private const DEFAULT_PER_PAGE = 500;
    private const PRODUCT_PER_PAGE = 100;

    /** @var PublicSeoController */ private $seo;
    /** @var ResourceLanguageResolver */ private $language;
    /** @var FreshnessResolver */ private $freshness;

    public function __construct() {
        $this->seo = new PublicSeoController();
        $this->language = new ResourceLanguageResolver();
        $this->freshness = new FreshnessResolver();
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
        add_action( 'template_redirect', array( $this, 'routeSchemaMap' ), 0 );
        add_filter( 'robots_txt', array( $this, 'robotsTxt' ), 35, 2 );
        add_action( 'save_post', array( $this, 'invalidateForPost' ), 90, 1 );
        add_action( 'deleted_post', array( $this, 'invalidateForPost' ), 90, 1 );
        // Schema graphs include taxonomy relations and term metadata. These may
        // change independently of save_post, so invalidate the public cache too.
        add_action( 'set_object_terms', array( $this, 'invalidateForPost' ), 90, 1 );
        add_action( 'edited_term', array( $this, 'invalidateForPost' ), 90, 1 );
        add_action( 'delete_term', array( $this, 'invalidateForPost' ), 90, 1 );
        // Metadata can affect the public schema without triggering save_post.
        add_action( 'added_post_meta', array( $this, 'invalidateForMeta' ), 90, 2 );
        add_action( 'updated_post_meta', array( $this, 'invalidateForMeta' ), 90, 2 );
        add_action( 'deleted_post_meta', array( $this, 'invalidateForMeta' ), 90, 2 );
    }

    public function registerRoutes(): void {
        register_rest_route(
            self::REST_NS,
            '/schema-aggregator/(?P<post_type>[a-zA-Z0-9_-]+)(?:/(?P<page>\d+))?',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'restSchema' ),
                'permission_callback' => '__return_true',
                'args' => array(
                    'post_type' => array( 'sanitize_callback' => 'sanitize_key' ),
                    'page' => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
                ),
            )
        );
        register_rest_route(
            self::REST_NS,
            '/schema-aggregator-map',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'restMap' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function routeSchemaMap(): void {
        $requestUri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $path = (string) wp_parse_url( is_string( $requestUri ) ? $requestUri : '', PHP_URL_PATH );
        if ( ! $this->isSchemaMapRequestPath( $path ) ) { return; }
        $this->sendXml( $this->buildMapXml() );
        exit;
    }

    /** Match the public URL even when WordPress uses a subdirectory home URL. */
    private function isSchemaMapRequestPath( string $requestPath ): bool {
        $expectedPath = (string) wp_parse_url( home_url( '/schemamap.xml' ), PHP_URL_PATH );
        return '' !== $expectedPath && '/' . trim( $expectedPath, '/' ) === '/' . trim( $requestPath, '/' );
    }

    public function robotsTxt( string $output, bool $public ): string {
        // Honour WordPress Search Engine Visibility; do not advertise schema
        // discovery in robots.txt while the site discourages indexing.
        if ( ! $public ) { return $output; }
        $url = home_url( '/schemamap.xml' );
        if ( false === strpos( $output, $url ) ) {
            $output = rtrim( $output ) . "\nSitemap: " . esc_url_raw( $url ) . "\n";
        }
        return $output;
    }

    public function restMap(): \WP_REST_Response {
        $response = new \WP_REST_Response( $this->mapDocument(), 200 );
        $response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=300' );
        return $response;
    }

    public function restSchema( \WP_REST_Request $request ): \WP_REST_Response {
        $postType = sanitize_key( (string) $request->get_param( 'post_type' ) );
        $page = max( 1, absint( $request->get_param( 'page' ) ) );
        $object = get_post_type_object( $postType );
        if ( ! $object || empty( $object->public ) || ! empty( $object->exclude_from_search ) || 'attachment' === $postType ) {
            return new \WP_REST_Response( array( 'code' => 'yseo_schema_post_type_not_found' ), 404 );
        }

        $perPage = 'product' === $postType ? self::PRODUCT_PER_PAGE : self::DEFAULT_PER_PAGE;
        $perPage = (int) apply_filters( 'yablonsky_seo_schema_aggregator_per_page_v1', $perPage, $postType );
        $perPage = min( 1000, max( 25, $perPage ) );
        $lastmod = $this->freshness->postTypeArchiveLastmod( $postType );
        // Pagination filters affect the query result, so they must affect its cache identity.
        $cacheKey = $this->versionedCacheKey( 'schema:' . md5( $postType . ':' . $page . ':' . $perPage . ':' . $lastmod ) );
        $cached = wp_cache_get( $cacheKey, self::CACHE_GROUP );
        if ( is_array( $cached ) && isset( $cached['payload'], $cached['pagination'] ) && is_array( $cached['payload'] ) && is_array( $cached['pagination'] ) ) {
            $response = new \WP_REST_Response( $cached['payload'], 200 );
            $response->header( 'X-YSEO-Cache', 'HIT' );
            $this->addPaginationHeaders( $response, $postType, $page, $cached['pagination'], $lastmod );
            return $response;
        }

        $query = new \WP_Query(
            array(
                'post_type' => $postType,
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => $perPage,
                'paged' => $page,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => false,
                'ignore_sticky_posts' => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => true,
            )
        );

        $graph = array();
        foreach ( (array) $query->posts as $postId ) {
            $postId = (int) $postId;
            $post = get_post( $postId );
            if ( ! $post instanceof \WP_Post || 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password ) { continue; }
            $url = (string) get_permalink( $postId );
            if ( '' === $url ) { continue; }
            $lang = $this->language->forPost( $postId );
            $resource = new ResourceIdentity( 'product' === $postType ? 'product' : 'post', $postId, $postType, (string) $lang['language_key'], $url );
            $schema = $this->seo->schemaForResource( $resource );
            foreach ( (array) ( $schema['@graph'] ?? array() ) as $piece ) {
                if ( ! is_array( $piece ) ) { continue; }
                $id = isset( $piece['@id'] ) ? (string) $piece['@id'] : '';
                $key = '' !== $id ? $id : hash( 'sha256', (string) wp_json_encode( $piece ) );
                $graph[ $key ] = $piece;
            }
        }
        $payload = array(
            '@context' => 'https://schema.org',
            '@graph' => array_values( $graph ),
        );
        $pagination = array( 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
        wp_cache_set( $cacheKey, array( 'payload' => $payload, 'pagination' => $pagination ), self::CACHE_GROUP, 6 * HOUR_IN_SECONDS );
        $response = new \WP_REST_Response( $payload, 200 );
        $response->header( 'X-YSEO-Cache', 'MISS' );
        $this->addPaginationHeaders( $response, $postType, $page, $pagination, $lastmod );
        return $response;
    }

    /** Keep HTTP metadata identical on fresh and cached JSON-LD responses. */
    private function addPaginationHeaders( \WP_REST_Response $response, string $postType, int $page, array $pagination, string $lastmod ): void {
        $total = max( 0, (int) ( $pagination['total'] ?? 0 ) );
        $pages = max( 0, (int) ( $pagination['pages'] ?? 0 ) );
        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) $pages );
        if ( $page < $pages ) { $response->link_header( 'next', rest_url( self::REST_NS . '/schema-aggregator/' . $postType . '/' . ( $page + 1 ) ) ); }
        if ( $page > 1 && $page <= $pages ) { $response->link_header( 'prev', rest_url( self::REST_NS . '/schema-aggregator/' . $postType . '/' . ( $page - 1 ) ) ); }
        $response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=600' );
        if ( '' !== $lastmod ) { $response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', strtotime( $lastmod . ' UTC' ) ) . ' GMT' ); }
    }

    /** Meta hooks pass the metadata row ID first, followed by the post ID. */
    public function invalidateForMeta( $metaId, int $postId ): void {
        unset( $metaId );
        // Draft/private metadata updates cannot appear in the public Schema graph.
        // save_post handles the later transition to or from published status.
        if ( 'publish' !== get_post_status( $postId ) ) { return; }
        $this->invalidateForPost( $postId );
    }

    public function invalidateForPost( int $postId ): void {
        // deleted_post fires after the row can no longer be retrieved; do not skip
        // invalidation when get_post_type() can no longer identify the deleted post.
        unset( $postId );
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) && function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( self::CACHE_GROUP );
            return;
        }
        // A group flush is unavailable: rotate the namespace so a saved, deleted,
        // unpublished or password-protected post cannot remain in a public response.
        update_option( 'yseo_schema_cache_generation', wp_generate_uuid4(), false );
    }

    private function versionedCacheKey( string $key ): string {
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) { return $key; }
        return (string) get_option( 'yseo_schema_cache_generation', '0' ) . ':' . $key;
    }

    /** @return array<string,mixed> */
    private function mapDocument(): array {
        $items = array();
        foreach ( $this->postTypes() as $postType ) {
            $items[] = array(
                'postType' => $postType,
                'endpoint' => rest_url( self::REST_NS . '/schema-aggregator/' . $postType ),
                'lastmod' => $this->freshness->postTypeArchiveLastmod( $postType ),
            );
        }
        return array(
            'schema' => 'yablonsky.seo-schema-map/v1',
            'canonical' => home_url( '/schemamap.xml' ),
            'items' => $items,
        );
    }

    private function buildMapXml(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ( $this->postTypes() as $postType ) {
            $url = rest_url( self::REST_NS . '/schema-aggregator/' . $postType );
            $lastmod = $this->freshness->postTypeArchiveLastmod( $postType );
            $xml .= '  <url><loc>' . esc_url( $url ) . '</loc>';
            if ( '' !== $lastmod ) { $xml .= '<lastmod>' . esc_html( mysql2date( 'c', $lastmod, false ) ) . '</lastmod>'; }
            $xml .= "</url>\n";
        }
        return $xml . "</urlset>\n";
    }

    /** @return array<int,string> */
    private function postTypes(): array {
        $out = array();
        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
            if ( 'attachment' === $name || ! is_object( $object ) || ! empty( $object->exclude_from_search ) ) { continue; }
            $out[] = sanitize_key( (string) $name );
        }
        sort( $out, SORT_STRING );
        return $out;
    }

    private function sendXml( string $xml ): void {
        SitemapPresentation::sendXmlHeaders();
        status_header( 200 );
        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML fragments are escaped during deterministic construction.
    }
}
