<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

use Yablonsky\PerformanceSEO\Migration\MigrationRepository;
use Yablonsky\PerformanceSEO\Migration\MigrationState;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastUserFieldMap;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Durable, bounded Yoast rollback/export anchor.
 *
 * Export and verification are intentionally separate passes. The export stores
 * exact raw source bytes in private JSONL artifacts. Verification independently
 * re-reads the live source and recomputes the same digest/count chain before the
 * manifest can be sealed.
 */
final class YoastBackupExportService {
    private const OPTION_NAMES = array(
        'wpseo',
        'wpseo_titles',
        'wpseo_social',
        'wpseo_ms',
        'wpseo_taxonomy_meta',
        'wpseo_llmstxt',
        'wpseo_tracking_only',
    );
    private const OPTION_CHUNK_BYTES = 65536;
    private const ROW_BATCH = 250;

    /** @var BackupExportRepository */ private $repository;
    /** @var MigrationRepository */ private $migrationRepository;
    /** @var BackupArtifactWriter */ private $writer;
    /** @var PrivateBackupPath */ private $pathPolicy;

    public function __construct( BackupExportRepository $repository, MigrationRepository $migrationRepository ) {
        $this->repository = $repository;
        $this->migrationRepository = $migrationRepository;
        $this->writer = new BackupArtifactWriter();
        $this->pathPolicy = new PrivateBackupPath();
    }

    public function start(): BackupExportState {
        $migration = $this->migrationRepository->load();
        $blockers = array();
        if ( null === $migration || MigrationState::MODE_DRY_RUN !== $migration->get( 'mode' ) || MigrationState::STATUS_COMPLETE !== $migration->get( 'status' ) || ! $migration->get( 'source_coverage_ready' ) ) {
            $blockers[] = 'successful-lossless-dry-run-required';
        }
        $version = null === $migration ? '' : (string) $migration->get( 'provider_version' );
        $sourceDigest = null === $migration ? '' : (string) $migration->get( 'source_digest' );
        if ( '' === $version || '' === $sourceDigest ) {
            $blockers[] = 'dry-run-source-identity-missing';
        }
        $dryRunUpdatedAt = null === $migration ? false : strtotime( (string) $migration->get( 'updated_at' ) );
        if ( false === $dryRunUpdatedAt || $dryRunUpdatedAt > time() + 60 || ( time() - $dryRunUpdatedAt ) > 900 ) {
            $blockers[] = 'dry-run-verification-stale';
        }
        if ( defined( 'WPSEO_VERSION' ) && '' !== $version && ! hash_equals( $version, (string) WPSEO_VERSION ) ) {
            $blockers[] = 'source-provider-version-changed';
        }

        $primary = $this->primaryDescriptor();
        if ( ! empty( $primary['blocker'] ) ) {
            $blockers[] = (string) $primary['blocker'];
        }

        if ( ! empty( $blockers ) ) {
            $state = new BackupExportState(
                array(
                    'status'           => BackupExportState::STATUS_BLOCKED,
                    'provider_version' => $version,
                    'blockers'         => array_values( array_unique( $blockers ) ),
                    'message'          => __( 'Backup/export is blocked because its source identity cannot be proven safely.', 'yablonsky-seo' ),
                )
            );
            $this->repository->save( $state );
            return $state;
        }

        $anchorId = 'yoast-' . gmdate( 'YmdHis' ) . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
        $path = $this->pathPolicy->createAnchorDirectory( $anchorId );
        foreach ( array( 'options.jsonl', 'postmeta.jsonl', 'usermeta.jsonl', 'primary-term.jsonl' ) as $artifact ) {
            $this->writer->writeAt( $path . DIRECTORY_SEPARATOR . $artifact, 0, '' );
        }

        $state = new BackupExportState(
            array(
                'anchor_id'             => $anchorId,
                'path'                  => $path,
                'provider_version'      => $version,
                'dry_run_source_digest' => $sourceDigest,
                'primary_table'         => (string) $primary['table'],
                'primary_key'           => (string) $primary['key'],
                'artifact_offsets'      => array(
                    'options.jsonl'      => 0,
                    'postmeta.jsonl'     => 0,
                    'usermeta.jsonl'     => 0,
                    'primary-term.jsonl' => 0,
                ),
                'message'               => __( 'Private bounded backup/export started. Yoast source remains read-only.', 'yablonsky-seo' ),
            )
        );
        $this->repository->save( $state );
        return $state;
    }

