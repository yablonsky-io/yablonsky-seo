<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastTableInventoryReader {
    /** @return array<int,array<string,mixed>> */
    public function inventory(): array {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
            return array();
        }

        $suffixes = array(
            'yoast_indexable'           => 'derived-validation',
            'yoast_indexable_hierarchy' => 'derived-validation',
            'yoast_migrations'          => 'ignore-internal',
            'yoast_primary_term'        => 'authored-primary-term',
            'yoast_prominent_words'     => 'ignore-derived',
            'yoast_seo_links'           => 'rebuild-link-graph',
            'yoast_seo_meta'            => 'legacy-derived',
        );
        $result = array();
        foreach ( $suffixes as $suffix => $policy ) {
            $table = $wpdb->prefix . $suffix;
            // Table names are plugin-owned and only passed as prepared LIKE values.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot admin-only source inventory.
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            $exists = (string) $found === $table;
            $rows = null;
            if ( $exists ) {
                // information_schema TABLE_ROWS is approximate and avoids COUNT(*) over large derived tables.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot approximate admin-only inventory.
                $rows = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                        $table
                    )
                );
            }
            $result[] = array(
                'table'         => $table,
                'suffix'        => $suffix,
                'exists'        => $exists,
                'approx_rows'   => null === $rows ? null : (int) $rows,
                'import_policy' => $policy,
            );
        }
        return $result;
    }
}
