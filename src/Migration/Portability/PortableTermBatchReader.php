<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PortableTermBatchReader {
    /** @return array<int,int> */
    public function next( int $cursor, int $limit ): array {
        global $wpdb;
        $taxonomies = array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
        $taxonomies = array_values( array_filter( array_map( 'sanitize_key', $taxonomies ) ) );
        if ( empty( $taxonomies ) ) { return array(); }
        $limit = max( 1, min( 250, $limit ) );
        $placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
        $sql = "SELECT DISTINCT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$placeholders}) AND term_id > %d ORDER BY term_id ASC LIMIT %d";
        $args = array_merge( $taxonomies, array( max( 0, $cursor ), $limit ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL section contains generated placeholders only; values are prepared below.
        $prepared = $wpdb->prepare( $sql, $args );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded migration-only keyset read; prepared immediately above.
        $ids = $wpdb->get_col( $prepared );
        return array_map( 'intval', is_array( $ids ) ? $ids : array() );
    }
}
