<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BackupExportState {
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_BLOCKED = 'blocked';

    /** @var array<string,mixed> */
    private $data;

    /** @param array<string,mixed> $data */
    public function __construct( array $data ) {
        $defaults = array(
            'status'                    => self::STATUS_RUNNING,
            'phase'                     => 'export-options',
            'anchor_id'                 => '',
            'path'                      => '',
            'provider'                  => 'yoast',
            'provider_version'          => '',
            'dry_run_source_digest'     => '',
            'option_index'              => 0,
            'option_offset'             => 0,
            'postmeta_cursor'           => 0,
            'usermeta_cursor'           => 0,
            'primary_cursor'            => 0,
            'verify_option_index'       => 0,
            'verify_option_offset'      => 0,
            'verify_postmeta_cursor'    => 0,
            'verify_usermeta_cursor'    => 0,
            'verify_primary_cursor'     => 0,
            'artifact_offsets'          => array(),
            'raw_source_digest'         => hash( 'sha256', 'YPS-BACKUP-SOURCE-V1' ),
            'verify_source_digest'      => hash( 'sha256', 'YPS-BACKUP-SOURCE-V1' ),
            'export_records'            => 0,
            'verify_records'            => 0,
            'postmeta_rows'             => 0,
            'usermeta_rows'             => 0,
            'primary_rows'              => 0,
            'verify_postmeta_rows'      => 0,
            'verify_usermeta_rows'      => 0,
            'verify_primary_rows'       => 0,
            'primary_table'             => '',
            'primary_key'               => '',
            'verified'                  => false,
            'manifest_sha256'           => '',
            'blockers'                  => array(),
            'started_at'                => gmdate( 'c' ),
            'completed_at'              => '',
            'verified_at'               => '',
            'message'                   => '',
        );
        $this->data = array_merge( $defaults, $data );
    }

    /** @return mixed */
    public function get( string $key ) {
        return $this->data[ $key ] ?? null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return $this->data;
    }

    /** @param array<string,mixed> $changes */
    public function merge( array $changes ): self {
        return new self( array_merge( $this->data, $changes ) );
    }
}