    public function step(): BackupExportState {
        $state = $this->repository->load();
        if ( null === $state ) {
            return $this->start();
        }
        if ( BackupExportState::STATUS_RUNNING !== $state->get( 'status' ) ) {
            return $state;
        }

        try {
            switch ( (string) $state->get( 'phase' ) ) {
                case 'export-options':
                    $next = $this->stepOptions( $state, false );
                    break;
                case 'export-postmeta':
                    $next = $this->stepPostmeta( $state, false );
                    break;
                case 'export-usermeta':
                    $next = $this->stepUsermeta( $state, false );
                    break;
                case 'export-primary':
                    $next = $this->stepPrimary( $state, false );
                    break;
                case 'verify-options':
                    $next = $this->stepOptions( $state, true );
                    break;
                case 'verify-postmeta':
                    $next = $this->stepPostmeta( $state, true );
                    break;
                case 'verify-usermeta':
                    $next = $this->stepUsermeta( $state, true );
                    break;
                case 'verify-primary':
                    $next = $this->stepPrimary( $state, true );
                    break;
                case 'seal':
                    $next = $this->seal( $state );
                    break;
                default:
                    throw new \RuntimeException( 'Unknown backup/export phase.' );
            }
        } catch ( \Throwable $e ) {
            $blockers = is_array( $state->get( 'blockers' ) ) ? $state->get( 'blockers' ) : array();
            $blockers[] = 'backup-runtime:' . sanitize_key( $e->getMessage() );
            $next = $state->merge(
                array(
                    'status'   => BackupExportState::STATUS_BLOCKED,
                    'blockers' => array_values( array_unique( $blockers ) ),
                    'message'  => __( 'Backup/export stopped fail-closed. No source SEO data was modified.', 'yablonsky-seo' ),
                )
            );
        }
        $this->repository->save( $next );
        return $next;
    }

    private function stepOptions( BackupExportState $state, bool $verify ): BackupExportState {
        $indexKey = $verify ? 'verify_option_index' : 'option_index';
        $offsetKey = $verify ? 'verify_option_offset' : 'option_offset';
        $index = (int) $state->get( $indexKey );
        $offset = (int) $state->get( $offsetKey );
        if ( $index >= count( self::OPTION_NAMES ) ) {
            return $state->merge(
                array(
                    'phase' => $verify ? 'verify-postmeta' : 'export-postmeta',
                    $offsetKey => 0,
                )
            );
        }

        $name = self::OPTION_NAMES[ $index ];
        $length = $this->optionLength( $name );
        $digestKey = $verify ? 'verify_source_digest' : 'raw_source_digest';
        $recordCountKey = $verify ? 'verify_records' : 'export_records';
        $digest = (string) $state->get( $digestKey );
        $records = (int) $state->get( $recordCountKey );

        if ( null === $length ) {
            $summary = array( 'type' => 'option-missing', 'name' => $name );
            $digest = $this->chainDigest( $digest, $summary );
            ++$records;
            $changes = array(
                $digestKey      => $digest,
                $recordCountKey => $records,
                $indexKey       => $index + 1,
                $offsetKey      => 0,
            );
            if ( ! $verify ) {
                $changes = $this->appendArtifact( $state, $changes, 'options.jsonl', array_merge( $summary, array( 'value_b64' => null ) ) );
            }
            return $state->merge( $changes );
        }

        if ( 0 === $length && 0 === $offset ) {
            $chunk = '';
        } else {
            $chunk = $this->optionChunk( $name, $offset, self::OPTION_CHUNK_BYTES );
            if ( '' === $chunk && $offset < $length ) {
                throw new \RuntimeException( 'option-chunk-read-incomplete' );
            }
        }
        $chunkBytes = strlen( $chunk );
        $summary = array(
            'type'   => 'option-chunk',
            'name'   => $name,
            'offset' => $offset,
            'bytes'  => $chunkBytes,
            'sha256' => hash( 'sha256', $chunk ),
            'total'  => $length,
        );
        $digest = $this->chainDigest( $digest, $summary );
        ++$records;
        $newOffset = $offset + $chunkBytes;
        $complete = $newOffset >= $length;
        $changes = array(
            $digestKey      => $digest,
            $recordCountKey => $records,
            $indexKey       => $complete ? $index + 1 : $index,
            $offsetKey      => $complete ? 0 : $newOffset,
        );
        if ( ! $verify ) {
            $changes = $this->appendArtifact( $state, $changes, 'options.jsonl', array_merge( $summary, array( 'value_b64' => base64_encode( $chunk ) ) ) );
        }
        return $state->merge( $changes );
    }

