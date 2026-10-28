<?php
namespace Yablonsky\PerformanceSEO\Admin;

use Yablonsky\PerformanceSEO\Migration\MigrationRepository;
use Yablonsky\PerformanceSEO\Migration\MigrationState;
use Yablonsky\PerformanceSEO\Migration\Provider\ProviderAdapterRegistry;
use Yablonsky\PerformanceSEO\Migration\YoastMigrationService;
use Yablonsky\PerformanceSEO\Migration\Diagnostics\MigrationConflictInspector;
use Yablonsky\PerformanceSEO\Migration\Backup\BackupExportRepository;
use Yablonsky\PerformanceSEO\Migration\Backup\BackupAnchorVerifier;
use Yablonsky\PerformanceSEO\Migration\Backup\BackupExportState;
use Yablonsky\PerformanceSEO\Migration\Backup\YoastBackupExportService;
use Yablonsky\PerformanceSEO\Migration\Takeover\YoastTakeoverManager;
use Yablonsky\PerformanceSEO\Runtime\OutputAuthorityGate;
use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;
use Yablonsky\PerformanceSEO\Visibility\EngineRegistry;
use Yablonsky\PerformanceSEO\Visibility\CrawlerRegistry;
use Yablonsky\PerformanceSEO\Visibility\ReadinessAudit;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapRepository;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPageCacheCompatibility;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapTransportProbe;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorRepository;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorService;
use Yablonsky\PerformanceSEO\Migration\Mirror\MetadataMirrorState;
use Yablonsky\PerformanceSEO\Migration\Portability\PortableMirrorRepository;
use Yablonsky\PerformanceSEO\Migration\Portability\PortableMirrorService;
use Yablonsky\PerformanceSEO\Migration\Portability\PortableMirrorState;
use Yablonsky\PerformanceSEO\Migration\Portability\PortableSourceRegistry;
use Yablonsky\PerformanceSEO\AI\Metadata\BulkMetadataRepository;
use Yablonsky\PerformanceSEO\AI\Metadata\BulkMetadataService;
use Yablonsky\PerformanceSEO\AI\Metadata\BulkMetadataState;
use Yablonsky\PerformanceSEO\AI\Metadata\BulkMetadataRunner;
use Yablonsky\PerformanceSEO\AI\Metadata\MetadataSuggestionService;
use Yablonsky\PerformanceSEO\AI\Content\ContentPlannerService;
use Yablonsky\PerformanceSEO\AI\Contract\ServiceError;
use Yablonsky\PerformanceSEO\AI\AiClientResolver;
use Yablonsky\PerformanceSEO\AI\Config\AiSettings;
use Yablonsky\PerformanceSEO\Analysis\AeoPolicy;
use Yablonsky\PerformanceSEO\Analysis\AeoReadinessAudit;
use Yablonsky\PerformanceSEO\Diagnostics\ReleaseReadinessAudit;
use Yablonsky\PerformanceSEO\Diagnostics\SeoTaskList;
use Yablonsky\PerformanceSEO\Diagnostics\SupportSnapshotBuilder;
use Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AdminController {
    // YSEO_223_SEO_TASK_LIST=PASS — deterministic priority list from local readiness evidence.
    private const PAGE = 'yablonsky-seo';
    private const LEGACY_PAGE = 'yablonsky-performance-seo';
    private const LEGACY_PLUGIN = 'yablonsky-performance-seo/yablonsky-performance-seo.php';
    private const NONCE_ACTION = 'yps_migration_action';

    /** @var ProviderDetector */
    private $detector;

    public function __construct( ProviderDetector $detector ) {
        $this->detector = $detector;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'registerMenu' ) );
        add_action( 'admin_init', array( $this, 'maybeRedirectLegacyPage' ) );
        add_action( 'admin_init', array( $this, 'maybeFinalizeManualTakeover' ) );
        add_action( 'admin_notices', array( $this, 'legacyIdentityNotice' ) );
        add_action( 'admin_post_yseo_download_support_snapshot', array( $this, 'downloadSupportSnapshot' ) );
        add_action( 'admin_post_yseo_sitemap_transport_probe', array( $this, 'probeSitemapTransport' ) );
        add_action( 'wp_ajax_yseo_sitemap_transport_probe', array( $this, 'ajaxProbeSitemapTransport' ) );
        add_action( 'admin_post_yseo_sitemap_purge_transport', array( $this, 'purgeSitemapTransport' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
        add_action( 'wp_ajax_yps_migration_start', array( $this, 'ajaxStartMigration' ) );
        add_action( 'wp_ajax_yps_migration_step', array( $this, 'ajaxStepMigration' ) );
        add_action( 'wp_ajax_yps_migration_reset', array( $this, 'ajaxResetMigration' ) );
        add_action( 'wp_ajax_yps_migration_inspect_conflicts', array( $this, 'ajaxInspectMigrationConflicts' ) );
        add_action( 'wp_ajax_yps_backup_start', array( $this, 'ajaxStartBackup' ) );
        add_action( 'wp_ajax_yps_backup_step', array( $this, 'ajaxStepBackup' ) );
        add_action( 'wp_ajax_yps_backup_reset', array( $this, 'ajaxResetBackup' ) );
        add_action( 'wp_ajax_yps_takeover', array( $this, 'ajaxTakeover' ) );
        add_action( 'wp_ajax_yseo_metadata_mirror_start', array( $this, 'ajaxStartMetadataMirror' ) );
        add_action( 'wp_ajax_yseo_metadata_mirror_step', array( $this, 'ajaxStepMetadataMirror' ) );
        add_action( 'wp_ajax_yseo_metadata_mirror_reset', array( $this, 'ajaxResetMetadataMirror' ) );
        add_action( 'wp_ajax_yseo_portable_mirror_start', array( $this, 'ajaxStartPortableMirror' ) );
        add_action( 'wp_ajax_yseo_portable_mirror_step', array( $this, 'ajaxStepPortableMirror' ) );
        add_action( 'wp_ajax_yseo_portable_mirror_reset', array( $this, 'ajaxResetPortableMirror' ) );
        add_action( 'wp_ajax_yseo_ai_metadata_start', array( $this, 'ajaxStartAiMetadata' ) );
        add_action( 'wp_ajax_yseo_ai_metadata_step', array( $this, 'ajaxStepAiMetadata' ) );
        add_action( 'wp_ajax_yseo_ai_metadata_reset', array( $this, 'ajaxResetAiMetadata' ) );
        add_action( 'wp_ajax_yseo_ai_metadata_pause', array( $this, 'ajaxPauseAiMetadata' ) );
        add_action( 'wp_ajax_yseo_ai_content_plan', array( $this, 'ajaxAiContentPlan' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( YPS_FILE ), array( $this, 'pluginActionLinks' ) );
    }

    public function maybeRedirectLegacyPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $page = filter_input( INPUT_GET, 'page', FILTER_UNSAFE_RAW );
        if ( ! is_string( $page ) || self::LEGACY_PAGE !== sanitize_key( $page ) ) {
            return;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public function legacyIdentityNotice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $legacyFile = trailingslashit( WP_PLUGIN_DIR ) . self::LEGACY_PLUGIN;
        if ( ! file_exists( $legacyFile ) ) {
            delete_option( 'yseo_technical_identity_migration' );
            return;
        }
        echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Historical Yablonsky SEO plugin detected.', 'yablonsky-seo' ) . '</strong> ';
        echo esc_html__( 'WordPress.org keeps plugin activation status and deletion under administrator control. Deactivate or delete the historical plugin manually from Plugins when you are ready; administrator action remains authoritative.', 'yablonsky-seo' );
        echo '</p></div>';
    }

    public function maybeFinalizeManualTakeover(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ( new YoastTakeoverManager( new MigrationRepository(), $this->detector ) )->maybeFinalizeManualCutover();
    }

    public function downloadSupportSnapshot(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Downloading the support snapshot requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( 'yseo_download_support_snapshot' );
        $snapshot = ( new SupportSnapshotBuilder( $this->detector ) )->build();
        nocache_headers();
        header( 'Content-Disposition: attachment; filename="yablonsky-seo-support-' . gmdate( 'Ymd-His' ) . '.json"' );
        wp_send_json( $snapshot, 200, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }


    public function probeSitemapTransport(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Testing sitemap transport requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( 'yseo_sitemap_transport_probe' );
        ( new SitemapTransportProbe() )->run();
        wp_safe_redirect( admin_url( 'admin.php?page=yablonsky-seo-sitemaps&transport=done' ) );
        exit;
    }

    public function ajaxProbeSitemapTransport(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Testing sitemap transport requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $started = microtime( true );
        $result = ( new SitemapTransportProbe() )->run();
        wp_send_json_success( array(
            'result' => $result,
            'elapsed_seconds' => round( max( 0.0, microtime( true ) - $started ), 2 ),
        ) );
    }

    public function purgeSitemapTransport(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Purging sitemap caches requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }
        check_admin_referer( 'yseo_sitemap_purge_transport' );
        ( new SitemapPageCacheCompatibility( new SitemapRepository() ) )->flushAllKnown();
        wp_safe_redirect( admin_url( 'admin.php?page=yablonsky-seo-sitemaps&purged=1' ) );
        exit;
    }

    public function registerMenu(): void {
        add_menu_page(
            esc_html__( 'Yablonsky SEO', 'yablonsky-seo' ),
            esc_html__( 'Yablonsky SEO', 'yablonsky-seo' ),
            'manage_options',
            self::PAGE,
            array( $this, 'renderPage' ),
            'dashicons-chart-area',
            58
        );

        add_submenu_page(
            self::PAGE,
            esc_html__( 'Yablonsky SEO Dashboard', 'yablonsky-seo' ),
            esc_html__( 'Dashboard', 'yablonsky-seo' ),
            'manage_options',
            self::PAGE,
            array( $this, 'renderPage' )
        );

    }

    /** @param array<int,string> $links @return array<int,string> */
    public function pluginActionLinks( array $links ): array {
        $dashboard = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
            esc_html__( 'Dashboard', 'yablonsky-seo' )
        );
        $sitemaps = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( admin_url( 'admin.php?page=yablonsky-seo-sitemaps' ) ),
            esc_html__( 'Sitemaps', 'yablonsky-seo' )
        );
        $discovery = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( admin_url( 'admin.php?page=yablonsky-seo-discovery' ) ),
            esc_html__( 'Commerce Feeds', 'yablonsky-seo' )
        );
        array_unshift( $links, $discovery );
        array_unshift( $links, $sitemaps );
        array_unshift( $links, $dashboard );
        return $links;
    }

    public function enqueueAssets( string $hook ): void {
        if ( 'toplevel_page_' . self::PAGE !== $hook ) {
            return;
        }

        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
        wp_localize_script(
            'yps-admin',
            'YPS_ADMIN',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                'labels'  => array(
                    'failed'    => esc_html__( 'The operation stopped safely. Public SEO output remains unchanged.', 'yablonsky-seo' ),
                    'empty'     => esc_html__( 'Migration job ready to start.', 'yablonsky-seo' ),
                    'mode'      => esc_html__( 'Mode', 'yablonsky-seo' ),
                    'status'    => esc_html__( 'Status', 'yablonsky-seo' ),
                    'phase'     => esc_html__( 'Phase', 'yablonsky-seo' ),
                    'posts'     => esc_html__( 'Posts scanned', 'yablonsky-seo' ),
                    'terms'     => esc_html__( 'Terms scanned', 'yablonsky-seo' ),
                    'users'     => esc_html__( 'Author/user meta scanned', 'yablonsky-seo' ),
                    'mapped'    => esc_html__( 'Mapped values', 'yablonsky-seo' ),
                    'global'    => esc_html__( 'Global settings mapped', 'yablonsky-seo' ),
                    'unsupported' => esc_html__( 'Unsupported values', 'yablonsky-seo' ),
                    'ignored'   => esc_html__( 'Ignored derived values', 'yablonsky-seo' ),
                    'imported'  => esc_html__( 'Imported values', 'yablonsky-seo' ),
                    'verified'  => esc_html__( 'Verified values', 'yablonsky-seo' ),
                    'conflicts' => esc_html__( 'Conflicts', 'yablonsky-seo' ),
                    'batch'     => esc_html__( 'Adaptive batch', 'yablonsky-seo' ),
                    'stepMs'    => esc_html__( 'Last step ms', 'yablonsky-seo' ),
                    'queries'   => esc_html__( 'Last step queries', 'yablonsky-seo' ),
                    'memory'    => esc_html__( 'Last step memory delta', 'yablonsky-seo' ),
                    'pressure'  => esc_html__( 'Origin pressure', 'yablonsky-seo' ),
                    'delay'     => esc_html__( 'Inter-step delay ms', 'yablonsky-seo' ),
                    'backupStatus' => esc_html__( 'Backup status', 'yablonsky-seo' ),
                    'backupPhase' => esc_html__( 'Backup phase', 'yablonsky-seo' ),
                    'backupRecords' => esc_html__( 'Exported records', 'yablonsky-seo' ),
                    'backupVerified' => esc_html__( 'Verified records', 'yablonsky-seo' ),
                    'backupEmpty' => esc_html__( 'Migration backup/export is ready to start for the current evidence set.', 'yablonsky-seo' ),
                    'invalidResponse' => esc_html__( 'Invalid server response.', 'yablonsky-seo' ),
                    'confirmTakeover' => esc_html__( 'Prepare the verified handoff? WordPress.org requires you to deactivate Yoast SEO manually on the Plugins screen.', 'yablonsky-seo' ),
                    'confirmRollback' => esc_html__( 'Reactivate Yoast SEO and return Yablonsky SEO to Shadow mode?', 'yablonsky-seo' ),
                    'mirrorEmpty' => esc_html__( 'Metadata mirror job ready to start.', 'yablonsky-seo' ),
                    'mirrorStatus' => esc_html__( 'Mirror status', 'yablonsky-seo' ),
                    'mirrorPhase' => esc_html__( 'Mirror phase', 'yablonsky-seo' ),
                    'mirrorPosts' => esc_html__( 'Content objects scanned', 'yablonsky-seo' ),
                    'mirrorCopied' => esc_html__( 'Exact values copied', 'yablonsky-seo' ),
                    'mirrorMatched' => esc_html__( 'Already matched', 'yablonsky-seo' ),
                    'mirrorPreserved' => esc_html__( 'Existing YSEO values preserved', 'yablonsky-seo' ),
                    'migrationRunning' => esc_html__( 'Migration is running', 'yablonsky-seo' ),
                    'migrationComplete' => esc_html__( 'Migration step completed', 'yablonsky-seo' ),
                    'migrationStopped' => esc_html__( 'Migration stopped safely', 'yablonsky-seo' ),
                    'backupRunning' => esc_html__( 'Rollback backup is running', 'yablonsky-seo' ),
                    'backupComplete' => esc_html__( 'Rollback backup verified', 'yablonsky-seo' ),
                    'mirrorRunning' => esc_html__( 'Metadata mirror is running', 'yablonsky-seo' ),
                    'mirrorComplete' => esc_html__( 'Metadata mirror completed', 'yablonsky-seo' ),
                    'phaseInventory' => esc_html__( 'Inspecting source', 'yablonsky-seo' ),
                    'phaseGlobals' => esc_html__( 'Processing global settings', 'yablonsky-seo' ),
                    'phasePosts' => esc_html__( 'Processing content metadata', 'yablonsky-seo' ),
                    'phaseUsers' => esc_html__( 'Processing author metadata', 'yablonsky-seo' ),
                    'phaseTaxonomy' => esc_html__( 'Processing taxonomy metadata', 'yablonsky-seo' ),
                    'phaseFinalize' => esc_html__( 'Verifying safety gates', 'yablonsky-seo' ),
                    'phaseComplete' => esc_html__( 'Complete', 'yablonsky-seo' ),
                    'progressWaiting' => esc_html__( 'Waiting to start', 'yablonsky-seo' ),
                    'progressReview' => esc_html__( 'Review required', 'yablonsky-seo' ),
                    'progressVerified' => esc_html__( 'Verified', 'yablonsky-seo' ),
                    'progressSafe' => esc_html__( 'Public SEO authority remains unchanged.', 'yablonsky-seo' ),
                    'inspectConflict' => esc_html__( 'Review migration conflict', 'yablonsky-seo' ),
                    'conflictClear' => esc_html__( 'Migration state is clear of recorded conflicts.', 'yablonsky-seo' ),
                    'running' => esc_html__( 'Running', 'yablonsky-seo' ),
                    'badgeRunning' => esc_html__( 'RUNNING', 'yablonsky-seo' ),
                    'badgeStopped' => esc_html__( 'STOPPED', 'yablonsky-seo' ),
                    'badgeVerified' => esc_html__( 'VERIFIED', 'yablonsky-seo' ),
                    'badgeFailed' => esc_html__( 'FAILED', 'yablonsky-seo' ),
                    'badgeLive' => esc_html__( 'LIVE', 'yablonsky-seo' ),
                    'badgeReady' => esc_html__( 'READY', 'yablonsky-seo' ),
                    'badgeActionRequired' => esc_html__( 'ACTION REQUIRED', 'yablonsky-seo' ),
                    'manualYoastTitle' => esc_html__( 'Manual Yoast deactivation required', 'yablonsky-seo' ),
                    'manualYoastDetail' => esc_html__( 'Deactivate Yoast SEO manually in Plugins, then return here.', 'yablonsky-seo' ),
                    'pluginsInstalled' => esc_html__( 'Plugins → Installed Plugins', 'yablonsky-seo' ),
                    'elapsed' => esc_html__( 'Elapsed', 'yablonsky-seo' ),
                    'available' => esc_html__( 'Available', 'yablonsky-seo' ),
                    'required' => esc_html__( 'Required', 'yablonsky-seo' ),
                    'stopped' => esc_html__( 'Stopped', 'yablonsky-seo' ),
                    'locked' => esc_html__( 'Locked', 'yablonsky-seo' ),
                    'safePreparationStarted' => esc_html__( 'Safe preparation started', 'yablonsky-seo' ),
                    'safePreparationDetail' => esc_html__( 'YSEO will verify the source, create a rollback backup and import to shadow storage. Public SEO remains with Yoast.', 'yablonsky-seo' ),
                    'safeStepDryRun' => esc_html__( 'Step 1 of 3 · fresh dry-run', 'yablonsky-seo' ),
                    'safeStepBackup' => esc_html__( 'Step 2 of 3 · rollback backup', 'yablonsky-seo' ),
                    'safeStepShadow' => esc_html__( 'Step 3 of 3 · shadow import', 'yablonsky-seo' ),
                    'sourcePassedBackupDetail' => esc_html__( 'Source coverage passed. Creating and independently verifying the rollback anchor now.', 'yablonsky-seo' ),
                    'shadowRunning' => esc_html__( 'Shadow import is running', 'yablonsky-seo' ),
                    'shadowRunningDetail' => esc_html__( 'Rollback protection is verified. Importing source values into YSEO shadow storage now. Yoast remains live.', 'yablonsky-seo' ),
                    'safePreparationComplete' => esc_html__( 'Safe preparation completed', 'yablonsky-seo' ),
                    'safePreparationCompleteDetail' => esc_html__( 'Shadow data parity passed. Yoast is still live; public takeover remains a separate explicit action.', 'yablonsky-seo' ),
                    'safePreparationCompleteMeta' => esc_html__( 'Dry-run ✓ · backup ✓ · shadow parity ✓', 'yablonsky-seo' ),
                    'dryRunExpired' => esc_html__( 'Dry-run verification expired. Run a fresh dry-run or use the one-click safe preparation.', 'yablonsky-seo' ),
                    'dryRunValid' => esc_html__( 'Dry-run proof valid for', 'yablonsky-seo' ),
                    'publicTakeoverRunning' => esc_html__( 'Public takeover is being applied', 'yablonsky-seo' ),
                    'publicTakeoverRunningDetail' => esc_html__( 'YSEO is changing the public authority only after verified shadow parity.', 'yablonsky-seo' ),
                    'publicTakeoverComplete' => esc_html__( 'Public takeover completed', 'yablonsky-seo' ),
                    'publicTakeoverCompleteDetail' => esc_html__( 'Yablonsky SEO now owns public SEO authority. Reloading the admin state once.', 'yablonsky-seo' ),
                    'verifiedCutoverComplete' => esc_html__( 'Verified cutover complete', 'yablonsky-seo' ),
                    'resetMigrationConfirm' => esc_html__( 'Reset the migration workspace? The verified private rollback backup is kept unless you explicitly replace it.', 'yablonsky-seo' ),
                    'readySafeDryRun' => esc_html__( 'Ready for a safe dry-run', 'yablonsky-seo' ),
                    'currentVerifySource' => esc_html__( 'Current step: verify source coverage.', 'yablonsky-seo' ),
                    'legacyMergeTitle' => esc_html__( '2.70.0 partial-global merge regression detected', 'yablonsky-seo' ),
                    'migrationConflictTitle' => esc_html__( 'Migration conflict requires review', 'yablonsky-seo' ),
                    'repairable' => esc_html__( 'REPAIRABLE', 'yablonsky-seo' ),
                    'conflictBadge' => esc_html__( 'CONFLICT', 'yablonsky-seo' ),
                    'legacyMergeNote' => esc_html__( 'This is the 2.70.0 whole-option comparison bug. 2.70.1 merges source-consistent global keys individually. Use Prepare verified shadow import to rerun the safe proof chain.', 'yablonsky-seo' ),
                    'scope' => esc_html__( 'Scope', 'yablonsky-seo' ),
                    'object' => esc_html__( 'Object', 'yablonsky-seo' ),
                    'sourceField' => esc_html__( 'Source field', 'yablonsky-seo' ),
                    'yseoField' => esc_html__( 'YSEO field', 'yablonsky-seo' ),
                    'reason' => esc_html__( 'Reason', 'yablonsky-seo' ),
                    'portableProvider' => esc_html__( 'Source provider', 'yablonsky-seo' ),
                    'portableEmpty' => esc_html__( 'Preserve-only portability job ready to start.', 'yablonsky-seo' ),
                    'aiJobEmpty' => esc_html__( 'Site-wide AI metadata job ready to start.', 'yablonsky-seo' ),
                    'selectAiField' => esc_html__( 'Select at least one metadata field.', 'yablonsky-seo' ),
                    'aiJobStatus' => esc_html__( 'AI job status', 'yablonsky-seo' ),
                    'aiJobPosts' => esc_html__( 'Posts scanned', 'yablonsky-seo' ),
                    'aiJobGenerated' => esc_html__( 'Posts generated', 'yablonsky-seo' ),
                    'aiJobUpdated' => esc_html__( 'Fields applied', 'yablonsky-seo' ),
                    'aiJobDrafted' => esc_html__( 'Review drafts staged', 'yablonsky-seo' ),
                    'aiJobPreserved' => esc_html__( 'Fields preserved', 'yablonsky-seo' ),
                    'aiJobMirrored' => esc_html__( 'Yoast source values mirrored first', 'yablonsky-seo' ),
                    'confirmAiReplace' => esc_html__( 'Generate site-wide AI values for existing YSEO Search metadata? Yoast source data remains unchanged; active YSEO output may change.', 'yablonsky-seo' ),
                    'contentPlanning' => esc_html__( 'Building a bounded content plan…', 'yablonsky-seo' ),
                    'contentPlanReady' => esc_html__( 'Content plan ready for human review. Publishing remains editor-controlled.', 'yablonsky-seo' ),
                ),
            )
        );
    }

    public function renderPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accessing this page requires the corresponding WordPress capability.', 'yablonsky-seo' ) );
        }

        $providers       = $this->detector->detectActiveProviders();
        $gate            = new OutputAuthorityGate( $providers );
        $repository      = new MigrationRepository();
        $state           = $repository->load();
        $backupRepository = new BackupExportRepository();
        $backupState      = $backupRepository->load();
        $mirrorRepository = new MetadataMirrorRepository();
        $mirrorState = $mirrorRepository->load();
        $portableMirrorRepository = new PortableMirrorRepository();
        $portableMirrorState = $portableMirrorRepository->load();
        $aiMetadataRepository = new BulkMetadataRepository();
        $aiMetadataState = $aiMetadataRepository->load();
        $aiConfigured = ( new MetadataSuggestionService() )->isConfigured();
        $aiSettings = new AiSettings();
        $aiClient = ( new AiClientResolver() )->resolve();
        $aiUsage = $aiClient->usage()->data();
        $aiModels = $aiClient->models();
        if ( $aiConfigured && $aiSettings->lastVerifiedAt() > 0 ) {
            $aiWorkspaceName = (string) ( $aiUsage['workspace_name'] ?? __( 'Workspace', 'yablonsky-seo' ) );
            $aiPlanTitle = (string) ( $aiUsage['plan_title'] ?? __( 'Plan', 'yablonsky-seo' ) );
            /* translators: 1: workspace name, 2: plan title, 3: selectable AI model count. */
            $aiComputeTemplate = __( '%1$s · %2$s · %3$d selectable models. This dashboard reads cached account evidence only; remote compute runs only for explicit AI actions.', 'yablonsky-seo' );
            $aiComputeSummary = sprintf(
                $aiComputeTemplate,
                $aiWorkspaceName,
                $aiPlanTitle,
                max( 0, count( $aiModels ) - 1 )
            );
        } else {
            $aiComputeSummary = __( 'Connect your Yablonsky AI User API key in SEO Settings. Remote compute runs only on explicit AI actions or configured bounded jobs.', 'yablonsky-seo' );
        }
        $yoastActive = $this->detector->hasProvider( 'yoast' );
        $takeoverManager  = new YoastTakeoverManager( $repository, $this->detector );
        $takeover         = $takeoverManager->readiness();
        $rollbackCheckpoint = $takeoverManager->rollbackCheckpoint();
        $takeoverComplete = is_array( $rollbackCheckpoint ) && 'cutover' === (string) ( $rollbackCheckpoint['status'] ?? '' ) && empty( $providers );
        $adapterRegistry = new ProviderAdapterRegistry();
        $portableProfiles = $this->portableActiveProfiles( $providers );
        $settings        = get_option( 'yps_public_settings', array() );
        $settings        = is_array( $settings ) ? $settings : array();
        $aeoPolicy       = AeoPolicy::fromSettings( $settings );
        $aeoAudit        = ( new AeoReadinessAudit() )->report();
        $engineCount     = $this->engineCount();
        $postTypes       = $this->publicContentTypes();
        $authorityLabel  = ! empty( $providers ) ? $this->providerLabel( $providers ) : ( $gate->canEmitPublicSeo() ? __( 'Yablonsky SEO', 'yablonsky-seo' ) : __( 'Manual shadow', 'yablonsky-seo' ) );
        $outputLabel     = $gate->canEmitPublicSeo() ? __( 'Active', 'yablonsky-seo' ) : __( 'Safely blocked', 'yablonsky-seo' );
        $setup           = $this->setupReadiness( $settings );
        $migration       = $this->migrationReadiness( $providers, $adapterRegistry, $state );
        $sitemapRepository = new SitemapRepository();
        $sitemapDiagnostics = $sitemapRepository->diagnostics();
        $sitemapTransportProbe = new SitemapTransportProbe();
        $sitemapTransport = $sitemapTransportProbe->lastResult();
        $sitemapTransportHistory = $sitemapTransportProbe->history();
        $internalLinkDiagnostics = ( new InternalLinkRepository() )->diagnostics();
        $releaseReadiness = ( new ReleaseReadinessAudit() )->run( $providers, $gate, $sitemapDiagnostics );
        $localeCoverageForTasks = \Yablonsky\PerformanceSEO\Localization\ProductLocaleRegistry::coverage();
        $seoTasks = ( new SeoTaskList() )->build( $providers, $settings, $sitemapDiagnostics, $internalLinkDiagnostics, $aeoAudit, $localeCoverageForTasks );
        $supportSnapshotUrl = wp_nonce_url( admin_url( 'admin-post.php?action=yseo_download_support_snapshot' ), 'yseo_download_support_snapshot' );
        $conflictReview = null;
        if ( null !== $state && (int) $state->get( 'conflicts' ) > 0 ) {
            $conflictReview = ( new MigrationConflictInspector() )->inspect( $state );
        }
        $migrationConsole = $this->migrationConsoleView( $state, $backupState );
        if ( $takeoverComplete ) {
            $migrationConsole = array(
                'tone' => 'complete',
                'badge' => __( 'LIVE', 'yablonsky-seo' ),
                'title' => __( 'Takeover complete', 'yablonsky-seo' ),
                'detail' => __( 'Yablonsky SEO owns public authority', 'yablonsky-seo' ),
                'meta' => __( 'Verified cutover complete', 'yablonsky-seo' ),
                'indeterminate' => false,
                'percent' => 100,
            );
        }
        $stageSourceClass = $this->isCurrentMigrationState( $state ) && $state->get( 'source_coverage_ready' ) ? 'is-complete' : ( $this->isCurrentMigrationState( $state ) && MigrationState::STATUS_RUNNING === $state->get( 'status' ) ? 'is-active' : 'is-pending' );
        $stageBackupClass = $this->isCurrentMigrationState( $state ) && $state->get( 'backup_export_verified' ) ? 'is-complete' : ( null !== $backupState && BackupExportState::STATUS_RUNNING === $backupState->get( 'status' ) ? 'is-active' : 'is-pending' );
        $stageShadowClass = $this->isCurrentMigrationState( $state ) && $state->get( 'semantic_parity_ready' ) ? 'is-complete' : ( $this->isCurrentMigrationState( $state ) && MigrationState::MODE_IMPORT === $state->get( 'mode' ) && MigrationState::STATUS_BLOCKED === $state->get( 'status' ) ? 'is-stopped' : ( $this->isCurrentMigrationState( $state ) && MigrationState::MODE_IMPORT === $state->get( 'mode' ) && MigrationState::STATUS_RUNNING === $state->get( 'status' ) ? 'is-active' : 'is-pending' ) );
        $stageTakeoverClass = $takeoverComplete ? 'is-complete' : ( ! empty( $takeover['ready'] ) ? 'is-active' : 'is-locked' );
        $crawlerCount = 0;
        $crawlerRegistryVersion = '';
        try {
            $crawlerRegistry = CrawlerRegistry::fromJsonFile( YPS_DIR . 'data/ai-crawlers.json' );
            $crawlerCount = $crawlerRegistry->count();
            $crawlerRegistryVersion = $crawlerRegistry->version();
        } catch ( \Throwable $exception ) {
            unset( $exception );
        }
        $aiReadiness = ( new ReadinessAudit() )->run( $settings, $gate->canEmitPublicSeo(), $sitemapDiagnostics );
        /* translators: %d: number of AI engines in the local visibility registry. */
        $engineCountLabel = sprintf( _n( '%d engine', '%d engines', $engineCount, 'yablonsky-seo' ), $engineCount );
        /* translators: %d: number of public WordPress content types supported by the SEO editor. */
        $postTypeCountLabel = sprintf( _n( '%d public type', '%d public types', count( $postTypes ), 'yablonsky-seo' ), count( $postTypes ) );
        /* translators: %d: number of AI engines in the local visibility registry. */
        $knownEngineCountLabel = sprintf( _n( '%d known AI engine', '%d known AI engines', $engineCount, 'yablonsky-seo' ), $engineCount );
        /* translators: %d: number of verified crawler/control tokens in the local registry. */
        $crawlerCountLabel = sprintf( _n( '%d verified crawler', '%d verified crawlers', $crawlerCount, 'yablonsky-seo' ), $crawlerCount );
        ?>
        <div class="wrap yps-wrap yps-product-shell">
            <header class="yps-product-header">
                <div class="yps-product-title">
                    <h1><?php echo esc_html__( 'Yablonsky SEO', 'yablonsky-seo' ); ?></h1>
                    <p><?php echo esc_html__( 'Three clear visibility layers: SEO for search, AEO for answers, GEO for AI understanding — with shared authority and operations underneath.', 'yablonsky-seo' ); ?></p>
                </div>
                <div class="yps-product-actions">
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-settings' ) ); ?>"><?php echo esc_html__( 'SEO Settings', 'yablonsky-seo' ); ?></a>
                    <span class="yps-pill">v<?php echo esc_html( YPS_VERSION ); ?></span>
                    <span class="yps-pill yps-pill-success"><?php echo esc_html__( 'Stable', 'yablonsky-seo' ); ?></span>
                    <span class="yps-pill yps-pill-free"><?php echo esc_html__( 'Local features · Free', 'yablonsky-seo' ); ?></span>
                </div>
            </header>

            <nav class="yps-layer-nav" aria-label="<?php echo esc_attr__( 'Visibility layers', 'yablonsky-seo' ); ?>">
                <button type="button" class="yps-layer-nav-item is-active" data-yps-layer="overview" aria-selected="true"><span class="dashicons dashicons-dashboard"></span><b><?php echo esc_html__( 'Overview', 'yablonsky-seo' ); ?></b></button>
                <button type="button" class="yps-layer-nav-item is-seo" data-yps-layer="seo" aria-selected="false"><span class="yps-layer-code">SEO</span><b><?php echo esc_html__( 'Search', 'yablonsky-seo' ); ?></b></button>
                <button type="button" class="yps-layer-nav-item is-aeo" data-yps-layer="aeo" aria-selected="false"><span class="yps-layer-code">AEO</span><b><?php echo esc_html__( 'Answers', 'yablonsky-seo' ); ?></b></button>
                <button type="button" class="yps-layer-nav-item is-geo" data-yps-layer="geo" aria-selected="false"><span class="yps-layer-code">GEO</span><b><?php echo esc_html__( 'AI visibility', 'yablonsky-seo' ); ?></b></button>
                <button type="button" class="yps-layer-nav-item" data-yps-layer="system" aria-selected="false"><span class="dashicons dashicons-admin-tools"></span><b><?php echo esc_html__( 'Operations', 'yablonsky-seo' ); ?></b></button>
            </nav>
            <nav class="yps-tabs yps-context-tabs" aria-label="<?php echo esc_attr__( 'Layer tools', 'yablonsky-seo' ); ?>">
                <?php
                $tabs = array(
                    'search'      => array( 'label' => __( 'Search appearance', 'yablonsky-seo' ), 'parent' => 'seo' ),
                    'content'     => array( 'label' => __( 'Content & metadata', 'yablonsky-seo' ), 'parent' => 'seo' ),
                    'redirects'   => array( 'label' => __( 'Redirects', 'yablonsky-seo' ), 'parent' => 'seo' ),
                    'links'       => array( 'label' => __( 'Internal links', 'yablonsky-seo' ), 'parent' => 'seo' ),
                    'answer'      => array( 'label' => __( 'Answer workspace', 'yablonsky-seo' ), 'parent' => 'aeo' ),
                    'schema'      => array( 'label' => __( 'Entities & schema', 'yablonsky-seo' ), 'parent' => 'geo' ),
                    'ai'          => array( 'label' => __( 'Agent visibility', 'yablonsky-seo' ), 'parent' => 'geo' ),
                    'migration'   => array( 'label' => __( 'Migration', 'yablonsky-seo' ), 'parent' => 'system' ),
                    'diagnostics' => array( 'label' => __( 'Diagnostics', 'yablonsky-seo' ), 'parent' => 'system' ),
                );
                foreach ( $tabs as $key => $definition ) {
                    printf(
                        '<button type="button" class="yps-tab" data-yps-tab="%1$s" data-yps-parent="%2$s" aria-controls="yps-panel-%1$s" aria-selected="false">%3$s</button>',
                        esc_attr( $key ),
                        esc_attr( (string) $definition['parent'] ),
                        esc_html( (string) $definition['label'] )
                    );
                }
                ?>
            </nav>

            <main class="yps-workspace">
                <section class="yps-panel" id="yps-panel-overview" data-yps-panel="overview">
                    <div class="yps-pillar-grid" aria-label="<?php echo esc_attr__( 'SEO AEO GEO visibility model', 'yablonsky-seo' ); ?>">
                        <article class="yps-pillar-card is-seo">
                            <div class="yps-pillar-head"><span class="yps-pillar-badge">SEO</span><span><?php echo esc_html__( 'Search Engine Optimization', 'yablonsky-seo' ); ?></span></div>
                            <h2><?php echo esc_html__( 'Be discovered and presented correctly', 'yablonsky-seo' ); ?></h2>
                            <p><?php echo esc_html__( 'Titles, descriptions, canonical URLs, robots, social/search images, redirects and sitemaps. This is the classic search layer users already know.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-pillar-footer"><span class="yps-mini-status <?php echo esc_attr( $gate->canEmitPublicSeo() ? 'good' : 'neutral' ); ?>"><?php echo esc_html( $gate->canEmitPublicSeo() ? __( 'PUBLIC', 'yablonsky-seo' ) : __( 'SHADOW', 'yablonsky-seo' ) ); ?></span><button type="button" class="button yps-jump-tab" data-yps-target="search"><?php echo esc_html__( 'Open SEO', 'yablonsky-seo' ); ?></button></div>
                        </article>
                        <article class="yps-pillar-card is-aeo">
                            <div class="yps-pillar-head"><span class="yps-pillar-badge">AEO</span><span><?php echo esc_html__( 'Answer Engine Optimization', 'yablonsky-seo' ); ?></span></div>
                            <h2><?php echo esc_html__( 'Make answers easy to extract', 'yablonsky-seo' ); ?></h2>
                            <p><?php echo esc_html__( 'Opening clarity, answerable sections, question structure, concise paragraphs, lists and tables. AEO works primarily inside each piece of content.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-pillar-footer"><span class="yps-mini-status good"><?php echo esc_html__( 'LOCAL ANALYSIS', 'yablonsky-seo' ); ?></span><button type="button" class="button yps-jump-tab" data-yps-target="answer"><?php echo esc_html__( 'Open AEO', 'yablonsky-seo' ); ?></button></div>
                        </article>
                        <article class="yps-pillar-card is-geo">
                            <div class="yps-pillar-head"><span class="yps-pillar-badge">GEO</span><span><?php echo esc_html__( 'Generative Engine Optimization', 'yablonsky-seo' ); ?></span></div>
                            <h2><?php echo esc_html__( 'Help AI systems understand and retrieve', 'yablonsky-seo' ); ?></h2>
                            <p><?php echo esc_html__( 'Entities, evidence, schema, crawler policy, preferred images, root/scoped llms.txt orientation, clean Markdown alternates and sitemap discovery for agent retrieval.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-pillar-footer"><span class="yps-mini-status <?php echo esc_attr( ! empty( $settings['agent_markdown_enabled'] ) ? 'good' : 'neutral' ); ?>"><?php echo esc_html( ! empty( $settings['agent_markdown_enabled'] ) ? __( 'AGENT WEB READY', 'yablonsky-seo' ) : __( 'OPTIONAL', 'yablonsky-seo' ) ); ?></span><button type="button" class="button yps-jump-tab" data-yps-target="ai"><?php echo esc_html__( 'Open GEO', 'yablonsky-seo' ); ?></button></div>
                        </article>
                    </div>
                    <div class="yps-foundation-strip"><div><span class="dashicons dashicons-shield"></span><strong><?php echo esc_html__( 'Shared foundation', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Authority, migration, diagnostics and safe coexistence support all three layers as shared infrastructure.', 'yablonsky-seo' ); ?></span></div><button type="button" class="button yps-jump-tab" data-yps-target="migration"><?php echo esc_html__( 'Open operations', 'yablonsky-seo' ); ?></button></div>
                    <div class="yps-kpi-grid">
                        <?php $this->renderKpi( __( 'SEO authority', 'yablonsky-seo' ), $authorityLabel, ! empty( $providers ) ? __( 'Safe coexistence', 'yablonsky-seo' ) : __( 'Public owner', 'yablonsky-seo' ), 'authority' ); ?>
                        <?php $this->renderKpi( __( 'Search output', 'yablonsky-seo' ), $outputLabel, $gate->canEmitPublicSeo() ? __( 'Frontend contracts registered', 'yablonsky-seo' ) : __( 'Single canonical tag set', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ? 'good' : 'neutral' ); ?>
                        <?php $this->renderKpi( __( 'Setup readiness', 'yablonsky-seo' ), $setup['label'], $setup['detail'], $setup['tone'] ); ?>
                        <?php $this->renderKpi( __( 'AI visibility registry', 'yablonsky-seo' ), $engineCountLabel, __( 'Local registry · local-only', 'yablonsky-seo' ), 'good' ); ?>
                    </div>

                    <div class="yps-overview-grid">
                        <article class="yps-card yps-card-wide yseo-task-list-card">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'SEO Task List', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Highest-value next actions', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status neutral"><?php echo esc_html( (string) count( $seoTasks ) ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'Prioritized from local YSEO evidence only. Remote ranking, Search Console and citation data stay outside this inference.', 'yablonsky-seo' ); ?></p>
                            <div class="yseo-task-list">
                                <?php foreach ( $seoTasks as $task ) : ?>
                                    <div class="yseo-task-row is-<?php echo esc_attr( (string) $task['tone'] ); ?>"><span class="yseo-task-priority"><?php echo esc_html( (string) $task['priority'] ); ?></span><div><strong><?php echo esc_html( (string) $task['label'] ); ?></strong><span><?php echo esc_html( (string) $task['detail'] ); ?></span></div><a class="button" href="<?php echo esc_url( (string) $task['url'] ); ?>"><?php echo esc_html( (string) $task['action'] ); ?></a></div>
                                <?php endforeach; ?>
                            </div>
                        </article>

                        <article class="yps-card yps-card-focus">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'What needs attention', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html( $this->attentionHeadline( $providers, $setup, $state ) ); ?></h2></div></div>
                            <?php $this->renderAttentionList( $providers, $setup, $state ); ?>
                        </article>

                        <article class="yps-card">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Current search stack', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html( $authorityLabel ); ?></h2></div><span class="yps-state-dot <?php echo esc_attr( $gate->canEmitPublicSeo() ? 'is-live' : 'is-shadow' ); ?>"></span></div>
                            <div class="yps-stack-list">
                                <?php $this->renderStackRow( __( 'Titles & descriptions', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ); ?>
                                <?php $this->renderStackRow( __( 'Canonical & robots', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ); ?>
                                <?php $this->renderStackRow( __( 'Open Graph & X', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ); ?>
                                <?php $this->renderStackRow( __( 'JSON-LD schema', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ); ?>
                                <?php $this->renderStackRow( __( 'Sitemap authority and fallback', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ); ?>
                            </div>
                        </article>

                        <article class="yps-card">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Content surfaces', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html( $postTypeCountLabel ); ?></h2></div></div>
                            <p class="yps-muted"><?php echo esc_html__( 'The Yablonsky SEO editor is attached to every public post type except attachments. Taxonomies and author archives have their own SEO fields.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-chip-row">
                                <?php foreach ( array_slice( $postTypes, 0, 6 ) as $type ) : ?><span class="yps-chip"><?php echo esc_html( $type ); ?></span><?php endforeach; ?>
                            </div>
                        </article>

                        <article class="yps-card">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Migration readiness', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html( $migration['label'] ); ?></h2></div><span class="yps-mini-status <?php echo esc_attr( $migration['tone'] ); ?>"><?php echo esc_html( $migration['badge'] ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html( $migration['detail'] ); ?></p>
                            <?php if ( $this->detector->hasProvider( 'yoast' ) ) : ?>
                                <div class="yps-chip-row yps-migration-summary-chips"><span class="yps-chip"><?php echo esc_html( $this->providerLabel( $providers ) ); ?></span><span class="yps-chip"><?php echo esc_html( $this->canPrepareYoastWorkflow( $providers, $adapterRegistry ) ? __( 'VERIFIED ADAPTER', 'yablonsky-seo' ) : __( 'AUDIT ONLY', 'yablonsky-seo' ) ); ?></span></div>
                            <?php endif; ?>
                            <button type="button" class="button yps-jump-tab" data-yps-target="migration"><?php echo esc_html__( 'Open Migration', 'yablonsky-seo' ); ?></button>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-search" data-yps-panel="search" hidden>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Search appearance', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'How your site presents itself in search', 'yablonsky-seo' ); ?></h2></div><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-settings' ) ); ?>"><?php echo esc_html__( 'Edit settings', 'yablonsky-seo' ); ?></a></div>
                            <div class="yps-search-preview">
                                <div class="yps-preview-domain"><?php echo esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?></div>
                                <div class="yps-preview-title"><?php echo esc_html( $this->previewTitle( $settings ) ); ?></div>
                                <div class="yps-preview-url"><?php echo esc_html( home_url( '/' ) ); ?></div>
                                <div class="yps-preview-description"><?php echo esc_html( $this->previewDescription( $settings ) ); ?></div>
                            </div>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Indexability', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Robots & canonical', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Resource-level canonical URLs, noindex, nofollow and advanced robots directives are supported.', 'yablonsky-seo' ); ?></p>
                            <span class="yps-mini-status good"><?php echo esc_html__( 'Implemented', 'yablonsky-seo' ); ?></span>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Social discovery', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Open Graph & X', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Dedicated titles, descriptions and image URLs are available per resource with safe fallbacks.', 'yablonsky-seo' ); ?></p>
                            <span class="yps-mini-status good"><?php echo esc_html__( 'Implemented', 'yablonsky-seo' ); ?></span>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-content" data-yps-panel="content" hidden>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Content SEO', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Editors are attached where content is created', 'yablonsky-seo' ); ?></h2></div></div>
                            <p class="yps-muted"><?php echo esc_html__( 'Posts, pages and products get a familiar Search Preview plus Search, Answer, AI, Social, Schema and Advanced workspaces. Search/AEO/GEO readiness uses local red/amber/green checks with explicit reasons; taxonomies and author archives retain dedicated SEO controls.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-capability-grid">
                                <?php foreach ( $postTypes as $type ) : ?><div class="yps-capability"><strong><?php echo esc_html( $type ); ?></strong><span><?php echo esc_html__( 'SEO editor active', 'yablonsky-seo' ); ?></span></div><?php endforeach; ?>
                            </div>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Optimization model', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Familiar workflow · broader guidance', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'The editor keeps the familiar keyphrase, title, description, preview and traffic-light pattern while separating Search, Answer and AI readiness. Scores are derived locally as readiness guidance.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Storage model', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Shared WordPress content database', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Authored SEO values stay with WordPress post, term and user metadata. Optimization scores are derived from current content rather than stored as authoritative ranking facts.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'AI metadata operations', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Multilingual bulk work lives in one canonical workspace', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status <?php echo esc_attr( $aiConfigured ? 'good' : 'neutral' ); ?>"><?php echo esc_html( $aiConfigured ? ( $aiSettings->lastVerifiedAt() > 0 ? __( 'CONNECTED', 'yablonsky-seo' ) : __( 'KEY SAVED', 'yablonsky-seo' ) ) : __( 'CONNECT TO ACTIVATE', 'yablonsky-seo' ) ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'Use Bulk SEO Editor for every site-wide AI metadata campaign. That workspace makes the resource-language scope explicit, supports one/several/all languages, shows durable progress with elapsed time and ETA, and can pause or resume after browser closure, PHP timeout or a transient provider error.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-runtime-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-bulk-editor' ) ); ?>"><?php echo esc_html__( 'Open multilingual Bulk SEO Editor', 'yablonsky-seo' ); ?></a></div>
                            <?php if ( $yoastActive ) : ?><div class="yps-callout"><strong><?php echo esc_html__( 'Indexed metadata protection', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'While Yoast remains public authority, bulk replacement stays locked. Fill-missing first preserves the existing indexed title or description by mirroring exact Yoast metadata. Replacement becomes available after verified takeover.', 'yablonsky-seo' ); ?></span></div><?php endif; ?>
                            <?php if ( ! $aiConfigured ) : ?><div class="yps-callout"><strong><?php echo esc_html__( 'Connect Yablonsky AI', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Add your account-scoped User API key in SEO Settings to enable on-demand and bounded remote metadata generation. Local analysis remains available independently.', 'yablonsky-seo' ); ?></span></div><?php elseif ( 0 === $aiSettings->lastVerifiedAt() ) : ?><div class="yps-callout"><strong><?php echo esc_html__( 'Verify the saved API key', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'The key is stored. Verification is pending for account, workspace and model capabilities. Open SEO Settings and run Test / refresh connection before bulk generation.', 'yablonsky-seo' ); ?></span></div><?php endif; ?>
                            <div id="yps-ai-metadata-status" class="yps-status" aria-live="polite"><?php $this->renderAiMetadataState( $aiMetadataState ); ?></div>
                        </article>
                        <article class="yps-card yps-card-wide yps-content-planner" data-yps-content-planner>
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'AI Content Planner', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Plan the next useful content with editor-controlled publishing', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status neutral"><?php echo esc_html__( 'REVIEW ONLY', 'yablonsky-seo' ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'On explicit request, YSEO sends a bounded sample of recent titles/excerpts plus site identity to Yablonsky AI. It returns up to eight ideas for review; draft creation and publishing remain editor-controlled.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-content-planner-controls"><label><span><?php echo esc_html__( 'Target language', 'yablonsky-seo' ); ?></span><input type="text" data-yps-content-plan-language value="<?php echo esc_attr( (string) get_bloginfo( 'language' ) ); ?>" placeholder="en-US" /></label><button type="button" class="button button-primary" data-yps-content-plan-generate <?php disabled( ! $aiConfigured ); ?>><?php echo esc_html__( 'Generate bounded content plan', 'yablonsky-seo' ); ?></button></div>
                            <p class="yps-assist-status" data-yps-content-plan-status><?php echo esc_html( $aiConfigured ? __( 'Current operation remains local.', 'yablonsky-seo' ) : __( 'Connect and verify Yablonsky AI to enable planning.', 'yablonsky-seo' ) ); ?></p>
                            <div class="yps-content-plan-results" data-yps-content-plan-results hidden></div>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-answer" data-yps-panel="answer" hidden>
                    <div class="yps-layer-hero is-aeo">
                        <div><span class="yps-pillar-badge">AEO</span><h2><?php echo esc_html__( 'Answer Engine Optimization', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'AEO remains content-first, while global Settings hold transparent analysis defaults and guardrails. The goal is to make useful answers easy to identify and extract while keeping page formats natural.', 'yablonsky-seo' ); ?></p></div>
                        <div class="yps-inline-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-settings#answer' ) ); ?>"><?php echo esc_html__( 'AEO Settings', 'yablonsky-seo' ); ?></a><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php echo esc_html__( 'Open content', 'yablonsky-seo' ); ?></a></div>
                    </div>
                    <div class="yps-section-grid">
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( '1 · Answer first', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Clear opening & extractable summary', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'The editor checks whether the saved opening gives enough context and whether a concise summary can be extracted directly from it.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( '2 · Structure', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Questions, sections, lists & tables', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Question-oriented headings, useful sections and compact list/table structures make individual facts and procedures easier to retrieve as answers.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( '3 · Readability', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Concise paragraphs with enough context', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'AEO flags overly dense passages and shallow pages while favoring useful, naturally written content.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Human-first boundary', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Answer text remains visible', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'YSEO analyzes visible saved content. Answer-block publishing remains editor-controlled, and the AEO score is a transparent local readiness heuristic.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Global guidance & local evidence', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'AEO profile', 'yablonsky-seo' ); ?> · <?php echo esc_html( strtoupper( $aeoPolicy->profile() ) ); ?></h2></div><span class="yps-mini-status neutral"><?php echo esc_html__( 'LOCAL', 'yablonsky-seo' ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'The active profile changes editor heuristics only. A bounded recent-content sample provides scoped operational evidence for those heuristics.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-aeo-dashboard-evidence">
                                <span><b><?php echo esc_html( number_format_i18n( (int) $aeoAudit['sampled'] ) ); ?></b><?php echo esc_html__( 'sampled', 'yablonsky-seo' ); ?></span>
                                <span><b><?php echo esc_html( number_format_i18n( (int) $aeoAudit['good'] ) ); ?></b><?php echo esc_html__( 'ready', 'yablonsky-seo' ); ?></span>
                                <span><b><?php echo esc_html( number_format_i18n( (int) $aeoAudit['attention'] ) ); ?></b><?php echo esc_html__( 'improve', 'yablonsky-seo' ); ?></span>
                                <span><b><?php echo esc_html( number_format_i18n( (int) $aeoAudit['critical'] ) ); ?></b><?php echo esc_html__( 'problems', 'yablonsky-seo' ); ?></span>
                            </div>
                        </article>

                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Where AEO lives', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Post · Page · Product → Yablonsky SEO → AEO', 'yablonsky-seo' ); ?></h2></div></div>
                            <div class="yps-aeo-flow"><span><?php echo esc_html__( 'Visible content', 'yablonsky-seo' ); ?></span><b>→</b><span><?php echo esc_html__( 'Local answer checks', 'yablonsky-seo' ); ?></span><b>→</b><span><?php echo esc_html__( 'Problems / Improvements / Good results', 'yablonsky-seo' ); ?></span><b>→</b><span><?php echo esc_html__( 'Human edit', 'yablonsky-seo' ); ?></span></div>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-schema" data-yps-panel="schema" hidden>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Structured data', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Connected JSON-LD graph', 'yablonsky-seo' ); ?></h2>
                            <div class="yps-schema-flow"><span>WebSite</span><b>→</b><span><?php echo esc_html( 'person' === (string) ( $settings['owner_type'] ?? '' ) ? 'Person' : 'Organization' ); ?></span><b>→</b><span>WebPage</span><b>→</b><span>Article / Service / SoftwareApplication</span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'WebSite and WebPage are generated when Yablonsky SEO owns public authority. Owner identity can carry description, external sameAs and Person expertise; content can add Article, Service or SoftwareApplication entities.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Schema aggregation', 'yablonsky-seo' ); ?></span>
                            <h2><code>/schemamap.xml</code></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'A cacheable XML discovery map points machines to bounded JSON-LD aggregation endpoints for each public post type. Canonical HTML remains the page-level authority.', 'yablonsky-seo' ); ?></p>
                            <a href="<?php echo esc_url( home_url( '/schemamap.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open SchemaMap', 'yablonsky-seo' ); ?></a>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Headless SEO', 'yablonsky-seo' ); ?></span>
                            <h2><code>/wp-json/yablonsky-seo/v1/head</code></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Public, read-only SEO projection exposes canonical metadata and the same YSEO schema graph for decoupled WordPress frontends using direct internal projection instead of self-HTTP rendering.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Owner identity', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html( '' !== trim( (string) ( $settings['owner_name'] ?? '' ) ) ? (string) $settings['owner_name'] : __( 'Setup required', 'yablonsky-seo' ) ); ?></h2>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-settings' ) ); ?>"><?php echo esc_html__( 'Edit site identity', 'yablonsky-seo' ); ?></a>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Per-content controls', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Page, article & primary entity', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Editors expose validated page/article types plus optional Service and SoftwareApplication entities. Structured commercial facts should match the visible page content.', 'yablonsky-seo' ); ?></p>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-ai" data-yps-panel="ai" hidden>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'AI / GEO readiness', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Machine-readable foundations · evidence-based signals only', 'yablonsky-seo' ); ?></h2></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-settings' ) ); ?>"><?php echo esc_html__( 'Edit identity & crawler policy', 'yablonsky-seo' ); ?></a></div>
                            <p class="yps-muted"><?php echo esc_html__( 'These checks report local configuration and Yablonsky SEO contract evidence. Rankings, citations, crawler visits and remote index coverage require external evidence.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-readiness-grid">
                                <?php foreach ( $aiReadiness as $check ) : ?>
                                    <div class="yps-readiness-item yps-tone-<?php echo esc_attr( (string) $check['tone'] ); ?>"><div><strong><?php echo esc_html( (string) $check['label'] ); ?></strong><span><?php echo esc_html( (string) $check['detail'] ); ?></span></div><b><?php echo esc_html( (string) $check['status'] ); ?></b></div>
                                <?php endforeach; ?>
                            </div>
                        </article>
                        <article class="yps-card yps-card-wide yps-agent-web-card">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Agent-readable web', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Root orientation · scoped orientation · Markdown detail · canonical HTML', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status <?php echo esc_attr( ! empty( $settings['agent_markdown_enabled'] ) ? 'good' : 'neutral' ); ?>"><?php echo esc_html( ! empty( $settings['agent_markdown_enabled'] ) ? __( 'CONFIGURED', 'yablonsky-seo' ) : __( 'OFF', 'yablonsky-seo' ) ); ?></span></div>
                            <div class="yps-agent-flow yps-agent-flow-four"><div><code>/llms.txt</code><strong><?php echo esc_html__( 'Site orientation', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'A small root map points agents toward major sections and useful resources.', 'yablonsky-seo' ); ?></span></div><b>→</b><div><code>/section/llms.txt</code><strong><?php echo esc_html__( 'Scoped orientation', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Optional path-level indexes reduce how much of a large site an agent must inspect.', 'yablonsky-seo' ); ?></span></div><b>→</b><div><code>/landing-page.md</code><strong><?php echo esc_html__( 'Clean page representation', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Posts, products, taxonomy landings and authors can expose bounded Markdown views.', 'yablonsky-seo' ); ?></span></div><b>→</b><div><code>/landing-page/</code><strong><?php echo esc_html__( 'Canonical HTML', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'The public human/search page remains authoritative.', 'yablonsky-seo' ); ?></span></div></div>
                            <div class="yps-chip-row"><span class="yps-chip"><?php echo esc_html( ! empty( $settings['agent_scoped_llms_enabled'] ) ? __( 'Scoped llms.txt ON', 'yablonsky-seo' ) : __( 'Scoped llms.txt OFF', 'yablonsky-seo' ) ); ?></span><span class="yps-chip"><?php echo esc_html( ! empty( $settings['agent_sitemap_extension_enabled'] ) ? __( 'Agent sitemap extension ON', 'yablonsky-seo' ) : __( 'Agent sitemap extension OFF', 'yablonsky-seo' ) ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'The XML sitemap keeps canonical HTML in <loc>. When enabled, a custom namespace advertises agent alternates; standard consumers keep normal sitemap semantics while compatible consumers can also read the extension.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Yablonsky AI compute', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html( $aiConfigured ? ( $aiSettings->lastVerifiedAt() > 0 ? __( 'Connected', 'yablonsky-seo' ) : __( 'Key saved · verify', 'yablonsky-seo' ) ) : __( 'Connect to activate', 'yablonsky-seo' ) ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html( $aiComputeSummary ); ?></p>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-settings#yps-ai-connection-card' ) ); ?>"><?php echo esc_html__( 'Open AI settings', 'yablonsky-seo' ); ?></a>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Evidence registry', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html( $crawlerCountLabel ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Search, user-directed retrieval and training are purpose-separated. Existing named robots.txt rules take precedence over generated directives.', 'yablonsky-seo' ); ?></p>
                            <?php if ( '' !== $crawlerRegistryVersion ) : ?><span class="yps-mini-status neutral"><?php echo esc_html( $crawlerRegistryVersion ); ?></span><?php endif; ?>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Entity graph', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Person · Organization · Service · SoftwareApplication', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Owner description, external sameAs, job title and knowsAbout can strengthen entity resolution. Individual pages can declare a primary Service or SoftwareApplication using the structured YSEO schema controls.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Per-content GEO', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'AI readiness now lives in the editor too', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Open any post, page or product to review entity context, corroboration, evidence links, image semantics, structure and discoverability for that specific resource.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'AI engine model', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html( $knownEngineCountLabel ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'The broader engine registry remains local and evidence-labelled. Dashboard operation remains local.', 'yablonsky-seo' ); ?></p>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-redirects" data-yps-panel="redirects" hidden>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Redirects', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Central Redirect Manager', 'yablonsky-seo' ); ?></h2></div><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-redirects' ) ); ?>"><?php echo esc_html__( 'Open Redirect Manager', 'yablonsky-seo' ); ?></a></div>
                            <p class="yps-muted"><?php echo esc_html__( 'Create plain or REGEX redirects, choose 301/302/307/410/451, import or export Yoast-compatible CSV, and keep automatically created move redirects in one indexed inventory.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-chip-row"><span class="yps-chip">301</span><span class="yps-chip">302</span><span class="yps-chip">307</span><span class="yps-chip">410</span><span class="yps-chip">451</span><span class="yps-chip">REGEX</span><span class="yps-chip">CSV</span></div>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Automatic moves', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Post and taxonomy URL changes', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'When a published post/page/product or public taxonomy term moves to a new URL, YSEO records a 301 from the previous path to the new canonical URL.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Deletion safety', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Explicit 410 / 451', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Permanent-removal semantics remain explicit. Choose 410 or 451 in Redirect Manager when that semantic is intended.', 'yablonsky-seo' ); ?></p>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-links" data-yps-panel="links" hidden>
                    <div class="yps-kpi-grid">
                        <?php $this->renderKpi( __( 'Indexed resources', 'yablonsky-seo' ), number_format_i18n( (int) $internalLinkDiagnostics['indexed_sources'] ), __( 'Local saved-content graph', 'yablonsky-seo' ), 'authority' ); ?>
                        <?php $this->renderKpi( __( 'Internal links', 'yablonsky-seo' ), number_format_i18n( (int) $internalLinkDiagnostics['link_occurrences'] ), __( 'Observed occurrences', 'yablonsky-seo' ), 'good' ); ?>
                        <?php $this->renderKpi( __( 'Linked targets', 'yablonsky-seo' ), number_format_i18n( (int) $internalLinkDiagnostics['linked_targets'] ), __( 'Resources receiving links', 'yablonsky-seo' ), 'neutral' ); ?>
                        <?php $this->renderKpi( __( 'Orphaned content', 'yablonsky-seo' ), number_format_i18n( (int) $internalLinkDiagnostics['orphaned'] ), __( 'Published, indexable, zero inbound links', 'yablonsky-seo' ), 0 === (int) $internalLinkDiagnostics['orphaned'] ? 'good' : 'attention' ); ?>
                    </div>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide"><div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Internal linking', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Local evidence instead of opaque cloud scoring', 'yablonsky-seo' ); ?></h2></div><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-internal-links' ) ); ?>"><?php echo esc_html__( 'Open Internal Linking', 'yablonsky-seo' ); ?></a></div><p class="yps-muted"><?php echo esc_html__( 'YSEO indexes links found in saved public post/page/product content, counts inbound and outbound relationships, surfaces orphaned resources and suggests unused contextual targets from shared taxonomy and title evidence. Link insertion remains editor-controlled.', 'yablonsky-seo' ); ?></p><div class="yps-chip-row"><span class="yps-chip"><?php echo esc_html__( 'Inbound / outbound counters', 'yablonsky-seo' ); ?></span><span class="yps-chip"><?php echo esc_html__( 'Orphan workflow', 'yablonsky-seo' ); ?></span><span class="yps-chip"><?php echo esc_html__( 'Local suggestions', 'yablonsky-seo' ); ?></span><span class="yps-chip"><?php echo esc_html__( 'Bounded background index', 'yablonsky-seo' ); ?></span></div></article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-sitemaps" data-yps-panel="sitemaps" hidden>
                    <div class="yps-kpi-grid">
                        <?php $this->renderKpi( __( 'Sitemap authority', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ? __( 'Yablonsky SEO', 'yablonsky-seo' ) : __( 'Shadow', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ? __( '/sitemap_index.xml', 'yablonsky-seo' ) : __( 'Competing provider remains public', 'yablonsky-seo' ), $gate->canEmitPublicSeo() ? 'good' : 'neutral' ); ?>
                        <?php $this->renderKpi( __( 'Indexed URLs', 'yablonsky-seo' ), number_format_i18n( (int) $sitemapDiagnostics['urls'] ), __( 'Materialized membership ledger', 'yablonsky-seo' ), 'authority' ); ?>
                        <?php $this->renderKpi( __( 'Child maps', 'yablonsky-seo' ), number_format_i18n( (int) $sitemapDiagnostics['chunks'] ), number_format_i18n( (int) $sitemapDiagnostics['bucket_size'] ) . ' ' . __( 'URLs per stable bucket', 'yablonsky-seo' ), 'neutral' ); ?>
                        <?php
                        if ( ! $gate->canEmitPublicSeo() ) {
                            $this->renderKpi( __( 'Backfill', 'yablonsky-seo' ), __( 'Paused', 'yablonsky-seo' ), __( 'Sitemap indexing pauses while Yablonsky SEO is in Shadow mode', 'yablonsky-seo' ), 'neutral' );
                        } else {
                            $this->renderKpi( __( 'Backfill', 'yablonsky-seo' ), ! empty( $sitemapDiagnostics['backfill_complete'] ) ? __( 'Complete', 'yablonsky-seo' ) : __( 'Warming up', 'yablonsky-seo' ), ! empty( $sitemapDiagnostics['backfill_complete'] ) ? __( 'Public takeover is eligible', 'yablonsky-seo' ) : __( 'Current phase:', 'yablonsky-seo' ) . ' ' . (string) $sitemapDiagnostics['backfill_phase'], ! empty( $sitemapDiagnostics['backfill_complete'] ) ? 'good' : 'attention' );
                        }
                        ?>
                    </div>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Sitemap architecture', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Stable hot/cold buckets', 'yablonsky-seo' ); ?></h2></div><div class="yps-inline-actions"><span class="yps-mini-status <?php echo esc_attr( ! $gate->canEmitPublicSeo() ? 'neutral' : ( ! empty( $sitemapDiagnostics['backfill_complete'] ) ? 'good' : 'attention' ) ); ?>"><?php echo esc_html( ! $gate->canEmitPublicSeo() ? __( 'SHADOW', 'yablonsky-seo' ) : ( ! empty( $sitemapDiagnostics['backfill_complete'] ) ? __( 'READY', 'yablonsky-seo' ) : __( 'WARM-UP', 'yablonsky-seo' ) ) ); ?></span><?php if ( $gate->canEmitPublicSeo() ) : ?><a class="button" href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open sitemap index', 'yablonsky-seo' ); ?></a><?php else : ?><button type="button" class="button yps-jump-tab" data-yps-target="migration"><?php echo esc_html__( 'Open Migration', 'yablonsky-seo' ); ?></button><?php endif; ?></div></div>
                            <p class="yps-muted yps-contract-legend"><?php echo esc_html__( 'These controls are live status indicators. Sitemap endpoints become LIVE together when Yablonsky SEO owns public SEO authority; while another SEO provider owns authority they remain in SHADOW mode.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-contract-grid">
                                <?php $this->renderContract( __( 'Canonical index', 'yablonsky-seo' ), '/sitemap_index.xml', $gate->canEmitPublicSeo() && ! empty( $sitemapDiagnostics['backfill_complete'] ) ); ?>
                                <?php $this->renderContract( __( 'Classic compatibility', 'yablonsky-seo' ), '/sitemap.xml → 301', $gate->canEmitPublicSeo() && ! empty( $sitemapDiagnostics['backfill_complete'] ) ); ?>
                                <?php $this->renderContract( __( 'WordPress compatibility', 'yablonsky-seo' ), '/wp-sitemap.xml → 301', $gate->canEmitPublicSeo() && ! empty( $sitemapDiagnostics['backfill_complete'] ) ); ?>
                                <?php $this->renderContract( __( 'Incomplete-index protection', 'yablonsky-seo' ), 'Core sitemap fallback', true ); ?>
                            </div>
                            <p class="yps-muted"><?php echo esc_html__( 'When Yablonsky SEO owns authority, membership is materialized locally and updated on content/meta events. In Shadow mode background sitemap indexing is paused. Old ready-state buckets stay cold; only affected buckets and the root manifest are invalidated.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <?php $sitemapTransportView = $this->sitemapTransportView( $sitemapTransport ); ?>
                        <article class="yps-card yps-card-wide yseo-sitemap-transport-card">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'HTTP transport', 'yablonsky-seo' ); ?> · <?php echo esc_html__( 'Latest', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Sitemap delivery verification', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status <?php echo esc_attr( $sitemapTransportView['tone'] ); ?>"><?php echo esc_html( $sitemapTransportView['label'] ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html( $sitemapTransportView['detail'] ); ?></p>
                            <?php if ( ! empty( $sitemapTransport ) ) : ?>
                                <div class="yseo-sitemap-transport-grid">
                                    <?php if ( ! empty( $sitemapTransport['endpoints'] ) && is_array( $sitemapTransport['endpoints'] ) ) : ?>
                                        <?php foreach ( $sitemapTransport['endpoints'] as $endpointPath => $endpointEvidence ) : $endpointEvidence = is_array( $endpointEvidence ) ? $endpointEvidence : array(); $probe = isset( $endpointEvidence['clean'] ) && is_array( $endpointEvidence['clean'] ) ? $endpointEvidence['clean'] : array(); ?>
                                            <div class="yseo-sitemap-transport-probe"><strong><?php echo esc_html( (string) $endpointPath ); ?></strong><span><?php echo esc_html( $this->sitemapTransportEndpointLabel( (string) ( $endpointEvidence['status'] ?? '' ) ) ); ?></span><code><?php echo esc_html( (string) ( $probe['content_type'] ?? '—' ) ); ?></code><small><?php echo esc_html( sprintf( 'HTTP %1$d · marker: %2$s', (int) ( $probe['http_status'] ?? 0 ), '' !== (string) ( $probe['transport_marker'] ?? '' ) ? (string) $probe['transport_marker'] : 'missing' ) ); ?></small><?php if ( ! empty( $probe['cache'] ) && is_array( $probe['cache'] ) ) : ?><small><?php echo esc_html( implode( ' · ', array_map( static function ( $key, $value ) { return (string) $key . ': ' . (string) $value; }, array_keys( $probe['cache'] ), array_values( $probe['cache'] ) ) ) ); ?></small><?php endif; ?></div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <?php foreach ( array( 'clean' => __( 'Canonical URL', 'yablonsky-seo' ), 'cache_bypass' => __( 'Cache-busted probe', 'yablonsky-seo' ) ) as $probeKey => $probeLabel ) : $probe = isset( $sitemapTransport[ $probeKey ] ) && is_array( $sitemapTransport[ $probeKey ] ) ? $sitemapTransport[ $probeKey ] : array(); ?>
                                            <div class="yseo-sitemap-transport-probe"><strong><?php echo esc_html( $probeLabel ); ?></strong><span><?php echo esc_html( 'HTTP ' . (int) ( $probe['http_status'] ?? 0 ) ); ?></span><code><?php echo esc_html( (string) ( $probe['content_type'] ?? '—' ) ); ?></code><small><?php echo esc_html( sprintf( 'marker: %s', '' !== (string) ( $probe['transport_marker'] ?? '' ) ? (string) $probe['transport_marker'] : 'missing' ) ); ?></small></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php
                                /* translators: %s: Localized date and time of the latest sitemap transport check. */
                                $sitemapLastCheckedLabel = sprintf( __( 'Last checked: %s', 'yablonsky-seo' ), wp_date( 'Y-m-d H:i:s T', (int) ( $sitemapTransport['checked_at'] ?? 0 ) ) );
                                ?>
                                <p class="yps-muted yseo-sitemap-transport-time"><?php echo esc_html( $sitemapLastCheckedLabel ); ?></p>
                                <?php if ( ! empty( $sitemapTransport['browser_like'] ) && is_array( $sitemapTransport['browser_like'] ) ) : ?>
                                    <details class="yseo-sitemap-browser-differential"><summary><?php echo esc_html__( 'Diagnostics', 'yablonsky-seo' ); ?> · browser</summary><div class="yseo-sitemap-transport-grid">
                                        <?php foreach ( $sitemapTransport['browser_like'] as $browserPath => $browserProbe ) : $browserProbe = is_array( $browserProbe ) ? $browserProbe : array(); ?>
                                            <div class="yseo-sitemap-transport-probe"><strong><?php echo esc_html( (string) $browserPath ); ?></strong><span><?php echo esc_html( 'browser_like' ); ?></span><code><?php echo esc_html( (string) ( $browserProbe['content_type'] ?? '—' ) ); ?></code><small><?php echo esc_html( sprintf( 'HTTP %1$d · marker: %2$s · prefix: %3$s', (int) ( $browserProbe['http_status'] ?? 0 ), '' !== (string) ( $browserProbe['transport_marker'] ?? '' ) ? (string) $browserProbe['transport_marker'] : 'missing', substr( (string) ( $browserProbe['prefix_sha256'] ?? '' ), 0, 12 ) ) ); ?></small><small><?php echo esc_html( sprintf( 'leading_ws=%1$d · bom=%2$s · pure_xml=%3$s · xhtml=%4$s · native_tree=%5$s · first=%6$s', (int) ( $browserProbe['leading_ws_bytes'] ?? 0 ), ! empty( $browserProbe['utf8_bom'] ) ? 'yes' : 'no', ! empty( $browserProbe['pure_xml'] ) ? 'yes' : 'no', ! empty( $browserProbe['xhtml_elements'] ) ? 'yes' : 'no', ! empty( $browserProbe['native_tree_candidate'] ) ? 'yes' : 'no', (string) ( $browserProbe['first_bytes_hex'] ?? '' ) ) ); ?></small></div>
                                        <?php endforeach; ?>
                                    </div></details>
                                <?php endif; ?>
                                <?php if ( count( $sitemapTransportHistory ) > 1 ) : ?>
                                    <details class="yseo-sitemap-transport-history"><summary><?php echo esc_html__( 'Diagnostics', 'yablonsky-seo' ); ?> · <?php echo esc_html( (string) max( 0, count( $sitemapTransportHistory ) - 1 ) ); ?></summary><ol>
                                        <?php foreach ( array_slice( $sitemapTransportHistory, 1 ) as $historicalProbe ) : $historicalProbe = is_array( $historicalProbe ) ? $historicalProbe : array(); $historicalView = $this->sitemapTransportView( $historicalProbe ); ?>
                                            <li><time><?php echo esc_html( wp_date( 'Y-m-d H:i:s T', (int) ( $historicalProbe['checked_at'] ?? 0 ) ) ); ?></time><span class="yps-mini-status <?php echo esc_attr( $historicalView['tone'] ); ?>"><?php echo esc_html( $historicalView['label'] ); ?></span></li>
                                        <?php endforeach; ?>
                                    </ol></details>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="yps-inline-actions yseo-sitemap-transport-actions">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-yseo-sitemap-transport-test><input type="hidden" name="action" value="yseo_sitemap_transport_probe" /><?php wp_nonce_field( 'yseo_sitemap_transport_probe' ); ?><button type="submit" class="button button-primary" data-yseo-sitemap-transport-button><?php echo esc_html__( 'Test transport', 'yablonsky-seo' ); ?></button></form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="yseo_sitemap_purge_transport" /><?php wp_nonce_field( 'yseo_sitemap_purge_transport' ); ?><button type="submit" class="button"><?php echo esc_html__( 'Purge sitemap caches', 'yablonsky-seo' ); ?></button></form>
                            </div>
                            <div class="yseo-sitemap-transport-live" data-yseo-sitemap-transport-live hidden>
                                <div class="yseo-sitemap-transport-live-head"><strong data-yseo-sitemap-transport-state><?php echo esc_html__( 'Running', 'yablonsky-seo' ); ?></strong><span data-yseo-sitemap-transport-elapsed></span></div>
                                <div class="yps-migration-progress is-indeterminate" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-yseo-sitemap-transport-progress><span style="width:34%"></span></div>
                            </div>
                            <p class="yps-muted"><?php echo esc_html__( 'The test stores only bounded HTTP evidence: status, MIME type, YSEO transport marker, XML body shape and known cache headers. The sitemap body stays outside stored evidence.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Child XML cache', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html( ! empty( $sitemapDiagnostics['persistent_object_cache'] ) ? number_format_i18n( (int) $sitemapDiagnostics['dirty'] ) . ' ' . __( 'dirty', 'yablonsky-seo' ) : __( 'Direct', 'yablonsky-seo' ) ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html( ! empty( $sitemapDiagnostics['persistent_object_cache'] ) ? __( 'Persistent child XML is invalidated at bucket level.', 'yablonsky-seo' ) : __( 'Child XML uses one bounded ledger slice and stays out of wp_options. A persistent object cache can accelerate repeated reads when available.', 'yablonsky-seo' ) ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Last XML generation', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html( number_format_i18n( (int) get_option( 'yps_sitemap_last_generation_ms', 0 ) ) . ' ms' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Locally sampled at most once every five minutes to avoid a database write on every crawler request.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Cache generation', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html( '#' . number_format_i18n( (int) $sitemapDiagnostics['cache_generation'] ) ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'A full membership rebuild advances the generation and prevents stale persistent child XML from reappearing.', 'yablonsky-seo' ); ?></p>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-migration" data-yps-panel="migration" hidden>
                    <div class="yps-section-grid">
                        <?php if ( $this->detector->hasProvider( 'yoast' ) ) : ?>
                            <article class="yps-card yps-card-wide yps-migration-runbook">
                                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Safe Yoast transition', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Migrate in Shadow mode before public takeover', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status <?php echo esc_attr( $this->canPrepareYoastWorkflow( $providers, $adapterRegistry ) ? 'good' : 'attention' ); ?>"><?php echo esc_html( $this->canPrepareYoastWorkflow( $providers, $adapterRegistry ) ? __( 'VERIFIED ADAPTER', 'yablonsky-seo' ) : __( 'AUDIT ONLY', 'yablonsky-seo' ) ); ?></span></div>
                                <p class="yps-muted"><?php echo esc_html__( 'Keep Yoast active through metadata mirror, dry-run, rollback backup and shadow import. After the safety gates pass, WordPress.org policy requires the administrator to deactivate Yoast manually in Plugins before YSEO can become public authority.', 'yablonsky-seo' ); ?></p>
                                <div class="yps-migration-runbook-grid">
                                    <div><span>1</span><strong><?php echo esc_html__( 'Mirror', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Copy exact source values into empty YSEO shadow fields.', 'yablonsky-seo' ); ?></small></div>
                                    <div><span>2</span><strong><?php echo esc_html__( 'Dry-run', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Measure source coverage and blockers while authority stays unchanged.', 'yablonsky-seo' ); ?></small></div>
                                    <div><span>3</span><strong><?php echo esc_html__( 'Backup + shadow import', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Create a verified rollback anchor, then import with Yoast still live.', 'yablonsky-seo' ); ?></small></div>
                                    <div><span>4</span><strong><?php echo esc_html__( 'Takeover', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'After verified parity, deactivate Yoast manually in Plugins. YSEO activates public authority only after detecting that administrator action.', 'yablonsky-seo' ); ?></small></div>
                                </div>
                                <p class="yps-muted"><strong><?php echo esc_html__( 'Detected source:', 'yablonsky-seo' ); ?></strong> <?php echo esc_html( $this->providerLabel( $providers ) ); ?></p>
                            </article>
                        <?php endif; ?>
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Stage 0 · Metadata mirror', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Copy existing Yoast values first. Keep Yoast live.', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status neutral"><?php echo esc_html__( 'PRESERVE-ONLY', 'yablonsky-seo' ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'This is intentionally separate from takeover evidence. It copies exact Yoast values only into empty YSEO shadow fields in bounded batches, preserves existing YSEO edits and Yoast source data, and keeps public authority unchanged. Use it to compare both editors long before takeover.', 'yablonsky-seo' ); ?></p>
                            <?php if ( $this->detector->hasProvider( 'yoast' ) ) : ?>
                                <div class="yps-actions"><button type="button" class="button button-primary" id="yps-mirror-search"><?php echo esc_html__( 'Mirror Search metadata', 'yablonsky-seo' ); ?></button><button type="button" class="button" id="yps-mirror-all"><?php echo esc_html__( 'Mirror all mapped metadata', 'yablonsky-seo' ); ?></button><button type="button" class="button" id="yps-mirror-resume" <?php disabled( null === $mirrorState || MetadataMirrorState::STATUS_RUNNING !== $mirrorState->get( 'status' ) ); ?>><?php echo esc_html__( 'Resume mirror', 'yablonsky-seo' ); ?></button><button type="button" class="button" id="yps-mirror-reset"><?php echo esc_html__( 'Reset mirror status', 'yablonsky-seo' ); ?></button></div>
                            <?php else : ?><div class="yps-callout"><strong><?php echo esc_html__( 'Yoast: activation required for live source migration.', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Yoast mirroring activates when a live Yoast source is detected.', 'yablonsky-seo' ); ?></span></div><?php endif; ?>
                            <div id="yps-mirror-status" class="yps-status" aria-live="polite"><?php $this->renderMirrorState( $mirrorState ); ?></div>
                        </article>
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Portable metadata · Preserve-only', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Bring verified explicit SEO fields into YSEO while authority remains unchanged.', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status neutral"><?php echo esc_html__( 'SOURCE READ-ONLY', 'yablonsky-seo' ); ?></span></div>
                            <p class="yps-muted"><?php echo esc_html__( 'Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO and SureRank can expose a verified preserve-only portability surface. YSEO reads documented or runtime-verified storage, copies values into available YSEO shadow fields, preserves the source provider and keeps public authority unchanged. Full automatic takeover activates only after a separate parity and rollback contract is verified.', 'yablonsky-seo' ); ?></p>
                            <?php if ( empty( $portableProfiles ) ) : ?>
                                <div class="yps-callout"><strong><?php echo esc_html__( 'Portable source provider ready to connect', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'This surface activates only when a supported competing provider is detected. Only verified explicit source storage is eligible for preserve-only portability.', 'yablonsky-seo' ); ?></span></div>
                            <?php else : ?>
                                <div class="yps-portability-grid">
                                    <?php foreach ( $portableProfiles as $portableProfile ) : ?>
                                        <div class="yps-portability-source">
                                            <div><strong><?php echo esc_html( $portableProfile->label() ); ?></strong><span><?php echo esc_html( $portableProfile->supportsTerms() ? __( 'Posts + public taxonomy terms', 'yablonsky-seo' ) : __( 'Posts only', 'yablonsky-seo' ) ); ?></span></div>
                                            <button type="button" class="button yseo-portable-mirror-start" data-yseo-portable-provider="<?php echo esc_attr( $portableProfile->id() ); ?>"><?php echo esc_html__( 'Mirror verified fields', 'yablonsky-seo' ); ?></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="yps-actions"><button type="button" class="button" id="yseo-portable-mirror-resume" <?php disabled( null === $portableMirrorState || PortableMirrorState::STATUS_RUNNING !== $portableMirrorState->get( 'status' ) ); ?>><?php echo esc_html__( 'Resume portability job', 'yablonsky-seo' ); ?></button><button type="button" class="button" id="yseo-portable-mirror-reset"><?php echo esc_html__( 'Reset portability status', 'yablonsky-seo' ); ?></button></div>
                            <?php endif; ?>
                            <div id="yseo-portable-mirror-status" class="yps-status" aria-live="polite"><?php $this->renderPortableMirrorState( $portableMirrorState ); ?></div>
                        </article>
                        <article class="yps-card yps-card-wide yps-migration-center">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Universal migration center', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'One safe path from Yoast to Yablonsky SEO', 'yablonsky-seo' ); ?></h2></div><span id="yps-migration-head-badge" class="yps-mini-status <?php echo esc_attr( $migrationConsole['tone'] ); ?>"><?php echo esc_html( $migrationConsole['badge'] ); ?></span></div>
                            <?php $this->renderAdapterMatrix( $providers, $adapterRegistry ); ?>
                            <?php if ( ! $this->isCurrentMigrationState( $state ) && null !== $state ) : ?>
                                <div class="yps-callout yps-callout-attention"><strong><?php echo esc_html__( 'Fresh verification required after the 2.70.1 migration fix', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'The previous stopped run is preserved for diagnosis. Start the safe preparation again; YSEO will run a fresh dry-run, seal a fresh rollback backup and retry shadow import while preserving current public SEO authority.', 'yablonsky-seo' ); ?></span></div>
                            <?php endif; ?>

                            <div id="yps-migration-console" class="yps-migration-console is-<?php echo esc_attr( $migrationConsole['tone'] ); ?>" aria-live="polite">
                                <div class="yps-migration-console-copy">
                                    <div class="yps-migration-console-title"><span id="yps-migration-console-badge" class="yps-migration-console-badge"><?php echo esc_html( $migrationConsole['badge'] ); ?></span><strong id="yps-migration-console-title"><?php echo esc_html( $migrationConsole['title'] ); ?></strong></div>
                                    <p id="yps-migration-console-detail"><?php echo esc_html( $migrationConsole['detail'] ); ?></p>
                                    <small id="yps-migration-console-meta"><?php echo esc_html( $migrationConsole['meta'] ); ?></small>
                                </div>
                                <div class="yps-migration-progress-shell">
                                    <div id="yps-migration-progress" class="yps-migration-progress <?php echo $migrationConsole['indeterminate'] ? 'is-indeterminate' : ''; ?>" role="progressbar" aria-label="<?php echo esc_attr__( 'Migration progress', 'yablonsky-seo' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $migrationConsole['percent'] ); ?>"><span style="width:<?php echo esc_attr( (string) $migrationConsole['percent'] ); ?>%"></span></div>
                                </div>
                            </div>

                            <?php if ( ! $this->detector->hasProvider( 'yoast' ) ) : ?>
                                <?php if ( is_array( $rollbackCheckpoint ) && 'cutover' === (string) ( $rollbackCheckpoint['status'] ?? '' ) ) : ?>
                                    <div class="yps-callout"><strong><?php echo esc_html__( 'Yablonsky SEO owns public authority', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Takeover complete', 'yablonsky-seo' ); ?></span></div>
                                <?php else : ?>
                                    <div class="yps-callout"><strong><?php echo esc_html__( 'Yoast migration source ready for detection', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Migration controls stay idle.', 'yablonsky-seo' ); ?></span></div>
                                <?php endif; ?>
                            <?php else : ?>
                                <div class="yps-migration-primary-actions">
                                    <button type="button" class="button button-primary button-hero" id="yps-prepare-takeover" <?php disabled( ! $this->canPrepareYoastWorkflow( $providers, $adapterRegistry ) ); ?>><?php echo esc_html__( 'Prepare verified shadow import', 'yablonsky-seo' ); ?></button>
                                    <span class="yps-muted"><?php echo esc_html__( 'Runs fresh dry-run → verified rollback backup → shadow import. Yoast remains live. Public takeover requires explicit approval.', 'yablonsky-seo' ); ?></span>
                                </div>
                                <div class="yps-migration-step-actions" aria-label="<?php echo esc_attr__( 'Individual migration steps', 'yablonsky-seo' ); ?>">
                                    <button type="button" class="button" id="yps-dry-run"><?php echo esc_html__( '1 · Run fresh dry-run', 'yablonsky-seo' ); ?></button>
                                    <button type="button" class="button" id="yps-backup-start" <?php disabled( ! $this->canOfferYoastBackup( $state ) ); ?>><?php echo esc_html__( '2 · Create rollback backup', 'yablonsky-seo' ); ?></button>
                                    <button type="button" class="button" id="yps-shadow-import" <?php disabled( ! $this->canOfferYoastShadowImport( $providers, $adapterRegistry, $state ) ); ?>><?php echo esc_html__( '3 · Import to shadow storage', 'yablonsky-seo' ); ?></button>
                                    <button type="button" class="button button-primary" id="yps-takeover" <?php disabled( empty( $takeover['ready'] ) ); ?>><?php echo esc_html__( '4 · Take over public SEO', 'yablonsky-seo' ); ?></button>
                                    <button type="button" class="button" id="yps-inspect-conflicts" <?php disabled( null === $state || (int) $state->get( 'conflicts' ) <= 0 ); ?>><?php echo esc_html__( 'Review conflict', 'yablonsky-seo' ); ?></button>
                                </div>
                                <details class="yps-migration-recovery"><summary><?php echo esc_html__( 'Advanced / recovery actions', 'yablonsky-seo' ); ?></summary><div class="yps-actions"><button type="button" class="button" id="yps-resume"><?php echo esc_html__( 'Resume interrupted bounded job', 'yablonsky-seo' ); ?></button><button type="button" class="button" id="yps-reset"><?php echo esc_html__( 'Reset migration workspace', 'yablonsky-seo' ); ?></button></div></details>
                            <?php endif; ?>

                            <div class="yps-migration-stage-grid" id="yps-migration-stages">
                                <div class="<?php echo esc_attr( $stageSourceClass ); ?>" data-yps-stage="source"><span><?php echo esc_html__( '1 · Source coverage', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $this->isCurrentMigrationState( $state ) && $state->get( 'source_coverage_ready' ) ? __( 'Verified', 'yablonsky-seo' ) : __( 'Required', 'yablonsky-seo' ) ); ?></strong></div>
                                <div class="<?php echo esc_attr( $stageBackupClass ); ?>" data-yps-stage="backup"><span><?php echo esc_html__( '2 · Rollback backup', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $this->isCurrentMigrationState( $state ) && $state->get( 'backup_export_verified' ) ? __( 'Verified', 'yablonsky-seo' ) : __( 'Required', 'yablonsky-seo' ) ); ?></strong></div>
                                <div class="<?php echo esc_attr( $stageShadowClass ); ?>" data-yps-stage="shadow"><span><?php echo esc_html__( '3 · Shadow parity', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $this->isCurrentMigrationState( $state ) && $state->get( 'semantic_parity_ready' ) ? __( 'Verified', 'yablonsky-seo' ) : ( null !== $state && MigrationState::STATUS_BLOCKED === $state->get( 'status' ) ? __( 'Stopped', 'yablonsky-seo' ) : __( 'Required', 'yablonsky-seo' ) ) ); ?></strong></div>
                                <div class="<?php echo esc_attr( $stageTakeoverClass ); ?>" data-yps-stage="takeover"><span><?php echo esc_html__( '4 · Public takeover', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $takeoverComplete ? __( 'Active', 'yablonsky-seo' ) : ( ! empty( $takeover['ready'] ) ? __( 'Available', 'yablonsky-seo' ) : __( 'Locked', 'yablonsky-seo' ) ) ); ?></strong></div>
                            </div>

                            <div id="yps-conflict-review-host"><?php $this->renderConflictReview( $conflictReview ); ?></div>

                            <details class="yps-migration-technical" <?php if ( null !== $state && MigrationState::STATUS_BLOCKED === $state->get( 'status' ) ) : ?>open<?php endif; ?>>
                                <summary><?php echo esc_html__( 'Technical evidence', 'yablonsky-seo' ); ?></summary>
                                <div id="yps-migration-status" class="yps-status" aria-live="polite"><?php $this->renderState( $state ); ?></div>
                                <div id="yps-backup-status" class="yps-status" aria-live="polite"><?php $this->renderBackupState( $backupState ); ?></div>
                            </details>
                        </article>
                    </div>
                </section>

                <section class="yps-panel" id="yps-panel-diagnostics" data-yps-panel="diagnostics" hidden>
                    <div class="yps-section-grid">
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Frontend contracts', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html( $gate->canEmitPublicSeo() ? __( 'Registered on public requests', 'yablonsky-seo' ) : __( 'Intentionally not registered', 'yablonsky-seo' ) ); ?></h2></div><span class="yps-mini-status <?php echo esc_attr( $gate->canEmitPublicSeo() ? 'good' : 'neutral' ); ?>"><?php echo esc_html( $gate->canEmitPublicSeo() ? __( 'LIVE', 'yablonsky-seo' ) : __( 'SHADOW', 'yablonsky-seo' ) ); ?></span></div>
                            <div class="yps-contract-grid">
                                <?php
                                $contracts = array(
                                    'pre_get_document_title' => __( 'Document title', 'yablonsky-seo' ),
                                    'wp_robots' => __( 'Robots directives', 'yablonsky-seo' ),
                                    'wp_head' => __( 'Meta / canonical / social / schema', 'yablonsky-seo' ),
                                    'template_redirect' => __( 'Same-site redirects', 'yablonsky-seo' ),
                                    'wp_sitemaps_posts_entry' => __( 'Core fallback · post membership', 'yablonsky-seo' ),
                                    'wp_sitemaps_taxonomies_entry' => __( 'Core fallback · taxonomy membership', 'yablonsky-seo' ),
                                    'wp_sitemaps_users_entry' => __( 'Core fallback · author membership', 'yablonsky-seo' ),
                                );
                                foreach ( $contracts as $hook => $label ) {
                                    $this->renderContract( $label, $hook, $gate->canEmitPublicSeo() );
                                }
                                ?>
                            </div>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'External requests', 'yablonsky-seo' ); ?></span>
                            <h2><?php echo esc_html__( 'Normal runtime is self-contained', 'yablonsky-seo' ); ?></h2>
                            <p class="yps-muted"><?php echo esc_html__( 'Activation, rendering, migration and this dashboard run locally.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card">
                            <span class="yps-card-kicker"><?php echo esc_html__( 'Product identity', 'yablonsky-seo' ); ?></span>
                            <h2><code>YSEO</code> · Yablonsky SEO</h2>
                            <p><code>yablonsky-seo/yablonsky-seo.php</code></p>
                            <p class="yps-muted"><?php echo esc_html__( 'YPS remains only as a legacy compatibility prefix for existing options, meta, hooks and migration state.', 'yablonsky-seo' ); ?></p>
                        </article>
                        <article class="yps-card yps-card-wide">
                            <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Release & support readiness', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Local evidence for production support', 'yablonsky-seo' ); ?></h2></div><a class="button" href="<?php echo esc_url( $supportSnapshotUrl ); ?>"><?php echo esc_html__( 'Download support snapshot', 'yablonsky-seo' ); ?></a></div>
                            <p class="yps-muted"><?php echo esc_html__( 'This local operational audit reports local readiness evidence. The downloadable JSON contains bounded diagnostics while sensitive API keys, content bodies, email addresses, raw SEO values and database credentials stay out of the export.', 'yablonsky-seo' ); ?></p>
                            <div class="yps-readiness-grid">
                                <?php foreach ( $releaseReadiness['checks'] as $releaseCheck ) : ?>
                                    <div class="yps-readiness-item yps-tone-<?php echo esc_attr( 'pass' === (string) $releaseCheck['status'] ? 'good' : 'attention' ); ?>"><div><strong><?php echo esc_html( (string) $releaseCheck['label'] ); ?></strong><span><?php echo esc_html( (string) $releaseCheck['detail'] ); ?></span></div><b><?php echo esc_html( (string) $releaseCheck['value'] ); ?></b></div>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    </div>
                </section>
            </main>
        </div>
        <?php
    }

    /** @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers @param array{label:string,detail:string,tone:string,missing:array<int,string>} $setup */
    private function attentionHeadline( array $providers, array $setup, ?MigrationState $state ): string {
        $count = count( $setup['missing'] );
        if ( ! empty( $providers ) ) {
            return __( 'Safe coexistence is doing its job', 'yablonsky-seo' );
        }
        if ( null !== $state && MigrationState::STATUS_BLOCKED === $state->get( 'status' ) ) {
            return __( 'Migration workspace needs review', 'yablonsky-seo' );
        }
        if ( $count > 0 ) {
            /* translators: %d: number of incomplete SEO setup items. */
            return sprintf( _n( '%d setup item remains', '%d setup items remain', $count, 'yablonsky-seo' ), $count );
        }
        return __( 'Setup is clear of blocking issues', 'yablonsky-seo' );
    }

    /** @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers @param array{label:string,detail:string,tone:string,missing:array<int,string>} $setup */
    private function renderAttentionList( array $providers, array $setup, ?MigrationState $state ): void {
        echo '<ul class="yps-attention-list">';
        if ( ! empty( $providers ) ) {
            /* translators: %s: active SEO provider name and version. */
            $providerMessage = sprintf( __( '%s currently owns the live SEO surface. Yablonsky SEO is observing in Shadow mode.', 'yablonsky-seo' ), $this->providerLabel( $providers ) );
            echo '<li><span class="yps-attention-icon good">✓</span><div><strong>' . esc_html__( 'Single-owner public SEO output', 'yablonsky-seo' ) . '</strong><span>' . esc_html( $providerMessage ) . '</span></div></li>';
        } else {
            echo '<li><span class="yps-attention-icon good">✓</span><div><strong>' . esc_html__( 'Authority boundary is clear', 'yablonsky-seo' ) . '</strong><span>' . esc_html__( 'YSEO currently has public authority', 'yablonsky-seo' ) . '</span></div></li>';
        }
        foreach ( array_slice( $setup['missing'], 0, 3 ) as $missing ) {
            echo '<li><span class="yps-attention-icon attention">!</span><div><strong>' . esc_html( $missing ) . '</strong><span>' . esc_html__( 'Complete this in SEO Settings to strengthen site-level search and schema identity.', 'yablonsky-seo' ) . '</span></div></li>';
        }
        if ( null !== $state && MigrationState::STATUS_BLOCKED === $state->get( 'status' ) ) {
            echo '<li><span class="yps-attention-icon attention">!</span><div><strong>' . esc_html__( 'Migration job stopped safely', 'yablonsky-seo' ) . '</strong><span>' . esc_html__( 'Open Migration to inspect the last bounded job state. Public SEO authority remains unchanged.', 'yablonsky-seo' ) . '</span></div></li>';
        }
        echo '</ul>';
    }

    private function renderKpi( string $label, string $value, string $detail, string $tone ): void {
        printf(
            '<article class="yps-kpi yps-tone-%1$s"><span>%2$s</span><strong>%3$s</strong><small>%4$s</small></article>',
            esc_attr( $tone ),
            esc_html( $label ),
            esc_html( $value ),
            esc_html( $detail )
        );
    }

    private function renderStackRow( string $label, bool $active ): void {
        printf(
            '<div class="yps-stack-row"><span>%1$s</span><strong class="%2$s">%3$s</strong></div>',
            esc_html( $label ),
            esc_attr( $active ? 'is-live' : 'is-shadow' ),
            esc_html( $active ? __( 'Yablonsky SEO', 'yablonsky-seo' ) : __( 'Provider-owned', 'yablonsky-seo' ) )
        );
    }

    private function renderContract( string $label, string $hook, bool $active ): void {
        printf(
            '<div class="yps-contract"><span class="yps-contract-status %1$s">%2$s</span><div><strong>%3$s</strong><code>%4$s</code></div></div>',
            esc_attr( $active ? 'is-live' : 'is-shadow' ),
            esc_html( $active ? __( 'LIVE', 'yablonsky-seo' ) : __( 'SHADOW', 'yablonsky-seo' ) ),
            esc_html( $label ),
            esc_html( $hook )
        );
    }

    private function sitemapTransportEndpointLabel( string $status ): string {
        $labels = array(
            'pass' => __( 'PASS · XML transport current', 'yablonsky-seo' ),
            'stale_clean_cache' => __( 'Stale canonical cache', 'yablonsky-seo' ),
            'plugin_not_reached' => __( 'YSEO route not reached', 'yablonsky-seo' ),
            'downstream_header_rewrite' => __( 'Response header rewritten', 'yablonsky-seo' ),
            'invalid_xml_transport' => __( 'XML body mismatch', 'yablonsky-seo' ),
            'request_error' => __( 'HTTP probe failed', 'yablonsky-seo' ),
            'not_available' => __( 'Available when supported on this site', 'yablonsky-seo' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Needs review', 'yablonsky-seo' );
    }

    /** @param array<string,mixed> $result @return array{label:string,detail:string,tone:string} */
    private function sitemapTransportView( array $result ): array {
        $status = (string) ( $result['status'] ?? '' );
        $map = array(
            'pass' => array( __( 'PASS', 'yablonsky-seo' ), __( 'The canonical sitemap response is reaching YSEO as XML with the expected transport marker.', 'yablonsky-seo' ), 'good' ),
            'stale_clean_cache' => array( __( 'STALE CACHE', 'yablonsky-seo' ), __( 'A cache-busted sitemap is correct but the canonical URL is stale. Purge sitemap caches, then test again.', 'yablonsky-seo' ), 'attention' ),
            'plugin_not_reached' => array( __( 'BYPASSED', 'yablonsky-seo' ), __( 'Even the cache-busted request does not carry the YSEO transport marker. An upstream cache, rewrite or alternate sitemap handler may be serving the URL before YSEO.', 'yablonsky-seo' ), 'attention' ),
            'downstream_header_rewrite' => array( __( 'HEADER REWRITE', 'yablonsky-seo' ), __( 'YSEO handled the request, but the final MIME type is not XML. A downstream server, proxy or cache layer is rewriting response headers.', 'yablonsky-seo' ), 'attention' ),
            'invalid_xml_transport' => array( __( 'BODY MISMATCH', 'yablonsky-seo' ), __( 'YSEO transport headers are present, but the returned body does not begin as an XML sitemap document.', 'yablonsky-seo' ), 'attention' ),
            'request_error' => array( __( 'TEST ERROR', 'yablonsky-seo' ), __( 'The local HTTP probe could not complete. Retry after checking loopback HTTP connectivity.', 'yablonsky-seo' ), 'neutral' ),
            'attention' => array( __( 'ATTENTION', 'yablonsky-seo' ), __( 'The sitemap transport needs review before release.', 'yablonsky-seo' ), 'attention' ),
        );
        if ( ! isset( $map[ $status ] ) ) {
            return array( 'label' => __( 'TEST TO VERIFY', 'yablonsky-seo' ), 'detail' => __( 'Run the HTTP transport test to distinguish valid XML generation from stale page/CDN cache or downstream header rewriting.', 'yablonsky-seo' ), 'tone' => 'neutral' );
        }
        return array( 'label' => $map[ $status ][0], 'detail' => $map[ $status ][1], 'tone' => $map[ $status ][2] );
    }

    /** @param array<string,mixed> $settings @return array{label:string,detail:string,tone:string,missing:array<int,string>} */
    private function setupReadiness( array $settings ): array {
        $checks = array(
            'site_display_name' => __( 'Site display name', 'yablonsky-seo' ),
            'site_description' => __( 'Site description', 'yablonsky-seo' ),
            'owner_name' => __( 'Owner identity', 'yablonsky-seo' ),
            'owner_description' => __( 'Owner/entity description', 'yablonsky-seo' ),
            'owner_same_as' => __( 'External entity profile', 'yablonsky-seo' ),
            'home_title' => __( 'Homepage SEO title', 'yablonsky-seo' ),
            'home_description' => __( 'Homepage meta description', 'yablonsky-seo' ),
        );
        $missing = array();
        foreach ( $checks as $key => $label ) {
            if ( ! isset( $settings[ $key ] ) || '' === trim( (string) $settings[ $key ] ) ) {
                $missing[] = $label;
            }
        }
        $complete = count( $checks ) - count( $missing );
        $percent = (int) round( ( $complete / count( $checks ) ) * 100 );
        if ( empty( $missing ) ) {
            $detail = __( 'Site identity complete', 'yablonsky-seo' );
        } else {
            /* translators: %d: number of incomplete SEO setup items. */
            $detail = sprintf( _n( '%d item missing', '%d items missing', count( $missing ), 'yablonsky-seo' ), count( $missing ) );
        }
        return array(
            'label' => $percent . '%',
            'detail' => $detail,
            'tone' => $percent >= 80 ? 'good' : ( $percent >= 40 ? 'attention' : 'neutral' ),
            'missing' => $missing,
        );
    }

    /** @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers @return array{label:string,detail:string,tone:string,badge:string} */
    /** @return array{tone:string,badge:string,title:string,detail:string,meta:string,indeterminate:bool,percent:int} */
    private function migrationConsoleView( ?MigrationState $state, ?BackupExportState $backupState ): array {
        if ( null !== $backupState && BackupExportState::STATUS_RUNNING === $backupState->get( 'status' ) ) {
            return array(
                'tone' => 'running',
                'badge' => __( 'RUNNING', 'yablonsky-seo' ),
                'title' => __( 'Rollback backup is running', 'yablonsky-seo' ),
                'detail' => __( 'Keep this page open. YSEO is exporting and independently verifying a private rollback anchor.', 'yablonsky-seo' ),
                'meta' => sprintf(
                    /* translators: 1: backup phase, 2: exported record count, 3: verified record count. */
                    __( 'Phase: %1$s · exported %2$d · verified %3$d', 'yablonsky-seo' ),
                    (string) $backupState->get( 'phase' ),
                    (int) $backupState->get( 'export_records' ),
                    (int) $backupState->get( 'verify_records' )
                ),
                'indeterminate' => true,
                'percent' => 35,
            );
        }
        if ( null === $state ) {
            return array(
                'tone' => 'neutral',
                'badge' => __( 'READY', 'yablonsky-seo' ),
                'title' => __( 'Ready for a safe dry-run', 'yablonsky-seo' ),
                'detail' => __( 'Public SEO output stays unchanged during dry-run, backup and shadow import.', 'yablonsky-seo' ),
                'meta' => __( 'Current step: verify source coverage.', 'yablonsky-seo' ),
                'indeterminate' => false,
                'percent' => 0,
            );
        }
        $status = (string) $state->get( 'status' );
        $mode = (string) $state->get( 'mode' );
        $phase = (string) $state->get( 'phase' );
        if ( MigrationState::STATUS_RUNNING === $status ) {
            return array(
                'tone' => 'running',
                'badge' => __( 'RUNNING', 'yablonsky-seo' ),
                'title' => MigrationState::MODE_IMPORT === $mode ? __( 'Shadow import is running', 'yablonsky-seo' ) : __( 'Dry-run is running', 'yablonsky-seo' ),
                'detail' => __( 'Keep this page open. Work is bounded into small server steps and public SEO authority remains unchanged.', 'yablonsky-seo' ),
                'meta' => sprintf(
                    /* translators: 1: migration phase, 2: processed post count, 3: imported value count, 4: conflict count. */
                    __( 'Phase: %1$s · content %2$d · imported %3$d · conflicts %4$d', 'yablonsky-seo' ),
                    $phase,
                    (int) $state->get( 'processed_posts' ),
                    (int) $state->get( 'imported_values' ),
                    (int) $state->get( 'conflicts' )
                ),
                'indeterminate' => true,
                'percent' => 45,
            );
        }
        if ( MigrationState::STATUS_BLOCKED === $status ) {
            return array(
                'tone' => 'stopped',
                'badge' => __( 'STOPPED', 'yablonsky-seo' ),
                'title' => __( 'Migration stopped safely', 'yablonsky-seo' ),
                'detail' => '' !== (string) $state->get( 'message' ) ? (string) $state->get( 'message' ) : __( 'A safety gate requires review. Yoast remains authoritative.', 'yablonsky-seo' ),
                'meta' => sprintf(
                    /* translators: 1: conflict count, 2: imported value count, 3: verified value count. */
                    __( 'Conflicts: %1$d · imported %2$d · verified %3$d · public takeover remains locked.', 'yablonsky-seo' ),
                    (int) $state->get( 'conflicts' ),
                    (int) $state->get( 'imported_values' ),
                    (int) $state->get( 'verified_values' )
                ),
                'indeterminate' => false,
                'percent' => 100,
            );
        }
        if ( MigrationState::MODE_IMPORT === $mode && $state->get( 'cutover_ready' ) ) {
            return array(
                'tone' => 'complete',
                'badge' => __( 'VERIFIED', 'yablonsky-seo' ),
                'title' => __( 'Shadow import and parity verification completed', 'yablonsky-seo' ),
                'detail' => __( 'YSEO shadow data matches the verified source evidence. Yoast is still live until you explicitly confirm public takeover.', 'yablonsky-seo' ),
                'meta' => __( 'Current step: review the result, then choose whether to take over public SEO authority.', 'yablonsky-seo' ),
                'indeterminate' => false,
                'percent' => 100,
            );
        }
        if ( $state->get( 'backup_export_verified' ) ) {
            return array(
                'tone' => 'complete',
                'badge' => __( 'VERIFIED', 'yablonsky-seo' ),
                'title' => __( 'Rollback backup verified', 'yablonsky-seo' ),
                'detail' => __( 'Source coverage and the private rollback anchor are ready. Shadow import is the next safe step.', 'yablonsky-seo' ),
                'meta' => __( 'Current step: import to shadow storage. Public SEO remains with Yoast.', 'yablonsky-seo' ),
                'indeterminate' => false,
                'percent' => 100,
            );
        }
        if ( MigrationState::MODE_DRY_RUN === $mode && MigrationState::STATUS_COMPLETE === $status && $state->get( 'source_coverage_ready' ) ) {
            return array(
                'tone' => 'complete',
                'badge' => __( 'VERIFIED', 'yablonsky-seo' ),
                'title' => __( 'Dry-run verified source coverage', 'yablonsky-seo' ),
                'detail' => __( 'The source scan completed and preserved public output. Create the rollback backup while this verification is fresh.', 'yablonsky-seo' ),
                'meta' => __( 'Current step: create verified rollback backup.', 'yablonsky-seo' ),
                'indeterminate' => false,
                'percent' => 100,
            );
        }
        return array(
            'tone' => 'neutral',
            'badge' => __( 'SAFE', 'yablonsky-seo' ),
            'title' => __( 'Migration evidence is available', 'yablonsky-seo' ),
            'detail' => __( 'Review the current state below before continuing.', 'yablonsky-seo' ),
            'meta' => __( 'Public SEO authority remains unchanged.', 'yablonsky-seo' ),
            'indeterminate' => false,
            'percent' => 0,
        );
    }

    /** @param array<string,mixed>|null $inspection */
    private function renderConflictReview( ?array $inspection ): void {
        if ( null === $inspection || (int) ( $inspection['state_conflicts'] ?? 0 ) <= 0 ) {
            return;
        }
        $repairable = ! empty( $inspection['legacy_global_merge'] );
        echo '<div id="yps-conflict-review" class="yps-conflict-review ' . esc_attr( $repairable ? 'is-repairable' : 'is-conflict' ) . '" aria-live="polite">';
        echo '<div class="yps-conflict-review-head"><div><span class="yps-card-kicker">' . esc_html__( 'Conflict review', 'yablonsky-seo' ) . '</span><h3>' . esc_html( $repairable ? __( '2.70.0 partial-global merge regression detected', 'yablonsky-seo' ) : __( 'Migration conflict requires review', 'yablonsky-seo' ) ) . '</h3></div><span class="yps-mini-status ' . esc_attr( $repairable ? 'attention' : 'bad' ) . '">' . esc_html( $repairable ? __( 'REPAIRABLE', 'yablonsky-seo' ) : __( 'CONFLICT', 'yablonsky-seo' ) ) . '</span></div>';
        echo '<p>' . esc_html( (string) ( $inspection['message'] ?? '' ) ) . '</p>';
        if ( $repairable ) {
            echo '<p class="yps-muted">' . esc_html__( 'YSEO 2.70.0 compared the entire global shadow option after Stage 0 had intentionally mirrored only a subset. 2.70.1 merges verified missing keys per field and does not overwrite differing YSEO values.', 'yablonsky-seo' ) . '</p>';
        }
        $details = isset( $inspection['details'] ) && is_array( $inspection['details'] ) ? $inspection['details'] : array();
        if ( ! empty( $details ) ) {
            echo '<div class="yps-conflict-table-wrap"><table class="widefat striped yps-conflict-table"><thead><tr><th>' . esc_html__( 'Scope', 'yablonsky-seo' ) . '</th><th>' . esc_html__( 'Object', 'yablonsky-seo' ) . '</th><th>' . esc_html__( 'Source field', 'yablonsky-seo' ) . '</th><th>' . esc_html__( 'YSEO field', 'yablonsky-seo' ) . '</th><th>' . esc_html__( 'Reason', 'yablonsky-seo' ) . '</th></tr></thead><tbody>';
            foreach ( array_slice( $details, 0, 20 ) as $detail ) {
                if ( ! is_array( $detail ) ) { continue; }
                echo '<tr><td>' . esc_html( (string) ( $detail['scope'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $detail['object_id'] ?? 0 ) ) . '</td><td><code>' . esc_html( (string) ( $detail['source_key'] ?? '' ) ) . '</code></td><td><code>' . esc_html( (string) ( $detail['target_key'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) ( $detail['reason'] ?? '' ) ) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }

    private function migrationReadiness( array $providers, ProviderAdapterRegistry $registry, ?MigrationState $state ): array {
        if ( empty( $providers ) ) {
            $rollback = get_option( 'yseo_takeover_rollback', null );
            if ( is_array( $rollback ) && 'cutover' === (string) ( $rollback['status'] ?? '' ) ) {
                return array( 'label' => __( 'Takeover complete', 'yablonsky-seo' ), 'detail' => __( 'Yablonsky SEO owns public authority', 'yablonsky-seo' ), 'tone' => 'good', 'badge' => __( 'LIVE', 'yablonsky-seo' ) );
            }
            return array( 'label' => __( 'Source provider ready to connect', 'yablonsky-seo' ), 'detail' => __( 'Nothing needs migration on the current provider stack.', 'yablonsky-seo' ), 'tone' => 'neutral', 'badge' => __( 'IDLE', 'yablonsky-seo' ) );
        }
        if ( 1 !== count( $providers ) || 'yoast' !== $providers[0]->id() ) {
            $portable = $this->portableActiveProfiles( $providers );
            if ( ! empty( $portable ) ) {
                return array( 'label' => __( 'Portable metadata ready', 'yablonsky-seo' ), 'detail' => __( 'Verified explicit source fields can be mirrored preserve-only while the current provider remains public authority. Automatic takeover activates only after a full parity and rollback contract.', 'yablonsky-seo' ), 'tone' => 'good', 'badge' => __( 'PORTABLE', 'yablonsky-seo' ) );
            }
            return array( 'label' => __( 'Audit only', 'yablonsky-seo' ), 'detail' => __( 'The detected provider is inventoried. Executable portability becomes available when a verified contract exists for its current storage surface.', 'yablonsky-seo' ), 'tone' => 'attention', 'badge' => __( 'AUDIT', 'yablonsky-seo' ) );
        }
        if ( null === $state ) {
            return array( 'label' => __( 'Ready for dry-run', 'yablonsky-seo' ), 'detail' => __( 'Yoast is detected. Start with a bounded lossless dry-run; source data remains unchanged.', 'yablonsky-seo' ), 'tone' => 'good', 'badge' => __( 'READY', 'yablonsky-seo' ) );
        }
        if ( MigrationState::STATUS_BLOCKED === $state->get( 'status' ) ) {
            return array( 'label' => __( 'Review required', 'yablonsky-seo' ), 'detail' => (string) $state->get( 'message' ), 'tone' => 'attention', 'badge' => __( 'STOPPED', 'yablonsky-seo' ) );
        }
        if ( $this->canOfferYoastShadowImport( $providers, $registry, $state ) ) {
            return array( 'label' => __( 'Shadow import ready', 'yablonsky-seo' ), 'detail' => __( 'Lossless coverage and verified rollback prerequisites are satisfied for the current source identity.', 'yablonsky-seo' ), 'tone' => 'good', 'badge' => __( 'VERIFIED', 'yablonsky-seo' ) );
        }
        return array( 'label' => __( 'Evidence in progress', 'yablonsky-seo' ), 'detail' => __( 'The migration workspace is collecting or validating the gates required before any shadow import.', 'yablonsky-seo' ), 'tone' => 'neutral', 'badge' => __( 'SAFE', 'yablonsky-seo' ) );
    }

    /** @return array<int,string> */
    private function publicContentTypes(): array {
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        $labels = array();
        foreach ( $objects as $object ) {
            if ( ! is_object( $object ) || 'attachment' === (string) $object->name ) {
                continue;
            }
            $labels[] = isset( $object->labels->name ) ? (string) $object->labels->name : (string) $object->name;
        }
        sort( $labels, SORT_NATURAL | SORT_FLAG_CASE );
        return $labels;
    }

    /** @param array<string,mixed> $settings */
    private function previewTitle( array $settings ): string {
        $value = isset( $settings['home_title'] ) ? trim( (string) $settings['home_title'] ) : '';
        if ( '' !== $value ) {
            return $value;
        }
        $name = isset( $settings['site_display_name'] ) ? trim( (string) $settings['site_display_name'] ) : '';
        return '' !== $name ? $name : (string) get_bloginfo( 'name' );
    }

    /** @param array<string,mixed> $settings */
    private function previewDescription( array $settings ): string {
        $value = isset( $settings['home_description'] ) ? trim( (string) $settings['home_description'] ) : '';
        if ( '' !== $value ) {
            return $value;
        }
        $value = isset( $settings['site_description'] ) ? trim( (string) $settings['site_description'] ) : '';
        return '' !== $value ? $value : (string) get_bloginfo( 'description' );
    }

    /** @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers */
    private function providerLabel( array $providers ): string {
        if ( empty( $providers ) ) {
            return __( 'Provider scan clear', 'yablonsky-seo' );
        }

        $names = array();
        foreach ( $providers as $provider ) {
            $label = $provider->name();
            if ( '' !== $provider->version() ) {
                $label .= ' ' . $provider->version();
            }
            $names[] = $label;
        }
        return implode( ', ', $names );
    }

    /**
     * @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers
     */
    private function renderAdapterMatrix( array $providers, ProviderAdapterRegistry $registry ): void {
        if ( empty( $providers ) ) {
            return;
        }
        echo '<div class="yps-adapter-list">';
        foreach ( $providers as $provider ) {
            $adapter = $registry->forProvider( $provider );
            $compatibility = null === $adapter ? 'unsupported' : $adapter->compatibility( $provider );
            $capabilities = null !== $adapter ? $adapter->capabilityMatrix() : array();
            if ( null !== $adapter && $adapter->canExecuteShadowImport( $provider ) ) {
                $importState = __( 'Full adapter verified · import safety gates required', 'yablonsky-seo' );
            } elseif ( isset( $capabilities['post_meta'] ) && 'preserve-only-portable' === $capabilities['post_meta'] ) {
                $importState = __( 'Preserve-only metadata portability · takeover locked', 'yablonsky-seo' );
            } else {
                $importState = __( 'Audit only', 'yablonsky-seo' );
            }
            echo '<div class="yps-adapter-row"><p><strong>' . esc_html( $provider->name() ) . '</strong> ';
            if ( '' !== $provider->version() ) {
                echo esc_html( $provider->version() ) . ' — ';
            }
            echo esc_html( $compatibility ) . ' — ' . esc_html( $importState ) . '</p>';
            if ( ! empty( $capabilities ) ) {
                $postCapability = isset( $capabilities['post_meta'] ) ? (string) $capabilities['post_meta'] : 'unknown';
                $termCapability = isset( $capabilities['taxonomy'] ) ? (string) $capabilities['taxonomy'] : 'unknown';
                $cutoverCapability = isset( $capabilities['cutover'] ) ? (string) $capabilities['cutover'] : 'unknown';
                echo '<span class="yps-muted">' . esc_html__( 'Posts:', 'yablonsky-seo' ) . ' ' . esc_html( $postCapability ) . ' · ' . esc_html__( 'Terms:', 'yablonsky-seo' ) . ' ' . esc_html( $termCapability ) . ' · ' . esc_html__( 'Cutover:', 'yablonsky-seo' ) . ' ' . esc_html( $cutoverCapability ) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function engineCount(): int {
        try {
            return EngineRegistry::fromJsonFile( YPS_DIR . 'data/ai-engines.json' )->count();
        } catch ( \Throwable $exception ) {
            unset( $exception );
            return 0;
        }
    }

    /**
     * @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers
     */
    private function canOfferYoastShadowImport( array $providers, ProviderAdapterRegistry $registry, ?MigrationState $state = null ): bool {
        if ( 1 !== count( $providers ) || 'yoast' !== $providers[0]->id() ) {
            return false;
        }
        $adapter = $registry->forProvider( $providers[0] );
        if ( null === $adapter || ! $adapter->canExecuteShadowImport( $providers[0] ) ) {
            return false;
        }
        if ( null === $state ) {
            return false;
        }
        return MigrationState::MODE_DRY_RUN === $state->get( 'mode' )
            && MigrationState::STATUS_COMPLETE === $state->get( 'status' )
            && (bool) $state->get( 'source_coverage_ready' )
            && (bool) $state->get( 'backup_export_ready' )
            && (bool) $state->get( 'backup_export_verified' )
            && (bool) $state->get( 'shadow_import_ready' )
            && $this->hasFreshVerifiedBackupAnchor( $providers[0]->version(), (string) $state->get( 'source_digest' ) );
    }

    private function hasFreshVerifiedBackupAnchor( string $providerVersion, string $dryRunDigest ): bool {
        $anchor = ( new BackupExportRepository() )->loadAnchor();
        return is_array( $anchor )
            && ( new BackupAnchorVerifier() )->verifyFreshForImport( $anchor, $providerVersion, $dryRunDigest, 900 );
    }

    /** YSEO_229_AI_CONTENT_PLANNER=PASS — bounded, explicit and review-only. */
    public function ajaxAiContentPlan(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Requesting a site content plan requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $languageInput = filter_input( INPUT_POST, 'language', FILTER_UNSAFE_RAW );
        $language = is_string( $languageInput ) ? sanitize_text_field( $languageInput ) : '';
        try {
            $plan = ( new ContentPlannerService() )->plan( $language );
            wp_send_json_success( array(
                'language' => (string) $plan['language'],
                'items' => (array) $plan['items'],
                'message' => esc_html__( 'Content plan ready for human review. Publishing remains editor-controlled.', 'yablonsky-seo' ),
            ) );
        } catch ( ServiceError $error ) {
            wp_send_json_error( array( 'message' => esc_html( $error->getMessage() ) ), 400 );
        } catch ( \Throwable $error ) {
            wp_send_json_error( array( 'message' => esc_html__( 'AI content planning stopped safely.', 'yablonsky-seo' ) ), 500 );
        }
    }

    public function ajaxStartMigration(): void {
        $this->guardAjax();

        if ( ! $this->detector->hasProvider( 'yoast' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Yoast SEO: activation required for live source migration.', 'yablonsky-seo' ) ), 409 );
        }

        $rawMode = filter_input( INPUT_POST, 'mode', FILTER_UNSAFE_RAW );
        $mode    = is_string( $rawMode ) ? sanitize_key( $rawMode ) : MigrationState::MODE_DRY_RUN;
        if ( ! in_array( $mode, array( MigrationState::MODE_DRY_RUN, MigrationState::MODE_IMPORT ), true ) ) {
            $mode = MigrationState::MODE_DRY_RUN;
        }

        if ( MigrationState::MODE_IMPORT === $mode ) {
            $providers = $this->detector->detectActiveProviders();
            if ( ! $this->canOfferYoastShadowImport( $providers, new ProviderAdapterRegistry(), ( new MigrationRepository() )->load() ) ) {
                wp_send_json_error(
                    array( 'message' => esc_html__( 'Shadow import is locked until the provider adapter is verified, a successful lossless dry-run exists, and a durable backup/export anchor is ready.', 'yablonsky-seo' ) ),
                    409
                );
            }
        }

        $repository = new MigrationRepository();
        $lockToken = $this->acquireMigrationStepLock( $repository );
        try {
            $service = new YoastMigrationService( $repository );
            $state   = $service->start( $mode );
        } finally {
            $repository->releaseStepLock( $lockToken );
        }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxStepMigration(): void {
        $this->guardAjax();

        if ( ! $this->detector->hasProvider( 'yoast' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Yoast SEO: activation required for live source migration.', 'yablonsky-seo' ) ), 409 );
        }

        $repository = new MigrationRepository();
        $lockToken = $this->acquireMigrationStepLock( $repository );
        try {
            $service = new YoastMigrationService( $repository );
            $state   = $service->step();
        } finally {
            $repository->releaseStepLock( $lockToken );
        }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxResetMigration(): void {
        $this->guardAjax();
        $repository = new MigrationRepository();
        $lockToken = $this->acquireMigrationStepLock( $repository );
        try {
            $repository->clear();
        } finally {
            $repository->releaseStepLock( $lockToken );
        }
        wp_send_json_success( array( 'status' => 'reset' ) );
    }

    public function ajaxInspectMigrationConflicts(): void {
        $this->guardAjax();
        $state = ( new MigrationRepository() )->load();
        $inspection = ( new MigrationConflictInspector() )->inspect( $state );
        wp_send_json_success( $inspection );
    }

    /** @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers */
    private function canPrepareYoastWorkflow( array $providers, ProviderAdapterRegistry $registry ): bool {
        if ( 1 !== count( $providers ) || 'yoast' !== $providers[0]->id() ) {
            return false;
        }
        $adapter = $registry->forProvider( $providers[0] );
        return null !== $adapter && $adapter->canExecuteShadowImport( $providers[0] );
    }

    private function isCurrentMigrationState( ?MigrationState $state ): bool {
        return null !== $state && YoastMigrationService::CONTRACT_REVISION === (string) $state->get( 'contract_revision' );
    }

    private function canOfferYoastBackup( ?MigrationState $state ): bool {
        return $this->isCurrentMigrationState( $state )
            && MigrationState::MODE_DRY_RUN === $state->get( 'mode' )
            && MigrationState::STATUS_COMPLETE === $state->get( 'status' )
            && (bool) $state->get( 'source_coverage_ready' );
    }

    public function ajaxStartMetadataMirror(): void {
        $this->guardAjax();
        if ( ! $this->detector->hasProvider( 'yoast' ) ) { wp_send_json_error( array( 'message' => esc_html__( 'Yoast SEO: activation required for live source migration.', 'yablonsky-seo' ) ), 409 ); }
        $rawScope = filter_input( INPUT_POST, 'scope', FILTER_UNSAFE_RAW );
        $scope = is_string( $rawScope ) && MetadataMirrorState::SCOPE_ALL === sanitize_key( $rawScope ) ? MetadataMirrorState::SCOPE_ALL : MetadataMirrorState::SCOPE_SEARCH;
        $repository = new MetadataMirrorRepository();
        $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another metadata mirror step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 400 ), 429 ); }
        try { $state = ( new MetadataMirrorService( $repository ) )->start( $scope ); }
        finally { $repository->releaseLock( $token ); }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxStepMetadataMirror(): void {
        $this->guardAjax();
        if ( ! $this->detector->hasProvider( 'yoast' ) ) { wp_send_json_error( array( 'message' => esc_html__( 'Yoast SEO: activation required for live source migration.', 'yablonsky-seo' ) ), 409 ); }
        $repository = new MetadataMirrorRepository(); $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another metadata mirror step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 400 ), 429 ); }
        try { $state = ( new MetadataMirrorService( $repository ) )->step(); }
        finally { $repository->releaseLock( $token ); }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxResetMetadataMirror(): void {
        $this->guardAjax(); $repository = new MetadataMirrorRepository(); $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another metadata mirror step is already running.', 'yablonsky-seo' ) ), 429 ); }
        try { $repository->clear(); BulkMetadataRunner::clearSchedule(); } finally { $repository->releaseLock( $token ); }
        wp_send_json_success( array( 'status' => 'reset' ) );
    }

    /**
     * @param array<int,\Yablonsky\PerformanceSEO\Runtime\Provider> $providers
     * @return array<int,\Yablonsky\PerformanceSEO\Migration\Portability\PortableSourceProfile>
     */
    private function portableActiveProfiles( array $providers ): array {
        $registry = new PortableSourceRegistry();
        $profiles = array();
        foreach ( $providers as $provider ) {
            $profile = $registry->get( $provider->id() );
            if ( null !== $profile ) { $profiles[] = $profile; }
        }
        return $profiles;
    }

    public function ajaxStartPortableMirror(): void {
        $this->guardAjax();
        $rawProvider = filter_input( INPUT_POST, 'provider_id', FILTER_UNSAFE_RAW );
        $providerId = is_string( $rawProvider ) ? sanitize_key( $rawProvider ) : '';
        $profile = ( new PortableSourceRegistry() )->get( $providerId );
        if ( null === $profile || ! $this->detector->hasProvider( $providerId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Selected verified portability source: activation required.', 'yablonsky-seo' ) ), 409 );
        }
        $repository = new PortableMirrorRepository();
        $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another preserve-only portability step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 400 ), 429 ); }
        try { $state = ( new PortableMirrorService( $repository ) )->start( $providerId ); }
        finally { $repository->releaseLock( $token ); }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxStepPortableMirror(): void {
        $this->guardAjax();
        $repository = new PortableMirrorRepository();
        $saved = $repository->load();
        if ( null === $saved ) { wp_send_json_error( array( 'message' => esc_html__( 'Start a preserve-only portability job first.', 'yablonsky-seo' ) ), 409 ); }
        $providerId = sanitize_key( (string) $saved->get( 'provider_id' ) );
        if ( '' === $providerId || ! $this->detector->hasProvider( $providerId ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'The portability job stopped because the source provider became inactive. Public authority remains unchanged.', 'yablonsky-seo' ) ), 409 );
        }
        $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another preserve-only portability step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 400 ), 429 ); }
        try { $state = ( new PortableMirrorService( $repository ) )->step(); }
        finally { $repository->releaseLock( $token ); }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxResetPortableMirror(): void {
        $this->guardAjax();
        $repository = new PortableMirrorRepository();
        $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another preserve-only portability step is already running.', 'yablonsky-seo' ) ), 429 ); }
        try { $repository->clear(); BulkMetadataRunner::clearSchedule(); } finally { $repository->releaseLock( $token ); }
        wp_send_json_success( array( 'status' => 'reset' ) );
    }

    private function renderPortableMirrorState( ?PortableMirrorState $state ): void {
        if ( null === $state ) { echo '<p>' . esc_html__( 'Preserve-only portability job ready to start.', 'yablonsky-seo' ) . '</p>'; return; }
        $data = $state->toArray();
        echo '<ul>';
        echo '<li>' . esc_html__( 'Source provider:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['provider_label'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Status:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['status'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Phase:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['phase'] ) . '</li>';
        echo '<li>' . esc_html__( 'Posts scanned:', 'yablonsky-seo' ) . ' ' . esc_html( number_format_i18n( (int) $data['processed_posts'] ) ) . '</li>';
        echo '<li>' . esc_html__( 'Terms scanned:', 'yablonsky-seo' ) . ' ' . esc_html( number_format_i18n( (int) $data['processed_terms'] ) ) . '</li>';
        echo '<li>' . esc_html__( 'Verified source values:', 'yablonsky-seo' ) . ' ' . esc_html( number_format_i18n( (int) $data['source_values'] ) ) . '</li>';
        echo '<li>' . esc_html__( 'Copied into open YSEO fields:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['copied_values'] ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Already matched:', 'yablonsky-seo' ) . ' ' . esc_html( number_format_i18n( (int) $data['matched_values'] ) ) . '</li>';
        echo '<li>' . esc_html__( 'Existing YSEO values preserved:', 'yablonsky-seo' ) . ' ' . esc_html( number_format_i18n( (int) $data['preserved_values'] ) ) . '</li>';
        echo '</ul>';
        if ( ! empty( $data['message'] ) ) { echo '<p>' . esc_html( (string) $data['message'] ) . '</p>'; }
    }

    public function ajaxStartAiMetadata(): void {
        $this->guardAjax();
        $rawPolicy = filter_input( INPUT_POST, 'policy', FILTER_UNSAFE_RAW );
        $policy = is_string( $rawPolicy ) ? sanitize_key( $rawPolicy ) : BulkMetadataState::POLICY_FILL_MISSING;
        if ( ! in_array( $policy, array( BulkMetadataState::POLICY_FILL_MISSING, BulkMetadataState::POLICY_AI_GENERATED, BulkMetadataState::POLICY_REPLACE ), true ) ) {
            $policy = BulkMetadataState::POLICY_FILL_MISSING;
        }
        $rawFields = filter_input( INPUT_POST, 'fields', FILTER_UNSAFE_RAW );
        $fields = array();
        if ( is_string( $rawFields ) && '' !== $rawFields ) {
            foreach ( explode( ',', $rawFields ) as $field ) { $fields[] = sanitize_key( $field ); }
        }
        $rawLanguages = filter_input( INPUT_POST, 'languages', FILTER_UNSAFE_RAW );
        $languages = array();
        if ( is_string( $rawLanguages ) && '' !== $rawLanguages ) {
            foreach ( explode( ',', $rawLanguages ) as $language ) { $languages[] = sanitize_text_field( $language ); }
        }
        $repository = new BulkMetadataRepository(); $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another AI metadata step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 750 ), 429 ); }
        try { $state = ( new BulkMetadataService( $repository, null, $this->detector ) )->start( $policy, $fields, $languages ); }
        finally { $repository->releaseLock( $token ); }
        if ( BulkMetadataState::STATUS_BLOCKED === $state->get( 'status' ) ) { wp_send_json_error( array( 'message' => esc_html( (string) $state->get( 'message' ) ) ), 409 ); }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxStepAiMetadata(): void {
        $this->guardAjax(); $repository = new BulkMetadataRepository(); $token = $repository->acquireLock( 300 );
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another AI metadata step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 750 ), 429 ); }
        try {
            $service = new BulkMetadataService( $repository, null, $this->detector );
            $current = $repository->load();
            if ( null !== $current && BulkMetadataState::STATUS_PAUSED === $current->get( 'status' ) ) { $service->resume(); }
            $state = $service->step();
        } finally { $repository->releaseLock( $token ); }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxPauseAiMetadata(): void {
        $this->guardAjax(); $repository = new BulkMetadataRepository(); $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another AI metadata step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 750 ), 429 ); }
        try { $state = ( new BulkMetadataService( $repository, null, $this->detector ) )->pause(); }
        finally { $repository->releaseLock( $token ); }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxResetAiMetadata(): void {
        $this->guardAjax(); $repository = new BulkMetadataRepository(); $token = $repository->acquireLock();
        if ( null === $token ) { wp_send_json_error( array( 'message' => esc_html__( 'Another AI metadata step is already running.', 'yablonsky-seo' ) ), 429 ); }
        try { $repository->clear(); BulkMetadataRunner::clearSchedule(); } finally { $repository->releaseLock( $token ); }
        wp_send_json_success( array( 'status' => 'reset' ) );
    }

    public function ajaxStartBackup(): void {
        $this->guardAjax();
        $migrationRepository = new MigrationRepository();
        if ( ! $this->canOfferYoastBackup( $migrationRepository->load() ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'A fresh successful lossless dry-run is required before backup/export.', 'yablonsky-seo' ) ), 409 );
        }
        $repository = new BackupExportRepository();
        $token = $repository->acquireLock( 60 );
        if ( null === $token ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Another backup/export step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 500 ), 429 );
        }
        try {
            $state = ( new YoastBackupExportService( $repository, $migrationRepository ) )->start();
        } finally {
            $repository->releaseLock( $token );
        }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxStepBackup(): void {
        $this->guardAjax();
        $repository = new BackupExportRepository();
        $token = $repository->acquireLock( 60 );
        if ( null === $token ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Another backup/export step is already running.', 'yablonsky-seo' ), 'retry_after_ms' => 500 ), 429 );
        }
        try {
            $state = ( new YoastBackupExportService( $repository, new MigrationRepository() ) )->step();
        } finally {
            $repository->releaseLock( $token );
        }
        wp_send_json_success( $state->toArray() );
    }

    public function ajaxResetBackup(): void {
        $this->guardAjax();
        $repository = new BackupExportRepository();
        $token = $repository->acquireLock( 60 );
        if ( null === $token ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Another backup/export step is already running.', 'yablonsky-seo' ) ), 429 );
        }
        try {
            $repository->clear();
        } finally {
            $repository->releaseLock( $token );
        }
        wp_send_json_success( array( 'status' => 'reset' ) );
    }

    public function ajaxTakeover(): void {
        $this->guardAjax();
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Changing plugin authority requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $result = ( new YoastTakeoverManager( new MigrationRepository(), $this->detector ) )->cutover();
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 409 );
        }
        wp_send_json_success( $result );
    }

    public function ajaxTakeoverRollback(): void {
        $this->guardAjax();
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Changing plugin authority requires the corresponding WordPress capability.', 'yablonsky-seo' ) ), 403 );
        }
        $result = ( new YoastTakeoverManager( new MigrationRepository(), $this->detector ) )->rollback();
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 409 );
        }
        wp_send_json_success( $result );
    }

    private function renderMirrorState( ?MetadataMirrorState $state ): void {
        if ( null === $state ) { echo '<p>' . esc_html__( 'Metadata mirror job ready to start.', 'yablonsky-seo' ) . '</p>'; return; }
        $data = $state->toArray();
        echo '<ul><li>' . esc_html__( 'Status:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['status'] ) . '</strong></li><li>' . esc_html__( 'Scope:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['scope'] ) . '</strong></li><li>' . esc_html__( 'Phase:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['phase'] ) . '</strong></li><li>' . esc_html__( 'Content scanned:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['processed_posts'] ) ) . '</strong></li><li>' . esc_html__( 'Exact values copied:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['copied_values'] ) ) . '</strong></li><li>' . esc_html__( 'Already matched:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['matched_values'] ) ) . '</strong></li><li>' . esc_html__( 'Existing YSEO values preserved:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['preserved_values'] ) ) . '</strong></li></ul>';
        if ( ! empty( $data['message'] ) ) { echo '<p>' . esc_html( (string) $data['message'] ) . '</p>'; }
    }

    private function renderAiMetadataState( ?BulkMetadataState $state ): void {
        if ( null === $state ) { echo '<p>' . esc_html__( 'Site-wide AI metadata job ready to start.', 'yablonsky-seo' ) . '</p>'; return; }
        $data = $state->toArray();
        $fields = isset( $data['fields'] ) && is_array( $data['fields'] ) ? implode( ', ', array_map( 'strval', $data['fields'] ) ) : '';
        $languages = isset( $data['languages'] ) && is_array( $data['languages'] ) ? implode( ', ', array_map( 'strval', $data['languages'] ) ) : 'all';
        echo '<ul>';
        echo '<li>' . esc_html__( 'Status:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['status'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Phase:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) ( $data['phase'] ?? '' ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Policy:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['policy'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Resource languages:', 'yablonsky-seo' ) . ' <strong>' . esc_html( $languages ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Selected fields:', 'yablonsky-seo' ) . ' <strong>' . esc_html( $fields ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Progress:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) (int) ( $data['progress_percent'] ?? 0 ) ) . '%</strong></li>';
        echo '<li>' . esc_html__( 'Resources processed:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['processed_posts'] ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Posts generated:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['generated_posts'] ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Fields applied:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['updated_fields'] ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Review drafts staged:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) ( $data['drafted_fields'] ?? 0 ) ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Fields preserved:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['preserved_fields'] ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Yoast source values mirrored first:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) ( $data['mirrored_source_values'] ?? 0 ) ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Resources failed:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) $data['failed_posts'] ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Elapsed seconds:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) ( $data['elapsed_seconds'] ?? 0 ) ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Estimated remaining seconds:', 'yablonsky-seo' ) . ' <strong>' . esc_html( number_format_i18n( (int) ( $data['eta_seconds'] ?? 0 ) ) ) . '</strong></li>';
        echo '</ul>';
        if ( ! empty( $data['message'] ) ) { echo '<p>' . esc_html( (string) $data['message'] ) . '</p>'; }
    }

    private function renderBackupState( ?BackupExportState $state ): void {
        echo '<h3>' . esc_html__( 'Private rollback backup', 'yablonsky-seo' ) . '</h3>';
        if ( null === $state ) {
            echo '<p>' . esc_html__( 'Migration backup/export is ready to start for the current evidence set.', 'yablonsky-seo' ) . '</p>';
            return;
        }
        $data = $state->toArray();
        echo '<ul>';
        echo '<li>' . esc_html__( 'Status:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['status'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Phase:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['phase'] ) . '</li>';
        echo '<li>' . esc_html__( 'Exported records:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['export_records'] ) . '</li>';
        echo '<li>' . esc_html__( 'Verified records:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['verify_records'] ) . '</li>';
        echo '<li>' . esc_html__( 'Anchor:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['anchor_id'] ) . '</li>';
        echo '</ul>';
        if ( ! empty( $data['blockers'] ) && is_array( $data['blockers'] ) ) {
            echo '<p><strong>' . esc_html__( 'Backup blockers:', 'yablonsky-seo' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $data['blockers'] ) ) ) . '</p>';
        }
        if ( ! empty( $data['message'] ) ) {
            echo '<p>' . esc_html( (string) $data['message'] ) . '</p>';
        }
    }

    private function acquireMigrationStepLock( MigrationRepository $repository ): string {
        $token = $repository->acquireStepLock( 45 );
        if ( null === $token ) {
            wp_send_json_error(
                array(
                    'message'        => esc_html__( 'Another Yablonsky SEO migration step is already running. The job will retry instead of multiplying origin work.', 'yablonsky-seo' ),
                    'retry_after_ms' => 500,
                ),
                429
            );
        }
        return (string) $token;
    }

    private function guardAjax(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions.', 'yablonsky-seo' ) ), 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
    }

    private function renderState( ?MigrationState $state ): void {
        if ( null === $state ) {
            echo '<p>' . esc_html__( 'Migration job ready to start.', 'yablonsky-seo' ) . '</p>';
            return;
        }

        $data = $state->toArray();
        echo '<ul>';
        echo '<li>' . esc_html__( 'Mode:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['mode'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Status:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['status'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Phase:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['phase'] ) . '</li>';
        echo '<li>' . esc_html__( 'Posts scanned:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['processed_posts'] ) . '</li>';
        echo '<li>' . esc_html__( 'Author/user meta scanned:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['processed_user_meta'] ) . '</li>';
        echo '<li>' . esc_html__( 'Terms scanned:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['processed_terms'] ) . '</li>';
        echo '<li>' . esc_html__( 'Mapped values:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['mapped_values'] ) . '</li>';
        echo '<li>' . esc_html__( 'Global settings mapped:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['global_mapped_values'] ) . '</li>';
        echo '<li>' . esc_html__( 'Ignored derived values:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['ignored_derived_values'] ) . '</li>';
        echo '<li>' . esc_html__( 'Imported values:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['imported_values'] ) . '</li>';
        echo '<li>' . esc_html__( 'Verified values:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['verified_values'] ) . '</li>';
        echo '<li>' . esc_html__( 'Conflicts:', 'yablonsky-seo' ) . ' <strong>' . esc_html( (string) $data['conflicts'] ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Adaptive batch:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['adaptive_batch_size'] ) . '</li>';
        echo '<li>' . esc_html__( 'Last step:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['last_step_ms'] ) . ' ms · ' . esc_html( (string) $data['last_step_queries'] ) . ' queries · ' . esc_html( (string) $data['last_step_memory_delta'] ) . ' B memory delta</li>';
        echo '<li>' . esc_html__( 'Origin pressure:', 'yablonsky-seo' ) . ' ' . esc_html( (string) $data['pressure_state'] ) . ' · ' . esc_html( (string) $data['pressure_reason'] ) . '</li>';
        echo '<li>' . esc_html__( 'Lossless source coverage:', 'yablonsky-seo' ) . ' <strong>' . esc_html( ! empty( $data['source_coverage_ready'] ) ? __( 'Ready', 'yablonsky-seo' ) : __( 'Preparation required', 'yablonsky-seo' ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Backup/export anchor:', 'yablonsky-seo' ) . ' <strong>' . esc_html( ! empty( $data['backup_export_ready'] ) ? __( 'Ready', 'yablonsky-seo' ) : __( 'Required before shadow import', 'yablonsky-seo' ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Source immutability:', 'yablonsky-seo' ) . ' <strong>' . esc_html( ! empty( $data['source_immutable_ready'] ) ? __( 'Verified', 'yablonsky-seo' ) : __( 'Verify to activate', 'yablonsky-seo' ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Shadow data parity:', 'yablonsky-seo' ) . ' <strong>' . esc_html( ! empty( $data['shadow_data_parity_ready'] ) ? __( 'Verified', 'yablonsky-seo' ) : __( 'Verify to activate', 'yablonsky-seo' ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'Takeover gate:', 'yablonsky-seo' ) . ' <strong>' . esc_html( ! empty( $data['cutover_ready'] ) ? __( 'Ready', 'yablonsky-seo' ) : __( 'Locked', 'yablonsky-seo' ) ) . '</strong></li>';
        echo '</ul>';

        if ( ! empty( $data['import_blockers'] ) && is_array( $data['import_blockers'] ) ) {
            echo '<p><strong>' . esc_html__( 'Shadow import blockers:', 'yablonsky-seo' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $data['import_blockers'] ) ) ) . '</p>';
        }

        if ( ! empty( $data['readiness_blockers'] ) && is_array( $data['readiness_blockers'] ) ) {
            echo '<p><strong>' . esc_html__( 'Readiness blockers:', 'yablonsky-seo' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $data['readiness_blockers'] ) ) ) . '</p>';
        }
        if ( ! empty( $data['unsupported_source_fields'] ) && is_array( $data['unsupported_source_fields'] ) ) {
            echo '<p><strong>' . esc_html__( 'Unsupported source settings (sample):', 'yablonsky-seo' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $data['unsupported_source_fields'] ) ) ) . '</p>';
        }
        if ( ! empty( $data['unknown_source_fields'] ) && is_array( $data['unknown_source_fields'] ) ) {
            echo '<p><strong>' . esc_html__( 'Unknown Yoast fields found (sample):', 'yablonsky-seo' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $data['unknown_source_fields'] ) ) ) . '</p>';
        }
        if ( ! empty( $data['message'] ) ) {
            echo '<p>' . esc_html( (string) $data['message'] ) . '</p>';
        }
    }
}
