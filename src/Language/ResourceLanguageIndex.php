<?php
namespace Yablonsky\PerformanceSEO\Language;

use Yablonsky\PerformanceSEO\Localization\ProductLocaleRegistry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable per-resource language index used by list navigation and long-running jobs.
 *
 * The index is derived evidence, never language authority. Authority remains the
 * versioned multilingual contract / exact-resource content detector in
 * ResourceLanguageResolver. Admin/user locale is intentionally absent.
 */
final class ResourceLanguageIndex {
    public const META_LANGUAGE = '_yseo_resource_language_key';
    public const META_SOURCE = '_yseo_resource_language_source';
    public const META_INDEXED = '_yseo_resource_language_indexed_gmt';
    private const CURSOR_OPTION = 'yseo_resource_language_index_cursor';
    private const COMPLETE_OPTION = 'yseo_resource_language_index_complete';
    private const CRON = 'yseo_resource_language_backfill';
    private const BATCH = 100;
    private const INDEX_VERSION = '2';
    private const META_INDEX_VERSION = '_yseo_resource_language_index_version';

    /** @var ResourceLanguageResolver */ private $resolver;

    public function __construct( ?ResourceLanguageResolver $resolver = null ) {
        $this->resolver = $resolver instanceof ResourceLanguageResolver ? $resolver : new ResourceLanguageResolver();
    }

    public function register(): void {
        add_action( 'save_post', array( $this, 'onSavePost' ), 35, 2 );
        add_action( 'before_delete_post', array( $this, 'onDeletePost' ) );
        add_action( self::CRON, array( $this, 'backfillStep' ) );
        add_action( 'init', array( $this, 'ensureBackfillScheduled' ), 90 );
    }

    public static function clearSchedule(): void { wp_clear_scheduled_hook( self::CRON ); }