    private function stepPostmeta( BackupExportState $state, bool $verify ): BackupExportState {
        global $wpdb;
        $cursorKey = $verify ? 'verify_postmeta_cursor' : 'postmeta_cursor';
        $rowKey = $verify ? 'verify_postmeta_rows' : 'postmeta_rows';
        $digestKey = $verify ? 'verify_source_digest' : 'raw_source_digest';
        $recordCountKey = $verify ? 'verify_records' : 'export_records';
        $cursor = (int) $state->get( $cursorKey );
        $like = $wpdb->esc_like( '_yoast_wpseo_' ) . '%';
        $sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key LIKE %s ORDER BY meta_id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains only the trusted WordPress postmeta table plus placeholders; all values are prepared below.
        $prepared = $wpdb->prepare( $sql, $cursor, $like, self::ROW_BATCH );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; bounded export/verify keyset read.
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        if ( empty( $rows ) ) {
            return $state->merge( array( 'phase' => $verify ? 'verify-usermeta' : 'export-usermeta' ) );
        }

        $digest = (string) $state->get( $digestKey );
        $records = (int) $state->get( $recordCountKey );
        $rowCount = (int) $state->get( $rowKey );
        $lines = array();
        foreach ( $rows as $row ) {
            $value = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
            $summary = array(
                'type'     => 'postmeta',
                'meta_id'  => (int) $row['meta_id'],
                'post_id'  => (int) $row['post_id'],
                'meta_key' => (string) $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Backup artifact field name; not a database query argument.
                'bytes'    => strlen( $value ),
                'sha256'   => hash( 'sha256', $value ),
            );
            $digest = $this->chainDigest( $digest, $summary );
            ++$records;
            ++$rowCount;
            $cursor = max( $cursor, (int) $row['meta_id'] );
            if ( ! $verify ) {
                $lines[] = array_merge( $summary, array( 'value_b64' => base64_encode( $value ) ) );
            }
        }
        $changes = array(
            $cursorKey      => $cursor,
            $rowKey         => $rowCount,
            $digestKey      => $digest,
            $recordCountKey => $records,
        );
        if ( ! $verify ) {
            $changes = $this->appendArtifactLines( $state, $changes, 'postmeta.jsonl', $lines );
        }
        if ( count( $rows ) < self::ROW_BATCH ) {
            $changes['phase'] = $verify ? 'verify-usermeta' : 'export-usermeta';
        }
        return $state->merge( $changes );
    }

