<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * User-initiated loopback probe for the real sitemap HTTP transport surface.
 *
 * Stores only bounded response metadata and a body-shape boolean. Full sitemap
 * bodies, cookies and arbitrary response headers are never persisted.
 */
final class SitemapTransportProbe {
    private const OPTION = 'yseo_sitemap_transport_probe_v1';
    private const HISTORY_OPTION = 'yseo_sitemap_transport_probe_history_v1';
    private const HISTORY_LIMIT = 5;
    private const MARKER = 'xml-v8';

    /** @return array<string,mixed> */
    public function run(): array {
        $paths = array( '/sitemap_index.xml', '/page-sitemap.xml', '/post-sitemap.xml', '/product-sitemap.xml' );
        $controls = array( '/category-sitemap.xml', '/post_tag-sitemap.xml', '/product_cat-sitemap.xml', '/product_tag-sitemap.xml' );
        $endpoints = array();
        $statuses = array();
        foreach ( $paths as $path ) {
            $cleanUrl = home_url( $path );
            $bypassUrl = add_query_arg(
                array( 'yseo_transport_probe' => (string) time() . '-' . (string) wp_rand( 1000, 9999 ) ),
                $cleanUrl
            );
            $clean = $this->inspect( $cleanUrl );
            $bypass = $this->inspect( $bypassUrl );
            if ( 404 === (int) ( $clean['http_status'] ?? 0 ) && 404 === (int) ( $bypass['http_status'] ?? 0 ) ) {
                $endpointStatus = 'not_available';
            } else {
                $endpointStatus = $this->classify( $clean, $bypass );
                $statuses[] = $endpointStatus;
            }
            $endpoints[ $path ] = array( 'status' => $endpointStatus, 'clean' => $clean, 'cache_bypass' => $bypass );
        }

        // Compare the affected index/post-type surfaces with one healthy taxonomy
        // control through the exact same HTTP client. This distinguishes payload
        // construction from a pre-bootstrap/cache delivery divergence.
        $selectedControlPath = '';
        foreach ( $controls as $controlPath ) {
            $clean = $this->inspect( home_url( $controlPath ) );
            $bypass = $this->inspect( add_query_arg( array( 'yseo_transport_probe' => (string) time() . '-' . (string) wp_rand( 1000, 9999 ) ), home_url( $controlPath ) ) );
            if ( 404 === (int) ( $clean['http_status'] ?? 0 ) && 404 === (int) ( $bypass['http_status'] ?? 0 ) ) { continue; }
            $endpointStatus = $this->classify( $clean, $bypass );
            $endpoints[ $controlPath ] = array( 'status' => $endpointStatus, 'clean' => $clean, 'cache_bypass' => $bypass, 'control' => true );
            $selectedControlPath = $controlPath;
            break;
        }

        $browserLike = array();
        foreach ( array_filter( array( '/post-sitemap.xml', $selectedControlPath ) ) as $comparisonPath ) {
            if ( isset( $endpoints[ $comparisonPath ] ) && 'not_available' !== (string) ( $endpoints[ $comparisonPath ]['status'] ?? '' ) ) {
                $browserLike[ $comparisonPath ] = $this->inspect( home_url( $comparisonPath ), 'browser' );
            }
        }

        $status = $this->overallStatus( $statuses );
        $index = isset( $endpoints['/sitemap_index.xml'] ) && is_array( $endpoints['/sitemap_index.xml'] ) ? $endpoints['/sitemap_index.xml'] : array();
        $result = array(
            'schema'       => 2,
            'checked_at'   => time(),
            'status'       => $status,
            'clean'        => isset( $index['clean'] ) && is_array( $index['clean'] ) ? $index['clean'] : array(),
            'cache_bypass' => isset( $index['cache_bypass'] ) && is_array( $index['cache_bypass'] ) ? $index['cache_bypass'] : array(),
            'endpoints'    => $endpoints,
            'browser_like'  => $browserLike,
        );
        update_option( self::OPTION, $result, false );
        $history = get_option( self::HISTORY_OPTION, array() );
        $history = is_array( $history ) ? $history : array();
        array_unshift( $history, $result );
        $history = array_slice( $history, 0, self::HISTORY_LIMIT );
        update_option( self::HISTORY_OPTION, $history, false );
        return $result;
    }

    /** @param array<int,string> $statuses */
    private function overallStatus( array $statuses ): string {
        if ( empty( $statuses ) ) { return 'attention'; }
        $priority = array( 'request_error', 'plugin_not_reached', 'downstream_header_rewrite', 'invalid_xml_transport', 'stale_clean_cache', 'attention', 'pass' );
        foreach ( $priority as $candidate ) {
            if ( in_array( $candidate, $statuses, true ) ) { return $candidate; }
        }
        return 'attention';
    }

    /** @return array<string,mixed> */
    public function lastResult(): array {
        $result = get_option( self::OPTION, array() );
        return is_array( $result ) ? $result : array();
    }

    /** @return array<int,array<string,mixed>> */
    public function history(): array {
        $history = get_option( self::HISTORY_OPTION, array() );
        if ( ! is_array( $history ) ) { return array(); }
        return array_slice( array_values( array_filter( $history, 'is_array' ) ), 0, self::HISTORY_LIMIT );
    }

