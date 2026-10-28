<?php
namespace Yablonsky\PerformanceSEO\Migration\Diagnostics;

use Yablonsky\PerformanceSEO\Migration\MigrationState;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastGlobalSettingsNormalizer;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastOptionSnapshotReader;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds bounded, secret-free evidence for a stopped Yoast shadow import.
 *
 * The inspector never writes source or target SEO data. It intentionally
 * exposes field locators and short fingerprints rather than metadata values.
 */
final class MigrationConflictInspector {
    private const GLOBAL_TARGET_OPTION = 'yps_shadow_global_settings';
    private const MAX_DETAILS = 20;

    /** @return array<string,mixed> */
    public function inspect( ?MigrationState $state ): array {
        if ( null === $state ) {
            return array(
                'status'             => 'idle',
                'state_conflicts'    => 0,
                'details'            => array(),
                'details_truncated'  => 0,
                'legacy_global_merge'=> false,
                'message'            => __( 'Run or load a migration state to inspect conflicts.', 'yablonsky-seo' ),
            );
        }

        $details = $this->sanitizeDetails( $state->get( 'conflict_details' ) );
        $truncated = max( 0, (int) $state->get( 'conflict_details_truncated' ) );
        $legacy = $this->inspectGlobalShadowSubset( $state );

        foreach ( $legacy['details'] as $detail ) {
            if ( count( $details ) >= self::MAX_DETAILS ) {
                ++$truncated;
                break;
            }
            $details[] = $detail;
        }

        $stateConflicts = max( 0, (int) $state->get( 'conflicts' ) );
        $legacyMerge = ! empty( $legacy['legacy_global_merge'] );
        if ( $legacyMerge ) {
            $message = __( 'The stopped 2.70.0 import matches the partial-global-shadow merge regression fixed in 2.70.1. Existing mirrored global keys match Yoast; only additional verified keys were missing.', 'yablonsky-seo' );
            $status = 'repairable';
        } elseif ( $stateConflicts > 0 && ! empty( $details ) ) {
            $message = __( 'One or more bounded migration conflicts have field-level evidence. Review the listed locators before retrying shadow import.', 'yablonsky-seo' );
            $status = 'conflicts-found';
        } elseif ( $stateConflicts > 0 ) {
            $message = __( 'The saved migration state reports conflicts. This older run predates field-level evidence persistence. Run a fresh safe preparation to reproduce them with 2.70.1 diagnostics.', 'yablonsky-seo' );
            $status = 'legacy-evidence-limited';
        } else {
            $message = __( 'Current migration state is clear of recorded conflicts.', 'yablonsky-seo' );
            $status = 'clear';
        }

        return array(
            'status'                    => $status,
            'state_conflicts'           => $stateConflicts,
            'details'                   => array_slice( $details, 0, self::MAX_DETAILS ),
            'details_truncated'         => $truncated,
            'legacy_global_merge'       => $legacyMerge,
            'global_source_keys'        => (int) $legacy['source_keys'],
            'global_shadow_keys'        => (int) $legacy['shadow_keys'],
            'global_matching_keys'      => (int) $legacy['matching_keys'],
            'global_missing_keys'       => (int) $legacy['missing_keys'],
            'global_differing_keys'     => (int) $legacy['differing_keys'],
            'recommended_action'        => $legacyMerge ? 'run-safe-preparation' : ( $stateConflicts > 0 ? 'review-conflicts' : 'none' ),
            'message'                   => $message,
        );
    }