    private function stepUsermeta( BackupExportState $state, bool $verify ): BackupExportState {
        global $wpdb;
        $cursorKey = $verify ? 'verify_usermeta_cursor' : 'usermeta_cursor';
        $rowKey = $verify ? 'verify_usermeta_rows' : 'usermeta_rows';
        $digestKey = $verify ? 'verify_source_digest' : 'raw_source_digest';
        $recordCountKey = $verify ? 'verify_records' : 'export_records';
        $cursor = (int) $state->get( $cursorKey );
        $social = YoastUserFieldMap::socialContactFields();
        $placeholders = implode( ',', array_fill( 0, count( $social ), '%s' ) );
        $sql = "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE umeta_id > %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key IN ({$placeholders})) ORDER BY umeta_id ASC LIMIT %d";
        $args = array_merge(
            array( $cursor, $wpdb->esc_like( 'wpseo_' ) . '%', $wpdb->esc_like( '_yoast_wpseo_' ) . '%' ),
            $social,
            array( self::ROW_BATCH )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic section contains generated placeholders only; all values are prepared below.
        $prepared = $wpdb->prepare( $sql, $args );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; bounded export/verify keyset read.
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        if ( empty( $rows ) ) {
            return $state->merge( array( 'phase' => $verify ? 'verify-primary' : 'export-primary' ) );
        }

        $digest = (string) $state->get( $digestKey );
        $records = (int) $state->get( $recordCountKey );
        $rowCount = (int) $state->get( $rowKey );
        $lines = array();
        foreach ( $rows as $row ) {
            $value = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
            $summary = array(
                'type'      => 'usermeta',
                'umeta_id'  => (int) $row['umeta_id'],
                'user_id'   => (int) $row['user_id'],
                'meta_key'  => (string) $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Backup artifact field name; not a database query argument.
                'bytes'     => strlen( $value ),
                'sha256'    => hash( 'sha256', $value ),
            );
            $digest = $this->chainDigest( $digest, $summary );
            ++$records;
            ++$rowCount;
            $cursor = max( $cursor, (int) $row['umeta_id'] );
            if ( ! $verify ) {
                $lines[] = array_merge( $summary, array( 'value_b64' => base64_encode( $value ) ) );
            }
        }
        $changes = array(
            $cursorKey      => $cursor,
            $rowKey         => $rowCount,
            $digestKey      => $digest,
            $recordCountKey => $records,
        );
        if ( ! $verify ) {
            $changes = $this->appendArtifactLines( $state, $changes, 'usermeta.jsonl', $lines );
        }
        if ( count( $rows ) < self::ROW_BATCH ) {
            $changes['phase'] = $verify ? 'verify-primary' : 'export-primary';
        }
        return $state->merge( $changes );
    }

    private function stepPrimary( BackupExportState $state, bool $verify ): BackupExportState {
        global $wpdb;
        $table = (string) $state->get( 'primary_table' );
        $key = (string) $state->get( 'primary_key' );
        $cursorKey = $verify ? 'verify_primary_cursor' : 'primary_cursor';
        $rowKey = $verify ? 'verify_primary_rows' : 'primary_rows';
        $digestKey = $verify ? 'verify_source_digest' : 'raw_source_digest';
        $recordCountKey = $verify ? 'verify_records' : 'export_records';
        $digest = (string) $state->get( $digestKey );
        $records = (int) $state->get( $recordCountKey );

        if ( '' === $table ) {
            $summary = array( 'type' => 'primary-table-absent' );
            $digest = $this->chainDigest( $digest, $summary );
            ++$records;
            $changes = array(
                $digestKey      => $digest,
                $recordCountKey => $records,
                'phase'         => $verify ? 'seal' : 'verify-options',
            );
            if ( ! $verify ) {
                $changes = $this->appendArtifact( $state, $changes, 'primary-term.jsonl', $summary );
            }
            return $state->merge( $changes );
        }
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $key ) ) {
            throw new \RuntimeException( 'primary-table-identifier-invalid' );
        }

