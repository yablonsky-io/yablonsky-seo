<?php
namespace Yablonsky\PerformanceSEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PostIdBatchReader {
    /** @return array<int,int> */
    public function next( int $cursor, int $limit ): array {
        global $wpdb;

        $postTypes = array_values( get_post_types( array( 'public' => true ), 'names' ) );
        $postTypes = array_values( array_filter( $postTypes, static function ( string $postType ): bool { return 'attachment' !== $postType; } ) );
        if ( empty( $postTypes ) ) {
            return array();
        }

        $limit = max( 1, min( 500, $limit ) );
        $placeholders = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d";
        $args = array_merge( $postTypes, array( max( 0, $cursor ), $limit ) );

        // Direct keyset SQL is intentional here: this migration-only reader must bypass
        // WP_Query, WPML, SEO, theme, and catalog filters that can amplify large-catalog scans.
        // The SQL template contains only $wpdb->posts plus generated %s placeholders; all values are prepared.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted SQL template with prepared values; dynamic section contains placeholders only.
        $prepared = $wpdb->prepare( $sql, $args );

        // Migration batches are one-shot cursor reads. Persistent caching would be stale and provides no reuse benefit.
        // $prepared is the immediate result of $wpdb->prepare() above; PHPCS does not trace that guarantee through the local variable.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; intentional bounded migration-only keyset read.
        $ids = $wpdb->get_col( $prepared );
        return array_map( 'intval', is_array( $ids ) ? $ids : array() );
    }
}
