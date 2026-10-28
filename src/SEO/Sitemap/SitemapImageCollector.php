<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

use Yablonsky\PerformanceSEO\Visibility\AgentResourceLocator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded image discovery for XML sitemap entries.
 *
 * Sources are deliberately local: explicit SEO/social image, featured image,
 * images referenced by saved content, WooCommerce gallery and term thumbnail.
 * The collector does not crawl rendered HTML or make remote requests.
 */
final class SitemapImageCollector {
    private const MAX_IMAGES = 20;
    /** @var array<int,array<int,int>> IDs found during this bulk request. */
    private $bulkAttachmentIdCache = array();

    /**
     * @param array<int,array<string,mixed>> $requests
     * @return array<string,array<int,string>> keyed by resource_type:object_id
     */
    public function collectBulk( array $requests, string $mode = 'all', int $maxImages = self::MAX_IMAGES ): array {
        $this->bulkAttachmentIdCache = array(); // Prevent stale results across bulk requests.
        $mode = in_array( $mode, array( 'primary', 'all' ), true ) ? $mode : 'primary';
        $maxImages = max( 1, min( self::MAX_IMAGES, $maxImages ) );
        $postIds = array();
        $termIds = array();
        foreach ( $requests as $request ) {
            if ( ! is_array( $request ) ) { continue; }
            $type = sanitize_key( (string) ( $request['resource_type'] ?? '' ) );
            $id = (int) ( $request['object_id'] ?? 0 );
            if ( $id <= 0 ) { continue; }
            if ( 'post' === $type ) { $postIds[] = $id; }
            if ( 'term' === $type ) { $termIds[] = $id; }
        }
        $postIds = array_values( array_unique( $postIds ) );
        $termIds = array_values( array_unique( $termIds ) );
        if ( ! empty( $postIds ) ) {
            if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $postIds, false, true ); }
            update_meta_cache( 'post', $postIds );
        }
        if ( ! empty( $termIds ) ) { update_meta_cache( 'term', $termIds ); }

        // First pass discovers attachment IDs so their post/meta caches are warmed once.
        $attachmentIds = array();
        foreach ( $postIds as $postId ) {
            foreach ( $this->postAttachmentIds( $postId, $mode ) as $attachmentId ) { $attachmentIds[ $attachmentId ] = true; }
        }
        foreach ( $termIds as $termId ) {
            $thumbnailId = (int) get_term_meta( $termId, 'thumbnail_id', true );
            if ( $thumbnailId > 0 ) { $attachmentIds[ $thumbnailId ] = true; }
        }
        if ( ! empty( $attachmentIds ) ) {
            $ids = array_keys( $attachmentIds );
            if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $ids, false, true ); }
            update_meta_cache( 'post', $ids );
        }

        $out = array();
        foreach ( $requests as $request ) {
            if ( ! is_array( $request ) ) { continue; }
            $type = sanitize_key( (string) ( $request['resource_type'] ?? '' ) );
            $id = (int) ( $request['object_id'] ?? 0 );
            if ( $id <= 0 ) { continue; }
            $key = $type . ':' . $id;
            $urls = 'term' === $type ? $this->termImages( $id ) : ( 'post' === $type ? $this->postImages( $id, $mode ) : array() );
            $urls = apply_filters( 'yablonsky_seo_sitemap_images_v1', $urls, $type, $id );
            $out[ $key ] = array_slice( $this->normalizeUrls( is_array( $urls ) ? $urls : array() ), 0, $maxImages );
        }
        return $out;
    }

    /** @return array<int,string> */
    private function postImages( int $postId, string $mode ): array {
        $urls = array();
        $locator = new AgentResourceLocator();
        $preferred = $locator->preferredImageUrl( 'post', $postId );
        if ( '' !== $preferred ) { $urls[] = $preferred; }

        foreach ( $this->postAttachmentIds( $postId, $mode ) as $attachmentId ) {
            $url = wp_get_attachment_image_url( $attachmentId, 'full' );
            if ( is_string( $url ) && '' !== $url ) { $urls[] = $url; }
        }

        if ( 'primary' === $mode ) { return $urls; }

        $post = get_post( $postId );
        if ( $post instanceof \WP_Post ) {
            $content = (string) $post->post_content;
            if ( preg_match_all( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content, $matches ) ) {
                foreach ( (array) ( $matches[1] ?? array() ) as $url ) { $urls[] = html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
            }
        }
        return $urls;
    }

    /** @return array<int,int> */
    private function postAttachmentIds( int $postId, string $mode = 'all' ): array {
        $ids = array();
        $featured = (int) get_post_thumbnail_id( $postId );
        if ( $featured > 0 ) { $ids[ $featured ] = true; }
        if ( 'primary' === $mode ) { return array_slice( array_keys( $ids ), 0, 1 ); }
        if ( isset( $this->bulkAttachmentIdCache[ $postId ] ) ) { return $this->bulkAttachmentIdCache[ $postId ]; }

        $gallery = trim( (string) get_post_meta( $postId, '_product_image_gallery', true ) );
        if ( '' !== $gallery ) {
            foreach ( explode( ',', $gallery ) as $id ) {
                $id = (int) trim( $id );
                if ( $id > 0 ) { $ids[ $id ] = true; }
            }
        }

        if ( 'primary' === $mode ) { return $urls; }

        $post = get_post( $postId );
        if ( $post instanceof \WP_Post ) {
            $content = (string) $post->post_content;
            if ( preg_match_all( '/\bwp-image-([0-9]+)\b/', $content, $matches ) ) {
                foreach ( (array) ( $matches[1] ?? array() ) as $id ) { $id = (int) $id; if ( $id > 0 ) { $ids[ $id ] = true; } }
            }
            if ( preg_match_all( '/<!--\s+wp:image\s+\{[^}]*"id"\s*:\s*([0-9]+)/', $content, $matches ) ) {
                foreach ( (array) ( $matches[1] ?? array() ) as $id ) { $id = (int) $id; if ( $id > 0 ) { $ids[ $id ] = true; } }
            }
        }
        $result = array_slice( array_keys( $ids ), 0, self::MAX_IMAGES );
        $this->bulkAttachmentIdCache[ $postId ] = $result;
        return $result;
    }

    /** @return array<int,string> */
    private function termImages( int $termId ): array {
        $urls = array();
        $thumbnailId = (int) get_term_meta( $termId, 'thumbnail_id', true );
        if ( $thumbnailId > 0 ) {
            $url = wp_get_attachment_image_url( $thumbnailId, 'full' );
            if ( is_string( $url ) && '' !== $url ) { $urls[] = $url; }
        }
        return $urls;
    }

    /** @param array<int,mixed> $urls @return array<int,string> */
    private function normalizeUrls( array $urls ): array {
        $out = array();
        foreach ( $urls as $url ) {
            if ( ! is_scalar( $url ) ) { continue; }
            $url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
            if ( '' === $url ) { continue; }
            $out[ $url ] = true;
            if ( count( $out ) >= self::MAX_IMAGES ) { break; }
        }
        return array_keys( $out );
    }
}