        $cursor = (int) $state->get( $cursorKey );
        $prepared = $wpdb->prepare(
            'SELECT * FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT %d',
            $table,
            $key,
            $cursor,
            $key,
            self::ROW_BATCH
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier/value placeholders are prepared immediately above; bounded derived-state export/verify read.
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        if ( empty( $rows ) ) {
            return $state->merge( array( 'phase' => $verify ? 'seal' : 'verify-options' ) );
        }

        $rowCount = (int) $state->get( $rowKey );
        $lines = array();
        foreach ( $rows as $row ) {
            if ( ! isset( $row[ $key ] ) ) {
                throw new \RuntimeException( 'primary-table-key-missing' );
            }
            $canonical = array();
            $artifactColumns = array();
            foreach ( $row as $column => $value ) {
                if ( null === $value ) {
                    $canonical[ (string) $column ] = null;
                    $artifactColumns[ (string) $column ] = null;
                } else {
                    $raw = (string) $value;
                    $canonical[ (string) $column ] = array( 'bytes' => strlen( $raw ), 'sha256' => hash( 'sha256', $raw ) );
                    $artifactColumns[ (string) $column ] = base64_encode( $raw );
                }
            }
            ksort( $canonical, SORT_STRING );
            ksort( $artifactColumns, SORT_STRING );
            $summary = array( 'type' => 'primary-row', 'pk' => (int) $row[ $key ], 'columns' => $canonical );
            $digest = $this->chainDigest( $digest, $summary );
            ++$records;
            ++$rowCount;
            $cursor = max( $cursor, (int) $row[ $key ] );
            if ( ! $verify ) {
                $lines[] = array( 'type' => 'primary-row', 'pk' => (int) $row[ $key ], 'columns_b64' => $artifactColumns );
            }
        }
        $changes = array(
            $cursorKey      => $cursor,
            $rowKey         => $rowCount,
            $digestKey      => $digest,
            $recordCountKey => $records,
        );
        if ( ! $verify ) {
            $changes = $this->appendArtifactLines( $state, $changes, 'primary-term.jsonl', $lines );
        }
        if ( count( $rows ) < self::ROW_BATCH ) {
            $changes['phase'] = $verify ? 'seal' : 'verify-options';
        }
        return $state->merge( $changes );
    }

