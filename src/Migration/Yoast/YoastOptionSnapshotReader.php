<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

use Yablonsky\PerformanceSEO\Migration\SafeSerializedValue;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads stored Yoast option payloads without relying on Yoast option filters.
 *
 * Migration needs to distinguish persisted source decisions from defaults that a
 * source plugin may merge at read time. Queries are admin-only, bounded to
 * explicitly requested option names and never run on the frontend.
 */
final class YoastOptionSnapshotReader {
    /**
     * Returns raw stored option strings without unserializing them.
     *
     * Use this for inventory/digests so large serialized options are not expanded
     * into PHP arrays merely to calculate byte size or a checksum.
     *
     * @param array<int,string> $optionNames
     * @return array<string,string|null>
     */
    public function readRaw( array $optionNames ): array {
        $names = array_values( array_unique( array_filter( array_map( 'strval', $optionNames ) ) ) );
        if ( empty( $names ) ) {
            return array();
        }

        $snapshot = array();
        foreach ( $names as $name ) {
            $snapshot[ $name ] = null;
        }

        global $wpdb;
        if ( is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_results' ) ) {
            $placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
            $sql = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder count is generated locally and all values are passed to prepare().
            $prepared = $wpdb->prepare( $sql, $names );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate prepared result; one-shot bounded migration source snapshot.
            $rows = $wpdb->get_results( $prepared, ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) || ! isset( $row['option_name'] ) ) {
                        continue;
                    }
                    $name = (string) $row['option_name'];
                    if ( array_key_exists( $name, $snapshot ) ) {
                        $snapshot[ $name ] = isset( $row['option_value'] ) ? (string) $row['option_value'] : '';
                    }
                }
                return $snapshot;
            }
        }

        // Compatibility fallback for non-standard test environments.
        foreach ( $names as $name ) {
            $value = get_option( $name, null );
            if ( null === $value ) {
                $snapshot[ $name ] = null;
                continue;
            }
            $snapshot[ $name ] = is_scalar( $value ) ? (string) $value : serialize( $value );
        }
        return $snapshot;
    }

    /**
     * @param array<int,string> $optionNames
     * @return array<string,mixed>
     */
    public function read( array $optionNames ): array {
        $raw = $this->readRaw( $optionNames );
        $snapshot = array();
        foreach ( $raw as $name => $value ) {
            $snapshot[ $name ] = null === $value ? null : SafeSerializedValue::decode( $value );
        }
        return $snapshot;
    }
}
