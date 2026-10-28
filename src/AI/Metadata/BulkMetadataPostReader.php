<?php
namespace Yablonsky\PerformanceSEO\AI\Metadata;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Keyset reader for durable metadata campaigns; never loads the whole site. */
final class BulkMetadataPostReader {
    /** @return array<int,string> */
    private function publicTypes(): array {
        $types = get_post_types( array( 'public' => true ), 'names' );
        return array_values( array_diff( $types, array( 'attachment' ) ) );
    }

    /** @return array<int,int> */
    public function next( int $afterId, int $limit, int $maxId = 0 ): array {
        global $wpdb;
        $afterId = max( 0, $afterId );
        $maxId = max( 0, $maxId );
        $limit = min( 500, max( 1, $limit ) );
        $types = $this->publicTypes();
        if ( empty( $types ) ) { return array(); }
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $maxClause = $maxId > 0 ? ' AND ID<=%d' : '';
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE ID>%d{$maxClause} AND post_type IN ({$placeholders}) AND post_status IN ('publish','draft','pending','private','future') ORDER BY ID ASC LIMIT %d";
        $args = array( $afterId );
        if ( $maxId > 0 ) { $args[] = $maxId; }
        $args = array_merge( $args, $types, array( $limit ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL shape is fixed here; dynamic post types and limits are parameterized through wpdb::prepare in this bounded keyset scan.
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
    }

    public function countCandidates( int $maxId = 0 ): int {
        global $wpdb;
        $maxId = max( 0, $maxId );
        $types = $this->publicTypes();
        if ( empty( $types ) ) { return 0; }
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $maxClause = $maxId > 0 ? ' AND ID<=%d' : '';
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status IN ('publish','draft','pending','private','future'){$maxClause}";
        $args = $types;
        if ( $maxId > 0 ) { $args[] = $maxId; }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL shape is fixed here; dynamic post types and max ID are parameterized through wpdb::prepare.
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
    }

    public function maxCandidateId(): int {
        global $wpdb;
        $types = $this->publicTypes();
        if ( empty( $types ) ) { return 0; }
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status IN ('publish','draft','pending','private','future')";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL shape is fixed here and every public post type is parameterized before the bounded MAX(ID) aggregate.
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) );
    }
}