    /** @return array<string,mixed> */
    private function inspectGlobalShadowSubset( MigrationState $state ): array {
        $result = array(
            'legacy_global_merge' => false,
            'source_keys'         => 0,
            'shadow_keys'         => 0,
            'matching_keys'       => 0,
            'missing_keys'        => 0,
            'differing_keys'      => 0,
            'details'             => array(),
        );

        if ( MigrationState::MODE_IMPORT !== (string) $state->get( 'mode' ) || (int) $state->get( 'conflicts' ) <= 0 ) {
            return $result;
        }

        $snapshot = ( new YoastOptionSnapshotReader() )->read( array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt' ) );
        $normalized = ( new YoastGlobalSettingsNormalizer() )->normalize( $snapshot );
        $source = isset( $normalized['values'] ) && is_array( $normalized['values'] ) ? $normalized['values'] : array();
        $shadow = get_option( self::GLOBAL_TARGET_OPTION, array() );
        $shadow = is_array( $shadow ) ? $shadow : array();

        $result['source_keys'] = count( $source );
        $result['shadow_keys'] = count( $shadow );
        if ( empty( $source ) || empty( $shadow ) ) {
            return $result;
        }

        foreach ( $source as $key => $sourceValue ) {
            $key = (string) $key;
            if ( ! array_key_exists( $key, $shadow ) ) {
                ++$result['missing_keys'];
                continue;
            }
            if ( $this->valuesEqual( $sourceValue, $shadow[ $key ] ) ) {
                ++$result['matching_keys'];
                continue;
            }

            ++$result['differing_keys'];
            if ( count( $result['details'] ) < self::MAX_DETAILS ) {
                $result['details'][] = $this->detail(
                    'global',
                    0,
                    'yoast-option:' . $key,
                    self::GLOBAL_TARGET_OPTION . ':' . $key,
                    'target-value-differs',
                    $sourceValue,
                    $shadow[ $key ]
                );
            }
        }

        // 2.70.0 compared the entire global option as one value. Stage 0 Search
        // mirror intentionally writes only a subset, so a source-consistent
        // subset plus missing keys was incorrectly reported as one conflict.
        $result['legacy_global_merge'] = 0 === (int) $result['differing_keys']
            && (int) $result['matching_keys'] > 0
            && (int) $result['missing_keys'] > 0
            && 0 === (int) $state->get( 'global_verified_values' );

        if ( $result['legacy_global_merge'] && count( $result['details'] ) < self::MAX_DETAILS ) {
            $result['details'][] = array(
                'scope'              => 'global',
                'object_id'          => 0,
                'source_key'         => 'yoast-global-settings',
                'target_key'         => self::GLOBAL_TARGET_OPTION,
                'reason'             => 'legacy-partial-shadow-merge',
                'source_fingerprint' => '',
                'target_fingerprint' => '',
                'source_bytes'       => 0,
                'target_bytes'       => 0,
            );
        }

        return $result;
    }

    /** @param mixed $raw @return array<int,array<string,mixed>> */
    private function sanitizeDetails( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $details = array();
        foreach ( array_slice( $raw, 0, self::MAX_DETAILS ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $details[] = array(
                'scope'              => sanitize_key( (string) ( $item['scope'] ?? '' ) ),
                'object_id'          => max( 0, (int) ( $item['object_id'] ?? 0 ) ),
                'source_key'         => sanitize_text_field( (string) ( $item['source_key'] ?? '' ) ),
                'target_key'         => sanitize_text_field( (string) ( $item['target_key'] ?? '' ) ),
                'reason'             => sanitize_key( (string) ( $item['reason'] ?? '' ) ),
                'source_fingerprint' => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $item['source_fingerprint'] ?? '' ) ) ) ?: '',
                'target_fingerprint' => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $item['target_fingerprint'] ?? '' ) ) ) ?: '',
                'source_bytes'       => max( 0, (int) ( $item['source_bytes'] ?? 0 ) ),
                'target_bytes'       => max( 0, (int) ( $item['target_bytes'] ?? 0 ) ),
            );
        }
        return $details;
    }

    /** @param mixed $left @param mixed $right */
    private function valuesEqual( $left, $right ): bool {
        return hash_equals( hash( 'sha256', $this->stableEncode( $left ) ), hash( 'sha256', $this->stableEncode( $right ) ) );
    }

    /** @param mixed $source @param mixed $target @return array<string,mixed> */
    private function detail( string $scope, int $objectId, string $sourceKey, string $targetKey, string $reason, $source, $target ): array {
        $sourceEncoded = $this->stableEncode( $source );
        $targetEncoded = $this->stableEncode( $target );
        return array(
            'scope'              => sanitize_key( $scope ),
            'object_id'          => max( 0, $objectId ),
            'source_key'         => sanitize_text_field( $sourceKey ),
            'target_key'         => sanitize_text_field( $targetKey ),
            'reason'             => sanitize_key( $reason ),
            'source_fingerprint' => substr( hash( 'sha256', $sourceEncoded ), 0, 16 ),
            'target_fingerprint' => substr( hash( 'sha256', $targetEncoded ), 0, 16 ),
            'source_bytes'       => strlen( $sourceEncoded ),
            'target_bytes'       => strlen( $targetEncoded ),
        );
    }

    /** @param mixed $value */
    private function stableEncode( $value ): string {
        if ( is_array( $value ) ) {
            ksort( $value, SORT_STRING );
        }
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
        return is_string( $encoded ) ? $encoded : '';
    }
}
