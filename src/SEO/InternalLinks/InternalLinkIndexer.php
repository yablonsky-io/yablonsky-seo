<?php
namespace Yablonsky\PerformanceSEO\SEO\InternalLinks;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class InternalLinkIndexer {
    private const CRON_HOOK = 'yseo_internal_link_backfill';
    private const CURSOR_OPTION = 'yseo_internal_link_backfill_cursor';
    private const COMPLETE_OPTION = 'yseo_internal_link_backfill_complete';
    /** @var InternalLinkRepository */ private $repository;

    public function __construct( ?InternalLinkRepository $repository = null ) {
        $this->repository = $repository instanceof InternalLinkRepository ? $repository : new InternalLinkRepository();
    }

    public function register(): void {
        add_action( 'save_post', array( $this, 'onSavePost' ), 30, 3 );
        add_action( 'deleted_post', array( $this, 'onDeletedPost' ) );
        add_action( 'trashed_post', array( $this, 'onDeletedPost' ) );
        add_action( 'untrashed_post', array( $this, 'onUntrashedPost' ) );
        add_action( self::CRON_HOOK, array( $this, 'runBackfill' ) );
        if ( false === get_option( self::COMPLETE_OPTION, false ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 60, self::CRON_HOOK );
        }
    }

    public function onSavePost( int $postId, \WP_Post $post, bool $update ): void {
        unset( $update );
        if ( wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) { return; }
        if ( 'publish' !== $post->post_status || ! $this->isPublicPostType( $post->post_type ) ) {
            $this->repository->removePost( $postId );
            return;
        }
        $this->indexPost( $postId );
    }

    public function onDeletedPost( int $postId ): void { $this->repository->removePost( $postId ); }
    public function onUntrashedPost( int $postId ): void { $this->indexPost( $postId ); }

    public function indexPost( int $postId, bool $force = false ): bool {
        $post = get_post( $postId );
        if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! $this->isPublicPostType( $post->post_type ) || '1' === (string) get_post_meta( $postId, '_yps_robots_noindex', true ) ) {
            $this->repository->removePost( $postId );
            return false;
        }
        $content = (string) $post->post_content;
        $isCornerstone = '1' === (string) get_post_meta( $postId, '_yps_is_cornerstone', true );
        $modifiedGmt = (string) $post->post_modified_gmt;
        $fingerprint = hash( 'sha256', $content . "\n" . $post->post_type . "\n" . $modifiedGmt . "\n" . ( $isCornerstone ? '1' : '0' ) );
        if ( ! $force && hash_equals( $this->repository->sourceHash( $postId ), $fingerprint ) ) { return false; }
        $targets = $this->extractTargets( $content, $postId );
        $language = ( new ResourceLanguageIndex() )->languageForPost( $postId, true );
        $this->repository->replaceSource( $postId, $fingerprint, $targets, $post->post_type, $isCornerstone, $modifiedGmt, (string) ( $language['language_key'] ?? 'auto' ) );
        return true;
    }

    public function runBackfill(): void {
        $cursor = max( 0, (int) get_option( self::CURSOR_OPTION, 0 ) );
        $types = array_values( array_filter( array_diff( array_values( get_post_types( array( 'public' => true ), 'names' ) ), array( 'attachment' ) ) ) );
        if ( empty( $types ) ) { update_option( self::COMPLETE_OPTION, gmdate( 'c' ), false ); return; }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_status='publish' AND post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT 40";
        $args = array_merge( array( $cursor ), $types );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Registry-derived post types and cursor are prepared; bounded background backfill only.
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
        if ( empty( $ids ) ) {
            update_option( self::COMPLETE_OPTION, gmdate( 'c' ), false );
            delete_option( self::CURSOR_OPTION );
            return;
        }
        foreach ( $ids as $id ) { $this->indexPost( $id, true ); }
        update_option( self::CURSOR_OPTION, max( $ids ), false );
        delete_option( self::COMPLETE_OPTION );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_single_event( time() + 30, self::CRON_HOOK ); }
    }

    public function resetAndSchedule(): void {
        $this->repository->reset();
        delete_option( self::CURSOR_OPTION );
        delete_option( self::COMPLETE_OPTION );
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_schedule_single_event( time() + 5, self::CRON_HOOK );
    }

    /** @return array<int,int> */
    private function extractTargets( string $content, int $sourceId ): array {
        $out = array();
        if ( '' === trim( $content ) || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) { return $out; }
        $processor = new \WP_HTML_Tag_Processor( $content );
        $seen = 0;
        while ( $seen < 250 && $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
            ++$seen;
            $href = $processor->get_attribute( 'href' );
            if ( ! is_string( $href ) ) { continue; }
            $url = $this->sameSiteUrl( $href );
            if ( '' === $url ) { continue; }
            $targetId = (int) url_to_postid( $url );
            if ( $targetId <= 0 || $targetId === $sourceId ) { continue; }
            $target = get_post( $targetId );
            if ( ! $target instanceof \WP_Post || 'publish' !== $target->post_status || ! $this->isPublicPostType( $target->post_type ) ) { continue; }
            if ( '1' === (string) get_post_meta( $targetId, '_yps_robots_noindex', true ) ) { continue; }
            $out[ $targetId ] = min( 65535, (int) ( $out[ $targetId ] ?? 0 ) + 1 );
        }
        return $out;
    }

    private function sameSiteUrl( string $href ): string {
        $href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) || 0 === stripos( $href, 'javascript:' ) ) { return ''; }
        if ( 0 === strpos( $href, '//' ) ) { $href = ( is_ssl() ? 'https:' : 'http:' ) . $href; }
        if ( 0 === strpos( $href, '/' ) ) { $href = home_url( $href ); }
        if ( ! preg_match( '#^https?://#i', $href ) ) { return ''; }
        $homeHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $hrefHost = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
        if ( '' === $homeHost || '' === $hrefHost || $homeHost !== $hrefHost ) { return ''; }
        $scheme = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
        $path = (string) wp_parse_url( $href, PHP_URL_PATH );
        if ( '' === $path ) { $path = '/'; }
        return esc_url_raw( $scheme . '://' . $homeHost . $path, array( 'http', 'https' ) );
    }

    private function isPublicPostType( string $postType ): bool {
        if ( 'attachment' === $postType ) { return false; }
        $object = get_post_type_object( $postType );
        return $object instanceof \WP_Post_Type && ! empty( $object->public );
    }
}
