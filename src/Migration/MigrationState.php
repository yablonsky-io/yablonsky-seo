<?php
namespace Yablonsky\PerformanceSEO\Migration;

use Yablonsky\PerformanceSEO\Migration\Contract\FieldClassification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MigrationState {
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_BLOCKED = 'blocked';
    public const MODE_DRY_RUN = 'dry-run';
    public const MODE_IMPORT = 'import';

    /** @var array<string,mixed> */ private $data;

    /** @param array<string,mixed> $data */
    public function __construct( array $data ) {
        $defaults = array(
            'provider'                  => 'yoast',
            'provider_version'          => '',
            'contract_revision'         => '',
            'authorized_source_digest'  => '',
            'source_immutable_ready'    => false,
            'shadow_data_parity_ready'  => false,
            'adapter_compatibility'     => '',
            'mode'                      => self::MODE_DRY_RUN,
            'status'                    => self::STATUS_RUNNING,
            'phase'                     => 'inventory',
            'post_cursor'               => 0,
            'taxonomy_cursor'           => '',
            'taxonomy_term_cursor'      => 0,
            'user_meta_cursor'           => 0,
            'processed_posts'           => 0,
            'processed_user_meta'       => 0,
            'processed_terms'           => 0,
            'mapped_values'             => 0,
            'ignored_derived_values'    => 0,
            'imported_values'           => 0,
            'verified_values'           => 0,
            'mapped_user_values'        => 0,
            'imported_user_values'      => 0,
            'verified_user_values'      => 0,
            'global_mapped_values'      => 0,
            'global_imported_values'    => 0,
            'global_verified_values'    => 0,
            'conflicts'                 => 0,
            'conflict_details'          => array(),
            'conflict_details_truncated'=> 0,
            'classification_counts'     => FieldClassification::zeroCounts(),
            'unknown_source_fields'     => array(),
            'unsupported_source_fields' => array(),
            'invalid_source_fields'     => array(),
            'source_digest'             => hash( 'sha256', '' ),
            'source_options_present'    => array(),
            'source_inventory'          => array(),
            'inventory_blockers'        => array(),
            'readiness_blockers'        => array(),
            'source_coverage_ready'     => false,
            'shadow_ready'              => false,
            'shadow_import_ready'       => false,
            'import_blockers'           => array(),
            'cutover_ready'             => false,
            'backup_export_ready'       => false,
            'backup_export_verified'    => false,
            'backup_anchor_id'          => '',
            'backup_anchor_path'        => '',
            'backup_verified_at'        => '',
            'semantic_parity_ready'     => false,
            'adaptive_batch_size'       => 200,
            'last_step_batch_size'      => 0,
            'last_step_ms'              => 0.0,
            'ewma_step_ms'              => 0.0,
            'last_step_queries'         => 0,
            'last_step_memory_delta'    => 0,
            'peak_step_ms'              => 0.0,
            'peak_step_memory_delta'    => 0,
            'total_step_queries'        => 0,
            'recommended_delay_ms'      => 25,
            'pressure_state'            => 'normal',
            'pressure_reason'           => 'initial',
            'pressure_events'           => 0,
            'worker_concurrency_limit'  => 1,
            'started_at'                => gmdate( 'c' ),
            'updated_at'                => gmdate( 'c' ),
            'message'                   => '',
        );
        $this->data = array_merge( $defaults, $data );
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->data; }

    /** @return mixed */
    public function get( string $key ) { return $this->data[ $key ] ?? null; }

    /** @param mixed $value */
    public function with( string $key, $value ): self {
        $next = $this->data;
        $next[ $key ] = $value;
        $next['updated_at'] = gmdate( 'c' );
        return new self( $next );
    }

    /** @param array<string,mixed> $changes */
    public function merge( array $changes ): self {
        $next = array_merge( $this->data, $changes );
        $next['updated_at'] = gmdate( 'c' );
        return new self( $next );
    }
}
