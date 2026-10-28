<?php
namespace Yablonsky\PerformanceSEO\Migration\Takeover;

use Yablonsky\PerformanceSEO\Migration\Backup\BackupAnchorVerifier;
use Yablonsky\PerformanceSEO\Migration\Backup\BackupExportRepository;
use Yablonsky\PerformanceSEO\Migration\MigrationState;
use Yablonsky\PerformanceSEO\Migration\YoastMigrationService;
use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class TakeoverReadiness {
    public const YOAST_PLUGIN = 'wordpress-seo/wp-seo.php';

    /** @return array{ready:bool,blockers:array<int,string>,plugin:string} */
    public function evaluate( ?MigrationState $state, ProviderDetector $detector, bool $fullBackupVerification = false ): array {
        $blockers = array();

        if ( null === $state ) {
            $blockers[] = 'migration-state-missing';
        } else {
            if ( YoastMigrationService::CONTRACT_REVISION !== (string) $state->get( 'contract_revision' ) ) {
                $blockers[] = 'migration-contract-outdated';
            }
            if ( MigrationState::MODE_IMPORT !== $state->get( 'mode' ) || MigrationState::STATUS_COMPLETE !== $state->get( 'status' ) ) {
                $blockers[] = 'completed-shadow-import-required';
            }
            if ( ! $state->get( 'source_coverage_ready' ) ) {
                $blockers[] = 'source-coverage-not-ready';
            }
            if ( ! $state->get( 'source_immutable_ready' ) ) {
                $blockers[] = 'source-changed-since-dry-run';
            }
            if ( ! $state->get( 'shadow_data_parity_ready' ) || ! $state->get( 'semantic_parity_ready' ) ) {
                $blockers[] = 'shadow-data-parity-not-ready';
            }
            if ( (int) $state->get( 'conflicts' ) > 0 ) {
                $blockers[] = 'target-conflicts';
            }
            if ( ! $state->get( 'backup_export_ready' ) || ! $state->get( 'backup_export_verified' ) ) {
                $blockers[] = 'verified-backup-required';
            }
            if ( ! $state->get( 'cutover_ready' ) ) {
                $blockers[] = 'cutover-gate-not-ready';
            }

            $anchor = ( new BackupExportRepository() )->loadAnchor();
            if ( ! is_array( $anchor ) ) {
                $blockers[] = 'backup-anchor-missing';
            } elseif ( '' !== (string) $state->get( 'backup_anchor_id' ) && (string) ( $anchor['anchor_id'] ?? '' ) !== (string) $state->get( 'backup_anchor_id' ) ) {
                $blockers[] = 'backup-anchor-mismatch';
            } elseif ( $fullBackupVerification && ! ( new BackupAnchorVerifier() )->verifyStored( $anchor ) ) {
                $blockers[] = 'backup-anchor-integrity-failed';
            }
        }

        $providers = $detector->detectActiveProviders();
        if ( 1 !== count( $providers ) || 'yoast' !== $providers[0]->id() ) {
            $blockers[] = 'yoast-must-be-sole-active-provider';
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! is_plugin_active( self::YOAST_PLUGIN ) ) {
            $blockers[] = 'verified-yoast-plugin-basename-not-active';
        }

        return array(
            'ready'    => empty( $blockers ),
            'blockers' => array_values( array_unique( $blockers ) ),
            'plugin'   => self::YOAST_PLUGIN,
        );
    }
}
