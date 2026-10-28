<?php
namespace Yablonsky\PerformanceSEO\Migration;

use Yablonsky\PerformanceSEO\Migration\Contract\FieldClassification;
use Yablonsky\PerformanceSEO\Migration\Provenance\ProvenanceRepository;
use Yablonsky\PerformanceSEO\Migration\Provider\ProviderAdapterRegistry;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastGlobalSettingsNormalizer;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastOptionSnapshotReader;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastPostMetaBatchReader;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastSourceInventory;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastUserMetaBatchReader;
use Yablonsky\PerformanceSEO\Migration\Yoast\YoastUserFieldMap;
use Yablonsky\PerformanceSEO\Migration\Backup\BackupExportRepository;
use Yablonsky\PerformanceSEO\Migration\Backup\BackupAnchorVerifier;
use Yablonsky\PerformanceSEO\Runtime\Provider;
use Yablonsky\PerformanceSEO\Performance\AdaptiveWorkBudget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastMigrationService {
    public const CONTRACT_REVISION = 'yoast-core-26.8-28.4-yseo-2.70.1-r3';
    private const GLOBAL_TARGET_OPTION = 'yps_shadow_global_settings';

    /** @var MigrationRepository */ private $repository;
    /** @var PostIdBatchReader */ private $postReader;
    /** @var YoastTaxonomyBatchReader */ private $taxonomyReader;
    /** @var YoastPostMetaBatchReader */ private $postMetaReader;
    /** @var YoastUserMetaBatchReader */ private $userMetaReader;
    /** @var ProvenanceRepository */ private $provenance;
    /** @var AdaptiveWorkBudget */ private $workBudget;

    public function __construct( MigrationRepository $repository ) {
        $this->repository = $repository;
        $this->postReader = new PostIdBatchReader();
        $this->taxonomyReader = new YoastTaxonomyBatchReader();
        $this->postMetaReader = new YoastPostMetaBatchReader();
        $this->userMetaReader = new YoastUserMetaBatchReader();
        $this->provenance = new ProvenanceRepository();
        $this->workBudget = new AdaptiveWorkBudget();
    }

    public function start( string $mode ): MigrationState {
        $mode = MigrationState::MODE_IMPORT === $mode ? MigrationState::MODE_IMPORT : MigrationState::MODE_DRY_RUN;
        $version = $this->sourceVersion();
        $provider = new Provider( 'yoast', 'Yoast SEO', $version );
        $adapter = ( new ProviderAdapterRegistry() )->get( 'yoast' );
        $carriedBackup = array();

        if ( MigrationState::MODE_IMPORT === $mode ) {
            if ( null === $adapter || ! $adapter->canExecuteShadowImport( $provider ) ) {
                return $this->saveBlockedStart(
                    $mode,
                    $version,
                    null === $adapter ? 'unsupported' : $adapter->compatibility( $provider ),
                    array( 'adapter-not-verified-for-shadow-import' )
                );
            }

            // Import is authorized only by the exact completed lossless dry-run plus
            // a freshly revalidated private backup anchor for that source identity.
            $previous = $this->repository->load();
            $importBlockers = array();
            if ( null === $previous || MigrationState::MODE_DRY_RUN !== $previous->get( 'mode' ) || MigrationState::STATUS_COMPLETE !== $previous->get( 'status' ) || ! $previous->get( 'source_coverage_ready' ) ) {
                $importBlockers[] = 'successful-dry-run-required';
            }
            if ( null === $previous || (string) $previous->get( 'provider_version' ) !== $version ) {
                $importBlockers[] = 'dry-run-provider-version-mismatch';
            }
            if ( null === $previous || self::CONTRACT_REVISION !== (string) $previous->get( 'contract_revision' ) ) {
                $importBlockers[] = 'dry-run-contract-revision-mismatch';
            }
            if ( null === $previous || ! $previous->get( 'backup_export_ready' ) || ! $previous->get( 'backup_export_verified' ) ) {
                $importBlockers[] = 'backup-export-required';
            }

            $anchor = ( new BackupExportRepository() )->loadAnchor();
            $dryRunDigest = null === $previous ? '' : (string) $previous->get( 'source_digest' );
            if ( null === $anchor || ! ( new BackupAnchorVerifier() )->verifyFreshForImport( $anchor, $version, $dryRunDigest, 900 ) ) {
                $importBlockers[] = 'backup-export-verification-required';
            }

            if ( ! empty( $importBlockers ) ) {
                return $this->saveBlockedStart(
                    $mode,
                    $version,
                    $adapter->compatibility( $provider ),
                    array_values( array_unique( $importBlockers ) )
                );
            }

            $carriedBackup = array(
                'backup_export_ready'    => true,
                'backup_export_verified' => true,
                'backup_anchor_id'       => (string) ( $anchor['anchor_id'] ?? '' ),
                'backup_anchor_path'     => (string) ( $anchor['path'] ?? '' ),
                'backup_verified_at'     => (string) ( $anchor['verified_at'] ?? '' ),
                'authorized_source_digest'=> $dryRunDigest,
                'shadow_import_ready'    => true,
                'import_blockers'        => array(),
            );
        }

        $inventory = ( new YoastSourceInventory() )->collect( $version );
        $present = array();
        foreach ( $inventory['options'] as $optionName => $optionState ) {
            if ( ! empty( $optionState['present'] ) ) {
                $present[] = (string) $optionName;
            }
        }
        $state = new MigrationState(
            array_merge(
                array(
                    'mode'                   => $mode,
                    'provider_version'       => $version,
                    'contract_revision'      => self::CONTRACT_REVISION,
                    'adapter_compatibility'  => null === $adapter ? '' : $adapter->compatibility( $provider ),
                    'source_options_present' => $present,
                    'source_inventory'       => $inventory,
                    'inventory_blockers'     => isset( $inventory['blockers'] ) && is_array( $inventory['blockers'] ) ? $inventory['blockers'] : array(),
                ),
                $carriedBackup
            )
        );
        $this->repository->save( $state );
        return $state;
    }

    public function step( ?int $batchSize = null ): MigrationState {
        global $wpdb;

        $state = $this->repository->load();
        if ( null === $state ) {
            $state = $this->start( MigrationState::MODE_DRY_RUN );
        }
        if ( MigrationState::STATUS_RUNNING !== $state->get( 'status' ) ) {
            return $state;
        }

        $phase = (string) $state->get( 'phase' );
        $effectiveBatchSize = null === $batchSize
            ? $this->workBudget->batchSize( $state )
            : max( AdaptiveWorkBudget::MIN_BATCH, min( AdaptiveWorkBudget::MAX_BATCH, $batchSize ) );

        // Inventory/globals/finalize are bounded one-shot phases and do not influence
        // the adaptive batch controller. Posts/users/taxonomy are the scalable phases.
        if ( 'inventory' === $phase ) {
            $next = $state->merge( array( 'phase' => 'globals' ) );
            $this->repository->save( $next );
            return $next;
        }
        if ( 'globals' === $phase ) {
            return $this->stepGlobals( $state );
        }
        if ( ! in_array( $phase, array( 'posts', 'users', 'taxonomy' ), true ) ) {
            return $this->finalize( $state );
        }

        $started = hrtime( true );
        $queryStart = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
        $memoryStart = memory_get_usage( true );

        if ( 'posts' === $phase ) {
            $next = $this->stepPosts( $state, $effectiveBatchSize );
        } elseif ( 'users' === $phase ) {
            $next = $this->stepUsers( $state, $effectiveBatchSize );
        } else {
            $next = $this->stepTaxonomyOption( $state, $effectiveBatchSize );
        }

        $elapsedMs = ( hrtime( true ) - $started ) / 1000000;
        $queryDelta = isset( $wpdb->num_queries ) ? max( 0, (int) $wpdb->num_queries - $queryStart ) : 0;
        $memoryDelta = max( 0, memory_get_usage( true ) - $memoryStart );

        $observed = $this->workBudget->observe(
            $next,
            $effectiveBatchSize,
            $elapsedMs,
            $queryDelta,
            $memoryDelta
        );
        $this->repository->save( $observed );
        return $observed;
    }

    private function stepGlobals( MigrationState $state ): MigrationState {
        $sourceOptions = ( new YoastOptionSnapshotReader() )->read( array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt' ) );
        $normalized = ( new YoastGlobalSettingsNormalizer() )->normalize( $sourceOptions );
        $counts = $this->mergeCounts( $state, $normalized['counts'] );
        $digest = (string) $state->get( 'source_digest' );
        $globalProvenance = array();
        foreach ( $normalized['records'] as $record ) {
            $locator = (string) $record['source_option'] . ':' . (string) $record['source_key'];
            $digest = hash( 'sha256', $digest . '|global|' . $locator . '|' . $this->stableEncode( $record['value'] ) );
            if ( '' !== (string) $record['canonical_key'] && FieldClassification::MAPPED === $record['classification'] ) {
                $globalProvenance[ (string) $record['canonical_key'] ] = ProvenanceRepository::record(
                    'yoast',
                    (string) $state->get( 'provider_version' ),
                    $locator,
                    (string) $record['classification'],
                    (string) $record['transform'],
                    $record['value']
                );
            }
        }

        $imported = (int) $state->get( 'global_imported_values' );
        $verified = (int) $state->get( 'global_verified_values' );
        $conflicts = (int) $state->get( 'conflicts' );
        $conflictDetails = is_array( $state->get( 'conflict_details' ) ) ? $state->get( 'conflict_details' ) : array();
        $conflictDetailsTruncated = (int) $state->get( 'conflict_details_truncated' );
        if ( MigrationState::MODE_IMPORT === $state->get( 'mode' ) ) {
            $copy = $this->copyGlobalValues( $normalized['values'] );
            $imported += $copy['imported'];
            $verified += $copy['verified'];
            $conflicts += $copy['conflicts'];
            $counts[ FieldClassification::CONFLICT ] = (int) ( $counts[ FieldClassification::CONFLICT ] ?? 0 ) + (int) $copy['conflicts'];
            foreach ( $copy['details'] as $detail ) {
                $this->rememberConflict( $conflictDetails, $conflictDetailsTruncated, $detail );
            }
            if ( 0 === $copy['conflicts'] && ! $this->provenance->replaceGlobalRecords( $globalProvenance ) ) {
                ++$conflicts;
                ++$counts[ FieldClassification::CONFLICT ];
                $this->rememberConflict(
                    $conflictDetails,
                    $conflictDetailsTruncated,
                    $this->conflictEvidence( 'global', 0, 'yoast-options', self::GLOBAL_TARGET_OPTION, 'provenance-write-verification-failed', '', '' )
                );
            }
        }

        $unsupported = $this->mergeSamples(
            is_array( $state->get( 'unsupported_source_fields' ) ) ? $state->get( 'unsupported_source_fields' ) : array(),
            $normalized['unsupported']
        );

        $next = $state->merge(
            array(
                'phase'                     => 'posts',
                'global_mapped_values'      => count( $normalized['values'] ),
                'global_imported_values'    => $imported,
                'global_verified_values'    => $verified,
                'classification_counts'     => $counts,
                'unsupported_source_fields' => $unsupported,
                'conflicts'                 => $conflicts,
                'conflict_details'          => $conflictDetails,
                'conflict_details_truncated'=> $conflictDetailsTruncated,
                'source_digest'             => $digest,
            )
        );
        $this->repository->save( $next );
        return $next;
    }

    private function stepPosts( MigrationState $state, int $batchSize ): MigrationState {
        $ids = $this->postReader->next( (int) $state->get( 'post_cursor' ), $batchSize );
        if ( empty( $ids ) ) {
            return $state->merge( array( 'phase' => 'users' ) );
        }

        $batchMeta = $this->postMetaReader->read( $ids );
        $mapped = (int) $state->get( 'mapped_values' );
        $ignored = (int) $state->get( 'ignored_derived_values' );
        $imported = (int) $state->get( 'imported_values' );
        $verified = (int) $state->get( 'verified_values' );
        $conflicts = (int) $state->get( 'conflicts' );
        $conflictDetails = is_array( $state->get( 'conflict_details' ) ) ? $state->get( 'conflict_details' ) : array();
        $conflictDetailsTruncated = (int) $state->get( 'conflict_details_truncated' );
        $unknown = is_array( $state->get( 'unknown_source_fields' ) ) ? $state->get( 'unknown_source_fields' ) : array();
        $invalid = is_array( $state->get( 'invalid_source_fields' ) ) ? $state->get( 'invalid_source_fields' ) : array();
        $counts = is_array( $state->get( 'classification_counts' ) ) ? $state->get( 'classification_counts' ) : FieldClassification::zeroCounts();
        $digest = (string) $state->get( 'source_digest' );

        foreach ( $ids as $postId ) {
            $provenance = array();
            $allMeta = isset( $batchMeta[ $postId ] ) && is_array( $batchMeta[ $postId ] ) ? $batchMeta[ $postId ] : array();
            foreach ( $allMeta as $sourceKey => $values ) {
                $sourceKey = (string) $sourceKey;
                if ( 0 !== strpos( $sourceKey, '_yoast_wpseo_' ) ) {
                    continue;
                }
                if ( YoastFieldMap::isIgnoredDerivedPostKey( $sourceKey ) ) {
                    ++$ignored;
                    ++$counts[ FieldClassification::IGNORED_DERIVED ];
                    continue;
                }
                $targetKey = YoastFieldMap::targetForPostKey( $sourceKey );
                if ( '' === $targetKey ) {
                    $this->rememberSample( $unknown, 'post:' . $sourceKey );
                    ++$counts[ FieldClassification::UNSUPPORTED ];
                    continue;
                }

                $sourceValue = isset( $values[0] ) ? SafeSerializedValue::decode( $values[0] ) : '';
                if ( ! is_scalar( $sourceValue ) ) {
                    $this->rememberSample( $invalid, 'post:' . $sourceKey );
                    ++$counts[ FieldClassification::INVALID_SOURCE ];
                    continue;
                }
                if ( '' === (string) $sourceValue ) {
                    continue;
                }
                $sourceValue = (string) $sourceValue;
                if ( '_yps_canonical' === $targetKey && ! $this->isAbsoluteHttpUrl( $sourceValue ) ) {
                    $this->rememberSample( $invalid, 'post:' . $sourceKey );
                    ++$counts[ FieldClassification::INVALID_SOURCE ];
                    continue;
                }
                ++$mapped;
                ++$counts[ FieldClassification::MAPPED ];
                $digest = hash( 'sha256', $digest . '|post|' . $postId . '|' . $sourceKey . '|' . $sourceValue );

                if ( MigrationState::MODE_IMPORT === $state->get( 'mode' ) ) {
                    $result = $this->copyPostValue( $postId, $targetKey, $sourceValue );
                    $imported += $result['imported'];
                    $verified += $result['verified'];
                    $conflicts += $result['conflicts'];
                    if ( $result['conflicts'] > 0 ) {
                        ++$counts[ FieldClassification::CONFLICT ];
                        $this->rememberConflict(
                            $conflictDetails,
                            $conflictDetailsTruncated,
                            $this->conflictEvidence( 'post', (int) $postId, $sourceKey, $targetKey, (string) $result['reason'], $sourceValue, (string) $result['target_value'] )
                        );
                    } elseif ( $result['verified'] > 0 ) {
                        $provenance[ $targetKey ] = ProvenanceRepository::record(
                            'yoast',
                            (string) $state->get( 'provider_version' ),
                            'postmeta:' . $sourceKey,
                            FieldClassification::MAPPED,
                            'direct-field-map',
                            $sourceValue
                        );
                    }
                }
            }
            if ( MigrationState::MODE_IMPORT === $state->get( 'mode' ) && ! empty( $provenance ) && ! $this->provenance->mergePostRecords( (int) $postId, $provenance ) ) {
                ++$conflicts;
                ++$counts[ FieldClassification::CONFLICT ];
                $this->rememberConflict(
                    $conflictDetails,
                    $conflictDetailsTruncated,
                    $this->conflictEvidence( 'post', (int) $postId, 'provenance', '_yps_migration_provenance', 'provenance-write-verification-failed', '', '' )
                );
            }
        }

        $next = $state->merge(
            array(
                'post_cursor'            => max( $ids ),
                'processed_posts'        => (int) $state->get( 'processed_posts' ) + count( $ids ),
                'mapped_values'          => $mapped,
                'ignored_derived_values' => $ignored,
                'imported_values'        => $imported,
                'verified_values'        => $verified,
                'conflicts'              => $conflicts,
                'conflict_details'       => $conflictDetails,
                'conflict_details_truncated' => $conflictDetailsTruncated,
                'classification_counts'  => $counts,
                'unknown_source_fields'  => $unknown,
                'invalid_source_fields'  => $invalid,
                'source_digest'          => $digest,
            )
        );
        return $next;
    }

    private function stepUsers( MigrationState $state, int $batchSize ): MigrationState {
        $batch = $this->userMetaReader->next( (int) $state->get( 'user_meta_cursor' ), $batchSize );
        $rows = is_array( $batch['rows'] ) ? $batch['rows'] : array();
        if ( empty( $rows ) && $batch['complete'] ) {
            return $state->merge( array( 'phase' => 'taxonomy' ) );
        }

        $mapped = (int) $state->get( 'mapped_values' );
        $mappedUser = (int) $state->get( 'mapped_user_values' );
        $ignored = (int) $state->get( 'ignored_derived_values' );
        $imported = (int) $state->get( 'imported_values' );
        $importedUser = (int) $state->get( 'imported_user_values' );
        $verified = (int) $state->get( 'verified_values' );
        $verifiedUser = (int) $state->get( 'verified_user_values' );
        $conflicts = (int) $state->get( 'conflicts' );
        $conflictDetails = is_array( $state->get( 'conflict_details' ) ) ? $state->get( 'conflict_details' ) : array();
        $conflictDetailsTruncated = (int) $state->get( 'conflict_details_truncated' );
        $unknown = is_array( $state->get( 'unknown_source_fields' ) ) ? $state->get( 'unknown_source_fields' ) : array();
        $invalid = is_array( $state->get( 'invalid_source_fields' ) ) ? $state->get( 'invalid_source_fields' ) : array();
        $counts = is_array( $state->get( 'classification_counts' ) ) ? $state->get( 'classification_counts' ) : FieldClassification::zeroCounts();
        $digest = (string) $state->get( 'source_digest' );
        $provenanceByUser = array();

        foreach ( $rows as $row ) {
            $userId = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            $sourceKey = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
            if ( $userId <= 0 || '' === $sourceKey ) {
                continue;
            }
            $raw = $row['meta_value'] ?? '';
            $sourceValue = SafeSerializedValue::decode( $raw );

            if ( YoastUserFieldMap::isIgnoredDerived( $sourceKey ) ) {
                ++$ignored;
                ++$counts[ FieldClassification::IGNORED_DERIVED ];
                continue;
            }

            $targetKey = YoastUserFieldMap::targetForKey( $sourceKey );
            if ( '' === $targetKey ) {
                if ( is_scalar( $sourceValue ) && '' === (string) $sourceValue ) {
                    ++$counts[ FieldClassification::DEFAULT_EQUIVALENT ];
                    continue;
                }
                $this->rememberSample( $unknown, 'user:' . $sourceKey );
                ++$counts[ FieldClassification::UNSUPPORTED ];
                continue;
            }
            if ( ! is_scalar( $sourceValue ) ) {
                $this->rememberSample( $invalid, 'user:' . $sourceKey );
                ++$counts[ FieldClassification::INVALID_SOURCE ];
                continue;
            }
            if ( '' === (string) $sourceValue ) {
                continue;
            }
            $sourceValue = (string) $sourceValue;
            ++$mapped;
            ++$mappedUser;
            ++$counts[ FieldClassification::MAPPED ];
            $digest = hash( 'sha256', $digest . '|user|' . $userId . '|' . $sourceKey . '|' . $sourceValue );

            if ( MigrationState::MODE_IMPORT === $state->get( 'mode' ) ) {
                $result = $this->copyUserValue( $userId, $targetKey, $sourceValue );
                $imported += $result['imported'];
                $importedUser += $result['imported'];
                $verified += $result['verified'];
                $verifiedUser += $result['verified'];
                $conflicts += $result['conflicts'];
                if ( $result['conflicts'] > 0 ) {
                    ++$counts[ FieldClassification::CONFLICT ];
                    $this->rememberConflict(
                        $conflictDetails,
                        $conflictDetailsTruncated,
                        $this->conflictEvidence( 'user', $userId, $sourceKey, $targetKey, (string) $result['reason'], $sourceValue, (string) $result['target_value'] )
                    );
                } elseif ( $result['verified'] > 0 ) {
                    if ( ! isset( $provenanceByUser[ $userId ] ) ) {
                        $provenanceByUser[ $userId ] = array();
                    }
                    $provenanceByUser[ $userId ][ $targetKey ] = ProvenanceRepository::record(
                        'yoast',
                        (string) $state->get( 'provider_version' ),
                        'usermeta:' . $sourceKey,
                        FieldClassification::MAPPED,
                        'direct-user-field-map',
                        $sourceValue
                    );
                }
            }
        }

        if ( MigrationState::MODE_IMPORT === $state->get( 'mode' ) ) {
            foreach ( $provenanceByUser as $userId => $records ) {
                if ( ! $this->provenance->mergeUserRecords( (int) $userId, $records ) ) {
                    ++$conflicts;
                    ++$counts[ FieldClassification::CONFLICT ];
                    $this->rememberConflict(
                        $conflictDetails,
                        $conflictDetailsTruncated,
                        $this->conflictEvidence( 'user', (int) $userId, 'provenance', '_yps_migration_provenance', 'provenance-write-verification-failed', '', '' )
                    );
                }
            }
        }

        return $state->merge(
            array(
                'user_meta_cursor'        => (int) $batch['cursor'],
                'processed_user_meta'     => (int) $state->get( 'processed_user_meta' ) + count( $rows ),
                'mapped_values'           => $mapped,
                'mapped_user_values'      => $mappedUser,
                'ignored_derived_values'  => $ignored,
                'imported_values'         => $imported,
                'imported_user_values'    => $importedUser,
                'verified_values'         => $verified,
                'verified_user_values'    => $verifiedUser,
                'conflicts'               => $conflicts,
                'conflict_details'        => $conflictDetails,
                'conflict_details_truncated' => $conflictDetailsTruncated,
                'classification_counts'   => $counts,
                'unknown_source_fields'   => $unknown,
                'invalid_source_fields'   => $invalid,
                'source_digest'            => $digest,
                'phase'                    => $batch['complete'] ? 'taxonomy' : 'users',
            )
        );
    }

    private function stepTaxonomyOption( MigrationState $state, int $batchSize ): MigrationState {
        $batch = $this->taxonomyReader->next( (string) $state->get( 'taxonomy_cursor' ), (int) $state->get( 'taxonomy_term_cursor' ), $batchSize );
        $records = $batch['records'];
        if ( empty( $records ) && $batch['complete'] ) {
            return $state->merge( array( 'phase' => 'finalize' ) );
        }

        $mapped = (int) $state->get( 'mapped_values' );
        $ignored = (int) $state->get( 'ignored_derived_values' );
        $imported = (int) $state->get( 'imported_values' );
        $verified = (int) $state->get( 'verified_values' );
        $conflicts = (int) $state->get( 'conflicts' );
        $conflictDetails = is_array( $state->get( 'conflict_details' ) ) ? $state->get( 'conflict_details' ) : array();
        $conflictDetailsTruncated = (int) $state->get( 'conflict_details_truncated' );
        $unknown = is_array( $state->get( 'unknown_source_fields' ) ) ? $state->get( 'unknown_source_fields' ) : array();
        $invalid = is_array( $state->get( 'invalid_source_fields' ) ) ? $state->get( 'invalid_source_fields' ) : array();
        $counts = is_array( $state->get( 'classification_counts' ) ) ? $state->get( 'classification_counts' ) : FieldClassification::zeroCounts();
        $digest = (string) $state->get( 'source_digest' );
        $map = YoastFieldMap::taxonomyFields();
        $ignoredTax = YoastFieldMap::ignoredDerivedTaxonomyFields();

        foreach ( $records as $record ) {
            $termId = (int) $record['term_id'];
            $taxonomy = (string) $record['taxonomy'];
            $fields = is_array( $record['fields'] ) ? $record['fields'] : array();
            $provenance = array();
            foreach ( $fields as $sourceKey => $sourceValue ) {
                $sourceKey = (string) $sourceKey;
                if ( in_array( $sourceKey, $ignoredTax, true ) ) {
                    ++$ignored;
                    ++$counts[ FieldClassification::IGNORED_DERIVED ];
                    continue;
                }
                if ( ! isset( $map[ $sourceKey ] ) ) {
                    if ( '' !== (string) $sourceValue ) {
                        $this->rememberSample( $unknown, 'term:' . $sourceKey );
                        ++$counts[ FieldClassification::UNSUPPORTED ];
                    }
                    continue;
                }
                if ( ! is_scalar( $sourceValue ) ) {
                    $this->rememberSample( $invalid, 'term:' . $sourceKey );
                    ++$counts[ FieldClassification::INVALID_SOURCE ];
                    continue;
                }
                if ( '' === (string) $sourceValue ) {
                    continue;
                }
                $sourceValue = (string) $sourceValue;
                if ( '_yps_canonical' === $map[ $sourceKey ] && ! $this->isAbsoluteHttpUrl( $sourceValue ) ) {
                    $this->rememberSample( $invalid, 'term:' . $sourceKey );
                    ++$counts[ FieldClassification::INVALID_SOURCE ];
                    continue;
                }
                ++$mapped;
                ++$counts[ FieldClassification::MAPPED ];
                $digest = hash( 'sha256', $digest . '|term|' . $taxonomy . '|' . $termId . '|' . $sourceKey . '|' . $sourceValue );

                if ( MigrationState::MODE_IMPORT === $state->get( 'mode' ) ) {
                    $result = $this->copyTermValue( $termId, $map[ $sourceKey ], $sourceValue );
                    $imported += $result['imported'];
                    $verified += $result['verified'];
                    $conflicts += $result['conflicts'];
                    if ( $result['conflicts'] > 0 ) {
                        ++$counts[ FieldClassification::CONFLICT ];
                        $this->rememberConflict(
                            $conflictDetails,
                            $conflictDetailsTruncated,
                            $this->conflictEvidence( 'term', $termId, $sourceKey, $map[ $sourceKey ], (string) $result['reason'], $sourceValue, (string) $result['target_value'] )
                        );
                    } elseif ( $result['verified'] > 0 ) {
                        $provenance[ $map[ $sourceKey ] ] = ProvenanceRepository::record(
                            'yoast',
                            (string) $state->get( 'provider_version' ),
                            'wpseo_taxonomy_meta:' . $taxonomy . ':' . $termId . ':' . $sourceKey,
                            FieldClassification::MAPPED,
                            'taxonomy-option-to-term-meta',
                            $sourceValue
                        );
                    }
                }
            }
            if ( MigrationState::MODE_IMPORT === $state->get( 'mode' ) && ! empty( $provenance ) && ! $this->provenance->mergeTermRecords( $termId, $provenance ) ) {
                ++$conflicts;
                ++$counts[ FieldClassification::CONFLICT ];
                $this->rememberConflict(
                    $conflictDetails,
                    $conflictDetailsTruncated,
                    $this->conflictEvidence( 'term', $termId, 'provenance', '_yps_migration_provenance', 'provenance-write-verification-failed', '', '' )
                );
            }
        }

        $next = $state->merge(
            array(
                'taxonomy_cursor'        => $batch['taxonomy_cursor'],
                'taxonomy_term_cursor'   => $batch['term_cursor'],
                'processed_terms'        => (int) $state->get( 'processed_terms' ) + count( $records ),
                'mapped_values'          => $mapped,
                'ignored_derived_values' => $ignored,
                'imported_values'        => $imported,
                'verified_values'        => $verified,
                'conflicts'              => $conflicts,
                'conflict_details'       => $conflictDetails,
                'conflict_details_truncated' => $conflictDetailsTruncated,
                'classification_counts'  => $counts,
                'unknown_source_fields'  => $unknown,
                'invalid_source_fields'  => $invalid,
                'source_digest'          => $digest,
                'phase'                  => $batch['complete'] ? 'finalize' : 'taxonomy',
            )
        );
        return $next;
    }

    private function finalize( MigrationState $state ): MigrationState {
        $readiness = ( new MigrationReadinessEvaluator() )->evaluate( $state );
        $coverageReady = $readiness['ready'];
        $importBlockers = array();
        if ( ! $coverageReady ) {
            $importBlockers = array_merge( $importBlockers, $readiness['blockers'] );
        }
        if ( (int) $state->get( 'conflicts' ) > 0 ) {
            $importBlockers[] = 'target-conflicts';
        }
        if ( ! $state->get( 'backup_export_ready' ) ) {
            $importBlockers[] = 'backup-export-required';
        }

        $sourceImmutable = false;
        $shadowParityReady = false;
        $semanticParityReady = false;
        $cutoverReady = false;

        if ( $coverageReady && MigrationState::MODE_IMPORT === $state->get( 'mode' ) ) {
            $authorizedDigest = (string) $state->get( 'authorized_source_digest' );
            $currentDigest = (string) $state->get( 'source_digest' );
            $sourceImmutable = '' !== $authorizedDigest && hash_equals( $authorizedDigest, $currentDigest );
            if ( ! $sourceImmutable ) {
                $importBlockers[] = 'source-changed-since-dry-run';
            }

            $mapped = (int) $state->get( 'mapped_values' );
            $verified = (int) $state->get( 'verified_values' );
            $globalMapped = (int) $state->get( 'global_mapped_values' );
            $globalVerified = (int) $state->get( 'global_verified_values' );
            $shadowParityReady = 0 === (int) $state->get( 'conflicts' )
                && $verified === $mapped
                && $globalVerified === $globalMapped;
            if ( ! $shadowParityReady ) {
                $importBlockers[] = 'shadow-data-parity-incomplete';
            }

            $anchor = ( new BackupExportRepository() )->loadAnchor();
            $anchorVerified = is_array( $anchor ) && ( new BackupAnchorVerifier() )->verifyStored( $anchor );
            if ( ! $anchorVerified ) {
                $importBlockers[] = 'backup-anchor-integrity-failed';
            } elseif ( '' !== (string) $state->get( 'backup_anchor_id' ) && (string) ( $anchor['anchor_id'] ?? '' ) !== (string) $state->get( 'backup_anchor_id' ) ) {
                $anchorVerified = false;
                $importBlockers[] = 'backup-anchor-mismatch';
            }

            // In 2.1.0 semantic parity means every mapped source value is represented
            // and read back from YSEO shadow storage, the source digest did not change
            // between the authorized dry-run and import, and the private rollback anchor
            // still verifies. Public frontend parity remains observable only after cutover.
            $semanticParityReady = $sourceImmutable && $shadowParityReady && $anchorVerified;
            $cutoverReady = $semanticParityReady && $coverageReady;
        }

        $importBlockers = array_values( array_unique( $importBlockers ) );
        $shadowImportReady = $coverageReady && empty( $importBlockers );
        $status = MigrationState::MODE_IMPORT === $state->get( 'mode' )
            ? ( $cutoverReady && empty( $importBlockers ) ? MigrationState::STATUS_COMPLETE : MigrationState::STATUS_BLOCKED )
            : ( $coverageReady ? MigrationState::STATUS_COMPLETE : MigrationState::STATUS_BLOCKED );

        if ( $coverageReady && MigrationState::MODE_IMPORT === $state->get( 'mode' ) && $cutoverReady ) {
            $message = 'Verified shadow import is complete. Source immutability, shadow data parity and rollback-anchor integrity passed. Explicit takeover is now available; Yoast remains authoritative until you confirm it.';
        } elseif ( $coverageReady && MigrationState::MODE_IMPORT === $state->get( 'mode' ) ) {
            $message = 'Shadow import stopped short of takeover because one or more parity or source-integrity gates did not pass. Yoast remains authoritative.';
        } elseif ( $coverageReady && $state->get( 'backup_export_ready' ) ) {
            $message = 'Lossless source coverage and a verified private backup anchor are ready. Shadow import is authorized; public authority remains fail-closed while the competing provider is active.';
        } elseif ( $coverageReady ) {
            $message = 'Lossless source coverage is verified. Create and verify the private rollback backup before shadow import.';
        } else {
            $message = 'Dry-run finished safely with meaningful source-coverage blockers. Source SEO remains authoritative and untouched; shadow import and public authority takeover are forbidden.';
        }
        $complete = $state->merge(
            array(
                'status'                   => $status,
                'source_coverage_ready'    => $coverageReady,
                'shadow_ready'             => $coverageReady,
                'shadow_import_ready'      => $shadowImportReady,
                'readiness_blockers'       => $readiness['blockers'],
                'import_blockers'          => $importBlockers,
                'source_immutable_ready'   => $sourceImmutable,
                'shadow_data_parity_ready' => $shadowParityReady,
                'semantic_parity_ready'    => $semanticParityReady,
                'cutover_ready'            => $cutoverReady,
                'message'                  => $message,
            )
        );
        $this->repository->save( $complete );
        return $complete;
    }

    /** @param array<string,int> $incoming @return array<string,int> */
    private function mergeCounts( MigrationState $state, array $incoming ): array {
        $counts = is_array( $state->get( 'classification_counts' ) ) ? $state->get( 'classification_counts' ) : FieldClassification::zeroCounts();
        foreach ( FieldClassification::all() as $classification ) {
            $counts[ $classification ] = (int) ( $counts[ $classification ] ?? 0 ) + (int) ( $incoming[ $classification ] ?? 0 );
        }
        return $counts;
    }

    /** @param array<int,string> $current @param array<int,string> $incoming @return array<int,string> */
    private function mergeSamples( array $current, array $incoming ): array {
        foreach ( $incoming as $value ) {
            $this->rememberSample( $current, (string) $value );
        }
        return $current;
    }

    /** @param array<int,string> $samples */
    private function rememberSample( array &$samples, string $field ): void {
        if ( count( $samples ) < 100 && ! in_array( $field, $samples, true ) ) {
            $samples[] = $field;
        }
    }

    /** @return array{imported:int,verified:int,conflicts:int,details:array<int,array<string,mixed>>} */
    private function copyGlobalValues( array $values ): array {
        $existing = get_option( self::GLOBAL_TARGET_OPTION, array() );
        $existing = is_array( $existing ) ? $existing : array();
        $next = $existing;
        $imported = 0;
        $verified = 0;
        $conflicts = 0;
        $details = array();
        $changed = false;

        // Stage 0 may already have mirrored a source-consistent subset of global
        // Search settings. Import must merge the remaining verified keys instead
        // of treating that partial shadow array as one whole-option conflict.
        foreach ( $values as $key => $sourceValue ) {
            $key = (string) $key;
            if ( array_key_exists( $key, $existing ) ) {
                if ( $this->valuesEqual( $existing[ $key ], $sourceValue ) ) {
                    ++$verified;
                    continue;
                }
                ++$conflicts;
                $details[] = $this->conflictEvidence(
                    'global',
                    0,
                    'yoast-option:' . $key,
                    self::GLOBAL_TARGET_OPTION . ':' . $key,
                    'target-value-differs',
                    $sourceValue,
                    $existing[ $key ]
                );
                continue;
            }
            $next[ $key ] = $sourceValue;
            ++$imported;
            $changed = true;
        }

        if ( $changed ) {
            ksort( $next, SORT_STRING );
            update_option( self::GLOBAL_TARGET_OPTION, $next, false );
        }
        $stored = get_option( self::GLOBAL_TARGET_OPTION, array() );
        $stored = is_array( $stored ) ? $stored : array();

        foreach ( $values as $key => $sourceValue ) {
            $key = (string) $key;
            if ( array_key_exists( $key, $stored ) && $this->valuesEqual( $stored[ $key ], $sourceValue ) ) {
                if ( ! array_key_exists( $key, $existing ) ) {
                    ++$verified;
                }
                continue;
            }
            if ( ! array_key_exists( $key, $existing ) || $this->valuesEqual( $existing[ $key ], $sourceValue ) ) {
                ++$conflicts;
                $details[] = $this->conflictEvidence(
                    'global',
                    0,
                    'yoast-option:' . $key,
                    self::GLOBAL_TARGET_OPTION . ':' . $key,
                    'write-verification-failed',
                    $sourceValue,
                    $stored[ $key ] ?? ''
                );
            }
        }

        return array(
            'imported'  => $imported,
            'verified'  => $verified,
            'conflicts' => $conflicts,
            'details'   => array_slice( $details, 0, 20 ),
        );
    }

    /** @return array{imported:int,verified:int,conflicts:int,reason:string,target_value:string} */
    private function copyPostValue( int $postId, string $targetKey, string $sourceValue ): array {
        $existing = get_post_meta( $postId, $targetKey, true );
        if ( '' !== (string) $existing && (string) $existing !== $sourceValue ) {
            return array( 'imported' => 0, 'verified' => 0, 'conflicts' => 1, 'reason' => 'target-value-differs', 'target_value' => (string) $existing );
        }
        if ( (string) $existing === $sourceValue ) {
            return array( 'imported' => 0, 'verified' => 1, 'conflicts' => 0, 'reason' => '', 'target_value' => (string) $existing );
        }
        update_post_meta( $postId, $targetKey, $sourceValue );
        $stored = (string) get_post_meta( $postId, $targetKey, true );
        $verified = $stored === $sourceValue ? 1 : 0;
        return array( 'imported' => 1, 'verified' => $verified, 'conflicts' => 1 - $verified, 'reason' => $verified ? '' : 'write-verification-failed', 'target_value' => $stored );
    }

    /** @return array{imported:int,verified:int,conflicts:int,reason:string,target_value:string} */
    private function copyUserValue( int $userId, string $targetKey, string $sourceValue ): array {
        $existing = get_user_meta( $userId, $targetKey, true );
        if ( '' !== (string) $existing && (string) $existing !== $sourceValue ) {
            return array( 'imported' => 0, 'verified' => 0, 'conflicts' => 1, 'reason' => 'target-value-differs', 'target_value' => (string) $existing );
        }
        if ( (string) $existing === $sourceValue ) {
            return array( 'imported' => 0, 'verified' => 1, 'conflicts' => 0, 'reason' => '', 'target_value' => (string) $existing );
        }
        update_user_meta( $userId, $targetKey, $sourceValue );
        $stored = (string) get_user_meta( $userId, $targetKey, true );
        $verified = $stored === $sourceValue ? 1 : 0;
        return array( 'imported' => 1, 'verified' => $verified, 'conflicts' => 1 - $verified, 'reason' => $verified ? '' : 'write-verification-failed', 'target_value' => $stored );
    }

    /** @return array{imported:int,verified:int,conflicts:int,reason:string,target_value:string} */
    private function copyTermValue( int $termId, string $targetKey, string $sourceValue ): array {
        $existing = get_term_meta( $termId, $targetKey, true );
        if ( '' !== (string) $existing && (string) $existing !== $sourceValue ) {
            return array( 'imported' => 0, 'verified' => 0, 'conflicts' => 1, 'reason' => 'target-value-differs', 'target_value' => (string) $existing );
        }
        if ( (string) $existing === $sourceValue ) {
            return array( 'imported' => 0, 'verified' => 1, 'conflicts' => 0, 'reason' => '', 'target_value' => (string) $existing );
        }
        update_term_meta( $termId, $targetKey, $sourceValue );
        $stored = (string) get_term_meta( $termId, $targetKey, true );
        $verified = $stored === $sourceValue ? 1 : 0;
        return array( 'imported' => 1, 'verified' => $verified, 'conflicts' => 1 - $verified, 'reason' => $verified ? '' : 'write-verification-failed', 'target_value' => $stored );
    }

    /** @param mixed $left @param mixed $right */
    private function valuesEqual( $left, $right ): bool {
        return hash_equals( hash( 'sha256', $this->stableEncode( $left ) ), hash( 'sha256', $this->stableEncode( $right ) ) );
    }

    /** @param mixed $sourceValue @param mixed $targetValue @return array<string,mixed> */
    private function conflictEvidence( string $scope, int $objectId, string $sourceKey, string $targetKey, string $reason, $sourceValue, $targetValue ): array {
        $sourceEncoded = $this->stableEncode( $sourceValue );
        $targetEncoded = $this->stableEncode( $targetValue );
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

    /** @param array<int,array<string,mixed>> $details @param array<string,mixed> $detail */
    private function rememberConflict( array &$details, int &$truncated, array $detail ): void {
        if ( count( $details ) >= 20 ) {
            ++$truncated;
            return;
        }
        $signature = (string) ( $detail['scope'] ?? '' ) . '|' . (string) ( $detail['object_id'] ?? 0 ) . '|' . (string) ( $detail['target_key'] ?? '' ) . '|' . (string) ( $detail['reason'] ?? '' );
        foreach ( $details as $existing ) {
            $existingSignature = (string) ( $existing['scope'] ?? '' ) . '|' . (string) ( $existing['object_id'] ?? 0 ) . '|' . (string) ( $existing['target_key'] ?? '' ) . '|' . (string) ( $existing['reason'] ?? '' );
            if ( hash_equals( $existingSignature, $signature ) ) {
                return;
            }
        }
        $details[] = $detail;
    }

    private function sourceVersion(): string {
        if ( defined( 'WPSEO_VERSION' ) ) {
            return (string) WPSEO_VERSION;
        }
        $wpseo = get_option( 'wpseo', array() );
        return is_array( $wpseo ) && isset( $wpseo['version'] ) ? (string) $wpseo['version'] : '';
    }

    /** @param array<int,string> $blockers */
    private function saveBlockedStart( string $mode, string $version, string $compatibility, array $blockers ): MigrationState {
        $state = new MigrationState(
            array(
                'mode'                  => $mode,
                'provider_version'      => $version,
                'adapter_compatibility' => $compatibility,
                'status'                => MigrationState::STATUS_BLOCKED,
                'inventory_blockers'    => $blockers,
                'readiness_blockers'    => $blockers,
                'import_blockers'       => $blockers,
                'message'               => __( 'Shadow import is blocked because one or more verified safety prerequisites are missing. Source SEO remains authoritative and untouched.', 'yablonsky-seo' ),
            )
        );
        // A rejected import attempt must not destroy the completed dry-run/backup
        // workspace that the operator may need to inspect or re-verify.
        return $state;
    }

    private function isAbsoluteHttpUrl( string $value ): bool {
        return 1 === preg_match( '#^https?://[^\s]+$#i', $value );
    }

    /** @param mixed $value */
    private function stableEncode( $value ): string {
        if ( is_array( $value ) ) {
            ksort( $value, SORT_STRING );
        }
        if ( function_exists( 'wp_json_encode' ) ) {
            $json = wp_json_encode( $value );
        } else {
            $json = json_encode( $value );
        }
        return is_string( $json ) ? $json : '';
    }
}
