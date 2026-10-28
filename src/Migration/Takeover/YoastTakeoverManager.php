<?php
namespace Yablonsky\PerformanceSEO\Migration\Takeover;

use Yablonsky\PerformanceSEO\Migration\MigrationRepository;
use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YoastTakeoverManager {
    private const ROLLBACK_OPTION = 'yseo_takeover_rollback';
    private const EVENT_OPTION = 'yseo_takeover_event';

    /** @var MigrationRepository */
    private $repository;
    /** @var ProviderDetector */
    private $detector;

    public function __construct( MigrationRepository $repository, ProviderDetector $detector ) {
        $this->repository = $repository;
        $this->detector = $detector;
    }

    /** @return array{ready:bool,blockers:array<int,string>,plugin:string} */
    public function readiness( bool $fullBackupVerification = false ): array {
        return ( new TakeoverReadiness() )->evaluate( $this->repository->load(), $this->detector, $fullBackupVerification );
    }

    /** @return array<string,mixed> */
    public function cutover(): array {
        $readiness = $this->readiness( true );
        if ( ! $readiness['ready'] ) {
            return array( 'success' => false, 'blockers' => $readiness['blockers'], 'message' => __( 'Takeover remains locked because one or more safety gates are not ready.', 'yablonsky-seo' ) );
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $state = $this->repository->load();
        if ( null === $state ) {
            return array( 'success' => false, 'blockers' => array( 'migration-state-missing' ), 'message' => __( 'Migration state disappeared before takeover.', 'yablonsky-seo' ) );
        }

        $plugin = (string) $readiness['plugin'];
        $checkpoint = array(
            'schema'             => 'yseo.takeover-rollback.v1',
            'provider'           => 'yoast',
            'plugin'             => $plugin,
            'provider_version'   => (string) $state->get( 'provider_version' ),
            'previous_authority' => (string) get_option( 'yps_output_authority', 'shadow' ),
            'source_digest'      => (string) $state->get( 'source_digest' ),
            'backup_anchor_id'   => (string) $state->get( 'backup_anchor_id' ),
            'cutover_at'         => '',
            'status'             => 'awaiting_manual_deactivation',
        );
        update_option( self::ROLLBACK_OPTION, $checkpoint, false );
        update_option( 'yps_output_authority', 'shadow', false );

        update_option(
            self::EVENT_OPTION,
            array(
                'type'       => 'manual-deactivation-required',
                'created_at' => gmdate( 'c' ),
                'message'    => __( 'Verified migration is ready. WordPress.org policy requires the administrator to deactivate Yoast SEO manually.', 'yablonsky-seo' ),
            ),
            false
        );

        return array(
            'success' => true,
            'status'  => 'manual-deactivation-required',
            'plugin'  => $plugin,
            'message' => __( 'Migration is verified. Deactivate Yoast SEO manually on the Plugins screen; Yablonsky SEO will finalize public authority after detecting that administrator action.', 'yablonsky-seo' ),
        );
    }

    /** Finalizes authority only after the administrator has manually deactivated Yoast. */
    public function maybeFinalizeManualCutover(): bool {
        $checkpoint = $this->rollbackCheckpoint();
        if ( ! is_array( $checkpoint ) || 'awaiting_manual_deactivation' !== (string) ( $checkpoint['status'] ?? '' ) ) {
            return false;
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin = (string) ( $checkpoint['plugin'] ?? TakeoverReadiness::YOAST_PLUGIN );
        if ( '' !== $plugin && is_plugin_active( $plugin ) ) {
            return false;
        }

        $state = $this->repository->load();
        if ( null === $state || ! $state->get( 'cutover_ready' ) ) {
            return false;
        }

        $checkpoint['status'] = 'cutover';
        $checkpoint['cutover_at'] = gmdate( 'c' );
        update_option( self::ROLLBACK_OPTION, $checkpoint, false );
        update_option( 'yps_output_authority', 'active', false );
        update_option(
            self::EVENT_OPTION,
            array(
                'type'       => 'cutover',
                'created_at' => gmdate( 'c' ),
                'message'    => __( 'Yoast SEO was manually deactivated by the administrator. Yablonsky SEO now owns public SEO output.', 'yablonsky-seo' ),
            ),
            false
        );
        return true;
    }

    /** @return array<string,mixed> */
    public function rollback(): array {
        return array( 'success' => false, 'message' => __( 'Public takeover is final.', 'yablonsky-seo' ) );
    }

    /** @return array<string,mixed>|null */
    public function rollbackCheckpoint(): ?array {
        $value = get_option( self::ROLLBACK_OPTION, null );
        return is_array( $value ) ? $value : null;
    }

    /** @return array<string,mixed>|null */
    public function lastEvent(): ?array {
        $value = get_option( self::EVENT_OPTION, null );
        return is_array( $value ) ? $value : null;
    }
}
