<?php
namespace Yablonsky\PerformanceSEO\SEO\Headless;

use Yablonsky\PerformanceSEO\Resource\ResourceIdentity;
use Yablonsky\PerformanceSEO\SEO\Developer\ResourceSurfaceResolver;
use Yablonsky\PerformanceSEO\SEO\Developer\DeveloperApiContract;
use Yablonsky\PerformanceSEO\SEO\Image\ImageMetadataResolver;
use Yablonsky\PerformanceSEO\SEO\PublicSeoController;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPresentation;
use Yablonsky\PerformanceSEO\Visibility\CrawlerPermissionPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public, read-only SEO projection for decoupled/headless frontends. */
final class HeadlessSeoController {
    private const REST_NS = 'yablonsky-seo/v1';
    /** @var PublicSeoController */ private $seo;
    /** @var ResourceSurfaceResolver */ private $surfaces;

    public function __construct() {
        $this->seo = new PublicSeoController();
        $this->surfaces = new ResourceSurfaceResolver();
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
        add_action( 'rest_api_init', array( $this, 'registerCoreFields' ) );
    }

    public function registerRoutes(): void {
        register_rest_route(
            self::REST_NS,
            '/head',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'getHeadByUrl' ),
                'permission_callback' => '__return_true',
                'args' => array(
                    'url' => array( 'required' => true, 'sanitize_callback' => 'esc_url_raw' ),
                ),
            )
        );
        register_rest_route(
            self::REST_NS,
            '/surface',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'getSurface' ),
                'permission_callback' => '__return_true',
                'args' => array(
                    'type' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
                    'id' => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ),
                    'subtype' => array( 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => '' ),
                ),
            )
        );
        register_rest_route(
            self::REST_NS,
            '/capabilities',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'getCapabilities' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            self::REST_NS,
            '/health',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'getHealth' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            self::REST_NS,
            '/contract',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'getContract' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            self::REST_NS,
            '/machine-policy',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'getMachinePolicy' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function registerCoreFields(): void {
        $types = array();
        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
            if ( 'attachment' === $name || ! is_object( $object ) || empty( $object->show_in_rest ) ) { continue; }
            $types[] = (string) $name;
        }
        if ( empty( $types ) ) { return; }
        register_rest_field(
            $types,
            'yseo_head_json',
            array(
                'get_callback' => array( $this, 'coreRestJson' ),
                'schema' => array( 'description' => 'Yablonsky SEO machine-readable metadata projection.', 'type' => 'object', 'context' => array( 'view' ), 'readonly' => true ),
            )
        );
        register_rest_field(
            $types,
            'yseo_head',
            array(
                'get_callback' => array( $this, 'coreRestHtml' ),
                'schema' => array( 'description' => 'Yablonsky SEO escaped head markup projection.', 'type' => 'string', 'context' => array( 'view' ), 'readonly' => true ),
            )
        );
    }

    public function getHeadByUrl( \WP_REST_Request $request ): \WP_REST_Response {
        $rawUrl = trim( (string) $request->get_param( 'url' ) );
        if ( strlen( $rawUrl ) > 2048 ) {
            return $this->errorResponse( 400, 'yseo_url_too_long', __( 'The requested URL exceeds the bounded lookup length.', 'yablonsky-seo' ) );
        }
        $url = esc_url_raw( preg_replace( '/#.*$/', '', $rawUrl ) ?: '', array( 'http', 'https' ) );
        if ( '' === $url || ! $this->sameOrigin( $url ) || ! $this->isPublicLookupPath( $url ) ) {
            return $this->errorResponse( 404, 'yseo_unknown_url', __( 'The requested same-origin public URL could not be resolved.', 'yablonsky-seo' ) );
        }
        $resource = $this->resourceFromUrl( $url );
        if ( null === $resource || ! $this->publicProjectionAllowed( $resource ) ) { return $this->errorResponse( 404, 'yseo_unknown_url', __( 'The requested same-origin public URL could not be resolved.', 'yablonsky-seo' ) ); }
        $projection = $this->seo->projectResource( $resource );
        return $this->projectionResponse( $projection, $request, 'head_by_url' );
    }

    public function getMachinePolicy( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $payload = array(
            'schema' => 'yablonsky.seo.machine-use-policy.v1',
            'api_version' => 1,
            'policy' => CrawlerPermissionPolicy::fromSettings( $settings )->all(),
            'external_authority_note' => 'External provider consoles, CDN/WAF rules and robots/meta directives remain independent authorities.',
        );
        return $this->cacheablePayloadResponse( $payload, $request, 'public, max-age=300, stale-while-revalidate=300' );
    }

    public function getContract( \WP_REST_Request $request ): \WP_REST_Response {
        $payload = DeveloperApiContract::manifest();
        $payload['contract_sha256'] = DeveloperApiContract::fingerprint();
        return $this->cacheablePayloadResponse( $payload, $request, 'public, max-age=3600, stale-while-revalidate=86400' );
    }

    public function getHealth(): \WP_REST_Response {
        $authority = sanitize_key( (string) get_option( 'yps_output_authority', 'active' ) );
        $payload = array(
            'status' => 'ok',
            'api_version' => 1,
            'plugin_version' => defined( 'YSEO_VERSION' ) ? (string) YSEO_VERSION : '',
            'read_only' => true,
            'public_read_authentication' => 'none',
            'output_authority' => in_array( $authority, array( 'active', 'shadow' ), true ) ? $authority : 'shadow',
            'contract_sha256' => DeveloperApiContract::fingerprint(),
            'sitemap_transport' => SitemapPresentation::transportPolicy(),
        );
        $response = new \WP_REST_Response( $payload, 200 );
        return $this->withApiHeaders( $response, 'no-cache, must-revalidate' );
    }

    public function getCapabilities( \WP_REST_Request $request ): \WP_REST_Response {
        $payload = DeveloperApiContract::manifest();
        $payload['plugin'] = 'Yablonsky SEO';
        $payload['plugin_version'] = defined( 'YSEO_VERSION' ) ? (string) YSEO_VERSION : '';
        $payload['api_namespace'] = self::REST_NS;
        $payload['contract_sha256'] = DeveloperApiContract::fingerprint();
        $payload['runtime_endpoints'] = array(
                'head_by_url' => rest_url( self::REST_NS . '/head' ),
                'typed_surface' => rest_url( self::REST_NS . '/surface' ),
                'capabilities' => rest_url( self::REST_NS . '/capabilities' ),
                'health' => rest_url( self::REST_NS . '/health' ),
                'contract' => rest_url( self::REST_NS . '/contract' ),
                'machine_policy' => rest_url( self::REST_NS . '/machine-policy' ),
                'schema_map' => home_url( '/schemamap.xml' ),
            );
        $payload['_links'] = array(
            'self' => rest_url( self::REST_NS . '/capabilities' ),
            'health' => rest_url( self::REST_NS . '/health' ),
            'contract' => rest_url( self::REST_NS . '/contract' ),
            'machine_policy' => rest_url( self::REST_NS . '/machine-policy' ),
            'schema_map' => home_url( '/schemamap.xml' ),
        );
        /* Legacy flat fields remain for v1 consumers. */
        $payload += array(
            'read_only' => true,
            'public_read_authentication' => 'none',
            'native_rest_fields' => array( 'yseo_head', 'yseo_head_json' ),
            'php_surface' => 'yablonsky_seo()->meta',
        );
        $encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $etag = '"' . hash( 'sha256', is_string( $encoded ) ? $encoded : '' ) . '"';
        if ( $this->etagMatches( $request, $etag ) ) {
            $response = new \WP_REST_Response( null, 304 );
            $response->header( 'ETag', $etag );
            return $this->withApiHeaders( $response, 'public, max-age=300, stale-while-revalidate=300' );
        }
        $response = new \WP_REST_Response( $payload, 200 );
        $response->header( 'ETag', $etag );
        return $this->withApiHeaders( $response, 'public, max-age=300, stale-while-revalidate=300' );
    }

    public function getSurface( \WP_REST_Request $request ): \WP_REST_Response {
        $type = sanitize_key( (string) $request->get_param( 'type' ) );
        $id = absint( $request->get_param( 'id' ) );
        $subtype = sanitize_key( (string) $request->get_param( 'subtype' ) );
        $resource = null;
        if ( 'home' === $type ) { $resource = $this->surfaces->forHome(); }
        elseif ( 'posts_page' === $type ) { $resource = $this->surfaces->forPostsPage(); }
        elseif ( in_array( $type, array( 'post', 'product', 'singular' ), true ) ) { $resource = $this->surfaces->forPost( $id ); }
        elseif ( 'term' === $type && '' !== $subtype ) { $resource = $this->surfaces->forTerm( $subtype, $id ); }
        elseif ( 'author_archive' === $type ) { $resource = $this->surfaces->forAuthor( $id ); }
        elseif ( 'post_type_archive' === $type && '' !== $subtype ) { $resource = $this->surfaces->forPostTypeArchive( $subtype ); }
        if ( null === $resource || ! $this->publicProjectionAllowed( $resource ) ) { return $this->errorResponse( 404, 'yseo_unknown_resource', __( 'The requested public resource could not be resolved.', 'yablonsky-seo' ) ); }
        $projection = $this->seo->projectResource( $resource );
        return $this->projectionResponse( $projection, $request, 'typed_surface' );
    }

    /** @param array<string,mixed> $payload */
    private function cacheablePayloadResponse( array $payload, \WP_REST_Request $request, string $cacheControl ): \WP_REST_Response {
        $encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $etag = '"' . hash( 'sha256', is_string( $encoded ) ? $encoded : '' ) . '"';
        if ( $this->etagMatches( $request, $etag ) ) {
            $response = new \WP_REST_Response( null, 304 );
            $response->header( 'ETag', $etag );
            return $this->withApiHeaders( $response, $cacheControl );
        }
        $response = new \WP_REST_Response( $payload, 200 );
        $response->header( 'ETag', $etag );
        return $this->withApiHeaders( $response, $cacheControl );
    }

    private function etagMatches( \WP_REST_Request $request, string $etag ): bool {
        $candidate = trim( (string) $request->get_header( 'if-none-match' ) );
        return '' !== $candidate && hash_equals( $etag, $candidate );
    }

    private function withApiHeaders( \WP_REST_Response $response, string $cacheControl ): \WP_REST_Response {
        if ( '' !== $cacheControl ) { $response->header( 'Cache-Control', $cacheControl ); }
        $response->header( 'X-YSEO-API-Version', '1' );
        $response->header( 'X-YSEO-Contract-SHA256', DeveloperApiContract::fingerprint() );
        $response->header( 'Vary', 'Accept' );
        return $response;
    }

    private function errorResponse( int $status, string $code, string $message ): \WP_REST_Response {
        $response = new \WP_REST_Response( array(
            'status' => $status,
            'code' => sanitize_key( $code ),
            'message' => sanitize_text_field( $message ),
            '_meta' => array( 'api_version' => 1, 'contract_sha256' => DeveloperApiContract::fingerprint(), 'read_only' => true ),
        ), $status );
        return $this->withApiHeaders( $response, 'no-store' );
    }

    /** @param array<string,mixed> $projection */
    private function projectionResponse( array $projection, \WP_REST_Request $request, string $endpoint ): \WP_REST_Response {
        $encoded = wp_json_encode( $projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $etag = '"' . hash( 'sha256', is_string( $encoded ) ? $encoded : '' ) . '"';
        $ifNoneMatch = trim( (string) $request->get_header( 'if-none-match' ) );
        if ( '' !== $ifNoneMatch && hash_equals( $etag, $ifNoneMatch ) ) {
            $response = new \WP_REST_Response( null, 304 );
            $response->header( 'ETag', $etag );
            return $this->withApiHeaders( $response, 'public, max-age=300, stale-while-revalidate=300' );
        }
        $response = new \WP_REST_Response( array(
            'status' => 200,
            'html' => $this->html( $projection ),
            'json' => $projection,
            '_meta' => array(
                'api_version' => 1,
                'contract_sha256' => DeveloperApiContract::fingerprint(),
                'read_only' => true,
                'endpoint' => sanitize_key( $endpoint ),
            ),
        ), 200 );
        $response->header( 'ETag', $etag );
        return $this->withApiHeaders( $response, 'public, max-age=300, stale-while-revalidate=300' );
    }

    /**
     * A configured front page or posts page may still be private or password-protected.
     * Those special surfaces bypass the normal published-post resolver, so the
     * public Headless API must enforce the same privacy boundary explicitly.
     */
    private function publicProjectionAllowed( ResourceIdentity $resource ): bool {
        if ( ! in_array( $resource->type(), array( 'home', 'posts_page' ), true ) ) { return true; }
        $postId = $resource->objectId();
        if ( $postId <= 0 ) { return true; }
        $post = get_post( $postId );
        return $post instanceof \WP_Post && 'publish' === $post->post_status && '' === (string) $post->post_password;
    }

    /** @param array<string,mixed> $object */
    public function coreRestJson( array $object ): array {
        $resource = $this->resourceFromPostId( (int) ( $object['id'] ?? 0 ) );
        return null === $resource ? array() : $this->seo->projectResource( $resource );
    }

    /** @param array<string,mixed> $object */
    public function coreRestHtml( array $object ): string {
        $resource = $this->resourceFromPostId( (int) ( $object['id'] ?? 0 ) );
        return null === $resource ? '' : $this->html( $this->seo->projectResource( $resource ) );
    }

    private function resourceFromUrl( string $url ): ?ResourceIdentity {
        return $this->surfaces->forUrl( $url );
    }

    private function resourceFromPostId( int $postId ): ?ResourceIdentity {
        return $this->surfaces->forPost( $postId );
    }

    /** @param array<string,mixed> $projection */
    private function html( array $projection ): string {
        $lines = array();
        $title = (string) ( $projection['title'] ?? '' );
        if ( '' !== $title ) { $lines[] = '<title>' . esc_html( $title ) . '</title>'; }
        $description = (string) ( $projection['description'] ?? '' );
        if ( '' !== $description ) { $lines[] = '<meta name="description" content="' . esc_attr( $description ) . '" />'; }
        $canonical = (string) ( $projection['canonical'] ?? '' );
        if ( '' !== $canonical ) { $lines[] = '<link rel="canonical" href="' . esc_url( $canonical ) . '" />'; }

        $robots = isset( $projection['robots'] ) && is_array( $projection['robots'] ) ? $projection['robots'] : array();
        $directives = array( ! empty( $robots['index'] ) ? 'index' : 'noindex', ! empty( $robots['follow'] ) ? 'follow' : 'nofollow' );
        foreach ( (array) ( $robots['advanced'] ?? array() ) as $directive => $value ) {
            $directive = sanitize_key( (string) $directive );
            if ( true === $value ) { $directives[] = $directive; }
            elseif ( is_scalar( $value ) && '' !== (string) $value ) { $directives[] = $directive . ':' . sanitize_text_field( (string) $value ); }
        }
        $lines[] = '<meta name="robots" content="' . esc_attr( implode( ',', array_values( array_unique( $directives ) ) ) ) . '" />';

        $og = isset( $projection['open_graph'] ) && is_array( $projection['open_graph'] ) ? $projection['open_graph'] : array();
        foreach ( array( 'type', 'title', 'description', 'image' ) as $key ) {
            $value = (string) ( $og[ $key ] ?? '' );
            if ( '' !== $value ) { $lines[] = '<meta property="og:' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '" />'; }
        }
        if ( ! empty( $og['image'] ) ) {
            $ogMeta = ( new ImageMetadataResolver() )->resolve( (string) $og['image'] );
            if ( ! empty( $ogMeta['width'] ) ) { $lines[] = '<meta property="og:image:width" content="' . (int) $ogMeta['width'] . '" />'; }
            if ( ! empty( $ogMeta['height'] ) ) { $lines[] = '<meta property="og:image:height" content="' . (int) $ogMeta['height'] . '" />'; }
            if ( ! empty( $ogMeta['mime_type'] ) ) { $lines[] = '<meta property="og:image:type" content="' . esc_attr( (string) $ogMeta['mime_type'] ) . '" />'; }
            if ( ! empty( $ogMeta['alt'] ) ) { $lines[] = '<meta property="og:image:alt" content="' . esc_attr( (string) $ogMeta['alt'] ) . '" />'; }
        }
        $twitter = isset( $projection['twitter'] ) && is_array( $projection['twitter'] ) ? $projection['twitter'] : array();
        if ( ! empty( $twitter['image'] ) ) { $lines[] = '<meta name="twitter:card" content="summary_large_image" />'; }
        foreach ( array( 'title', 'description', 'image' ) as $key ) {
            $value = (string) ( $twitter[ $key ] ?? '' );
            if ( '' !== $value ) { $lines[] = '<meta name="twitter:' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '" />'; }
        }
        if ( ! empty( $twitter['image'] ) ) {
            $twitterMeta = ( new ImageMetadataResolver() )->resolve( (string) $twitter['image'] );
            if ( ! empty( $twitterMeta['alt'] ) ) { $lines[] = '<meta name="twitter:image:alt" content="' . esc_attr( (string) $twitterMeta['alt'] ) . '" />'; }
        }
        foreach ( (array) ( $projection['alternates'] ?? array() ) as $alternate ) {
            if ( ! is_array( $alternate ) ) { continue; }
            $language = sanitize_text_field( (string) ( $alternate['language_key'] ?? '' ) );
            $url = esc_url( (string) ( $alternate['url'] ?? '' ) );
            if ( '' !== $language && '' !== $url ) { $lines[] = '<link rel="alternate" hreflang="' . esc_attr( $language ) . '" href="' . $url . '" />'; }
        }
        $schema = isset( $projection['schema'] ) && is_array( $projection['schema'] ) ? $projection['schema'] : array();
        if ( ! empty( $schema['@graph'] ) ) {
            $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            if ( is_string( $json ) ) { $lines[] = '<script type="application/ld+json">' . $json . '</script>'; }
        }
        return implode( "\n", $lines ) . "\n";
    }

    private function isPublicLookupPath( string $url ): bool {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) { return false; }
        $path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
        $homeParts = wp_parse_url( home_url( '/' ) );
        $homePath = is_array( $homeParts ) ? '/' . trim( (string) ( $homeParts['path'] ?? '/' ), '/' ) : '/';
        $base = '/' === $homePath ? '' : rtrim( $homePath, '/' );
        $siteParts = wp_parse_url( site_url( '/' ) );
        $sitePath = is_array( $siteParts ) ? '/' . trim( (string) ( $siteParts['path'] ?? '/' ), '/' ) : '/';
        $siteBase = '/' === $sitePath ? '' : rtrim( $sitePath, '/' );
        foreach ( array_unique( array( '', $base, $siteBase ) ) as $prefix ) {
            foreach ( array( '/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json' ) as $blocked ) {
                $restricted = $prefix . $blocked;
                if ( $path === $restricted || 0 === strpos( $path, $restricted . '/' ) ) { return false; }
            }
        }
        return true;
    }

    private function sameOrigin( string $url ): bool {
        $home = wp_parse_url( home_url( '/' ) );
        $target = wp_parse_url( $url );
        if ( ! is_array( $home ) || ! is_array( $target ) ) { return false; }
        $homeHost = strtolower( (string) ( $home['host'] ?? '' ) );
        $targetHost = strtolower( (string) ( $target['host'] ?? '' ) );
        $homeScheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
        $targetScheme = strtolower( (string) ( $target['scheme'] ?? '' ) );
        if ( isset( $target['user'] ) || isset( $target['pass'] ) ) { return false; }
        $homePort = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $homeScheme ? 443 : 80 );
        $targetPort = isset( $target['port'] ) ? (int) $target['port'] : ( 'https' === $targetScheme ? 443 : 80 );
        return '' !== $homeHost && $homeHost === $targetHost && $homeScheme === $targetScheme && $homePort === $targetPort;
    }
}
