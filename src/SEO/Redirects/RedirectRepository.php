<?php
namespace Yablonsky\PerformanceSEO\SEO\Redirects;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RedirectRepository {
    private const SCHEMA_VERSION = '3';
    private const OPTION_SCHEMA = 'yseo_redirect_schema_version';
    private const CACHE_GROUP = 'yablonsky-seo-redirects';
    /** @var string */ private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'yseo_redirects';
    }

    public function ensureSchema(): void {
        $previous = (string) get_option( self::OPTION_SCHEMA, '' );
        if ( self::SCHEMA_VERSION === $previous ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            origin text NOT NULL,
            origin_hash char(64) NOT NULL,
            target text NOT NULL,
            status_code smallint(5) unsigned NOT NULL DEFAULT 301,
            match_type varchar(16) NOT NULL DEFAULT 'plain',
            language_key varchar(32) NOT NULL DEFAULT 'any',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            source varchar(24) NOT NULL DEFAULT 'manual',
            hits bigint(20) unsigned NOT NULL DEFAULT 0,
            last_hit_gmt datetime NULL,
            created_gmt datetime NOT NULL,
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY origin_match_language (origin_hash,match_type,language_key),
            KEY enabled_match (enabled,match_type),
            KEY language_enabled (language_key,enabled),
            KEY status_code (status_code),
            KEY updated_id (updated_gmt,id),
            KEY enabled_updated (enabled,updated_gmt,id)
        ) {$charset};";
        dbDelta( $sql );
        if ( '' !== $previous && version_compare( $previous, '3', '<' ) ) {
            // Schema v2 used a two-column unique key. dbDelta() adds the new
            // language-aware key but does not reliably remove obsolete indexes.
            // Drop only this known plugin-owned legacy index, then re-run dbDelta.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time plugin-owned schema migration.
            $legacyIndex = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s LIMIT 1', $this->table, 'origin_match' ) );
            if ( null !== $legacyIndex ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time migration of the exact plugin-owned legacy index; required so language-specific redirect uniqueness can work.
                $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $this->table, 'origin_match' ) );
                dbDelta( $sql );
            }
        }
        update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
        $this->flushCache();
    }

    /** @return array<string,mixed>|null */
    public function plain( string $origin, string $language = '' ): ?array {
        $hash = hash( 'sha256', $origin );
        $language = sanitize_text_field( $language );
        $cacheKey = $this->versionedCacheKey( 'plain:' . $hash . ':' . ( '' !== $language ? $language : 'any' ) );
        $cached = wp_cache_get( $cacheKey, self::CACHE_GROUP );
        if ( is_array( $cached ) ) { return $cached; }
        global $wpdb;
        if ( '' !== $language ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed plugin-owned redirect lookup; result is cached immediately below.
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE origin_hash=%s AND match_type=%s AND enabled=1 AND language_key IN (%s,%s) ORDER BY CASE WHEN language_key=%s THEN 0 ELSE 1 END,id ASC LIMIT 1', $this->table, $hash, 'plain', $language, 'any', $language ), ARRAY_A );
        } else {
            // Fail closed for language-specific rules when no request-language authority exists. Existing rules migrate as any.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed plugin-owned redirect lookup; result is cached immediately below.
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE origin_hash=%s AND match_type=%s AND enabled=1 AND language_key=%s LIMIT 1', $this->table, $hash, 'plain', 'any' ), ARRAY_A );
        }
        if ( ! is_array( $row ) ) { return null; }
        wp_cache_set( $cacheKey, $row, self::CACHE_GROUP, 300 );
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function regexRules( string $language = '' ): array {
        $language = sanitize_text_field( $language );
        $cacheKey = $this->versionedCacheKey( 'regex:enabled:' . ( '' !== $language ? $language : 'any' ) );
        $cached = wp_cache_get( $cacheKey, self::CACHE_GROUP );
        if ( is_array( $cached ) ) { return $cached; }
        global $wpdb;
        if ( '' !== $language ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded enabled regex list cached for runtime matching.
            $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE match_type=%s AND enabled=1 AND language_key IN (%s,%s) ORDER BY CASE WHEN language_key=%s THEN 0 ELSE 1 END,id ASC LIMIT 200', $this->table, 'regex', $language, 'any', $language ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded language-neutral regex list cached for runtime matching.
            $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE match_type=%s AND enabled=1 AND language_key=%s ORDER BY id ASC LIMIT 200', $this->table, 'regex', 'any' ), ARRAY_A );
        }
        wp_cache_set( $cacheKey, $rows, self::CACHE_GROUP, 300 );
        return $rows;
    }

    /** @param array<string,mixed> $rule */
    public function save( array $rule ): int {
        $origin = (string) ( $rule['origin'] ?? '' );
        $matchType = in_array( (string) ( $rule['match_type'] ?? '' ), array( 'plain', 'regex' ), true ) ? (string) $rule['match_type'] : 'plain';
        $target = (string) ( $rule['target'] ?? '' );
        $status = in_array( (int) ( $rule['status_code'] ?? 301 ), array( 301, 302, 307, 410, 451 ), true ) ? (int) $rule['status_code'] : 301;
        $source = sanitize_key( (string) ( $rule['source'] ?? 'manual' ) );
        $enabled = empty( $rule['enabled'] ) ? 0 : 1;
        $language = sanitize_text_field( (string) ( $rule['language_key'] ?? 'any' ) );
        if ( '' === $language ) { $language = 'any'; }
        $now = current_time( 'mysql', true );
        $hash = hash( 'sha256', $origin );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional write to plugin-owned redirect table.
        $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE origin_hash=%s AND match_type=%s AND language_key=%s LIMIT 1', $this->table, $hash, $matchType, $language ) );
        // A failed lookup must not be mistaken for an absent rule: inserting a
        // replacement would hide the database error and risk a duplicate.
        if ( '' !== (string) $wpdb->last_error ) { return 0; }
        $data = array(
            'origin' => $origin,
            'origin_hash' => $hash,
            'target' => $target,
            'status_code' => $status,
            'match_type' => $matchType,
            'language_key' => $language,
            'enabled' => $enabled,
            'source' => '' !== $source ? $source : 'manual',
            'updated_gmt' => $now,
        );
        if ( $existing ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional update to plugin-owned redirect table.
            $updated = $wpdb->update( $this->table, $data, array( 'id' => (int) $existing ), array( '%s','%s','%s','%d','%s','%s','%d','%s','%s' ), array( '%d' ) );
            if ( false === $updated ) { return 0; }
            $this->flushCache();
            return (int) $existing;
        }
        $data['created_gmt'] = $now;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert to plugin-owned redirect table.
        $inserted = $wpdb->insert( $this->table, $data, array( '%s','%s','%s','%d','%s','%s','%d','%s','%s','%s' ) );
        if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) { return 0; }
        $this->flushCache();
        return (int) $wpdb->insert_id;
    }

    public function delete( int $id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional delete from plugin-owned redirect table.
        $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
        $this->flushCache();
    }

    public function toggle( int $id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned single-row administrative read.
        $enabled = $wpdb->get_var( $wpdb->prepare( 'SELECT enabled FROM %i WHERE id=%d LIMIT 1', $this->table, $id ) );
        if ( null === $enabled ) { return; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional update to plugin-owned redirect table.
        $wpdb->update( $this->table, array( 'enabled' => (int) ! (bool) $enabled, 'updated_gmt' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%d','%s' ), array( '%d' ) );
        $this->flushCache();
    }

    public function setEnabled( int $id, bool $enabled ): bool {
        if ( $id <= 0 ) { return false; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional bounded update to the plugin-owned redirect table.
        $updated = $wpdb->update(
            $this->table,
            array( 'enabled' => $enabled ? 1 : 0, 'updated_gmt' => current_time( 'mysql', true ) ),
            array( 'id' => $id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
        if ( false !== $updated ) { $this->flushCache(); return true; }
        return false;
    }

    public function recordHit( int $id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded statistics update in plugin-owned table.
        $wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits=hits+1,last_hit_gmt=%s WHERE id=%d', $this->table, current_time( 'mysql', true ), $id ) );
    }

    /**
     * Paginated redirect inventory for the admin UI.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function page( int $page = 1, int $perPage = 25, string $search = '', string $match = '', int $status = 0, string $state = '', string $language = '', string $source = '', string $dateFrom = '', string $dateTo = '' ): array {
        global $wpdb;
        $page = max( 1, $page );
        $perPage = min( 100, max( 10, $perPage ) );
        $search = trim( sanitize_text_field( $search ) );
        $match = in_array( $match, array( 'plain', 'regex' ), true ) ? $match : '';
        $status = in_array( $status, array( 301,302,307,410,451 ), true ) ? $status : 0;
        $state = in_array( $state, array( 'enabled', 'disabled' ), true ) ? $state : '';
        $language = sanitize_text_field( $language );
        $source = sanitize_key( $source );
        $source = in_array( $source, array( 'manual', 'csv', 'auto-post-move', 'auto-term-move' ), true ) ? $source : '';
        $dateFrom = $this->normalizeDate( $dateFrom );
        $dateTo = $this->normalizeDate( $dateTo );

        $where = array( '1=1' );
        $values = array();
        if ( '' !== $match ) { $where[] = 'match_type=%s'; $values[] = $match; }
        if ( $status > 0 ) { $where[] = 'status_code=%d'; $values[] = $status; }
        if ( '' !== $state ) { $where[] = 'enabled=%d'; $values[] = 'enabled' === $state ? 1 : 0; }
        if ( '' !== $language ) { $where[] = 'language_key=%s'; $values[] = $language; }
        if ( '' !== $source ) { $where[] = 'source=%s'; $values[] = $source; }
        if ( '' !== $dateFrom ) { $where[] = 'updated_gmt>=%s'; $values[] = $dateFrom . ' 00:00:00'; }
        if ( '' !== $dateTo ) { $where[] = 'updated_gmt<%s'; $values[] = gmdate( 'Y-m-d H:i:s', strtotime( $dateTo . ' +1 day' ) ); }
        if ( '' !== $search ) {
            $where[] = '(origin LIKE %s OR target LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $values[] = $like;
            $values[] = $like;
        }
        $whereSql = implode( ' AND ', $where );
        $countSql = "SELECT COUNT(*) FROM %i WHERE {$whereSql}";
        $countArgs = array_merge( array( $this->table ), $values );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic clauses are fixed strings; all identifiers and user-controlled values are prepared.
        $total = (int) $wpdb->get_var( $wpdb->prepare( $countSql, $countArgs ) );
        $pages = max( 1, (int) ceil( $total / $perPage ) );
        $page = min( $page, $pages );
        $offset = ( $page - 1 ) * $perPage;
        $sql = "SELECT * FROM %i WHERE {$whereSql} ORDER BY updated_gmt DESC,id DESC LIMIT %d OFFSET %d";
        $args = array_merge( array( $this->table ), $values, array( $perPage, $offset ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic clauses are fixed strings; all identifiers and user-controlled values are prepared and the result is paginated.
        $items = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        return array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage );
    }

    /**
     * Keyset-paginated export batch. This deliberately does not use OFFSET so a
     * large export remains predictable as the table grows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function exportBatch( int $afterId = 0, int $limit = 500 ): array {
        global $wpdb;
        $afterId = max( 0, $afterId );
        $limit = min( 1000, max( 50, $limit ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit user-requested export; keyset query is bounded and uses the table primary key.
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id>%d ORDER BY id ASC LIMIT %d', $this->table, $afterId, $limit ), ARRAY_A );
    }

    /** @return array<string,int> */
    public function diagnostics(): array {
        $cacheKey = $this->versionedCacheKey( 'diagnostics' );
        $cached = wp_cache_get( $cacheKey, self::CACHE_GROUP );
        if ( is_array( $cached ) ) { return $cached; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate over plugin-owned redirect table; cached immediately below.
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT match_type,enabled,COUNT(*) AS n FROM %i GROUP BY match_type,enabled', $this->table ), ARRAY_A );
        $out = array( 'total' => 0, 'enabled' => 0, 'plain' => 0, 'regex' => 0 );
        foreach ( $rows as $row ) {
            $n = (int) ( $row['n'] ?? 0 );
            $out['total'] += $n;
            if ( ! empty( $row['enabled'] ) ) { $out['enabled'] += $n; }
            $type = (string) ( $row['match_type'] ?? '' );
            if ( isset( $out[ $type ] ) ) { $out[ $type ] += $n; }
        }
        wp_cache_set( $cacheKey, $out, self::CACHE_GROUP, 60 );
        return $out;
    }

    private function normalizeDate( string $date ): string {
        $date = trim( sanitize_text_field( $date ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return ''; }
        list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );
        return checkdate( $month, $day, $year ) ? $date : '';
    }

    /**
     * Object-cache implementations may provide wp_cache_flush_group() without supporting
     * group invalidation. Namespace keys when necessary so edited redirects cannot be
     * served from a previous cache generation.
     */
    private function versionedCacheKey( string $key ): string {
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) { return $key; }
        return (string) get_option( 'yseo_redirect_cache_generation', '0' ) . ':' . $key;
    }

    private function flushCache(): void {
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) && function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( self::CACHE_GROUP );
            return;
        }
        // No unsupported flush call: a new generation leaves existing short-TTL keys
        // unreachable, while preserving other WordPress plugins' shared object cache.
        update_option( 'yseo_redirect_cache_generation', wp_generate_uuid4(), false );
    }
}
