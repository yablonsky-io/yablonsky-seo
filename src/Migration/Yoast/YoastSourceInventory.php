<?php
namespace Yablonsky\PerformanceSEO\Migration\Yoast;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastSourceInventory {
    /** @return array<string,mixed> */
    public function collect( string $version ): array {
        $options = array();
        $optionNames = array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_ms', 'wpseo_taxonomy_meta', 'wpseo_llmstxt', 'wpseo_tracking_only' );
        $rawSnapshot = ( new YoastOptionSnapshotReader() )->readRaw( $optionNames );
        foreach ( $optionNames as $name ) {
            $rawValue = array_key_exists( $name, $rawSnapshot ) ? $rawSnapshot[ $name ] : null;
            $present = null !== $rawValue;
            $options[ $name ] = array(
                'present' => $present,
                'type'    => ! $present ? 'NULL' : ( function_exists( 'is_serialized' ) && is_serialized( $rawValue ) ? 'serialized' : 'string' ),
                'bytes'   => $present ? strlen( (string) $rawValue ) : 0,
                'sha256'  => $present ? hash( 'sha256', (string) $rawValue ) : hash( 'sha256', '' ),
            );
        }

        $addons = ( new YoastAddOnDetector() )->detect();
        $blockers = array();
        foreach ( $addons as $addon ) {
            if ( isset( $addon['state'] ) && in_array( $addon['state'], array( 'requires-exact-redirect-parity', 'requires-addon-parity', 'blocking-unknown-addon' ), true ) ) {
                $blockers[] = 'addon:' . (string) $addon['id'];
            }
        }
        if ( function_exists( 'is_multisite' ) && is_multisite() && ( $options['wpseo_ms']['present'] ?? false ) ) {
            $blockers[] = 'multisite-global-settings-require-parity';
        }

        $postTypes = array();
        if ( function_exists( 'get_post_types' ) && function_exists( 'wp_count_posts' ) ) {
            foreach ( get_post_types( array( 'public' => true ), 'names' ) as $postType ) {
                $counts = wp_count_posts( $postType );
                $postTypes[ (string) $postType ] = is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : null;
            }
        }

        return array(
            'provider'      => 'yoast',
            'version'       => $version,
            'options'       => $options,
            'addons'        => $addons,
            'tables'        => ( new YoastTableInventoryReader() )->inventory(),
            'post_types'    => $postTypes,
            'blockers'      => array_values( array_unique( $blockers ) ),
            'captured_at'   => gmdate( 'c' ),
        );
    }
}
