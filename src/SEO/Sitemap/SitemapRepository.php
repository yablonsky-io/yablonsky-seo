<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

use Yablonsky\PerformanceSEO\Discovery\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SitemapRepository {
    private const SCHEMA_VERSION = '1';
    private const OPTION_SCHEMA = 'yps_sitemap_schema_version';
    private const OPTION_BACKFILL = 'yps_sitemap_backfill_state';
    private const OPTION_BACKFILL_LOCK = 'yps_sitemap_backfill_lock';
    private const OPTION_DIRTY = 'yps_sitemap_dirty_buckets';
    private const OPTION_CACHE_GENERATION = 'yps_sitemap_cache_generation';
    private const OPTION_SHADOW_PAUSED = 'yps_sitemap_shadow_paused';
    private const TRANSIENT_MANIFEST = 'yps_sitemap_manifest_v1';
    private const CACHE_GROUP = 'yablonsky-seo-sitemaps';
    private const BUCKET_SIZE = 1000;
    private const BUCKET_URL_BYTES = 8388608;

    /** @var string */ private $table;
    /** @var array<string,array{bucket:int,count:int,bytes:int}> */ private $hotBucketState = array();

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'yps_sitemap_membership';
    }

    public function table(): string { return $this->table; }
    public function bucketSize(): int { return self::BUCKET_SIZE; }

    public function ensureSchema(): void {
        if ( self::SCHEMA_VERSION !== (string) get_option( self::OPTION_SCHEMA, '' ) ) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$this->table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                resource_key varchar(191) NOT NULL,
                resource_type varchar(32) NOT NULL,
                resource_subtype varchar(64) NOT NULL,
                object_id bigint(20) unsigned NOT NULL,
                language_key varchar(32) NOT NULL DEFAULT '',
                url text NOT NULL,
                url_hash char(64) NOT NULL,
                lastmod datetime NULL,
                bucket_no int(10) unsigned NOT NULL,
                indexable tinyint(1) NOT NULL DEFAULT 1,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY resource_key (resource_key),
                KEY sitemap_slice (resource_type,resource_subtype,indexable,bucket_no,object_id),
                KEY object_lookup (resource_type,object_id),
                KEY updated_at (updated_at)
            ) {$charset};";
            dbDelta( $sql );
            update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
        }
        if ( false === get_option( self::OPTION_BACKFILL, false ) ) {
            update_option( self::OPTION_BACKFILL, array( 'phase' => 'posts', 'cursor' => 0, 'complete' => false, 'updated_at' => time() ), false );
        }
        if ( false === get_option( self::OPTION_CACHE_GENERATION, false ) ) {
            add_option( self::OPTION_CACHE_GENERATION, 1, '', false );
        }
    }

    /** @param array<string,mixed> $row */
    public function upsert( array $row ): void {
        global $wpdb;
        $resourceKey = (string) $row['resource_key'];
        $type = (string) $row['resource_type'];
        $subtype = (string) $row['resource_subtype'];
        $url = (string) $row['url'];

        // This is a write-path consistency read against the plugin-owned membership table.
        // It must not be cached because an existing member must retain its stable bucket.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; prepared bounded lookup required for stable bucket identity.
        $existingBucket = $wpdb->get_var( $wpdb->prepare( 'SELECT bucket_no FROM %i WHERE resource_key=%s LIMIT 1', $this->table, $resourceKey ) );
        $isNew = null === $existingBucket;
        $bucket = $isNew ? $this->nextBucket( $type, $subtype, strlen( $url ) ) : max( 1, (int) $existingBucket );

        // Plugin-owned materialized index. WordPress has no CRUD API for this table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional write to the plugin-owned sitemap membership table.
        $result = $wpdb->replace(
            $this->table,
            array(
                'resource_key'     => $resourceKey,
                'resource_type'    => $type,
                'resource_subtype' => $subtype,
                'object_id'        => (int) $row['object_id'],
                'language_key'     => (string) ( $row['language_key'] ?? '' ),
                'url'              => $url,
                'url_hash'         => hash( 'sha256', $url ),
                'lastmod'          => ! empty( $row['lastmod'] ) ? (string) $row['lastmod'] : null,
                'bucket_no'        => $bucket,
                'indexable'        => ! empty( $row['indexable'] ) ? 1 : 0,
                'updated_at'       => current_time( 'mysql', true ),
            ),
            array( '%s','%s','%s','%d','%s','%s','%s','%s','%d','%d','%s' )
        );
        if ( false === $result ) { return; }

        if ( $isNew ) { $this->rememberNewMembership( $type, $subtype, $bucket, strlen( $url ) ); }

        // During initial/rebuild warm-up there is no public YSEO child-cache authority yet.
        // Avoid one dirty-option write per indexed object; the ready-state transition is atomic.
        if ( $this->isReady() ) { $this->markDirty( $type, $subtype, $bucket ); }
    }

    /** Marks every materialized bucket containing one object dirty without changing membership. */
    public function invalidateObject( string $type, int $objectId ): void {
        if ( $objectId <= 0 || ! $this->isReady() ) { return; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded plugin-owned lookup used only to invalidate already materialized child caches.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare( 'SELECT resource_subtype,bucket_no FROM %i WHERE resource_type=%s AND object_id=%d', $this->table, sanitize_key( $type ), $objectId ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            $this->markDirty( sanitize_key( $type ), (string) ( $row['resource_subtype'] ?? '' ), (int) ( $row['bucket_no'] ?? 0 ) );
        }
    }

    public function deleteObject( string $type, int $objectId ): void {
        global $wpdb;
        // Write-path lookup is intentionally uncached: the exact old buckets must be invalidated.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; prepared bounded lookup immediately precedes deletion.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT resource_subtype,bucket_no FROM %i WHERE resource_type=%s AND object_id=%d', $this->table, $type, $objectId ), ARRAY_A );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional delete from the plugin-owned materialized index.
        $wpdb->delete( $this->table, array( 'resource_type' => $type, 'object_id' => $objectId ), array( '%s','%d' ) );
        if ( ! $this->isReady() ) { return; }
        foreach ( (array) $rows as $row ) { $this->markDirty( $type, (string) $row['resource_subtype'], (int) $row['bucket_no'] ); }
    }

    /** @return array<int,array<string,mixed>> */
    public function entries( string $type, string $subtype, int $bucket ): array {
        global $wpdb;
        // Child XML is cached as a whole only when a persistent object cache exists. Without one,
        // this deliberately reads one bounded materialized bucket instead of writing large XML to wp_options.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared bounded read from plugin-owned index; higher-level XML cache is handled by SitemapController.
        return (array) $wpdb->get_results(
            $wpdb->prepare( 'SELECT object_id,language_key,url,lastmod FROM %i WHERE resource_type=%s AND resource_subtype=%s AND bucket_no=%d AND indexable=1 ORDER BY object_id ASC, language_key ASC LIMIT 50000', $this->table, $type, $subtype, $bucket ),
            ARRAY_A
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function manifest(): array {
        $cached = get_transient( self::TRANSIENT_MANIFEST );
        if ( is_array( $cached ) ) { return $cached; }

        global $wpdb;
        // The manifest is a small aggregate over the plugin-owned materialized index and is cached here.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned aggregate; result is cached immediately below and invalidated on membership change.
        $manifest = (array) $wpdb->get_results(
            $wpdb->prepare( 'SELECT resource_type,resource_subtype,bucket_no,COUNT(*) AS url_count,MAX(lastmod) AS lastmod FROM %i WHERE indexable=1 GROUP BY resource_type,resource_subtype,bucket_no ORDER BY resource_type,resource_subtype,bucket_no', $this->table ),
            ARRAY_A
        );
        set_transient( self::TRANSIENT_MANIFEST, $manifest, HOUR_IN_SECONDS );
        return $manifest;
    }

    /** @return array<string,int|string|bool> */
    public function diagnostics(): array {
        $manifest = $this->manifest();
        $total = 0;
        foreach ( $manifest as $row ) { $total += isset( $row['url_count'] ) ? max( 0, (int) $row['url_count'] ) : 0; }

        $dirty = wp_using_ext_object_cache() ? get_option( self::OPTION_DIRTY, array() ) : array();
        $state = $this->backfillState();
        return array(
            'urls' => $total,
            'chunks' => count( $manifest ),
            'dirty' => is_array( $dirty ) ? count( $dirty ) : 0,
            'backfill_complete' => ! empty( $state['complete'] ),
            'backfill_phase' => isset( $state['phase'] ) ? (string) $state['phase'] : 'posts',
            'bucket_size' => self::BUCKET_SIZE,
            'bucket_byte_budget' => self::BUCKET_URL_BYTES,
            'cache_generation' => $this->cacheGeneration(),
            'persistent_object_cache' => wp_using_ext_object_cache(),
            'shadow_paused' => $this->shadowPaused(),
        );
    }

    /** @return array<string,mixed> */
    public function backfillState(): array {
        $state = get_option( self::OPTION_BACKFILL, array() );
        return is_array( $state ) ? $state : array();
    }

    /** @param array<string,mixed> $state */
    public function saveBackfillState( array $state ): void {
        $previous = $this->backfillState();
        $state['updated_at'] = time();
        update_option( self::OPTION_BACKFILL, $state, false );
        delete_transient( self::TRANSIENT_MANIFEST );
        if ( empty( $previous['complete'] ) && ! empty( $state['complete'] ) ) {
            do_action( 'yablonsky_seo_sitemap_full_invalidate_v1' );
        }
    }

    public function acquireBackfillLock( int $ttlSeconds = 120 ): ?string {
        $ttlSeconds = max( 30, min( 300, $ttlSeconds ) );
        $now = time();
        $token = wp_generate_uuid4();
        $value = array( 'token' => $token, 'expires_at' => $now + $ttlSeconds );
        if ( add_option( self::OPTION_BACKFILL_LOCK, $value, '', false ) ) { return $token; }

        $existing = get_option( self::OPTION_BACKFILL_LOCK, null );
        if ( ! is_array( $existing ) || ! isset( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now ) { return null; }
        delete_option( self::OPTION_BACKFILL_LOCK );
        return add_option( self::OPTION_BACKFILL_LOCK, $value, '', false ) ? $token : null;
    }

    public function releaseBackfillLock( string $token ): void {
        if ( '' === $token ) { return; }
        $existing = get_option( self::OPTION_BACKFILL_LOCK, null );
        if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) {
            delete_option( self::OPTION_BACKFILL_LOCK );
        }
    }

    public function pauseForShadow(): void {
        if ( false === get_option( self::OPTION_SHADOW_PAUSED, false ) ) {
            add_option( self::OPTION_SHADOW_PAUSED, time(), '', false );
        }
    }

    public function shadowPaused(): bool {
        return false !== get_option( self::OPTION_SHADOW_PAUSED, false );
    }

    public function resumeAfterShadowIfNeeded(): void {
        if ( ! $this->shadowPaused() ) { return; }
        // Membership was intentionally not maintained while another provider owned public SEO.
        // Rebuild from zero under the core-sitemap fallback before YSEO can take sitemap authority.
        $this->truncateAndReset();
        delete_option( self::OPTION_SHADOW_PAUSED );
    }

    public function truncateAndReset(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit rebuild of the plugin-owned materialized index.
        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->table ) );
        delete_option( self::OPTION_DIRTY );
        delete_transient( self::TRANSIENT_MANIFEST );
        $this->hotBucketState = array();
        $this->bumpCacheGeneration();
        $this->resetBackfill();
        do_action( 'yablonsky_seo_sitemap_full_invalidate_v1' );
    }

    public function resetBackfill(): void {
        update_option( self::OPTION_BACKFILL, array( 'phase' => 'posts', 'cursor' => 0, 'complete' => false, 'updated_at' => time() ), false );
    }

    public function isReady(): bool {
        $state = $this->backfillState();
        return ! empty( $state['complete'] );
    }

    public function childCacheKey( string $type, string $subtype, int $bucket ): string {
        $settings = get_option( 'yps_public_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $featureSignature = implode(
            '',
            array(
                ! empty( $settings['agent_markdown_enabled'] ) ? '1' : '0',
                ! empty( $settings['agent_scoped_llms_enabled'] ) ? '1' : '0',
                ! empty( $settings['agent_sitemap_extension_enabled'] ) ? '1' : '0',
            )
        );
        $discoverySignature = ( new DiscoverySettings() )->sitemapPayloadSignature();
        return 'v' . sanitize_key( str_replace( '.', '-', YPS_VERSION ) ) . ':g' . $this->cacheGeneration() . ':a' . $featureSignature . ':d' . $discoverySignature . ':child:' . sanitize_key( $type ) . ':' . sanitize_key( $subtype ) . ':' . max( 1, $bucket );
    }

    public function cacheGeneration(): int {
        return max( 1, (int) get_option( self::OPTION_CACHE_GENERATION, 1 ) );
    }

    private function bumpCacheGeneration(): void {
        $generation = $this->cacheGeneration();
        $generation = $generation >= 2147483646 ? 1 : $generation + 1;
        update_option( self::OPTION_CACHE_GENERATION, $generation, false );
    }

    private function nextBucket( string $type, string $subtype, int $newUrlBytes ): int {
        $key = sanitize_key( $type . ':' . $subtype );
        if ( ! isset( $this->hotBucketState[ $key ] ) ) {
            global $wpdb;
            // One uncached hot-bucket state read per resource subtype/request replaces two reads per inserted object.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared write-path allocator read from plugin-owned index; caching across requests could overfill stale buckets.
            $stats = $wpdb->get_row(
                $wpdb->prepare( 'SELECT bucket_no,COUNT(*) AS row_count,COALESCE(SUM(OCTET_LENGTH(url)),0) AS url_bytes FROM %i WHERE resource_type=%s AND resource_subtype=%s GROUP BY bucket_no ORDER BY bucket_no DESC LIMIT 1', $this->table, $type, $subtype ),
                ARRAY_A
            );
            $this->hotBucketState[ $key ] = array(
                'bucket' => is_array( $stats ) && ! empty( $stats['bucket_no'] ) ? max( 1, (int) $stats['bucket_no'] ) : 1,
                'count' => is_array( $stats ) ? max( 0, (int) $stats['row_count'] ) : 0,
                'bytes' => is_array( $stats ) ? max( 0, (int) $stats['url_bytes'] ) : 0,
            );
        }

        $state = $this->hotBucketState[ $key ];
        $wouldExceedBytes = $state['count'] > 0 && ( $state['bytes'] + max( 0, $newUrlBytes ) ) > self::BUCKET_URL_BYTES;
        if ( $state['count'] >= self::BUCKET_SIZE || $wouldExceedBytes ) {
            $state = array( 'bucket' => $state['bucket'] + 1, 'count' => 0, 'bytes' => 0 );
            $this->hotBucketState[ $key ] = $state;
        }
        return $state['bucket'];
    }

    private function rememberNewMembership( string $type, string $subtype, int $bucket, int $urlBytes ): void {
        $key = sanitize_key( $type . ':' . $subtype );
        if ( ! isset( $this->hotBucketState[ $key ] ) || (int) $this->hotBucketState[ $key ]['bucket'] !== $bucket ) {
            $this->hotBucketState[ $key ] = array( 'bucket' => $bucket, 'count' => 0, 'bytes' => 0 );
        }
        $this->hotBucketState[ $key ]['count']++;
        $this->hotBucketState[ $key ]['bytes'] += max( 0, $urlBytes );
    }

    public function markDirty( string $type, string $subtype, int $bucket ): void {
        delete_transient( self::TRANSIENT_MANIFEST );
        do_action( 'yablonsky_seo_sitemap_bucket_invalidated_v1', sanitize_key( $type ), sanitize_key( $subtype ), max( 1, $bucket ) );
        do_action( 'yablonsky_seo_sitemap_index_invalidated_v1' );

        // Without a persistent object cache child XML is rebuilt from the bounded ledger on every request,
        // so there is no persistent child payload to mark dirty or clear.
        if ( ! wp_using_ext_object_cache() ) { return; }

        $dirty = get_option( self::OPTION_DIRTY, array() );
        $dirty = is_array( $dirty ) ? $dirty : array();
        $dirty[ sanitize_key( $type . ':' . $subtype . ':' . $bucket ) ] = time();
        if ( count( $dirty ) > 2000 ) { $dirty = array_slice( $dirty, -2000, null, true ); }
        update_option( self::OPTION_DIRTY, $dirty, false );
        wp_cache_delete( $this->childCacheKey( $type, $subtype, $bucket ), self::CACHE_GROUP );
    }

    public function clearDirty( string $type, string $subtype, int $bucket ): void {
        if ( ! wp_using_ext_object_cache() ) { return; }
        $dirty = get_option( self::OPTION_DIRTY, array() );
        if ( ! is_array( $dirty ) ) { return; }
        $key = sanitize_key( $type . ':' . $subtype . ':' . $bucket );
        if ( ! isset( $dirty[ $key ] ) ) { return; }
        unset( $dirty[ $key ] );
        update_option( self::OPTION_DIRTY, $dirty, false );
    }
}
