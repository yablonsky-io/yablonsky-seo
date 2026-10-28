<?php
namespace Yablonsky\PerformanceSEO\SEO\InternalLinks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class InternalLinkRepository {
    private const SCHEMA_VERSION = '3';
    private const OPTION_SCHEMA = 'yseo_internal_link_schema_version';
    private const CACHE_GROUP = 'yablonsky-seo-internal-links';
    /** @var string */ private $edgesTable;
    /** @var string */ private $sourcesTable;

    public function __construct() {
        global $wpdb;
        $this->edgesTable = $wpdb->prefix . 'yseo_internal_links';
        $this->sourcesTable = $wpdb->prefix . 'yseo_internal_link_sources';
    }

    public function ensureSchema(): void {
        $previous = (string) get_option( self::OPTION_SCHEMA, '' );
        if ( self::SCHEMA_VERSION === $previous ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $edgesSql = "CREATE TABLE {$this->edgesTable} (
            source_id bigint(20) unsigned NOT NULL,
            target_id bigint(20) unsigned NOT NULL,
            link_count smallint(5) unsigned NOT NULL DEFAULT 1,
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (source_id,target_id),
            KEY target_id (target_id),
            KEY updated_gmt (updated_gmt)
        ) {$charset};";
        $sourcesSql = "CREATE TABLE {$this->sourcesTable} (
            source_id bigint(20) unsigned NOT NULL,
            content_hash char(64) NOT NULL,
            post_type varchar(32) NOT NULL DEFAULT '',
            language_key varchar(32) NOT NULL DEFAULT 'auto',
            is_cornerstone tinyint(1) NOT NULL DEFAULT 0,
            modified_gmt datetime NULL,
            outbound_links int(10) unsigned NOT NULL DEFAULT 0,
            distinct_targets int(10) unsigned NOT NULL DEFAULT 0,
            indexed_gmt datetime NOT NULL,
            PRIMARY KEY  (source_id),
            KEY post_type (post_type),
            KEY language_key (language_key),
            KEY cornerstone_modified (is_cornerstone,modified_gmt),
            KEY modified_gmt (modified_gmt),
            KEY indexed_gmt (indexed_gmt)
        ) {$charset};";
        dbDelta( $edgesSql );
        dbDelta( $sourcesSql );
        update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );

        if ( '' !== $previous && self::SCHEMA_VERSION !== $previous ) {
            // The table is derived evidence. A forced bounded backfill populates the new
            // navigation columns without deleting the still-useful existing edge graph.
            delete_option( 'yseo_internal_link_backfill_cursor' );
            delete_option( 'yseo_internal_link_backfill_complete' );
        }
        $this->flushCache();
    }

    /** @param array<int,int> $targetCounts */
    public function replaceSource( int $sourceId, string $contentHash, array $targetCounts, string $postType = '', bool $isCornerstone = false, string $modifiedGmt = '', string $languageKey = 'auto' ): void {
        if ( $sourceId <= 0 ) { return; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional replacement of plugin-owned internal-link edges for one saved source resource.
        $wpdb->delete( $this->edgesTable, array( 'source_id' => $sourceId ), array( '%d' ) );
        $total = 0;
        $now = current_time( 'mysql', true );
        foreach ( array_slice( $targetCounts, 0, 250, true ) as $targetId => $count ) {
            $targetId = (int) $targetId;
            $count = min( 65535, max( 1, (int) $count ) );
            if ( $targetId <= 0 || $targetId === $sourceId ) { continue; }
            $total += $count;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional bounded write to plugin-owned internal-link edge table.
            $wpdb->insert(
                $this->edgesTable,
                array( 'source_id' => $sourceId, 'target_id' => $targetId, 'link_count' => $count, 'updated_gmt' => $now ),
                array( '%d', '%d', '%d', '%s' )
            );
        }
        $data = array(
            'source_id' => $sourceId,
            'content_hash' => sanitize_text_field( $contentHash ),
            'post_type' => sanitize_key( $postType ),
            'language_key' => sanitize_text_field( $languageKey ?: 'auto' ),
            'is_cornerstone' => $isCornerstone ? 1 : 0,
            'modified_gmt' => '' !== $modifiedGmt ? sanitize_text_field( $modifiedGmt ) : null,
            'outbound_links' => $total,
            'distinct_targets' => count( array_filter( array_map( 'intval', array_keys( $targetCounts ) ) ) ),
            'indexed_gmt' => $now,
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned source ledger; one row per indexed WordPress object.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT source_id FROM %i WHERE source_id=%d LIMIT 1', $this->sourcesTable, $sourceId ) );
        if ( $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional update to plugin-owned source ledger.
            $wpdb->update( $this->sourcesTable, $data, array( 'source_id' => $sourceId ), array( '%d','%s','%s','%s','%d','%s','%d','%d','%s' ), array( '%d' ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert to plugin-owned source ledger.
            $wpdb->insert( $this->sourcesTable, $data, array( '%d','%s','%s','%s','%d','%s','%d','%d','%s' ) );
        }
        $this->flushCache();
    }

    public function removePost( int $postId ): void {
        if ( $postId <= 0 ) { return; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup of plugin-owned local link evidence for a deleted/non-public resource.
        $wpdb->delete( $this->edgesTable, array( 'source_id' => $postId ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup incoming evidence pointing to a deleted/non-public resource.
        $wpdb->delete( $this->edgesTable, array( 'target_id' => $postId ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup plugin-owned indexing ledger.
        $wpdb->delete( $this->sourcesTable, array( 'source_id' => $postId ), array( '%d' ) );
        $this->flushCache();
    }

    public function reset(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative reset of plugin-owned derived evidence; identifier is prepared with %i.
        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->edgesTable ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative reset of plugin-owned derived evidence; identifier is prepared with %i.
        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->sourcesTable ) );
        $this->flushCache();
    }

    public function sourceHash( int $sourceId ): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single local derived-state read used to avoid needless re-indexing.
        $value = $wpdb->get_var( $wpdb->prepare( 'SELECT content_hash FROM %i WHERE source_id=%d LIMIT 1', $this->sourcesTable, $sourceId ) );
        return is_string( $value ) ? $value : '';
    }

    /** @return array<int,int> */
    public function outboundTargetIds( int $sourceId ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded plugin-owned edge read for editor link suggestions.
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT target_id,link_count FROM %i WHERE source_id=%d ORDER BY link_count DESC,target_id ASC LIMIT 250', $this->edgesTable, $sourceId ), ARRAY_A );
        $out = array();
        foreach ( $rows as $row ) { $out[ (int) $row['target_id'] ] = (int) $row['link_count']; }
        return $out;
    }

    /** @return array<string,int> */
    public function statsForPost( int $postId ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate over plugin-owned link-edge table for one resource.
        $out = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS distinct_targets,COALESCE(SUM(link_count),0) AS links FROM %i WHERE source_id=%d', $this->edgesTable, $postId ), ARRAY_A );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate over plugin-owned link-edge table for one resource.
        $in = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS distinct_sources,COALESCE(SUM(link_count),0) AS links FROM %i WHERE target_id=%d AND source_id<>%d', $this->edgesTable, $postId, $postId ), ARRAY_A );
        return array(
            'outbound_targets' => (int) ( $out['distinct_targets'] ?? 0 ),
            'outbound_links' => (int) ( $out['links'] ?? 0 ),
            'inbound_sources' => (int) ( $in['distinct_sources'] ?? 0 ),
            'inbound_links' => (int) ( $in['links'] ?? 0 ),
        );
    }

    /** @return array<string,int> */
    public function diagnostics(): array {
        $cached = wp_cache_get( 'diagnostics', self::CACHE_GROUP );
        if ( is_array( $cached ) ) { return $cached; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small local aggregate over plugin-owned indexing ledger; cached below.
        $sources = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->sourcesTable ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small local aggregate over plugin-owned edge table; cached below.
        $edges = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->edgesTable ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small local aggregate over plugin-owned edge table; cached below.
        $links = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(link_count),0) FROM %i', $this->edgesTable ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small local aggregate over plugin-owned edge table; cached below.
        $targets = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT target_id) FROM %i', $this->edgesTable ) );
        $eligible = $this->eligiblePublishedCount();
        $out = array(
            'indexed_sources' => $sources,
            'eligible_sources' => $eligible,
            'unindexed_sources' => max( 0, $eligible - $sources ),
            'distinct_edges' => $edges,
            'link_occurrences' => $links,
            'linked_targets' => $targets,
            'orphaned' => $this->orphanCount(),
            'stale_cornerstones' => $this->staleCornerstoneCount(),
        );
        wp_cache_set( 'diagnostics', $out, self::CACHE_GROUP, 60 );
        return $out;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function orphanedPage( int $page = 1, int $perPage = 25, string $search = '', string $postType = '', string $language = '', string $dateFrom = '', string $dateTo = '', string $sort = 'modified_desc' ): array {
        return $this->sourcePage( 'orphaned', $page, $perPage, $search, $postType, $language, $dateFrom, $dateTo, $sort );
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function staleCornerstonesPage( int $page = 1, int $perPage = 25, string $search = '', string $postType = '', string $language = '', string $dateFrom = '', string $dateTo = '', string $sort = 'modified_asc' ): array {
        return $this->sourcePage( 'cornerstone', $page, $perPage, $search, $postType, $language, $dateFrom, $dateTo, $sort );
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function inventoryPage( int $page = 1, int $perPage = 25, string $search = '', string $postType = '', string $language = '', string $dateFrom = '', string $dateTo = '', string $sort = 'modified_desc' ): array {
        $pageData = $this->sourcePage( 'inventory', $page, $perPage, $search, $postType, $language, $dateFrom, $dateTo, $sort );
        if ( empty( $pageData['items'] ) ) { return $pageData; }
        $ids = array_values( array_filter( array_map( static function ( array $row ): int { return (int) ( $row['ID'] ?? 0 ); }, $pageData['items'] ) ) );
        if ( empty( $ids ) ) { return $pageData; }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT target_id,COUNT(*) AS inbound_sources,COALESCE(SUM(link_count),0) AS inbound_links FROM %i WHERE target_id IN ({$placeholders}) GROUP BY target_id";
        $args = array_merge( array( $this->edgesTable ), $ids );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier and bounded row IDs are prepared; one aggregate enriches only the current admin page.
        $inboundRows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        $inbound = array();
        foreach ( $inboundRows as $row ) {
            $inbound[ (int) ( $row['target_id'] ?? 0 ) ] = array(
                'inbound_sources' => (int) ( $row['inbound_sources'] ?? 0 ),
                'inbound_links' => (int) ( $row['inbound_links'] ?? 0 ),
            );
        }
        foreach ( $pageData['items'] as &$row ) {
            $id = (int) ( $row['ID'] ?? 0 );
            $row['inbound_sources'] = (int) ( $inbound[ $id ]['inbound_sources'] ?? 0 );
            $row['inbound_links'] = (int) ( $inbound[ $id ]['inbound_links'] ?? 0 );
        }
        unset( $row );
        return $pageData;
    }

    public function orphanCount(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed target_id lookup over plugin-owned derived tables; cached through diagnostics on normal admin rendering.
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i s WHERE NOT EXISTS (SELECT 1 FROM %i e WHERE e.target_id=s.source_id AND e.source_id<>s.source_id)', $this->sourcesTable, $this->edgesTable ) );
    }

    public function staleCornerstoneCount(): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed cornerstone/modified columns on plugin-owned derived table; cached through diagnostics on normal admin rendering.
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_cornerstone=1 AND modified_gmt IS NOT NULL AND modified_gmt<=%s', $this->sourcesTable, $cutoff ) );
    }

    public function eligiblePublishedCount(): int {
        $count = 0;
        foreach ( $this->publicPostTypes() as $postType ) {
            $status = wp_count_posts( $postType, 'readable' );
            if ( is_object( $status ) && isset( $status->publish ) ) { $count += (int) $status->publish; }
        }
        return $count;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    private function sourcePage( string $view, int $page, int $perPage, string $search, string $postType, string $language, string $dateFrom = '', string $dateTo = '', string $sort = 'modified_desc' ): array {
        global $wpdb;
        $page = max( 1, $page );
        $perPage = min( 100, max( 10, $perPage ) );
        $search = trim( sanitize_text_field( $search ) );
        $postType = sanitize_key( $postType );
        $language = sanitize_text_field( $language );
        $dateFrom = $this->normalizeDate( $dateFrom );
        $dateTo = $this->normalizeDate( $dateTo );
        $sort = in_array( $sort, array( 'modified_desc', 'modified_asc', 'title_asc', 'title_desc' ), true ) ? $sort : ( 'cornerstone' === $view ? 'modified_asc' : 'modified_desc' );
        if ( '' !== $postType && ! in_array( $postType, $this->publicPostTypes(), true ) ) { $postType = ''; }
        $where = array( "p.post_status='publish'" );
        $args = array( $this->sourcesTable, $wpdb->posts );
        $countArgs = $args;

        if ( 'orphaned' === $view ) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM %i e WHERE e.target_id=s.source_id AND e.source_id<>s.source_id)';
            $args[] = $this->edgesTable;
            $countArgs[] = $this->edgesTable;
        } elseif ( 'cornerstone' === $view ) {
            $where[] = 's.is_cornerstone=1';
            $where[] = 's.modified_gmt IS NOT NULL';
            $where[] = 's.modified_gmt<=%s';
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS );
            $args[] = $cutoff;
            $countArgs[] = $cutoff;
        }
        if ( '' !== $postType ) {
            $where[] = 's.post_type=%s';
            $args[] = $postType;
            $countArgs[] = $postType;
        }
        if ( '' !== $language ) {
            $where[] = 's.language_key=%s';
            $args[] = $language;
            $countArgs[] = $language;
        }
        if ( '' !== $dateFrom ) { $where[] = 'p.post_modified_gmt>=%s'; $args[] = $dateFrom . ' 00:00:00'; $countArgs[] = $dateFrom . ' 00:00:00'; }
        if ( '' !== $dateTo ) { $dateEnd = gmdate( 'Y-m-d H:i:s', strtotime( $dateTo . ' +1 day' ) ); $where[] = 'p.post_modified_gmt<%s'; $args[] = $dateEnd; $countArgs[] = $dateEnd; }
        if ( '' !== $search ) {
            $where[] = 'p.post_title LIKE %s';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $args[] = $like;
            $countArgs[] = $like;
        }

        $whereSql = implode( ' AND ', $where );
        $orderMap = array( 'modified_desc' => 's.modified_gmt DESC,s.source_id DESC', 'modified_asc' => 's.modified_gmt ASC,s.source_id ASC', 'title_asc' => 'p.post_title ASC,s.source_id ASC', 'title_desc' => 'p.post_title DESC,s.source_id DESC' );
        $orderSql = $orderMap[ $sort ];
        $sql = "SELECT p.ID,p.post_title,s.post_type,s.language_key,p.post_modified_gmt,s.outbound_links,s.distinct_targets,s.is_cornerstone
            FROM %i s INNER JOIN %i p ON p.ID=s.source_id
            WHERE {$whereSql}
            ORDER BY {$orderSql} LIMIT %d OFFSET %d";
        $countSql = "SELECT COUNT(*) FROM %i s INNER JOIN %i p ON p.ID=s.source_id WHERE {$whereSql}";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same prepared admin filter as the paginated data query; count is required for navigation.
        $total = (int) $wpdb->get_var( $wpdb->prepare( $countSql, $countArgs ) );
        $pages = max( 1, (int) ceil( $total / $perPage ) );
        $page = min( $page, $pages );
        $offset = ( $page - 1 ) * $perPage;
        $args[] = $perPage;
        $args[] = $offset;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- All identifiers and user-controlled values are prepared; query is paginated over indexed plugin-owned evidence.
        $items = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        return array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage );
    }

    /** @return array<int,string> */
    private function publicPostTypes(): array {
        $types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
        return array_values( array_filter( array_diff( array_map( 'sanitize_key', $types ), array( 'attachment' ) ) ) );
    }

    private function normalizeDate( string $date ): string {
        $date = trim( sanitize_text_field( $date ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return ''; }
        list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );
        return checkdate( $month, $day, $year ) ? $date : '';
    }

    private function flushCache(): void {
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) && function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( self::CACHE_GROUP );
            return;
        }
        // Only the diagnostics key is cached; invalidate it without flushing shared caches.
        wp_cache_delete( 'diagnostics', self::CACHE_GROUP );
    }
}
