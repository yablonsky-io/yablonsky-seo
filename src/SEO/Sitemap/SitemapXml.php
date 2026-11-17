<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SitemapXml {
    public static function text( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    public static function url( string $value ): string {
        $url = esc_url_raw( $value, array( 'http', 'https' ) );
        return '' === $url ? '' : self::text( $url );
    }

    /**
     * Last-mile XML document integrity gate. XML element values and attributes
     * are already escaped by text()/url()/attr() at construction. Validate
     * the finished document without HTML-escaping its XML markup or changing
     * the underlying sitemap semantics. Reject DTDs, entities and malformed
     * output rather than emitting untrusted or invalid markup.
     */
    public static function verifiedDocument( string $xml ): string {
        $prolog = '<?xml version="1.0" encoding="UTF-8"?>';
        if ( 0 !== strpos( $xml, $prolog ) || strlen( $xml ) > 16777216 || ! preg_match( '//u', $xml ) ) { return ''; }
        if ( preg_match( '/<!|<\?xml-stylesheet|[\x00-\x08\x0B\x0C\x0E-\x1F]/i', substr( $xml, strlen( $prolog ) ) ) ) { return ''; }
        $body = substr( $xml, strlen( $prolog ) );
        // Validate our strict, small XML grammar even when ext-dom is unavailable.
        // Never permit arbitrary HTML/XML tags, attributes, or raw entities.
        if ( ! preg_match_all( '/<[^>]*>|[^<]+/s', $body, $tokens ) || implode( '', $tokens[0] ) !== $body ) { return ''; }
        $allowed = array(
            'urlset' => array( 'xmlns', 'xmlns:image', 'xmlns:agent' ),
            'sitemapindex' => array( 'xmlns' ),
            'sitemap' => array(), 'url' => array(), 'loc' => array(),
            'lastmod' => array(), 'image:image' => array(), 'image:loc' => array(),
            'agent:alternate' => array( 'rel', 'type', 'href' ),
            'agent:llms' => array( 'href' ),
        );
        $stack = array();
        $rootSeen = false;
        foreach ( $tokens[0] as $token ) {
            if ( '<' !== $token[0] ) {
                if ( empty( $stack ) && '' !== trim( $token ) ) { return ''; }
                if ( preg_match( '/&(?!amp;|lt;|gt;|quot;|apos;|#\d+;|#x[0-9a-fA-F]+;)/', $token ) ) { return ''; }
                continue;
            }
            if ( preg_match( '/^<\/([A-Za-z][A-Za-z0-9:._-]*)>$/', $token, $match ) ) {
                if ( empty( $stack ) || array_pop( $stack ) !== $match[1] ) { return ''; }
                continue;
            }
            if ( ! preg_match( '/^<([A-Za-z][A-Za-z0-9:._-]*)(.*?)(\/?)>$/s', $token, $match ) ) { return ''; }
            $tag = $match[1];
            if ( ! isset( $allowed[ $tag ] ) ) { return ''; }
            if ( empty( $stack ) ) {
                if ( $rootSeen || ! in_array( $tag, array( 'urlset', 'sitemapindex' ), true ) ) { return ''; }
                $rootSeen = true;
            }
            $attributes = $match[2];
            while ( '' !== $attributes ) {
                if ( ! preg_match( '/^\s+([A-Za-z_:][A-Za-z0-9:._-]*)=("[^"<>]*"|\'[^\'<>]*\')/s', $attributes, $attr ) ) {
                    if ( '' !== trim( $attributes ) ) { return ''; }
                    break;
                }
                if ( ! in_array( $attr[1], $allowed[ $tag ], true ) ) { return ''; }
                if ( preg_match( '/&(?!amp;|lt;|gt;|quot;|apos;|#\d+;|#x[0-9a-fA-F]+;)/', $attr[2] ) ) { return ''; }
                $attributes = substr( $attributes, strlen( $attr[0] ) );
            }
            if ( '/' !== $match[3] ) { $stack[] = $tag; }
        }
        if ( ! $rootSeen || ! empty( $stack ) ) { return ''; }
        if ( class_exists( '\DOMDocument' ) ) {
            $previous = libxml_use_internal_errors( true );
            try {
                $document = new \DOMDocument( '1.0', 'UTF-8' );
                if ( ! $document->loadXML( $xml, LIBXML_NONET ) || null !== $document->doctype ) { return ''; }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );
            }
        }
        return $xml;
    }

    public static function attr( string $value ): string {
        return self::text( $value );
    }
}