    /** @param array<string,mixed> $clean @param array<string,mixed> $bypass */
    private function classify( array $clean, array $bypass ): string {
        if ( ! empty( $clean['pass'] ) ) { return 'pass'; }
        if ( ! empty( $bypass['pass'] ) ) { return 'stale_clean_cache'; }
        if ( ! empty( $bypass['request_error'] ) || ! empty( $clean['request_error'] ) ) { return 'request_error'; }
        if ( self::MARKER !== (string) ( $bypass['transport_marker'] ?? '' ) ) { return 'plugin_not_reached'; }
        if ( empty( $bypass['xml_mime'] ) ) { return 'downstream_header_rewrite'; }
        if ( empty( $bypass['xml_body'] ) ) { return 'invalid_xml_transport'; }
        return 'attention';
    }

    /** @return array<string,mixed> */
    private function inspect( string $url, string $profile = 'probe' ): array {
        $headers = array(
            'Accept'        => 'application/xml,text/xml;q=0.9,*/*;q=0.1',
            'Cache-Control' => 'no-cache',
            'Pragma'        => 'no-cache',
            'User-Agent'    => 'YablonskySEO-SitemapTransportProbe/' . YPS_VERSION,
        );
        if ( 'browser' === $profile ) {
            $headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
            $headers['User-Agent'] = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152 Safari/537.36 YSEO-Browser-Differential/' . YPS_VERSION;
        }
        $response = wp_remote_get(
            $url,
            array(
                'timeout'             => 8,
                'redirection'         => 3,
                'reject_unsafe_urls'  => true,
                'limit_response_size' => 16384,
                'headers'             => $headers,
            )
        );
        if ( is_wp_error( $response ) ) {
            return array(
                'pass'          => false,
                'request_error' => sanitize_text_field( $response->get_error_code() ),
                'http_status'   => 0,
                'content_type'  => '',
                'transport_marker' => '',
                'xml_mime'      => false,
                'xml_body'      => false,
                'cache'         => array(),
                'request_profile' => sanitize_key( $profile ),
                'prefix_bytes' => 0,
                'prefix_sha256' => '',
                'first_bytes_hex' => '',
                'leading_ws_bytes' => 0,
                'utf8_bom' => false,
                'pure_xml' => false,
                'stylesheet_pi' => false,
                'stylesheet_css' => false,
                'stylesheet_xsl' => false,
                'xhtml_elements' => false,
                'native_tree_candidate' => false,
            );
        }

        $contentType = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
        $marker = trim( (string) wp_remote_retrieve_header( $response, 'x-yseo-sitemap-transport' ) );
        $rawBody = (string) wp_remote_retrieve_body( $response );
        $body = ltrim( $rawBody );
        $xmlMime = false !== strpos( $contentType, 'application/xml' ) || false !== strpos( $contentType, 'text/xml' );
        $xmlBody = 0 === strpos( $body, '<?xml' ) || 0 === strpos( $body, '<sitemapindex' ) || 0 === strpos( $body, '<urlset' );
        $status = (int) wp_remote_retrieve_response_code( $response );
        $cache = array();
        foreach ( array( 'cf-cache-status', 'age', 'x-cache', 'x-w3-total-cache', 'x-litespeed-cache', 'cdn-cache-control', 'x-yperf-cache', 'x-yperf-cache-version', 'x-yseo-sitemap-route-class', 'x-yseo-sitemap-stray-bytes', 'content-encoding', 'content-length', 'vary' ) as $header ) {
            $value = trim( (string) wp_remote_retrieve_header( $response, $header ) );
            if ( '' !== $value ) { $cache[ $header ] = sanitize_text_field( substr( $value, 0, 120 ) ); }
        }

        $pureXml = false === strpos( substr( $rawBody, 0, 2048 ), '<?xml-stylesheet' ) && false === stripos( substr( $rawBody, 0, 4096 ), '<html');
        $xhtmlElements = 1 === preg_match( '/<xhtml:[a-z0-9_-]+\b/i', $rawBody );
        $svgElements = 1 === preg_match( '/<svg(?::[a-z0-9_-]+)?\b/i', $rawBody );
        $nativeTreeCandidate = $pureXml && ! $xhtmlElements && ! $svgElements;

        return array(
            'pass'             => 200 === $status && $xmlMime && $xmlBody && self::MARKER === $marker && $nativeTreeCandidate,
            'request_error'    => '',
            'http_status'      => $status,
            'content_type'     => sanitize_text_field( substr( $contentType, 0, 160 ) ),
            'transport_marker' => sanitize_text_field( substr( $marker, 0, 80 ) ),
            'xml_mime'         => $xmlMime,
            'xml_body'         => $xmlBody,
            'cache'            => $cache,
            'request_profile'  => sanitize_key( $profile ),
            'prefix_bytes'     => strlen( $rawBody ),
            'prefix_sha256'    => hash( 'sha256', $rawBody ),
            'first_bytes_hex'  => bin2hex( substr( $rawBody, 0, 16 ) ),
            'leading_ws_bytes' => max( 0, strlen( $rawBody ) - strlen( $body ) ),
            'utf8_bom'         => 0 === strncmp( $rawBody, "\xEF\xBB\xBF", 3 ),
            'pure_xml'         => $pureXml,
            'stylesheet_pi'    => false !== strpos( substr( $rawBody, 0, 1024 ), '<?xml-stylesheet' ),
            'stylesheet_css'   => 1 === preg_match( '/<\?xml-stylesheet\s+[^?]*type=["\']text\/css["\'][^?]*\?>/i', substr( $rawBody, 0, 2048 ) ),
            'stylesheet_xsl'   => 1 === preg_match( '/<\?xml-stylesheet\s+[^?]*(?:text\/xsl|application\/xslt\+xml|\.xsl(?:[?"\']|\s))[^?]*\?>/i', substr( $rawBody, 0, 2048 ) ),
            'xhtml_elements'    => $xhtmlElements,
            'native_tree_candidate' => $nativeTreeCandidate,
        );
    }
}
