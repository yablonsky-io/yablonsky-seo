<?php
namespace Yablonsky\PerformanceSEO\Discovery;

use Yablonsky\PerformanceSEO\SEO\Freshness\FreshnessResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Opt-in IndexNow push channel. Content writes only enqueue local URLs; remote
 * delivery happens later in WP-Cron so editors never wait on an external service.
 */
final class IndexNowController {
    /** @var array<int,bool> Post ID => collection surfaces also changed. */
    private $pendingSemanticPosts = array();
    /** @var array<int,bool> */
    private $queuedPostsThisRequest = array();
    private const KEY_OPTION = 'yseo_indexnow_key_v1';
    private const QUEUE_OPTION = 'yseo_indexnow_queue_v1';
    private const STATUS_OPTION = 'yseo_indexnow_status_v1';
    private const CRON_HOOK = 'yseo_indexnow_flush_v1';
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function register(): void {
        if ( ! $this->enabled() ) { return; }
        $this->ensureKey();
        add_action( 'parse_request', array( $this, 'maybeServeKey' ), 0, 1 );
        add_action( 'save_post', array( $this, 'postSaved' ), 50, 1 );
        add_action( 'transition_post_status', array( $this, 'postStatusChanged' ), 50, 3 );
        add_action( 'before_delete_post', array( $this, 'postDeleting' ), 50, 1 );
        add_action( 'added_post_meta', array( $this, 'postMetaChanged' ), 50, 4 );
        add_action( 'updated_post_meta', array( $this, 'postMetaChanged' ), 50, 4 );
        add_action( 'deleted_post_meta', array( $this, 'postMetaChanged' ), 50, 4 );
        add_action( 'created_term', array( $this, 'termChanged' ), 50, 3 );
        add_action( 'edited_term', array( $this, 'termChanged' ), 50, 3 );
        add_action( 'profile_update', array( $this, 'authorChanged' ), 50, 1 );
        add_action( self::CRON_HOOK, array( $this, 'flush' ) );
        add_action( 'shutdown', array( $this, 'queuePendingSemanticPosts' ), 25 );
        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( is_array( $queue ) && ! empty( $queue ) ) { $this->scheduleFlush( 5 * MINUTE_IN_SECONDS ); }
    }

    public static function clearSchedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function maybeServeKey( $wp ): void {
        if ( ! is_object( $wp ) || ! isset( $wp->request ) ) { return; }
        $key = $this->ensureKey();
        if ( '' === $key || (string) $wp->request !== $key . '.txt' ) { return; }
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=UTF-8', true );
        header( 'Cache-Control: public, max-age=86400, s-maxage=86400', true );
        header( 'X-Robots-Tag: noindex', true );
        echo esc_html( $key );
        exit;
    }

