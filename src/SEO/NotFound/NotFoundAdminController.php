<?php
namespace Yablonsky\PerformanceSEO\SEO\NotFound;

use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;
use Yablonsky\PerformanceSEO\SEO\Redirects\RedirectController;
use Yablonsky\PerformanceSEO\SEO\Redirects\RedirectRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NotFoundAdminController {
    private const PAGE = 'yablonsky-seo-404-monitor';
    /** @var NotFoundRepository */ private $repository;
    public function __construct( ?NotFoundRepository $repository = null ) { $this->repository = $repository instanceof NotFoundRepository ? $repository : new NotFoundRepository(); }
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_yseo_404_delete', array( $this, 'delete' ) );
        add_action( 'admin_post_yseo_404_clear', array( $this, 'clear' ) );
        add_action( 'admin_post_yseo_404_cleanup_noise', array( $this, 'cleanupNoise' ) );
        add_action( 'admin_post_yseo_404_run_maintenance', array( $this, 'runMaintenance' ) );
        add_action( 'admin_post_yseo_404_clear_nonseo', array( $this, 'clearNonSeo' ) );
        add_action( 'admin_post_yseo_404_bulk', array( $this, 'bulkAction' ) );
        add_action( 'admin_post_yseo_404_export', array( $this, 'exportCsv' ) );
    }
    public function menu(): void { add_submenu_page( 'yablonsky-seo', __( '404 Monitor', 'yablonsky-seo' ), __( '404 Monitor', 'yablonsky-seo' ), 'manage_options', self::PAGE, array( $this, 'render' ) ); }
    public function assets( string $hook ): void { if ( 'yablonsky-seo_page_' . self::PAGE !== $hook ) { return; } wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION ); wp_enqueue_script( 'yps-admin-editor', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) ); }
    public function delete(): void { $this->guard( 'yseo_404_delete' ); $id = (int) filter_input( INPUT_GET, 'id', FILTER_VALIDATE_INT ); $this->repository->delete( $id ); $this->back(); }
    public function clear(): void { $this->guard( 'yseo_404_clear' ); $this->repository->clear(); $this->back( 'cleared' ); }
    public function cleanupNoise(): void { $this->guard( 'yseo_404_cleanup_noise' ); $report = $this->repository->purgeLowValueNoise(); $this->back( 'noise-cleaned-' . (string) (int) $report['rows_removed'] ); }
    public function runMaintenance(): void { $this->guard( 'yseo_404_run_maintenance' ); $report = $this->repository->pruneAdaptive(); $this->back( 'maintenance-' . (string) (int) ( $report['rows_removed'] ?? 0 ) ); }
    public function clearNonSeo(): void { $this->guard( 'yseo_404_clear_nonseo' ); $report = $this->repository->purgeNonSeoBacklog(); $this->back( 'nonseo-cleared-' . (string) (int) $report['rows_removed'] ); }

    public function bulkAction(): void {
        $this->guard( 'yseo_404_bulk' );
        $action = sanitize_key( (string) filter_input( INPUT_POST, 'bulk_action', FILTER_UNSAFE_RAW ) );
        $rawIds = filter_input( INPUT_POST, 'yseo_ids', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
        $rawIds = is_array( $rawIds ) ? $rawIds : array();
        $ids = array_values( array_slice( array_unique( array_filter( array_map( 'absint', $rawIds ) ) ), 0, 100 ) );
        if ( empty( $ids ) || ! in_array( $action, array( 'dismiss', 'gone', 'redirect' ), true ) ) { $this->back( 'bulk-none' ); }
        if ( 'dismiss' === $action ) {
            $count = $this->repository->deleteMany( $ids );
            $this->back( 'bulk-dismissed-' . $count );
        }

        $rows = $this->repository->rowsByIds( $ids );
        $redirectRepository = new RedirectRepository();
        $validator = new RedirectController( $redirectRepository );
        $target = '';
        if ( 'redirect' === $action ) {
            $targetRaw = trim( (string) filter_input( INPUT_POST, 'bulk_target', FILTER_UNSAFE_RAW ) );
            $target = $validator->normalizeTarget( $targetRaw );
            if ( '' === $target ) { $this->back( 'bulk-invalid-target' ); }
        }
        $completed = array();
        foreach ( $rows as $row ) {
            $id = max( 0, (int) ( $row['id'] ?? 0 ) );
            $origin = (string) ( $row['path'] ?? '' );
            if ( $id <= 0 || NotFoundSignalClassifier::SEO !== (string) ( $row['signal_kind'] ?? '' ) || ! NotFoundSignalClassifier::isRedirectable( $origin ) ) { continue; }
            $language = sanitize_text_field( (string) ( $row['language_key'] ?? '' ) );
            if ( '' === $language ) { $language = 'any'; }
            $savedId = $redirectRepository->save( array(
                'origin' => $validator->normalizeOrigin( $origin ),
                'target' => 'gone' === $action ? '' : $target,
                'status_code' => 'gone' === $action ? 410 : 301,
                'match_type' => 'plain',
                'language_key' => $language,
                'enabled' => 1,
                'source' => '404-bulk',
            ) );
            // Preserve the 404 evidence if the redirect table write failed.
            if ( $savedId <= 0 ) { continue; }
            $completed[] = $id;
        }
        $removed = ! empty( $completed ) ? $this->repository->deleteMany( $completed ) : 0;
        // Success counters must describe confirmed 404 removals, not merely
        // saved redirect rules when the final DELETE failed.
        $this->back( 'bulk-' . $action . '-' . $removed );
    }

    public function exportCsv(): void {
        $this->guard( 'yseo_404_export' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="yablonsky-seo-404-monitor-' . gmdate( 'Ymd-His' ) . '.csv"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $this->csvLine( array( __( 'Path', 'yablonsky-seo' ), __( 'Signal', 'yablonsky-seo' ), __( 'Language', 'yablonsky-seo' ), __( 'Hits', 'yablonsky-seo' ), __( 'First seen', 'yablonsky-seo' ) . ' UTC', __( 'Last seen', 'yablonsky-seo' ) . ' UTC' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV fields are quoted/escaped by csvLine().
        $afterId = 0;
        do {
            $rows = $this->repository->exportBatch( $afterId, 500 );
            foreach ( $rows as $row ) {
                $line = $this->csvLine( array(
                    (string) ( $row['path'] ?? '' ),
                    (string) ( $row['signal_kind'] ?? 'seo' ),
                    (string) ( $row['language_key'] ?? '' ),
                    (string) (int) ( $row['hits'] ?? 0 ),
                    (string) ( $row['first_seen_gmt'] ?? '' ),
                    (string) ( $row['last_seen_gmt'] ?? '' ),
                ) );
                echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV fields are quoted/escaped by csvLine().
                $afterId = max( $afterId, (int) ( $row['id'] ?? 0 ) );
            }
        } while ( 500 === count( $rows ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accessing this page requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        $search = trim( sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_search', FILTER_UNSAFE_RAW ) ) );
        $language = strtolower( str_replace( '_', '-', sanitize_text_field( (string) filter_input( INPUT_GET, 'language', FILTER_UNSAFE_RAW ) ) ) );
        $dateFrom = sanitize_text_field( (string) filter_input( INPUT_GET, 'date_from', FILTER_UNSAFE_RAW ) );
        $dateTo = sanitize_text_field( (string) filter_input( INPUT_GET, 'date_to', FILTER_UNSAFE_RAW ) );
        $minHits = max( 0, (int) filter_input( INPUT_GET, 'min_hits', FILTER_VALIDATE_INT ) );
        $sort = sanitize_key( (string) filter_input( INPUT_GET, 'sort', FILTER_UNSAFE_RAW ) );
        $signal = sanitize_key( (string) filter_input( INPUT_GET, 'signal', FILTER_UNSAFE_RAW ) );
        if ( '' === $signal ) { $signal = 'seo'; }
        $perPage = (int) filter_input( INPUT_GET, 'per_page', FILTER_VALIDATE_INT );
        if ( ! in_array( $perPage, array( 25, 50, 100 ), true ) ) { $perPage = 25; }
        $page = max( 1, (int) filter_input( INPUT_GET, 'yseo_paged', FILTER_VALIDATE_INT ) );
        $catalog = ( new ResourceLanguageIndex() )->catalog( true );
        $languageOptions = array();
        foreach ( $catalog as $key => $label ) { $languageOptions[ strtolower( (string) $key ) ] = (string) $label; }
        if ( '' !== $language && ! isset( $languageOptions[ $language ] ) ) { $language = ''; }
        if ( ! in_array( $sort, array( 'hits', 'recent', 'oldest', 'first_seen' ), true ) ) { $sort = 'hits'; }
        if ( ! in_array( $signal, array( 'seo', 'access', 'technical', 'probe', 'all' ), true ) ) { $signal = 'seo'; }
        $data = $this->repository->page( $page, $perPage, $search, $language, $dateFrom, $dateTo, $minHits, $sort, $signal );
        $counts = $this->repository->signalCounts();
        $diagnostics = $this->repository->diagnostics();
        $classifierStatus = $this->repository->classificationStatus();
        $classifierProgressText = '';
        if ( ! empty( $classifierStatus['pending'] ) ) {
            /* translators: 1: remaining aggregate count, 2: classifier batch size. */
            $classifierProgressText = sprintf( __( '%1$d rows remain; background batches process up to %2$d rows at a time.', 'yablonsky-seo' ), (int) $classifierStatus['remaining'], (int) $classifierStatus['batch'] );
        }
        $securityBridge = NotFoundSecurityBridge::status();
        $securityPlugin = NotFoundSecurityBridge::yablonskySecurityPluginState();
        $notice = sanitize_text_field( (string) filter_input( INPUT_GET, 'yseo_notice', FILTER_UNSAFE_RAW ) );
        $exportUrl = wp_nonce_url( admin_url( 'admin-post.php?action=yseo_404_export' ), 'yseo_404_export' );
        $cleanupUrl = wp_nonce_url( admin_url( 'admin-post.php?action=yseo_404_cleanup_noise' ), 'yseo_404_cleanup_noise' );
        $maintenanceUrl = wp_nonce_url( admin_url( 'admin-post.php?action=yseo_404_run_maintenance' ), 'yseo_404_run_maintenance' );
        $clearNonSeoUrl = wp_nonce_url( admin_url( 'admin-post.php?action=yseo_404_clear_nonseo' ), 'yseo_404_clear_nonseo' );
        $nonSeoRows = max( 0, (int) $counts['access'] + (int) $counts['technical'] + (int) $counts['probe'] );
        $retention = isset( $diagnostics['retention'] ) && is_array( $diagnostics['retention'] ) ? $diagnostics['retention'] : array();
        $lastRetention = isset( $diagnostics['last_retention_run'] ) && is_array( $diagnostics['last_retention_run'] ) ? $diagnostics['last_retention_run'] : array();
        $lastRetentionAt = sanitize_text_field( (string) ( $lastRetention['ran_at_gmt'] ?? '' ) );
        $classifiedRows = max( 0, (int) $diagnostics['total_rows'] - (int) ( $classifierStatus['remaining'] ?? 0 ) );
        $classifierPercent = (int) $diagnostics['total_rows'] > 0 ? min( 100, max( 0, (int) round( ( $classifiedRows / (int) $diagnostics['total_rows'] ) * 100 ) ) ) : 100;
        ?>
        <div class="wrap yps-wrap yps-product-shell yseo-404-monitor">
            <header class="yps-product-header"><div class="yps-product-title"><h1><?php echo esc_html__( '404 Monitor', 'yablonsky-seo' ); ?></h1><p><?php echo esc_html__( 'SEO-first unresolved URL triage. YSEO separates likely content 404s from technical requests and probe-like noise while retaining privacy-light aggregate evidence only.', 'yablonsky-seo' ); ?></p></div><div class="yps-product-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-redirects' ) ); ?>"><?php echo esc_html__( 'Redirect Manager', 'yablonsky-seo' ); ?></a><a class="button" href="<?php echo esc_url( $exportUrl ); ?>"><?php echo esc_html__( 'Export CSV', 'yablonsky-seo' ); ?></a></div></header>
            <?php if ( '' !== $notice ) : ?><div class="notice notice-success inline"><p><?php echo esc_html( $this->noticeText( $notice ) ); ?></p></div><?php endif; ?>
            <article class="yps-card yps-card-wide yseo-404-control-center">
                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Operations', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( '404 control center', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'SEO candidates are work for you. Scanner and infrastructure noise is work for YSEO and the security layer. Automatic protection stays active independently of this screen.', 'yablonsky-seo' ); ?></p></div><span class="yps-mini-status good"><?php echo esc_html__( 'Automatic protection ON', 'yablonsky-seo' ); ?></span></div>
                <div class="yseo-404-kpi-grid">
                    <a class="yseo-404-kpi is-seo" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'signal' => 'seo' ), admin_url( 'admin.php' ) ) ); ?>"><span><?php echo esc_html__( 'Needs SEO review', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $counts['seo'] ) ); ?></strong><small><?php echo esc_html__( 'Possible broken or moved content URLs', 'yablonsky-seo' ); ?></small></a>
                    <a class="yseo-404-kpi" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'signal' => 'all' ), admin_url( 'admin.php' ) ) ); ?>"><span><?php echo esc_html__( 'Security / technical noise', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( $nonSeoRows ) ); ?></strong><small><?php echo esc_html__( 'Access-like, technical and probe-like aggregates', 'yablonsky-seo' ); ?></small></a>
                    <div class="yseo-404-kpi"><span><?php echo esc_html__( 'New paths · 24h', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $diagnostics['new_24h'] ) ); ?></strong><small><?php echo esc_html__( 'High churn is expected during scanner bursts', 'yablonsky-seo' ); ?></small></div>
                    <div class="yseo-404-kpi <?php echo (float) $diagnostics['storage_pressure_pct'] > 100 ? 'is-attention' : 'is-good'; ?>"><span><?php echo esc_html__( 'Storage guard', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (float) $diagnostics['storage_pressure_pct'], 0 ) . '%' ); ?></strong><small><?php echo esc_html( (float) $diagnostics['storage_pressure_pct'] > 100 ? __( 'Above soft cap · automatic trimming applies', 'yablonsky-seo' ) : __( 'Within automatic operating range', 'yablonsky-seo' ) ); ?></small></div>
                </div>
                <div class="yseo-404-operations-grid">
                    <section class="yseo-404-operation-card">
                        <div class="yseo-404-operation-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Automatic fail-safe', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'Storage manages itself', 'yablonsky-seo' ); ?></h3></div><span class="yps-mini-status good"><?php echo esc_html__( 'Hourly', 'yablonsky-seo' ); ?></span></div>
                        <p><?php echo esc_html__( 'Low-value scanner churn expires automatically. If non-SEO storage crosses the soft cap, YSEO trims old low-value rows. A separate hard ceiling runs during live scanner traffic even when WP-Cron is delayed. SEO candidates remain protected by both guards.', 'yablonsky-seo' ); ?></p>
                        <div class="yseo-404-policy-grid"><div><span><?php echo esc_html__( 'Soft cap', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) ( $retention['nonseo_soft_cap'] ?? 10000 ) ) ); ?></strong></div><div><span><?php echo esc_html__( 'Trim target', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) ( $retention['nonseo_target'] ?? 8000 ) ) ); ?></strong></div><div><span><?php echo esc_html__( 'Hard ceiling', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) ( $retention['nonseo_hard_cap'] ?? 20000 ) ) ); ?></strong></div><div><span><?php echo esc_html__( 'Low-value', 'yablonsky-seo' ); ?></span><strong>≤ <?php echo esc_html( number_format_i18n( (int) ( $retention['low_value_max_hits'] ?? 2 ) ) ); ?> <?php echo esc_html__( 'hits', 'yablonsky-seo' ); ?></strong></div><div><span><?php echo esc_html__( 'Last maintenance', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( '' !== $lastRetentionAt ? $lastRetentionAt : __( 'Scheduled', 'yablonsky-seo' ) ); ?></strong></div></div>
                        <div class="yseo-404-operation-actions"><a class="button button-primary" href="<?php echo esc_url( $maintenanceUrl ); ?>"><?php echo esc_html__( 'Run protection now', 'yablonsky-seo' ); ?></a><a class="button" href="<?php echo esc_url( $cleanupUrl ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove current access-like, technical and probe-like aggregates with up to two hits while preserving SEO candidates and stronger repeated signals?', 'yablonsky-seo' ) ); ?>')"><?php echo esc_html__( 'Clean low-value noise', 'yablonsky-seo' ); ?></a></div>
                    </section>
                    <section class="yseo-404-operation-card">
                        <div class="yseo-404-operation-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Security handoff', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'Evidence view · redirects remain separate', 'yablonsky-seo' ); ?></h3></div><?php if ( ! empty( $securityBridge['connected'] ) ) : ?><span class="yps-mini-status good"><?php echo esc_html__( 'Connected', 'yablonsky-seo' ); ?></span><?php else : ?><span class="yps-mini-status neutral"><?php echo esc_html__( 'Awaiting consumer', 'yablonsky-seo' ); ?></span><?php endif; ?></div>
                        <p><?php echo esc_html__( 'YSEO emits privacy-light non-SEO evidence for a security provider. A connected provider can consume new observations and historical batches, then assume evidence ownership locally. Blocking, CAPTCHA and rate limits remain security responsibilities.', 'yablonsky-seo' ); ?></p>
                        <div class="yseo-404-bridge-flow"><span>YSEO</span><b>→</b><span><?php echo esc_html__( 'Security evidence', 'yablonsky-seo' ); ?></span><b>→</b><span><?php echo esc_html__( 'Firewall policy', 'yablonsky-seo' ); ?></span></div>
                        <?php if ( ! empty( $securityBridge['connected'] ) ) : ?>
                            <p class="yps-muted"><?php /* translators: %s: connected security provider name. */ echo esc_html( sprintf( __( 'Provider: %s. Live observations and bounded history can be consumed while IP, user-agent, referrer and query-string data stay private.', 'yablonsky-seo' ), (string) $securityBridge['provider'] ) ); ?></p>
                            <div class="yseo-404-capability-row"><span class="yps-mini-status <?php echo ! empty( $securityBridge['live_events'] ) ? 'good' : 'neutral'; ?>"><?php echo esc_html__( 'Live evidence', 'yablonsky-seo' ); ?></span><span class="yps-mini-status <?php echo ! empty( $securityBridge['history_pull'] ) ? 'good' : 'neutral'; ?>"><?php echo esc_html__( 'History pull', 'yablonsky-seo' ); ?></span><span class="yps-mini-status <?php echo ! empty( $securityBridge['suppress_duplicate_storage'] ) ? 'good' : 'neutral'; ?>"><?php echo esc_html__( 'Duplicate suppression', 'yablonsky-seo' ); ?></span></div>
                        <?php elseif ( ! empty( $securityPlugin['active'] ) ) : ?>
                            <p class="yps-muted"><?php /* translators: %s: installed Yablonsky Security version. */ echo esc_html( sprintf( __( 'Yablonsky Security %s is active. Evidence bridge connection is pending for this installed build, so YSEO keeps the bounded local copy. Update the Security-side consumer when its bridge-compatible release is available.', 'yablonsky-seo' ), '' !== (string) $securityPlugin['version'] ? (string) $securityPlugin['version'] : '—' ) ); ?></p>
                        <?php elseif ( ! empty( $securityPlugin['installed'] ) ) : ?>
                            <p class="yps-muted"><?php echo esc_html__( 'Yablonsky Security: activation required. YSEO continues bounded local cleanup until security enforcement is delegated.', 'yablonsky-seo' ); ?></p>
                        <?php else : ?>
                            <p class="yps-muted"><?php echo esc_html__( 'YSEO continues bounded local cleanup until a security consumer claims the bridge. Firewall enforcement remains the responsibility of the security layer.', 'yablonsky-seo' ); ?></p>
                        <?php endif; ?>
                        <div class="yseo-404-operation-actions"><a class="button" href="<?php echo esc_url( $exportUrl ); ?>"><?php echo esc_html__( 'Export evidence', 'yablonsky-seo' ); ?></a><a class="button button-link-delete" href="<?php echo esc_url( $clearNonSeoUrl ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove all current access-like, technical and probe-like aggregates? SEO candidates remain preserved. Security evidence already exported or consumed by another plugin remains intact.', 'yablonsky-seo' ) ); ?>')"><?php echo esc_html__( 'Clear all non-SEO backlog', 'yablonsky-seo' ); ?></a></div>
                    </section>
                </div>
                <?php if ( ! empty( $classifierStatus['pending'] ) ) : ?>
                    <section class="yseo-404-classifier-status">
                        <div><strong><?php echo esc_html__( 'Updating 404 classification policy', 'yablonsky-seo' ); ?> · v<?php echo esc_html( (string) $classifierStatus['target'] ); ?></strong><span><?php echo esc_html__( 'Older stored paths are being reclassified in bounded background batches. New requests already use the current policy. Continue normal operation while the background pass completes.', 'yablonsky-seo' ); ?></span></div><b><?php echo esc_html( (string) $classifierPercent ); ?>%</b>
                        <div class="yseo-404-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $classifierPercent ); ?>"><span style="width:<?php echo esc_attr( (string) $classifierPercent ); ?>%"></span></div>
                        <?php if ( '' !== $classifierProgressText ) : ?><small><?php echo esc_html( $classifierProgressText ); ?></small><?php endif; ?>
                    </section>
                <?php endif; ?>
                <div class="yseo-404-inventory-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Inventory', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'Review individual paths', 'yablonsky-seo' ); ?></h3></div><div class="yseo-404-signal-counts"><span class="yps-mini-status good"><?php echo esc_html( (string) $counts['seo'] ); ?> <?php echo esc_html__( 'SEO', 'yablonsky-seo' ); ?></span><span class="yps-mini-status"><?php echo esc_html( (string) $counts['access'] ); ?> <?php echo esc_html__( 'access-like', 'yablonsky-seo' ); ?></span><span class="yps-mini-status"><?php echo esc_html( (string) $counts['technical'] ); ?> <?php echo esc_html__( 'technical', 'yablonsky-seo' ); ?></span><span class="yps-mini-status attention"><?php echo esc_html( (string) $counts['probe'] ); ?> <?php echo esc_html__( 'probe-like', 'yablonsky-seo' ); ?></span></div></div>
                <p class="yps-muted"><?php echo esc_html__( 'Use redirects only for genuine content URLs that moved. Route security, access and infrastructure probes to review or discard instead.', 'yablonsky-seo' ); ?></p>
                <?php if ( empty( $classifierStatus['pending'] ) ) : ?>
                    <details class="yseo-404-classifier-diagnostics"><summary><?php
                        /* translators: %s: active classifier version. */
                        $classifierCurrentLabel = sprintf( __( 'Classification policy v%s · current', 'yablonsky-seo' ), (string) $classifierStatus['target'] );
                        echo esc_html( $classifierCurrentLabel );
                    ?></summary><p><?php echo esc_html__( 'This is YSEO’s local deterministic path triage policy for SEO, access-like, technical and probe-like 404s. The historical inventory is current and every new 404 is classified immediately when recorded. Security verdicts remain with the security layer.', 'yablonsky-seo' ); ?></p></details>
                <?php endif; ?>
                <form method="get" class="yseo-list-toolbar" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                    <label class="yseo-filter-field"><span><?php echo esc_html__( 'Search', 'yablonsky-seo' ); ?></span><input type="search" name="yseo_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search unresolved paths…', 'yablonsky-seo' ); ?>"></label>
                    <label class="yseo-filter-field"><span><?php echo esc_html__( 'Signal', 'yablonsky-seo' ); ?></span><select name="signal"><option value="seo" <?php selected( $signal, 'seo' ); ?>><?php echo esc_html__( 'SEO candidates', 'yablonsky-seo' ); ?></option><option value="access" <?php selected( $signal, 'access' ); ?>><?php echo esc_html__( 'Access-like requests', 'yablonsky-seo' ); ?></option><option value="technical" <?php selected( $signal, 'technical' ); ?>><?php echo esc_html__( 'Technical requests', 'yablonsky-seo' ); ?></option><option value="probe" <?php selected( $signal, 'probe' ); ?>><?php echo esc_html__( 'Probe-like requests', 'yablonsky-seo' ); ?></option><option value="all" <?php selected( $signal, 'all' ); ?>><?php echo esc_html__( 'All signals', 'yablonsky-seo' ); ?></option></select></label>
                    <label class="yseo-filter-field"><span><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></span><select name="language" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search languages…', 'yablonsky-seo' ); ?>"><option value=""><?php echo esc_html__( 'Any language', 'yablonsky-seo' ); ?></option><?php foreach ( $languageOptions as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $language, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
                    <label class="yseo-filter-field"><span><?php echo esc_html__( 'From', 'yablonsky-seo' ); ?></span><input type="date" name="date_from" value="<?php echo esc_attr( $dateFrom ); ?>"></label>
                    <label class="yseo-filter-field"><span><?php echo esc_html__( 'To', 'yablonsky-seo' ); ?></span><input type="date" name="date_to" value="<?php echo esc_attr( $dateTo ); ?>"></label>
                    <label class="yseo-filter-field"><span><?php echo esc_html__( 'Hits', 'yablonsky-seo' ); ?></span><input type="number" min="0" step="1" name="min_hits" value="<?php echo esc_attr( (string) $minHits ); ?>" placeholder="<?php echo esc_attr__( 'Minimum', 'yablonsky-seo' ); ?>"></label>
                    <label class="yseo-filter-field"><span><?php echo esc_html__( 'Sort', 'yablonsky-seo' ); ?></span><select name="sort"><option value="hits" <?php selected( $sort, 'hits' ); ?>><?php echo esc_html__( 'Most hits', 'yablonsky-seo' ); ?></option><option value="recent" <?php selected( $sort, 'recent' ); ?>><?php echo esc_html__( 'Most recent', 'yablonsky-seo' ); ?></option><option value="oldest" <?php selected( $sort, 'oldest' ); ?>><?php echo esc_html__( 'Oldest', 'yablonsky-seo' ); ?></option><option value="first_seen" <?php selected( $sort, 'first_seen' ); ?>><?php echo esc_html__( 'Recently discovered', 'yablonsky-seo' ); ?></option></select></label>
                    <input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $perPage ); ?>">
                    <div class="yseo-filter-actions"><button class="button button-primary" type="submit"><?php echo esc_html__( 'Filter', 'yablonsky-seo' ); ?></button><a class="button" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Reset', 'yablonsky-seo' ); ?></a></div>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yseo-404-bulk-form" data-yseo-bulk-form data-yseo-bulk-confirm="<?php echo esc_attr__( 'Apply this action to the selected 404 rows?', 'yablonsky-seo' ); ?>">
                    <input type="hidden" name="action" value="yseo_404_bulk"><?php wp_nonce_field( 'yseo_404_bulk' ); ?>
                    <div class="yseo-table-actionbar"><label><span class="screen-reader-text"><?php echo esc_html__( 'Bulk action', 'yablonsky-seo' ); ?></span><select name="bulk_action" data-yseo-bulk-action><option value=""><?php echo esc_html__( 'Bulk actions', 'yablonsky-seo' ); ?></option><option value="dismiss"><?php echo esc_html__( 'Dismiss selected', 'yablonsky-seo' ); ?></option><option value="gone"><?php echo esc_html__( 'Create 410 for selected SEO candidates', 'yablonsky-seo' ); ?></option><option value="redirect"><?php echo esc_html__( '301 selected SEO candidates to one target', 'yablonsky-seo' ); ?></option></select></label><input type="text" name="bulk_target" data-yseo-conditional-target="redirect" hidden disabled placeholder="/replacement/ or https://example.com/" aria-label="<?php echo esc_attr__( 'Bulk redirect target', 'yablonsky-seo' ); ?>"><button class="button button-primary" type="submit" data-yseo-bulk-apply><?php echo esc_html__( 'Apply', 'yablonsky-seo' ); ?></button><span class="yseo-selection-count" data-yseo-selection-count data-yseo-empty-label="<?php echo esc_attr__( 'Select an option', 'yablonsky-seo' ); ?>" data-yseo-selected-label="<?php echo esc_attr__( 'selected', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'Select an option', 'yablonsky-seo' ); ?></span><span class="description"><?php echo esc_html__( 'Up to 100 selected rows. Redirect/410 is limited to SEO candidates; non-SEO evidence can only be dismissed.', 'yablonsky-seo' ); ?></span></div>
                    <div class="yseo-list-table-wrap"><table class="widefat striped"><thead><tr><th class="check-column"><input type="checkbox" data-yseo-check-all aria-label="<?php echo esc_attr__( 'Select visible rows', 'yablonsky-seo' ); ?>"></th><th><?php echo esc_html__( 'Path', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Signal', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Language', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Hits', 'yablonsky-seo' ); ?></th><th title="<?php echo esc_attr__( 'First time this unresolved path was observed.', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'First observed', 'yablonsky-seo' ); ?></th><th title="<?php echo esc_attr__( 'Most recent time this unresolved path was observed.', 'yablonsky-seo' ); ?>"><?php echo esc_html__( 'Last observed', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Actions', 'yablonsky-seo' ); ?></th></tr></thead><tbody>
                    <?php if ( empty( $data['items'] ) ) : ?><tr><td colspan="8"><?php echo esc_html__( 'Current filters are clear of unresolved 404 paths.', 'yablonsky-seo' ); ?></td></tr><?php endif; ?>
                    <?php foreach ( $data['items'] as $row ) : $id = (int) $row['id']; $origin = (string) $row['path']; $lang = (string) $row['language_key']; $kind = (string) ( $row['signal_kind'] ?? 'seo' ); ?>
                        <?php
                        /* translators: %s: unresolved URL path. */
                        $selectLabel = sprintf( __( 'Select %s', 'yablonsky-seo' ), $origin );
                        ?>
                        <tr><th class="check-column"><input type="checkbox" name="yseo_ids[]" value="<?php echo esc_attr( (string) $id ); ?>" data-yseo-row-check aria-label="<?php echo esc_attr( $selectLabel ); ?>"></th><td><code><?php echo esc_html( $origin ); ?></code></td><td><?php echo esc_html( NotFoundSignalClassifier::label( $kind ) ); ?></td><td><?php echo esc_html( NotFoundSignalClassifier::SEO === $kind && '' !== $lang ? ( $languageOptions[ $lang ] ?? $lang ) : '—' ); ?></td><td><strong><?php echo esc_html( (string) (int) $row['hits'] ); ?></strong></td><td><time datetime="<?php echo esc_attr( $this->observedDatetimeAttribute( (string) $row['first_seen_gmt'] ) ); ?>" title="<?php echo esc_attr( (string) $row['first_seen_gmt'] . ' UTC' ); ?>"><?php echo esc_html( $this->observedDisplayTime( (string) $row['first_seen_gmt'] ) ); ?></time></td><td><time datetime="<?php echo esc_attr( $this->observedDatetimeAttribute( (string) $row['last_seen_gmt'] ) ); ?>" title="<?php echo esc_attr( (string) $row['last_seen_gmt'] . ' UTC' ); ?>"><?php echo esc_html( $this->observedDisplayTime( (string) $row['last_seen_gmt'] ) ); ?></time></td><td><?php if ( NotFoundSignalClassifier::isRedirectable( $origin ) ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'yablonsky-seo-redirects', 'origin' => $origin, 'yseo_from' => '404' ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Create redirect', 'yablonsky-seo' ); ?></a> · <?php endif; ?><a class="submitdelete" onclick="return confirm('<?php echo esc_js( __( 'Dismiss this 404 aggregate? It will reappear if the path returns 404 again.', 'yablonsky-seo' ) ); ?>')" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'yseo_404_delete', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'yseo_404_delete' ) ); ?>"><?php echo esc_html__( 'Dismiss', 'yablonsky-seo' ); ?></a></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                </form>
                <?php $this->pagination( $data, array( 'yseo_search' => $search, 'signal' => $signal, 'language' => $language, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'min_hits' => $minHits, 'sort' => $sort, 'per_page' => $perPage ) ); ?>
            </article>
            <p><a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'yseo_404_clear', admin_url( 'admin-post.php' ) ), 'yseo_404_clear' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear all 404 monitor aggregates?', 'yablonsky-seo' ) ); ?>')"><?php echo esc_html__( 'Clear monitor', 'yablonsky-seo' ); ?></a></p>
        </div><?php
    }

    /** @param array{total:int,page:int,pages:int,per_page:int} $data @param array<string,mixed> $args */
    private function pagination( array $data, array $args ): void {
        $total = (int) $data['total'];
        if ( $total <= 0 ) { return; }
        $page = (int) $data['page'];
        $pages = (int) $data['pages'];
        $per = (int) $data['per_page'];
        $from = ( ( $page - 1 ) * $per ) + 1;
        $to = min( $total, $page * $per );
        /* translators: 1: first visible item number, 2: last visible item number, 3: total item count. */
        $summary = sprintf( __( 'Showing %1$d–%2$d of %3$d', 'yablonsky-seo' ), $from, $to, $total );
        echo '<div class="yseo-list-pagination"><span class="yps-muted">' . esc_html( $summary ) . '</span><div class="yseo-pagination-controls">';
        $sizeArgs = $args; unset( $sizeArgs['per_page'], $sizeArgs['yseo_paged'] );
        echo '<form class="yseo-pagination-size-form" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
        foreach ( $sizeArgs as $key => $value ) { if ( '' !== (string) $value ) { echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '">'; } }
        echo '<label><span>' . esc_html__( 'Rows per page', 'yablonsky-seo' ) . '</span><select name="per_page" data-yseo-page-size>';
        foreach ( array( 25, 50, 100 ) as $option ) { echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $per, $option, false ) . '>' . esc_html( (string) $option ) . '</option>'; }
        echo '</select></label></form>';
        if ( $pages > 1 ) {
            $args['page'] = self::PAGE;
            $args['yseo_paged'] = 'YSEO_PAGE_NUMBER';
            $base = add_query_arg( array_filter( $args, static function ( $v ) { return '' !== (string) $v; } ), admin_url( 'admin.php' ) );
            $base = str_replace( 'YSEO_PAGE_NUMBER', '%#%', $base );
            echo '<div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $pages, 'mid_size' => 2, 'prev_text' => '‹', 'next_text' => '›' ) ) ) . '</div>';
        }
        echo '</div></div>';
    }

    private function observedDisplayTime( string $gmt ): string {
        $gmt = trim( sanitize_text_field( $gmt ) );
        if ( '' === $gmt ) { return '—'; }
        $format = trim( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) );
        $local = get_date_from_gmt( $gmt, $format );
        return '' !== $local ? $local : $gmt . ' UTC';
    }

    private function observedDatetimeAttribute( string $gmt ): string {
        $timestamp = strtotime( trim( $gmt ) . ' UTC' );
        return false !== $timestamp ? gmdate( 'c', $timestamp ) : '';
    }

    /** @param array<int,string> $fields */
    private function csvLine( array $fields ): string {
        $quoted = array();
        foreach ( $fields as $field ) {
            $value = trim( str_replace( array( "\r", "\n" ), ' ', (string) $field ) );
            // Prevent spreadsheet formula execution when exported 404 data is opened locally.
            if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t" ), true ) ) { $value = "'" . $value; }
            $quoted[] = '"' . str_replace( '"', '""', $value ) . '"';
        }
        return implode( ',', $quoted ) . "\n";
    }

    private function guard( string $action ): void { if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Managing the 404 monitor requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); } check_admin_referer( $action ); }
    private function noticeText( string $notice ): string {
        if ( 'cleared' === $notice ) { return __( '404 monitor cleared.', 'yablonsky-seo' ); }
        if ( 0 === strpos( $notice, 'maintenance-' ) ) {
            $count = max( 0, (int) substr( $notice, strlen( 'maintenance-' ) ) );
            /* translators: %d: number of 404 aggregates removed by maintenance. */
            return sprintf( __( 'Protection maintenance completed; %d expired or pressure-trimmed aggregates were removed.', 'yablonsky-seo' ), $count );
        }
        if ( 0 === strpos( $notice, 'nonseo-cleared-' ) ) {
            $count = max( 0, (int) substr( $notice, strlen( 'nonseo-cleared-' ) ) );
            /* translators: %d: number of non-SEO 404 aggregates removed. */
            return sprintf( __( 'Non-SEO backlog cleared; %d aggregates were removed. SEO candidates were preserved.', 'yablonsky-seo' ), $count );
        }
        if ( 0 === strpos( $notice, 'noise-cleaned-' ) ) {
            $count = max( 0, (int) substr( $notice, strlen( 'noise-cleaned-' ) ) );
            /* translators: %d: number of low-value non-SEO aggregates removed. */
            return sprintf( __( 'Noise cleanup removed %d low-value non-SEO aggregates.', 'yablonsky-seo' ), $count );
        }
        if ( 'bulk-none' === $notice ) { return __( 'Select at least one 404 row and a bulk action.', 'yablonsky-seo' ); }
        if ( 'bulk-invalid-target' === $notice ) { return __( 'The bulk redirect target is not a valid HTTP(S) URL or local path.', 'yablonsky-seo' ); }
        if ( preg_match( '/^bulk-(dismissed|gone|redirect)-(\d+)$/', $notice, $matches ) ) {
            $count = max( 0, (int) $matches[2] );
            /* translators: %d: number of 404 rows affected by the bulk action. */
            return sprintf( __( 'Bulk 404 action completed for %d rows.', 'yablonsky-seo' ), $count );
        }
        return __( '404 monitor updated.', 'yablonsky-seo' );
    }

    private function back( string $notice = '' ): void {
        $referer = wp_get_referer();
        $url = $referer ? $referer : admin_url( 'admin.php?page=' . self::PAGE );
        if ( '' !== $notice ) { $url = add_query_arg( 'yseo_notice', $notice, $url ); }
        wp_safe_redirect( $url );
        exit;
    }
}
