<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared machine-first response headers for YSEO XML surfaces. */
final class SitemapPresentation {
    public static function registerTransportGuards(): void {
        $isXmlRequest = self::isXmlRequest();
        if ( $isXmlRequest && ! defined( 'DONOTCACHEPAGE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache interoperability constant; the name is defined by cache plugins, not by YSEO.
            define( 'DONOTCACHEPAGE', true );
        }
        add_filter( 'wp_headers', array( __CLASS__, 'filterHeaders' ), PHP_INT_MAX );
        add_action( 'send_headers', array( __CLASS__, 'sendXmlHeadersForRequest' ), PHP_INT_MAX );
        if ( $isXmlRequest && ! headers_sent() && function_exists( 'header_register_callback' ) ) {
            header_register_callback( array( __CLASS__, 'sendXmlHeadersForRequest' ) );
        }
    }

    public static function sendXmlHeadersForRequest(): void {
        if ( self::isXmlRequest() ) { self::sendXmlHeaders(); }
    }

    /** @return array<string,mixed> */
    public static function transportPolicy(): array {
        return array(
            'mime' => 'application/xml',
            'charset' => (string) get_option( 'blog_charset' ),
            'nosniff' => true,
            'page_cache_bypass' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
            'xsl_html_presentation' => false,
            'css_xml_presentation' => false,
            'machine_first_pure_xml' => true,
        );
    }

    public static function isXmlRequest(): bool {
        if ( defined( 'YSEO_SITEMAP_MISSING_REQUEST' ) && YSEO_SITEMAP_MISSING_REQUEST ) { return false; }
        $requestUri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW );
        if ( ! is_string( $requestUri ) || '' === $requestUri ) {
            $requestUri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
        }
        $path = trim( (string) wp_parse_url( $requestUri, PHP_URL_PATH ), '/' );
        if ( in_array( $path, array( 'sitemap_index.xml', 'sitemap.xml', 'wp-sitemap.xml', 'schemamap.xml', 'news-sitemap.xml', 'video-sitemap.xml', 'geo-sitemap.xml' ), true ) ) { return true; }
        return 1 === preg_match( '/^[a-z0-9_-]+-sitemap[0-9]*\.xml$/', $path );
    }

    /** @param array<string,string> $headers @return array<string,string> */
    public static function filterHeaders( array $headers ): array {
        if ( ! self::isXmlRequest() ) { return $headers; }
        $headers['Content-Type'] = 'application/xml; charset=' . get_option( 'blog_charset' );
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
        $headers['CDN-Cache-Control'] = 'no-store';
        $headers['Surrogate-Control'] = 'no-store';
        $headers['X-YSEO-Sitemap-Transport'] = 'xml-v8';
        $headers['Pragma'] = 'no-cache';
        $headers['Expires'] = '0';
        unset( $headers['Content-Disposition'] );
        return $headers;
    }

    public static function sendXmlHeaders(): void {
        if ( headers_sent() ) { return; }
        nocache_headers();
        header_remove( 'Content-Type' );
        header_remove( 'Content-Disposition' );
        header( 'Content-Type: application/xml; charset=' . get_option( 'blog_charset' ), true );
        header( 'X-Content-Type-Options: nosniff', true );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
        header( 'CDN-Cache-Control: no-store', true );
        header( 'Surrogate-Control: no-store', true );
        header( 'X-YSEO-Sitemap-Transport: xml-v8', true );
        header( 'Pragma: no-cache', true );
        header( 'Expires: 0', true );
    }
}