    public function postSaved( int $postId ): void {
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) { return; }
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) { return; }
        if ( '' !== (string) $post->post_password ) { $this->dropQueuedPostUrl( $post ); return; }
        $this->queuePostAndCollections( $post );
        $this->queuedPostsThisRequest[ $postId ] = true;
    }

    public function postStatusChanged( string $newStatus, string $oldStatus, \WP_Post $post ): void {
        if ( '' !== (string) $post->post_password ) { $this->dropQueuedPostUrl( $post ); return; }
        if ( $newStatus === $oldStatus ) { return; }
        if ( 'publish' === $newStatus || 'publish' === $oldStatus ) {
            $this->queuePostAndCollections( $post );
            $this->queuedPostsThisRequest[ (int) $post->ID ] = true;
        }
    }

    public function postDeleting( int $postId ): void {
        $post = get_post( $postId );
        if ( $post instanceof \WP_Post ) {
            if ( '' !== (string) $post->post_password ) { $this->dropQueuedPostUrl( $post ); return; }
            $this->queuePostAndCollections( $post );
        }
    }

    public function postMetaChanged( $metaId, $postId, $metaKey, $value ): void {
        unset( $metaId, $value );
        if ( ! FreshnessResolver::isSemanticPostMeta( (string) $metaKey ) ) { return; }
        $post = get_post( (int) $postId );
        if ( $post instanceof \WP_Post && 'product_variation' === $post->post_type && (int) $post->post_parent > 0 ) { $post = get_post( (int) $post->post_parent ); }
        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) { return; }
        $postId = (int) $post->ID;
        if ( isset( $this->queuedPostsThisRequest[ $postId ] ) ) { return; }
        $collectionChanged = FreshnessResolver::isCollectionSemanticPostMeta( (string) $metaKey );
        $this->pendingSemanticPosts[ $postId ] = ! empty( $this->pendingSemanticPosts[ $postId ] ) || $collectionChanged;
    }

    public function termChanged( int $termId, int $ttId = 0, string $taxonomy = '' ): void {
        unset( $ttId );
        $term = get_term( $termId, $taxonomy ?: '' );
        if ( ! $term instanceof \WP_Term ) { return; }
        $url = get_term_link( $term );
        if ( ! is_wp_error( $url ) ) { $this->queueUrl( (string) $url ); }
    }

    public function authorChanged( int $userId ): void {
        if ( $userId > 0 ) { $this->queueUrl( (string) get_author_posts_url( $userId ) ); }
    }

    public function queuePendingSemanticPosts(): void {
        if ( empty( $this->pendingSemanticPosts ) ) { return; }
        $urls = array();
        foreach ( $this->pendingSemanticPosts as $postId => $collectionsChanged ) {
            $post = get_post( (int) $postId );
            if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) { continue; }
            $urls = array_merge( $urls, $collectionsChanged ? $this->urlsForPostAndCollections( $post ) : $this->urlsForPostOnly( $post ) );
        }
        $this->pendingSemanticPosts = array();
        $this->queueUrls( array_values( array_unique( $urls ) ) );
    }

    public function flush(): void {
        if ( ! $this->enabled() ) { return; }
        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) { return; }

        $urls = array();
        foreach ( array_slice( array_keys( $queue ), 0, 500 ) as $url ) {
            // Older queues may contain a once-public post which has since become
            // password-protected. Never submit that URL to an external indexer.
            $postId = url_to_postid( (string) $url );
            $post = $postId > 0 ? get_post( $postId ) : null;
            if ( $post instanceof \WP_Post && '' !== (string) $post->post_password ) {
                unset( $queue[ $url ] );
                continue;
            }
            $urls[] = $url;
        }
        if ( empty( $urls ) ) {
            update_option( self::QUEUE_OPTION, $queue, false );
            if ( ! empty( $queue ) ) { $this->scheduleFlush( 60 ); }
            return;
        }
        $key = $this->ensureKey();
        $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( '' === $key || '' === $host || empty( $urls ) ) { return; }

        $payload = array(
            'host' => $host,
            'key' => $key,
            'keyLocation' => home_url( '/' . $key . '.txt' ),
            'urlList' => array_values( $urls ),
        );
        $response = wp_remote_post(
            self::ENDPOINT,
            array(
                'timeout' => 8,
                'redirection' => 0,
                'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body' => wp_json_encode( $payload ),
                'data_format' => 'body',
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->recordStatus( 'retry', 0, count( $urls ), $response->get_error_message() );
            $this->scheduleFlush( 15 * MINUTE_IN_SECONDS );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, array( 200, 202 ), true ) ) {
            foreach ( $urls as $url ) { unset( $queue[ $url ] ); }
            update_option( self::QUEUE_OPTION, $queue, false );
            $this->recordStatus( 'accepted', $code, count( $urls ), '' );
            if ( ! empty( $queue ) ) { $this->scheduleFlush( 60 ); }
            return;
        }

        if ( 429 === $code || $code >= 500 ) {
            $this->recordStatus( 'retry', $code, count( $urls ), '' );
            $this->scheduleFlush( 30 * MINUTE_IN_SECONDS );
            return;
        }

        // Permanent protocol/key/payload failures should not create an endless loop.
        foreach ( $urls as $url ) { unset( $queue[ $url ] ); }
        update_option( self::QUEUE_OPTION, $queue, false );
        $this->recordStatus( 'rejected', $code, count( $urls ), '' );
        if ( ! empty( $queue ) ) { $this->scheduleFlush( 15 * MINUTE_IN_SECONDS ); }
    }

    private function queuePostAndCollections( \WP_Post $post ): void {
        $this->queueUrls( $this->urlsForPostAndCollections( $post ) );
    }

    /** @return array<int,string> */
    private function urlsForPostOnly( \WP_Post $post ): array {
        if ( '' !== (string) $post->post_password ) { return array(); }
        $object = get_post_type_object( $post->post_type );
        if ( ! $object || empty( $object->public ) ) { return array(); }
        $url = get_permalink( $post );
        return is_string( $url ) && '' !== $url ? array( $url ) : array();
    }

    /** @return array<int,string> */
    private function urlsForPostAndCollections( \WP_Post $post ): array {
        if ( '' !== (string) $post->post_password ) { return array(); }
        $object = get_post_type_object( $post->post_type );
        if ( ! $object || empty( $object->public ) ) { return array(); }
        $urls = array();
        $url = get_permalink( $post );
        if ( is_string( $url ) ) { $urls[] = $url; }

        if ( ! empty( $object->has_archive ) ) {
            $archive = get_post_type_archive_link( $post->post_type );
            if ( is_string( $archive ) ) { $urls[] = $archive; }
        }
        if ( (int) $post->post_author > 0 ) { $urls[] = (string) get_author_posts_url( (int) $post->post_author ); }
        foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
            $tax = get_taxonomy( $taxonomy );
            if ( ! $tax || empty( $tax->public ) ) { continue; }
            $terms = get_the_terms( $post->ID, $taxonomy );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term ) { continue; }
                $termUrl = get_term_link( $term );
                if ( ! is_wp_error( $termUrl ) ) { $urls[] = (string) $termUrl; }
            }
        }
        return $urls;
    }

    private function dropQueuedPostUrl( \WP_Post $post ): void {
        $url = (string) get_permalink( $post );
        if ( '' === $url ) { return; }
        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || ! isset( $queue[ $url ] ) ) { return; }
        unset( $queue[ $url ] );
        update_option( self::QUEUE_OPTION, $queue, false );
    }

    private function queueUrl( string $url, bool $schedule = true ): void {
        $this->queueUrls( array( $url ), $schedule );
    }

    /** @param array<int,string> $urls */
    private function queueUrls( array $urls, bool $schedule = true ): void {
        $queue = get_option( self::QUEUE_OPTION, array() );
        $queue = is_array( $queue ) ? $queue : array();
        $changed = false;
        foreach ( $urls as $url ) {
            $url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
            if ( '' === $url || ! $this->sameSite( $url ) ) { continue; }
            $queue[ $url ] = time();
            $changed = true;
        }
        if ( ! $changed ) { return; }
        if ( count( $queue ) > 10000 ) { $queue = array_slice( $queue, -10000, null, true ); }
        update_option( self::QUEUE_OPTION, $queue, false );
        if ( $schedule ) { $this->scheduleFlush( 5 * MINUTE_IN_SECONDS ); }
    }

    private function scheduleFlush( int $delay ): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_single_event( time() + max( 60, $delay ), self::CRON_HOOK ); }
    }

    private function enabled(): bool {
        $settings = get_option( 'yps_public_settings', array() );
        return is_array( $settings ) && ! empty( $settings['indexnow_enabled'] );
    }

    private function ensureKey(): string {
        $key = trim( (string) get_option( self::KEY_OPTION, '' ) );
        if ( preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ) { return $key; }
        $key = wp_generate_password( 32, false, false );
        if ( ! preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ) { return ''; }
        update_option( self::KEY_OPTION, $key, false );
        return $key;
    }

    private function sameSite( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $siteHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        return '' !== $host && '' !== $siteHost && $host === $siteHost;
    }

    private function recordStatus( string $state, int $code, int $count, string $detail ): void {
        update_option(
            self::STATUS_OPTION,
            array(
                'state' => sanitize_key( $state ),
                'http_code' => $code,
                'url_count' => $count,
                'at' => time(),
                'detail' => sanitize_text_field( $detail ),
            ),
            false
        );
    }
}