    private function seal( BackupExportState $state ): BackupExportState {
        if ( ! hash_equals( (string) $state->get( 'raw_source_digest' ), (string) $state->get( 'verify_source_digest' ) ) ) {
            throw new \RuntimeException( 'export-verify-digest-mismatch' );
        }
        if ( (int) $state->get( 'export_records' ) !== (int) $state->get( 'verify_records' ) ) {
            throw new \RuntimeException( 'export-verify-record-count-mismatch' );
        }
        if ( (int) $state->get( 'postmeta_rows' ) !== (int) $state->get( 'verify_postmeta_rows' ) ) {
            throw new \RuntimeException( 'export-verify-postmeta-count-mismatch' );
        }
        if ( (int) $state->get( 'usermeta_rows' ) !== (int) $state->get( 'verify_usermeta_rows' ) ) {
            throw new \RuntimeException( 'export-verify-usermeta-count-mismatch' );
        }
        if ( (int) $state->get( 'primary_rows' ) !== (int) $state->get( 'verify_primary_rows' ) ) {
            throw new \RuntimeException( 'export-verify-primary-count-mismatch' );
        }

        $path = (string) $state->get( 'path' );
        $this->pathPolicy->assertPrivateDirectory( $path );
        $artifacts = array();
        foreach ( array( 'options.jsonl', 'postmeta.jsonl', 'usermeta.jsonl', 'primary-term.jsonl' ) as $name ) {
            $artifactPath = $path . DIRECTORY_SEPARATOR . $name;
            $this->pathPolicy->assertPrivateFile( $artifactPath );
            $bytes = filesize( $artifactPath );
            $sha = hash_file( 'sha256', $artifactPath );
            if ( false === $bytes || ! is_string( $sha ) ) {
                throw new \RuntimeException( 'artifact-seal-read-failed' );
            }
            $artifacts[] = array( 'name' => $name, 'bytes' => (int) $bytes, 'sha256' => $sha, 'mode' => '0600' );
        }
        $completedAt = gmdate( 'c' );
        $manifest = array(
            'schema'                => 'yps.backup-anchor.v1',
            'anchor_id'             => (string) $state->get( 'anchor_id' ),
            'provider'              => 'yoast',
            'provider_version'      => (string) $state->get( 'provider_version' ),
            'dry_run_source_digest' => (string) $state->get( 'dry_run_source_digest' ),
            'raw_source_digest'     => (string) $state->get( 'raw_source_digest' ),
            'records'               => (int) $state->get( 'export_records' ),
            'postmeta_rows'         => (int) $state->get( 'postmeta_rows' ),
            'usermeta_rows'         => (int) $state->get( 'usermeta_rows' ),
            'primary_rows'          => (int) $state->get( 'primary_rows' ),
            'primary_table'         => (string) $state->get( 'primary_table' ),
            'primary_key'           => (string) $state->get( 'primary_key' ),
            'artifacts'             => $artifacts,
            'completed_at'          => $completedAt,
        );
        $manifestPath = $path . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestSha = $this->writer->writeAtomicJson( $manifestPath, $manifest );
        $this->pathPolicy->assertPrivateFile( $manifestPath );
        if ( '' === $manifestSha ) {
            throw new \RuntimeException( 'manifest-sha256-missing' );
        }

        $verifiedAt = gmdate( 'c' );
        $anchor = array(
            'schema'                => 'yps.backup-anchor.v1',
            'anchor_id'             => (string) $state->get( 'anchor_id' ),
            'path'                  => $path,
            'provider'              => 'yoast',
            'provider_version'      => (string) $state->get( 'provider_version' ),
            'dry_run_source_digest' => (string) $state->get( 'dry_run_source_digest' ),
            'raw_source_digest'     => (string) $state->get( 'raw_source_digest' ),
            'manifest_sha256'       => $manifestSha,
            'completed_at'          => $completedAt,
            'verified_at'           => $verifiedAt,
        );
        if ( ! ( new BackupAnchorVerifier() )->verifyStored( $anchor ) ) {
            throw new \RuntimeException( 'sealed-anchor-full-verification-failed' );
        }
        $this->repository->saveAnchor( $anchor );

        $migration = $this->migrationRepository->load();
        if ( null === $migration || MigrationState::MODE_DRY_RUN !== $migration->get( 'mode' ) || MigrationState::STATUS_COMPLETE !== $migration->get( 'status' ) || ! $migration->get( 'source_coverage_ready' ) ) {
            throw new \RuntimeException( 'dry-run-state-changed-before-anchor-seal' );
        }
        if ( ! hash_equals( (string) $state->get( 'dry_run_source_digest' ), (string) $migration->get( 'source_digest' ) ) ) {
            throw new \RuntimeException( 'dry-run-digest-changed-before-anchor-seal' );
        }
        $importBlockers = is_array( $migration->get( 'import_blockers' ) ) ? $migration->get( 'import_blockers' ) : array();
        $importBlockers = array_values( array_filter( $importBlockers, static function ( string $value ): bool { return 'backup-export-required' !== $value; } ) );
        $migration = $migration->merge(
            array(
                'backup_export_ready'    => true,
                'backup_export_verified' => true,
                'backup_anchor_id'       => (string) $state->get( 'anchor_id' ),
                'backup_anchor_path'     => $path,
                'backup_verified_at'     => $verifiedAt,
                'shadow_import_ready'    => empty( $importBlockers ),
                'import_blockers'        => $importBlockers,
                'cutover_ready'          => false,
                'message'                => __( 'Lossless source coverage and a verified private rollback anchor are ready. Shadow import is authorized; public cutover remains locked.', 'yablonsky-seo' ),
            )
        );
        $this->migrationRepository->save( $migration );

        return $state->merge(
            array(
                'status'          => BackupExportState::STATUS_COMPLETE,
                'phase'           => 'complete',
                'verified'        => true,
                'manifest_sha256' => $manifestSha,
                'completed_at'    => $completedAt,
                'verified_at'     => $verifiedAt,
                'message'         => __( 'Private rollback/export anchor sealed and independently verified.', 'yablonsky-seo' ),
            )
        );
    }