    public function onSavePost( int $postId, \WP_Post $post ): void {
        if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) || 'attachment' === $post->post_type ) { return; }
        $this->indexPost( $postId );
    }

    public function onDeletePost( int $postId ): void {
        delete_post_meta( $postId, self::META_LANGUAGE );
        delete_post_meta( $postId, self::META_SOURCE );
        delete_post_meta( $postId, self::META_INDEXED );
        delete_post_meta( $postId, self::META_INDEX_VERSION );
    }

    /** @return array{language_key:string,source:string} */
    public function languageForPost( int $postId, bool $hydrate = true ): array {
        $language = $this->normalize( (string) get_post_meta( $postId, self::META_LANGUAGE, true ) );
        $source = sanitize_key( (string) get_post_meta( $postId, self::META_SOURCE, true ) );
        $version = sanitize_text_field( (string) get_post_meta( $postId, self::META_INDEX_VERSION, true ) );
        if ( '' !== $language && self::INDEX_VERSION === $version ) { return array( 'language_key' => $language, 'source' => '' !== $source ? $source : 'indexed' ); }
        if ( ! $hydrate ) { return array( 'language_key' => 'auto', 'source' => 'unindexed' ); }
        return $this->indexPost( $postId );
    }

    /** @return array{language_key:string,source:string} */
    public function indexPost( int $postId ): array {
        $resolved = $this->resolver->forPost( $postId );
        $language = $this->normalize( (string) ( $resolved['language_key'] ?? 'auto' ) );
        if ( '' === $language ) { $language = 'auto'; }
        $source = sanitize_key( (string) ( $resolved['source'] ?? 'content-auto' ) );
        update_post_meta( $postId, self::META_LANGUAGE, $language );
        update_post_meta( $postId, self::META_SOURCE, $source );
        update_post_meta( $postId, self::META_INDEXED, gmdate( 'c' ) );
        update_post_meta( $postId, self::META_INDEX_VERSION, self::INDEX_VERSION );
        return array( 'language_key' => $language, 'source' => $source );
    }

    public function ensureBackfillScheduled(): void {
        if ( self::INDEX_VERSION === (string) get_option( self::COMPLETE_OPTION, '' ) ) { return; }
        if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_single_event( time() + 15, self::CRON ); }
    }

    public function backfillStep(): void {
        global $wpdb;
        $cursor = max( 0, (int) get_option( self::CURSOR_OPTION, 0 ) );
        $types = get_post_types( array( 'public' => true ), 'names' );
        $types = array_values( array_diff( $types, array( 'attachment' ) ) );
        if ( empty( $types ) ) { update_option( self::COMPLETE_OPTION, self::INDEX_VERSION, false ); return; }
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_type IN ({$placeholders}) AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT %d";
        $args = array_merge( array( $cursor ), $types, array( self::BATCH ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset scan of core posts for a derived plugin-owned language index.
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
        if ( empty( $ids ) ) {
            update_option( self::COMPLETE_OPTION, self::INDEX_VERSION, false );
            delete_option( self::CURSOR_OPTION );
            return;
        }
        foreach ( $ids as $postId ) { $this->indexPost( $postId ); }
        update_option( self::CURSOR_OPTION, max( $ids ), false );
        wp_schedule_single_event( time() + 10, self::CRON );
    }

    /** @return array<string,string> language key => human label */
    public function catalog( bool $includeAuto = true ): array {
        $catalog = array();
        if ( $includeAuto ) { $catalog['auto'] = __( 'Unknown / auto-detect', 'yablonsky-seo' ); }
        foreach ( ProductLocaleRegistry::all() as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $code = $this->normalize( (string) ( $row['code'] ?? '' ) );
            if ( '' === $code || 'auto' === $code ) { continue; }
            $catalog[ $code ] = sanitize_text_field( (string) ( $row['name'] ?? $code ) );
        }
        /**
         * Allows a multilingual owner (for example YMulti) to expose site-enabled
         * languages without making YSEO depend on that plugin.
         * Expected shape: array( 'en' => 'English', 'lt' => 'Lietuvių', ... ).
         */
        $filtered = apply_filters( 'yablonsky_seo_available_content_languages_v1', $catalog );
        $filtered = apply_filters( 'yablonsky_performance_seo_available_content_languages_v1', $filtered );
        if ( is_array( $filtered ) ) {
            $clean = array();
            foreach ( $filtered as $key => $label ) {
                $key = $this->normalize( (string) $key );
                if ( '' === $key ) { continue; }
                $clean[ $key ] = sanitize_text_field( (string) $label );
            }
            if ( ! empty( $clean ) ) { $catalog = $clean; }
        }
        return $catalog;
    }

    /**
     * Optional snapshot avoids rebuilding a filter-adjusted locale catalog for
     * every row in a single admin table. Omitting it preserves existing callers.
     * @param array<string,string>|null $catalog
     */
    public function label( string $languageKey, ?array $catalog = null ): string {
        $languageKey = $this->normalize( $languageKey );
        if ( null === $catalog ) { $catalog = $this->catalog( true ); }
        return isset( $catalog[ $languageKey ] ) ? (string) $catalog[ $languageKey ] : strtoupper( $languageKey ?: 'AUTO' );
    }

    private function normalize( string $language ): string {
        $language = trim( str_replace( '_', '-', $language ) );
        if ( '' === $language ) { return ''; }
        if ( 'auto' === strtolower( $language ) ) { return 'auto'; }
        $parts = explode( '-', $language );
        $parts[0] = strtolower( preg_replace( '/[^A-Za-z]/', '', $parts[0] ) ?? '' );
        if ( '' === $parts[0] ) { return ''; }
        if ( isset( $parts[1] ) && '' !== $parts[1] ) {
            $second = preg_replace( '/[^A-Za-z]/', '', $parts[1] ) ?? '';
            $parts[1] = 4 === strlen( $second ) ? ucfirst( strtolower( $second ) ) : strtoupper( $second );
        }
        return implode( '-', array_filter( $parts, static function ( string $part ): bool { return '' !== $part; } ) );
    }
}
