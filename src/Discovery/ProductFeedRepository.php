<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Materialized product feed rows: expensive WooCommerce projection happens on writes/backfill, not on feed fetch. */
final class ProductFeedRepository {
    private const SCHEMA_VERSION = '1';
    private const OPTION_SCHEMA = 'yseo_product_feed_schema_version';
    private const OPTION_STATE = 'yseo_product_feed_backfill_v1';
    private const OPTION_LOCK = 'yseo_product_feed_backfill_lock_v1';
    private const OPTION_REVISION = 'yseo_product_feed_revision_v1';
    /** @var string */ private $table;

    public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'yseo_product_feed'; }

    public function ensureSchema(): void {
        if ( self::SCHEMA_VERSION !== (string) get_option( self::OPTION_SCHEMA, '' ) ) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$this->table} (
                product_id bigint(20) unsigned NOT NULL,
                payload longtext NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (product_id),
                KEY updated_at (updated_at)
            ) {$charset};";
            dbDelta( $sql );
            update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
        }
        if ( false === get_option( self::OPTION_STATE, false ) ) { $this->resetBackfill(); }
        if ( false === get_option( self::OPTION_REVISION, false ) ) { add_option( self::OPTION_REVISION, 1, '', false ); }
    }

    /** @param array<string,mixed> $payload */
    public function upsert( int $productId, array $payload ): void {
        if ( $productId <= 0 ) { return; }
        $json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) || '' === $json ) { return; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned materialized feed table.
        $result = $wpdb->replace( $this->table, array( 'product_id' => $productId, 'payload' => $json, 'updated_at' => current_time( 'mysql', true ) ), array( '%d','%s','%s' ) );
        if ( false !== $result && $this->isReady() ) { $this->bumpRevision(); }
    }

    public function delete( int $productId ): void {
        if ( $productId <= 0 ) { return; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned materialized feed table.
        $deleted = $wpdb->delete( $this->table, array( 'product_id' => $productId ), array( '%d' ) );
        if ( $deleted && $this->isReady() ) { $this->bumpRevision(); }
    }

    /** @return array<int,array{product_id:int,payload:string}> */
    public function pageAfter( int $productId, int $limit = 500 ): array {
        global $wpdb;
        $limit = max( 50, min( 1000, $limit ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Streaming bounded read from plugin-owned materialized feed table.
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT product_id,payload FROM %i WHERE product_id>%d ORDER BY product_id ASC LIMIT %d', $this->table, $productId, $limit ), ARRAY_A );
    }

    public function count(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small admin/feed diagnostic aggregate on plugin-owned table.
        return max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) ) );
    }

    public function truncateAndReset(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit rebuild of plugin-owned materialization.
        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->table ) );
        $this->bumpRevision();
        $this->resetBackfill();
    }

    public function resetBackfill(): void { update_option( self::OPTION_STATE, array( 'cursor' => 0, 'complete' => false, 'updated_at' => time() ), false ); }
    /** @return array<string,mixed> */
    public function backfillState(): array { $state = get_option( self::OPTION_STATE, array() ); return is_array( $state ) ? $state : array(); }
    /** @param array<string,mixed> $state */
    public function saveBackfillState( array $state ): void { $state['updated_at'] = time(); update_option( self::OPTION_STATE, $state, false ); if ( ! empty( $state['complete'] ) ) { $this->bumpRevision(); } }
    public function isReady(): bool { $state = $this->backfillState(); return ! empty( $state['complete'] ); }
    public function revision(): int { return max( 1, (int) get_option( self::OPTION_REVISION, 1 ) ); }
    public function touchRevision(): void { $this->bumpRevision(); }

    public function acquireLock( int $ttl = 120 ): ?string {
        $ttl = max( 30, min( 300, $ttl ) ); $now = time(); $token = wp_generate_uuid4(); $value = array( 'token' => $token, 'expires_at' => $now + $ttl );
        if ( add_option( self::OPTION_LOCK, $value, '', false ) ) { return $token; }
        $existing = get_option( self::OPTION_LOCK, null );
        if ( ! is_array( $existing ) || ! isset( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now ) { return null; }
        delete_option( self::OPTION_LOCK );
        return add_option( self::OPTION_LOCK, $value, '', false ) ? $token : null;
    }

    public function releaseLock( string $token ): void {
        $existing = get_option( self::OPTION_LOCK, null );
        if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) { delete_option( self::OPTION_LOCK ); }
    }

    private function bumpRevision(): void {
        $revision = $this->revision();
        update_option( self::OPTION_REVISION, $revision >= 2147483646 ? 1 : $revision + 1, false );
    }
}
