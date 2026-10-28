<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bounded keyset reader for Yoast author/profile metadata.
 */
final class YoastUserMetaBatchReader {
    /** @return array{rows:array<int,array<string,mixed>>,cursor:int,complete:bool} */
    public function next( int $cursor, int $limit ): array {
        global $wpdb;
        $limit = max( 1, min( 500, $limit ) );
        $social = YoastUserFieldMap::socialContactFields();
        $placeholders = implode( ',', array_fill( 0, count( $social ), '%s' ) );
        $sql = "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE umeta_id > %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key IN ({$placeholders})) ORDER BY umeta_id ASC LIMIT %d";
        $args = array_merge(
            array(
                max( 0, $cursor ),
                $wpdb->esc_like( 'wpseo_' ) . '%',
                $wpdb->esc_like( '_yoast_wpseo_' ) . '%',
            ),
            $social,
            array( $limit )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic section contains generated placeholders only; all values are prepared below.
        $prepared = $wpdb->prepare( $sql, $args );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; bounded one-shot migration projection.
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        $nextCursor = $cursor;
        foreach ( $rows as $row ) {
            if ( isset( $row['umeta_id'] ) ) {
                $nextCursor = max( $nextCursor, (int) $row['umeta_id'] );
            }
        }
        return array(
            'rows'     => $rows,
            'cursor'   => $nextCursor,
            'complete' => count( $rows ) < $limit,
        );
    }
}
