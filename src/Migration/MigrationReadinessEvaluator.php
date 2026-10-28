<?php
namespace Yablonsky\PerformanceSEO\Migration;

use Yablonsky\PerformanceSEO\Migration\Contract\AdapterCompatibility;
use Yablonsky\PerformanceSEO\Migration\Contract\FieldClassification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MigrationReadinessEvaluator {
    /** @return array{ready:bool,blockers:array<int,string>} */
    public function evaluate( MigrationState $state ): array {
        $blockers = array();
        $compatibility = (string) $state->get( 'adapter_compatibility' );
        if ( in_array( $compatibility, array( AdapterCompatibility::UNKNOWN_VERSION, AdapterCompatibility::AUDIT_ONLY, AdapterCompatibility::UNSUPPORTED ), true ) ) {
            $blockers[] = 'adapter-not-verified';
        }
        $counts = is_array( $state->get( 'classification_counts' ) ) ? $state->get( 'classification_counts' ) : array();
        $unsupported = is_array( $state->get( 'unsupported_source_fields' ) ) ? $state->get( 'unsupported_source_fields' ) : array();
        if ( ! empty( $unsupported ) ) {
            $blockers[] = 'unsupported-source-data';
        }
        if ( (int) ( $counts[ FieldClassification::INVALID_SOURCE ] ?? 0 ) > 0 || ! empty( $state->get( 'invalid_source_fields' ) ) ) {
            $blockers[] = 'invalid-source-data';
        }
        $inventoryBlockers = is_array( $state->get( 'inventory_blockers' ) ) ? $state->get( 'inventory_blockers' ) : array();
        foreach ( $inventoryBlockers as $blocker ) {
            $blocker = (string) $blocker;
            if ( '' !== $blocker && ! in_array( $blocker, $blockers, true ) ) {
                $blockers[] = $blocker;
            }
        }
        if ( ! empty( $state->get( 'unknown_source_fields' ) ) ) {
            $blockers[] = 'unclassified-source-fields';
        }
        return array( 'ready' => empty( $blockers ), 'blockers' => array_values( array_unique( $blockers ) ) );
    }
}