    /** @return array{table:string,key:string,blocker:string} */
    private function primaryDescriptor(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'yoast_primary_term';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot schema inventory; no reusable application data is fetched.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( (string) $found !== $table ) {
            return array( 'table' => '', 'key' => '', 'blocker' => '' );
        }
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
            return array( 'table' => '', 'key' => '', 'blocker' => 'primary-term-table-name-invalid' );
        }
        $keysSql = $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier/value placeholders are prepared above; one-shot schema inventory.
        $keys = $wpdb->get_results( $keysSql, ARRAY_A );
        if ( ! is_array( $keys ) || 1 !== count( $keys ) || empty( $keys[0]['Column_name'] ) ) {
            return array( 'table' => $table, 'key' => '', 'blocker' => 'primary-term-table-unsupported-shape' );
        }
        $key = (string) $keys[0]['Column_name'];
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $key ) ) {
            return array( 'table' => $table, 'key' => '', 'blocker' => 'primary-term-table-key-invalid' );
        }
        return array( 'table' => $table, 'key' => $key, 'blocker' => '' );
    }

    private function optionLength( string $name ): ?int {
        global $wpdb;
        $sql = $wpdb->prepare( "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; bounded source export read.
        $value = $wpdb->get_var( $sql );
        return null === $value ? null : max( 0, (int) $value );
    }

    private function optionChunk( string $name, int $offset, int $length ): string {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT SUBSTRING(option_value, %d, %d) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $offset + 1,
            max( 1, min( self::OPTION_CHUNK_BYTES, $length ) ),
            $name
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; bounded raw option chunk read.
        $value = $wpdb->get_var( $sql );
        return null === $value ? '' : (string) $value;
    }

    /** @param array<string,mixed> $record */
    private function chainDigest( string $digest, array $record ): string {
        ksort( $record, SORT_STRING );
        $json = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            throw new \RuntimeException( 'backup-digest-record-encode-failed' );
        }
        return hash( 'sha256', $digest . "\n" . $json );
    }

    /** @param array<string,mixed> $changes @param array<string,mixed> $record @return array<string,mixed> */
    private function appendArtifact( BackupExportState $state, array $changes, string $name, array $record ): array {
        return $this->appendArtifactLines( $state, $changes, $name, array( $record ) );
    }

    /** @param array<string,mixed> $changes @param array<int,array<string,mixed>> $records @return array<string,mixed> */
    private function appendArtifactLines( BackupExportState $state, array $changes, string $name, array $records ): array {
        if ( empty( $records ) ) {
            return $changes;
        }
        $payload = '';
        foreach ( $records as $record ) {
            $json = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
            if ( ! is_string( $json ) ) {
                throw new \RuntimeException( 'backup-artifact-record-encode-failed' );
            }
            $payload .= $json . "\n";
        }
        $offsets = is_array( $state->get( 'artifact_offsets' ) ) ? $state->get( 'artifact_offsets' ) : array();
        $offset = isset( $offsets[ $name ] ) ? (int) $offsets[ $name ] : 0;
        $path = rtrim( (string) $state->get( 'path' ), '/\\' ) . DIRECTORY_SEPARATOR . $name;
        $offsets[ $name ] = $this->writer->writeAt( $path, $offset, $payload );
        $changes['artifact_offsets'] = $offsets;
        return $changes;
    }
}
