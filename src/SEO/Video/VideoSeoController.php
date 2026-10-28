<?php
namespace Yablonsky\PerformanceSEO\SEO\Video;

use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPresentation;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapEligibilityPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VideoSeoController {
    private const MAX_ITEMS = 1000;
    /** @var bool */ private $emitPublicSeo;

    public function __construct( bool $emitPublicSeo = true ) { $this->emitPublicSeo = $emitPublicSeo; }

    public function register(): void {
        add_action( 'save_post', array( $this, 'indexPostVideo' ), 30, 2 );
        add_action( 'yseo_video_backfill', array( $this, 'backfillBatch' ) );
        if ( ! get_option( 'yseo_video_backfill_complete', false ) && ! wp_next_scheduled( 'yseo_video_backfill' ) ) {
            wp_schedule_single_event( time() + 60, 'yseo_video_backfill' );
        }
        if ( $this->emitPublicSeo ) {
            add_action( 'template_redirect', array( $this, 'route' ), 0 );
            add_filter( 'yablonsky_seo_schema_graph_v1', array( $this, 'augmentSchema' ), 20, 3 );
            add_filter( 'yablonsky_seo_sitemap_index_extra_v1', array( $this, 'sitemapIndexEntry' ) );
        }
    }

    public function backfillBatch(): void {
        $offset = max( 0, (int) get_option( 'yseo_video_backfill_offset', 0 ) );
        $ids = get_posts(
            array(
                'post_type' => $this->publicPostTypes(),
                'post_status' => 'publish',
                'posts_per_page' => 30,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'suppress_filters' => false,
            )
        );
        $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        if ( empty( $ids ) ) { update_option( 'yseo_video_backfill_complete', '1', false ); return; }
        foreach ( $ids as $postId ) {
            $post = get_post( $postId );
            if ( $post instanceof \WP_Post ) { $this->indexPostVideo( $postId, $post ); }
        }
        $offset += count( $ids );
        update_option( 'yseo_video_backfill_offset', $offset, false );
        if ( count( $ids ) < 30 ) { update_option( 'yseo_video_backfill_complete', '1', false ); return; }
        if ( ! wp_next_scheduled( 'yseo_video_backfill' ) ) { wp_schedule_single_event( time() + 60, 'yseo_video_backfill' ); }
    }

    /** @param array<int,array<string,string>> $items @return array<int,array<string,string>> */
    public function sitemapIndexEntry( array $items ): array {
        $latest = $this->videoCandidateIds( 50 );
        foreach ( $latest as $candidateId ) {
            if ( $candidateId <= 0 || '1' === (string) get_post_meta( $candidateId, '_yps_video_exclude', true ) || '1' === (string) get_post_meta( $candidateId, '_yps_robots_noindex', true ) ) { continue; }
            $post = get_post( $candidateId );
            $url = $post instanceof \WP_Post ? (string) get_permalink( $post ) : '';
            if ( ! $post instanceof \WP_Post || empty( ( new SitemapEligibilityPolicy() )->evaluatePost( $post, $url )['eligible'] ) ) { continue; }
            $video = $this->videoForPost( $candidateId );
            if ( empty( $video ) || empty( $video['thumbnail'] ) ) { continue; }
            $items[] = array( 'loc' => home_url( '/video-sitemap.xml' ), 'lastmod' => (string) get_post_modified_time( 'c', true, $candidateId ) );
            break;
        }
        return $items;
    }

    public function indexPostVideo( int $postId, \WP_Post $post ): void {
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) || 'attachment' === $post->post_type ) { return; }
        if ( ! is_post_type_viewable( $post->post_type ) ) { return; }
        $detected = $this->detectVideo( (string) $post->post_content );
        if ( empty( $detected ) ) {
            delete_post_meta( $postId, '_yps_video_detected_url' );
            delete_post_meta( $postId, '_yps_video_detected_kind' );
            delete_post_meta( $postId, '_yps_video_detected_thumbnail' );
            return;
        }
        update_post_meta( $postId, '_yps_video_detected_url', (string) $detected['url'] );
        update_post_meta( $postId, '_yps_video_detected_kind', (string) $detected['kind'] );
        if ( ! empty( $detected['thumbnail'] ) ) { update_post_meta( $postId, '_yps_video_detected_thumbnail', (string) $detected['thumbnail'] ); }
        else { delete_post_meta( $postId, '_yps_video_detected_thumbnail' ); }
    }

    public function route(): void {
        $requestUri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $path = trim( (string) wp_parse_url( is_string( $requestUri ) ? $requestUri : '', PHP_URL_PATH ), '/' );
        if ( 'video-sitemap.xml' !== $path ) { return; }
        $this->renderSitemap();
        exit;
    }

    /** @param array<string,mixed> $graph @param array<string,mixed> $document @return array<string,mixed> */
    public function augmentSchema( array $graph, array $document, $resource ): array {
        if ( ! is_object( $resource ) || ! method_exists( $resource, 'objectId' ) ) { return $graph; }
        $postId = (int) $resource->objectId();
        if ( $postId <= 0 || '1' === (string) get_post_meta( $postId, '_yps_video_exclude', true ) ) { return $graph; }
        $video = $this->videoForPost( $postId );
        if ( empty( $video ) || empty( $video['thumbnail'] ) ) { return $graph; }
        $canonical = isset( $document['canonical'] ) ? (string) $document['canonical'] : (string) get_permalink( $postId );
        $piece = array(
            '@type' => 'VideoObject',
            '@id' => $canonical . '#video',
            'name' => (string) $video['title'],
            'description' => (string) $video['description'],
            'thumbnailUrl' => array( (string) $video['thumbnail'] ),
            'uploadDate' => (string) $video['published'],
        );
        if ( 'player' === (string) $video['kind'] ) { $piece['embedUrl'] = (string) $video['url']; }
        else { $piece['contentUrl'] = (string) $video['url']; }
        if ( (int) $video['duration'] > 0 ) { $piece['duration'] = $this->isoDuration( (int) $video['duration'] ); }
        $graph['@graph'] = isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ? $graph['@graph'] : array();
        $graph['@graph'][] = $piece;
        foreach ( $graph['@graph'] as &$candidate ) {
            if ( is_array( $candidate ) && isset( $candidate['@id'] ) && $canonical . '#webpage' === (string) $candidate['@id'] ) {
                $candidate['subjectOf'] = array( '@id' => $canonical . '#video' );
            }
        }
        unset( $candidate );
        return $graph;
    }

    private function renderSitemap(): void {
        $ids = $this->videoCandidateIds( self::MAX_ITEMS );
        SitemapPresentation::sendXmlHeaders();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";
        foreach ( $ids as $postId ) {
            $post = get_post( $postId );
            if ( ! $post instanceof \WP_Post || '1' === (string) get_post_meta( $postId, '_yps_video_exclude', true ) || '1' === (string) get_post_meta( $postId, '_yps_robots_noindex', true ) ) { continue; }
            $video = $this->videoForPost( $postId );
            if ( empty( $video ) || empty( $video['thumbnail'] ) ) { continue; }
            $url = get_permalink( $post );
            if ( ! is_string( $url ) || '' === $url ) { continue; }
            if ( empty( ( new SitemapEligibilityPolicy() )->evaluatePost( $post, $url )['eligible'] ) ) { continue; }
            echo '<url><loc>' . esc_url( $url ) . '</loc><video:video>';
            echo '<video:thumbnail_loc>' . esc_url( (string) $video['thumbnail'] ) . '</video:thumbnail_loc>';
            echo '<video:title>' . esc_html( (string) $video['title'] ) . '</video:title>';
            echo '<video:description>' . esc_html( (string) $video['description'] ) . '</video:description>';
            if ( 'player' === (string) $video['kind'] ) { echo '<video:player_loc>' . esc_url( (string) $video['url'] ) . '</video:player_loc>'; }
            else { echo '<video:content_loc>' . esc_url( (string) $video['url'] ) . '</video:content_loc>'; }
            if ( (int) $video['duration'] > 0 ) { echo '<video:duration>' . esc_html( (string) (int) $video['duration'] ) . '</video:duration>'; }
            echo '<video:publication_date>' . esc_html( (string) $video['published'] ) . '</video:publication_date>';
            echo '</video:video></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    }

    /** @return array<string,mixed> */
    private function videoForPost( int $postId ): array {
        $manualUrl = esc_url_raw( (string) get_post_meta( $postId, '_yps_video_content_url', true ), array( 'http', 'https' ) );
        $detectedUrl = esc_url_raw( (string) get_post_meta( $postId, '_yps_video_detected_url', true ), array( 'http', 'https' ) );
        $url = '' !== (string) $manualUrl ? (string) $manualUrl : (string) $detectedUrl;
        if ( '' === $url ) { return array(); }
        $kind = '' !== (string) $manualUrl ? sanitize_key( (string) get_post_meta( $postId, '_yps_video_kind', true ) ) : sanitize_key( (string) get_post_meta( $postId, '_yps_video_detected_kind', true ) );
        if ( ! in_array( $kind, array( 'content', 'player' ), true ) ) { $kind = $this->looksLikePlayer( $url ) ? 'player' : 'content'; }
        $thumbnail = esc_url_raw( (string) get_post_meta( $postId, '_yps_video_thumbnail', true ), array( 'http', 'https' ) );
        if ( '' === (string) $thumbnail ) { $thumbnail = esc_url_raw( (string) get_post_meta( $postId, '_yps_video_detected_thumbnail', true ), array( 'http', 'https' ) ); }
        if ( '' === (string) $thumbnail && has_post_thumbnail( $postId ) ) {
            $featured = get_the_post_thumbnail_url( $postId, 'full' );
            $thumbnail = is_string( $featured ) ? esc_url_raw( $featured, array( 'http', 'https' ) ) : '';
        }
        $title = trim( wp_strip_all_tags( (string) get_post_meta( $postId, '_yps_video_title', true ), true ) );
        if ( '' === $title ) { $title = trim( wp_strip_all_tags( (string) get_the_title( $postId ), true ) ); }
        $description = trim( wp_strip_all_tags( (string) get_post_meta( $postId, '_yps_video_description', true ), true ) );
        if ( '' === $description ) { $description = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $postId ), true ), 30, '…' ); }
        $duration = min( 28800, max( 0, (int) get_post_meta( $postId, '_yps_video_duration', true ) ) );
        return array(
            'url' => $url,
            'kind' => $kind,
            'thumbnail' => (string) $thumbnail,
            'title' => $title,
            'description' => $description,
            'duration' => $duration,
            'published' => (string) get_post_time( 'c', true, $postId ),
        );
    }

    /** @return array<string,string> */
    private function detectVideo( string $content ): array {
        if ( preg_match( '~<video[^>]+src=["\']([^"\']+)["\']~i', $content, $match ) ) {
            $url = esc_url_raw( html_entity_decode( (string) $match[1] ), array( 'http', 'https' ) );
            if ( '' !== (string) $url ) { return array( 'url' => (string) $url, 'kind' => 'content', 'thumbnail' => '' ); }
        }
        if ( preg_match( '~<source[^>]+src=["\']([^"\']+)["\']~i', $content, $match ) ) {
            $url = esc_url_raw( html_entity_decode( (string) $match[1] ), array( 'http', 'https' ) );
            if ( '' !== (string) $url ) { return array( 'url' => (string) $url, 'kind' => 'content', 'thumbnail' => '' ); }
        }
        if ( preg_match( '~<iframe[^>]+src=["\']([^"\']+)["\']~i', $content, $match ) ) {
            $url = esc_url_raw( html_entity_decode( (string) $match[1] ), array( 'http', 'https' ) );
            if ( '' !== (string) $url && $this->looksLikePlayer( (string) $url ) ) {
                return array( 'url' => (string) $url, 'kind' => 'player', 'thumbnail' => $this->youtubeThumbnail( (string) $url ) );
            }
        }
        if ( preg_match( '~https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})~i', $content, $match ) ) {
            $id = sanitize_text_field( (string) $match[1] );
            return array( 'url' => 'https://www.youtube.com/embed/' . rawurlencode( $id ), 'kind' => 'player', 'thumbnail' => 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/hqdefault.jpg' );
        }
        return array();
    }

    private function looksLikePlayer( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        return false !== strpos( $host, 'youtube.com' ) || false !== strpos( $host, 'youtu.be' ) || false !== strpos( $host, 'vimeo.com' );
    }

    private function youtubeThumbnail( string $url ): string {
        if ( preg_match( '~/(?:embed/|shorts/)([A-Za-z0-9_-]{6,})~', $url, $match ) ) {
            return 'https://i.ytimg.com/vi/' . rawurlencode( (string) $match[1] ) . '/hqdefault.jpg';
        }
        return '';
    }

    /** @return array<int,int> */
    private function videoCandidateIds( int $limit ): array {
        global $wpdb;
        $types = $this->publicPostTypes();
        if ( empty( $types ) ) { return array(); }
        $limit = min( self::MAX_ITEMS, max( 1, $limit ) );
        $typePlaceholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT DISTINCT p.ID FROM %i p INNER JOIN %i pm ON pm.post_id=p.ID WHERE p.post_status='publish' AND p.post_type IN ({$typePlaceholders}) AND pm.meta_key IN (%s,%s) ORDER BY p.post_modified_gmt DESC,p.ID DESC LIMIT %d";
        $args = array_merge( array( $wpdb->posts, $wpdb->postmeta ), $types, array( '_yps_video_content_url', '_yps_video_detected_url', $limit ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed meta keys plus registered public post types are prepared; bounded query replaces slow OR meta_query scans.
        $ids = array_values( array_filter( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ) ) );
        if ( ! empty( $ids ) ) { update_meta_cache( 'post', $ids ); }
        return $ids;
    }

    /** @return array<int|string,string> */
    private function publicPostTypes(): array {
        $types = get_post_types( array( 'public' => true ), 'names' );
        if ( ! is_array( $types ) ) { return array( 'post', 'page' ); }
        unset( $types['attachment'] );
        return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
    }

    private function isoDuration( int $seconds ): string {
        $hours = intdiv( $seconds, 3600 );
        $minutes = intdiv( $seconds % 3600, 60 );
        $remaining = $seconds % 60;
        $out = 'PT';
        if ( $hours > 0 ) { $out .= $hours . 'H'; }
        if ( $minutes > 0 ) { $out .= $minutes . 'M'; }
        if ( $remaining > 0 || 'PT' === $out ) { $out .= $remaining . 'S'; }
        return $out;
    }
}
