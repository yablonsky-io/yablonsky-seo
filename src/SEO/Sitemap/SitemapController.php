<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

use Yablonsky\PerformanceSEO\Visibility\AgentResourceLocator;
use Yablonsky\PerformanceSEO\Discovery\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SitemapController {
    private const CACHE_GROUP = 'yablonsky-seo-sitemaps';
    private const TIMING_SAMPLE_TRANSIENT = 'yps_sitemap_timing_sample_lock';
    /** @var SitemapRepository */ private $repository;
    /** @var float */ private $startedAt = 0.0;
    /** @var bool */ private $missingSitemap = false;

    public function __construct( SitemapRepository $repository ) { $this->repository = $repository; }

    public function register(): void {
        // Serve sitemap XML before the normal WordPress send_headers/theme pipeline.
        // This prevents late content-type overrides from turning valid XML into HTML-rendered text.
        add_action( 'parse_request', array( $this, 'route' ), -1000, 0 );
        // Compatibility fallback for unusual front-controller stacks that bypass parse_request.
        add_action( 'template_redirect', array( $this, 'route' ), -1000 );
        add_filter( 'robots_txt', array( $this, 'robotsTxt' ), 30, 2 );
        add_filter( 'redirect_canonical', array( $this, 'preventMissingSitemapRedirect' ), 10, 2 );
    }

    public function route(): void {
        $this->startedAt = microtime( true );
        $requestUri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $path = trim( (string) wp_parse_url( is_string( $requestUri ) ? $requestUri : '', PHP_URL_PATH ), '/' );
        if ( ! in_array( $path, array( 'sitemap_index.xml', 'sitemap.xml', 'wp-sitemap.xml' ), true ) && ! preg_match( '/^([a-z0-9_-]+)-sitemap([0-9]*)\.xml$/', $path, $m ) ) { return; }

        if ( ! $this->repository->isReady() ) {
            if ( 'wp-sitemap.xml' === $path ) { return; }
            wp_safe_redirect( home_url( '/wp-sitemap.xml' ), 302, 'Yablonsky SEO sitemap warm-up' ); exit;
        }

        if ( 'sitemap.xml' === $path || 'wp-sitemap.xml' === $path ) { wp_safe_redirect( home_url( '/sitemap_index.xml' ), 301, 'Yablonsky SEO' ); exit; }
        if ( 'sitemap_index.xml' === $path ) { $this->renderIndex(); exit; }
        if ( ! empty( $m[1] ) ) {
            if ( $this->renderChild( sanitize_key( $m[1] ), empty( $m[2] ) ? 1 : (int) $m[2] ) ) { exit; }
            $this->missingSitemap = true;
            // parse_request runs before WordPress sends headers. Mark this route now so
            // the global sitemap transport guard does not force application/xml onto
            // the theme-native HTML 404 that will be rendered later.
            if ( ! defined( 'YSEO_SITEMAP_MISSING_REQUEST' ) ) {
                define( 'YSEO_SITEMAP_MISSING_REQUEST', true );
            }
            if ( 'template_redirect' === current_filter() ) { $this->prepareTheme404(); }
            return;
        }
    }

    public function robotsTxt( string $output, bool $public ): string {
        unset( $public );
        $url = $this->repository->isReady() ? home_url( '/sitemap_index.xml' ) : home_url( '/wp-sitemap.xml' );
        if ( false === strpos( $output, 'Sitemap:' ) ) { $output .= "\nSitemap: " . esc_url_raw( $url ) . "\n"; }
        return $output;
    }

    /** @param string|false $redirectUrl @return string|false */
    public function preventMissingSitemapRedirect( $redirectUrl, string $requestedUrl = '' ) {
        unset( $requestedUrl );
        return $this->missingSitemap ? false : $redirectUrl;
    }

    private function prepareTheme404(): void {
        global $wp_query;
        if ( $wp_query instanceof \WP_Query ) { $wp_query->set_404(); }
        status_header( 404 );
        nocache_headers();
        // A removed *.xml sitemap route deliberately falls through to the site's
        // normal theme 404. Force the representation back to HTML so browsers do
        // not parse the theme's HTML entities (for example &raquo;) as XML.
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ), true );
            header( 'X-YSEO-Sitemap-Route-Class: missing', true );
        }
    }

    private function renderIndex(): void {
        $discovery = new DiscoverySettings();
        $capture = $this->captureXmlBuild(
            function () use ( $discovery ): string {
                $manifest = $this->repository->manifest();
                $routes = $this->routeMap( $manifest );
                $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
                foreach ( $manifest as $row ) {
                    $routeKey = $this->resourceRouteKey( (string) $row['resource_type'], (string) $row['resource_subtype'] );
                    if ( ! isset( $routes['by_resource'][ $routeKey ] ) ) { continue; }
                    $slug = (string) $routes['by_resource'][ $routeKey ];
                    $bucket = (int) $row['bucket_no'];
                    $url = home_url( '/' . $slug . '-sitemap' . ( $bucket > 1 ? $bucket : '' ) . '.xml' );
                    $xml .= '<sitemap><loc>' . SitemapXml::url( $url ) . '</loc>';
                    if ( $discovery->includeLastmod() && ! empty( $row['lastmod'] ) ) { $xml .= '<lastmod>' . SitemapXml::text( mysql2date( 'c', (string) $row['lastmod'], false ) ) . '</lastmod>'; }
                    $xml .= "</sitemap>\n";
                }
                $extraSitemaps = apply_filters( 'yablonsky_seo_sitemap_index_extra_v1', array() );
                if ( is_array( $extraSitemaps ) ) {
                    $seen = array();
                    foreach ( $extraSitemaps as $extra ) {
                        if ( ! is_array( $extra ) ) { continue; }
                        $extraUrl = isset( $extra['loc'] ) ? esc_url_raw( (string) $extra['loc'], array( 'http', 'https' ) ) : '';
                        if ( '' === $extraUrl || isset( $seen[ $extraUrl ] ) ) { continue; }
                        $seen[ $extraUrl ] = true;
                        $xml .= '<sitemap><loc>' . SitemapXml::url( $extraUrl ) . '</loc>';
                        $extraLastmod = isset( $extra['lastmod'] ) ? trim( (string) $extra['lastmod'] ) : '';
                        if ( '' !== $extraLastmod ) { $xml .= '<lastmod>' . SitemapXml::text( $extraLastmod ) . '</lastmod>'; }
                        $xml .= "</sitemap>\n";
                    }
                }
                return $xml . "</sitemapindex>\n";
            }
        );
        $this->sendXmlDocument( (string) $capture['xml'], 'index', (int) $capture['stray_bytes'] );
    }

    private function renderChild( string $slug, int $bucket ): bool {
        $manifest = $this->repository->manifest();
        $routes = $this->routeMap( $manifest );
        if ( ! isset( $routes['by_slug'][ $slug ] ) ) { return false; }
        $route = $routes['by_slug'][ $slug ];
        $type = (string) $route['type'];
        $subtype = (string) $route['subtype'];
        $cacheKey = $this->repository->childCacheKey( $type, $subtype, $bucket );
        $xml = wp_using_ext_object_cache() ? wp_cache_get( $cacheKey, self::CACHE_GROUP ) : false;
        $strayBytes = 0;
        if ( ! is_string( $xml ) || '' === $xml ) {
            $entries = $this->repository->entries( $type, $subtype, $bucket );
            if ( empty( $entries ) ) { return false; }
            $capture = $this->captureXmlBuild(
                function () use ( $entries, $type, $subtype ): string {
                    return $this->buildChildXml( $entries, $type, $subtype );
                }
            );
            $xml = (string) $capture['xml'];
            $strayBytes = (int) $capture['stray_bytes'];
            if ( wp_using_ext_object_cache() ) { wp_cache_set( $cacheKey, $xml, self::CACHE_GROUP, HOUR_IN_SECONDS ); }
        }

        $this->sendXmlDocument( $xml, 'term' === $type ? 'taxonomy' : sanitize_key( $type ), $strayBytes );
        $this->repository->clearDirty( $type, $subtype, $bucket );
        return true;
    }

    /**
     * Run route-specific providers/filters behind a private output buffer.
     *
     * A sitemap callback is allowed to return data, but it must never become a
     * second response writer. Capturing and discarding accidental callback
     * output keeps the XML declaration at byte zero and prevents an early echo
     * from committing an unrelated Content-Type before YSEO owns the response.
     *
     * @param callable():string $producer
     * @return array{xml:string,stray_bytes:int}
     */
    private function captureXmlBuild( callable $producer ): array {
        $level = ob_get_level();
        ob_start( null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_REMOVABLE );
        $xml = '';
        try {
            $xml = (string) $producer();
        } finally {
            $stray = '';
            while ( ob_get_level() > $level ) {
                $chunk = ob_get_clean();
                if ( is_string( $chunk ) ) { $stray = $chunk . $stray; }
            }
        }
        return array( 'xml' => $xml, 'stray_bytes' => strlen( $stray ) );
    }

    private function sendXmlDocument( string $xml, string $routeClass, int $strayBytes ): void {
        $this->xmlHeaders();
        if ( ! headers_sent() ) {
            header( 'X-YSEO-Sitemap-Route-Class: ' . sanitize_key( $routeClass ), true );
            header( 'X-YSEO-Sitemap-Stray-Bytes: ' . max( 0, $strayBytes ), true );
        }
        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML values are escaped during deterministic construction and callback output is discarded before this boundary.
        $this->recordTiming();
    }

    /** @param array<int,array<string,mixed>> $entries */
    private function buildChildXml( array $entries, string $type, string $subtype ): string {
        $locator = new AgentResourceLocator();
        $agentExtension = $locator->sitemapExtensionEnabled();
        $discovery = new DiscoverySettings();
        $imageMode = $discovery->imageMode();
        $includeImages = 'off' !== $imageMode;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ( $includeImages ) { $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"'; }
        if ( $agentExtension ) { $xml .= ' xmlns:agent="' . esc_attr( AgentResourceLocator::SITEMAP_NAMESPACE ) . '"'; }
        $xml .= '>' . "\n";
        $requests = array();
        foreach ( $entries as $row ) {
            $requests[] = array(
                'resource_type'    => $type,
                'resource_subtype' => $subtype,
                'taxonomy'         => 'term' === $type ? $subtype : '',
                'object_id'        => (int) $row['object_id'],
                'url'              => (string) $row['url'],
            );
        }
        // Hreflang is intentionally not duplicated in XML sitemaps. YSEO already emits
        // the complete alternate-language family in each public page HTML <head>.
        // Keeping sitemap XML URL/lastmod/image-first avoids XHTML namespace elements,
        // reduces payload/provider work, and lets browsers use their native XML tree viewer.
        $bulkImages = $includeImages ? ( new SitemapImageCollector() )->collectBulk( $requests, $imageMode, $discovery->maxImages() ) : array();
        foreach ( $entries as $row ) {
            $xml .= '<url><loc>' . SitemapXml::url( (string) $row['url'] ) . '</loc>';
            if ( $discovery->includeLastmod() && ! empty( $row['lastmod'] ) ) { $xml .= '<lastmod>' . SitemapXml::text( mysql2date( 'c', (string) $row['lastmod'], false ) ) . '</lastmod>'; }
            $imageKey = $type . ':' . (int) $row['object_id'];
            $images = isset( $bulkImages[ $imageKey ] ) && is_array( $bulkImages[ $imageKey ] ) ? $bulkImages[ $imageKey ] : array();
            foreach ( $images as $imageUrl ) {
                $xml .= '<image:image><image:loc>' . SitemapXml::url( (string) $imageUrl ) . '</image:loc></image:image>';
            }

            if ( $agentExtension ) {
                $markdown = $locator->markdownUrl( $type, (int) $row['object_id'], (string) $row['url'] );
                if ( '' !== $markdown ) {
                    $xml .= '<agent:alternate rel="alternate" type="text/markdown" href="' . SitemapXml::url( $markdown ) . '"/>';
                }
                $llms = $locator->describedByUrl( $type, (int) $row['object_id'], (string) $row['url'] );
                if ( '' !== $llms ) {
                    $xml .= '<agent:llms href="' . SitemapXml::url( $llms ) . '"/>';
                }
            }
            $xml .= "</url>\n";
        }
        return $xml . "</urlset>\n";
    }


    /** @param array<int,array<string,mixed>> $manifest @return array{by_resource:array<string,string>,by_slug:array<string,array{type:string,subtype:string}>} */
    private function routeMap( array $manifest ): array {
        return ( new SitemapRouteMap() )->build( $manifest );
    }

    private function resourceRouteKey( string $type, string $subtype ): string { return sanitize_key( $type ) . ':' . sanitize_key( $subtype ); }
    private function xmlHeaders(): void {
        SitemapPresentation::sendXmlHeaders();
        status_header( 200 );
    }

    private function recordTiming(): void {
        if ( $this->startedAt <= 0 || false !== get_transient( self::TIMING_SAMPLE_TRANSIENT ) ) { return; }
        // Sampling avoids a wp_options write on every crawler request while retaining useful operational timing.
        set_transient( self::TIMING_SAMPLE_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
        update_option( 'yps_sitemap_last_generation_ms', (int) round( ( microtime( true ) - $this->startedAt ) * 1000 ), false );
    }
}
