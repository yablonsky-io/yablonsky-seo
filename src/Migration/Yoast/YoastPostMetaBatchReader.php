<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

use Yablonsky\PerformanceSEO\Migration\SafeSerializedValue;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads only Yoast-authored post metadata for one bounded migration batch.
 *
 * This deliberately avoids update_meta_cache()/get_post_meta( $postId ) because
 * those APIs can hydrate every metadata row for every object into process memory
 * and can traverse third-party metadata filters on very large WooCommerce sites.
 */
final class YoastPostMetaBatchReader {
    /**
     * @param array<int,int> $postIds Post IDs from the bounded keyset reader.
     * @return array<int,array<string,array<int,mixed>>>
     */
    public function read( array $postIds ): array {
        global $wpdb;

        $postIds = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', $postIds ),
                    static function ( int $postId ): bool {
                        return $postId > 0;
                    }
                )
            )
        );
        if ( empty( $postIds ) ) {
            return array();
        }

        // The upstream PostIdBatchReader is capped at 500, but keep this reader bounded independently.
        $postIds = array_slice( $postIds, 0, 500 );
        $placeholders = implode( ',', array_fill( 0, count( $postIds ), '%d' ) );
        $like = $wpdb->esc_like( '_yoast_wpseo_' ) . '%';
        $sql = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders}) AND meta_key LIKE %s ORDER BY post_id ASC, meta_id ASC";
        $args = array_merge( $postIds, array( $like ) );

        // The SQL template contains only the trusted postmeta table name plus generated placeholders.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted SQL template with prepared values; dynamic section contains placeholders only.
        $prepared = $wpdb->prepare( $sql, $args );

        // Migration batches are one-shot projections. Caching the complete source metadata would defeat the memory-bounded contract.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; intentional bounded migration-only source projection.
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $result = array();
        foreach ( $rows as $row ) {
            $postId = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            $metaKey = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
            if ( $postId <= 0 || '' === $metaKey || 0 !== strpos( $metaKey, '_yoast_wpseo_' ) ) {
                continue;
            }
            if ( ! isset( $result[ $postId ] ) ) {
                $result[ $postId ] = array();
            }
            if ( ! isset( $result[ $postId ][ $metaKey ] ) ) {
                $result[ $postId ][ $metaKey ] = array();
            }
            $result[ $postId ][ $metaKey ][] = isset( $row['meta_value'] ) ? SafeSerializedValue::decode( $row['meta_value'] ) : '';
        }

        return $result;
    }
}
