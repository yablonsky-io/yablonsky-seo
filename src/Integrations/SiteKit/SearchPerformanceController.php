<?php
namespace Yablonsky\PerformanceSEO\Integrations\SiteKit;

use Yablonsky\PerformanceSEO\Analytics\Search\NativeSearchSignalsRepository;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only Search Performance bridge over Google Site Kit REST datapoints; never reads OAuth credentials. */
final class SearchPerformanceController {
    private const PAGE = 'yablonsky-seo-search-performance';
    private const NONCE = 'yseo_site_kit_performance';
    private const REPORT_DAYS = 28;
    private const TABLE_LIMIT = 10;
    private const SNAPSHOT_OPTION = 'yseo_search_performance_snapshot_v1';

    /** @var array<int,string> */
    private $siteKitErrors = array();
    /** @var NativeSearchSignalsRepository */
    private $nativeSignals;

    public function __construct( ?NativeSearchSignalsRepository $nativeSignals = null ) {
        $this->nativeSignals = $nativeSignals instanceof NativeSearchSignalsRepository ? $nativeSignals : new NativeSearchSignalsRepository();
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'registerPage' ), 35 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
        add_action( 'admin_post_yseo_search_performance_export', array( $this, 'exportCsv' ) );
        add_action( 'admin_post_yseo_native_search_performance_export', array( $this, 'exportNativeCsv' ) );
        add_action( 'admin_post_yseo_native_search_signals_toggle', array( $this, 'toggleNativeSignals' ) );
    }

    public function registerPage(): void {
        add_submenu_page( 'yablonsky-seo', esc_html__( 'Search Performance', 'yablonsky-seo' ), esc_html__( 'Search Performance', 'yablonsky-seo' ), 'manage_options', self::PAGE, array( $this, 'render' ) );
    }

    public function enqueueAssets( string $hook ): void {
        if ( false === strpos( $hook, self::PAGE ) ) { return; }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin-editor', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
        wp_enqueue_script( 'yseo-performance-charts', YPS_URL . 'assets/performance-charts.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Viewing search performance requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        $range = $this->reportRangeFromRequest();
        $refresh = '1' === sanitize_text_field( (string) filter_input( INPUT_GET, 'load', FILTER_UNSAFE_RAW ) );
        $snapshot = $this->snapshot();
        $report = isset( $snapshot['report'] ) && is_array( $snapshot['report'] ) ? $snapshot['report'] : array();
        $refreshMessages = array();
        if ( $refresh ) {
            $fresh = $this->loadReportWithNonce( $range );
            if ( $this->hasExternalEvidence( $fresh ) ) {
                $this->saveSnapshot( $fresh );
                $snapshot = $this->snapshot();
                $report = $fresh;
            } elseif ( ! empty( $report ) ) {
                $refreshMessages[] = __( 'YSEO is showing the last successful local snapshot because this refresh returned insufficient search-engine evidence.', 'yablonsky-seo' );
                if ( isset( $fresh['messages'] ) && is_array( $fresh['messages'] ) ) { $refreshMessages = array_merge( $refreshMessages, $fresh['messages'] ); }
            } else {
                $report = $fresh;
            }
        }

        $native = $this->nativeSignals->summaryRange( (string) $range['start'], (string) $range['end'] );
        $nativePrevious = $this->nativePreviousPeriod( $range );
        $nativeEntrances = max( 0, (int) ( $native['entrances'] ?? 0 ) );
        $nativeAverage = (int) $range['days'] > 0 ? $nativeEntrances / (int) $range['days'] : 0.0;
        $nativeActiveDays = 0;
        foreach ( isset( $native['daily'] ) && is_array( $native['daily'] ) ? $native['daily'] : array() as $nativeDay ) {
            if ( is_array( $nativeDay ) && (int) ( $nativeDay['entrances'] ?? 0 ) > 0 ) { $nativeActiveDays++; }
        }
        $nativeComparison = null;
        if ( is_array( $nativePrevious ) ) { $nativeComparison = $nativeEntrances - max( 0, (int) ( $nativePrevious['entrances'] ?? 0 ) ); }
        $rangeArgs = $this->rangeQueryArgs( $range );
        $loadUrl = wp_nonce_url( add_query_arg( array_merge( array( 'page' => self::PAGE, 'load' => '1' ), $rangeArgs ), admin_url( 'admin.php' ) ), self::NONCE );
        $exportUrl = wp_nonce_url( add_query_arg( array( 'action' => 'yseo_search_performance_export' ), admin_url( 'admin-post.php' ) ), self::NONCE . '_export' );
        $nativeExportUrl = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'yseo_native_search_performance_export' ), $rangeArgs ), admin_url( 'admin-post.php' ) ), self::NONCE . '_native_export' );
        $siteKitUrl = admin_url( 'admin.php?page=googlesitekit-dashboard' );
        $siteKitPresent = defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( 'Google\\Site_Kit\\Plugin' );
        $moduleStates = $siteKitPresent ? $this->moduleStates() : array();
        $connectionLabel = $this->moduleConnectionLabel( $siteKitPresent, $moduleStates );
        $hasExternal = $this->hasExternalEvidence( $report );
        $updated = isset( $snapshot['updated_at_gmt'] ) ? sanitize_text_field( (string) $snapshot['updated_at_gmt'] ) : '';
        $nativeEnabled = ! empty( $native['enabled'] );
        $toggleUrl = admin_url( 'admin-post.php' );
        ?>
        <div class="wrap yps-wrap yseo-search-performance">
            <header class="yps-product-header">
                <div class="yps-product-title"><span class="yps-card-kicker"><?php echo esc_html__( 'Search intelligence', 'yablonsky-seo' ); ?></span><h1><?php echo esc_html__( 'Search Performance', 'yablonsky-seo' ); ?></h1><p><?php echo esc_html__( 'Local organic trends first; external search-engine metrics when an authorized connector is available.', 'yablonsky-seo' ); ?></p></div>
                <div class="yps-product-actions">
                    <a class="button" href="<?php echo esc_url( $nativeExportUrl ); ?>"><?php echo esc_html__( 'Export local CSV', 'yablonsky-seo' ); ?></a>
                    <?php if ( $siteKitPresent ) : ?><a class="button" href="<?php echo esc_url( $siteKitUrl ); ?>"><?php echo esc_html__( 'Open Site Kit', 'yablonsky-seo' ); ?></a><?php endif; ?>
                    <?php if ( $hasExternal ) : ?><a class="button" href="<?php echo esc_url( $exportUrl ); ?>"><?php echo esc_html__( 'Export external CSV', 'yablonsky-seo' ); ?></a><?php endif; ?>
                    <?php if ( $siteKitPresent ) : ?><a class="button button-primary" href="<?php echo esc_url( $loadUrl ); ?>"><?php echo esc_html__( 'Refresh selected period', 'yablonsky-seo' ); ?></a><?php endif; ?>
                </div>
            </header>

            <?php $this->renderRangeControls( $range ); ?>

            <section class="yps-card yseo-native-search-signals">
                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'First-party evidence', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Native organic landing signals', 'yablonsky-seo' ); ?></h2><p><?php echo esc_html__( 'Organic entrances, search-engine mix and landing-page demand for the selected period.', 'yablonsky-seo' ); ?></p></div><span class="yps-mini-status <?php echo esc_attr( $nativeEnabled ? 'good' : 'neutral' ); ?>"><?php echo esc_html( $nativeEnabled ? __( 'ON', 'yablonsky-seo' ) : __( 'PAUSED', 'yablonsky-seo' ) ); ?></span></div>
                <div class="yps-kpi-grid yseo-native-kpi-grid">
                    <?php $this->metric( __( 'Organic entrances', 'yablonsky-seo' ), $nativeEntrances, $nativeComparison ); ?>
                    <?php $this->metric( __( 'Average per day', 'yablonsky-seo' ), number_format_i18n( $nativeAverage, 1 ) ); ?>
                    <?php $this->metric( __( 'Search engines observed', 'yablonsky-seo' ), (int) ( $native['engine_count'] ?? 0 ) ); ?>
                    <?php $this->metric( __( 'Days with traffic', 'yablonsky-seo' ), $nativeActiveDays ); ?>
                </div>
                <?php $this->renderNativeTrend( isset( $native['daily'] ) && is_array( $native['daily'] ) ? $native['daily'] : array(), isset( $native['engines'] ) && is_array( $native['engines'] ) ? $native['engines'] : array() ); ?>
                <?php $this->renderNativePages( isset( $native['pages'] ) && is_array( $native['pages'] ) ? $native['pages'] : array() ); ?>
                <form class="yseo-native-toggle" method="post" action="<?php echo esc_url( $toggleUrl ); ?>"><input type="hidden" name="action" value="yseo_native_search_signals_toggle" /><input type="hidden" name="enabled" value="<?php echo esc_attr( $nativeEnabled ? '0' : '1' ); ?>" /><?php wp_nonce_field( 'yseo_native_search_signals_toggle' ); ?><button class="button" type="submit"><?php echo esc_html( $nativeEnabled ? __( 'Pause native signals', 'yablonsky-seo' ) : __( 'Enable native signals', 'yablonsky-seo' ) ); ?></button></form>
            </section>

            <?php if ( $hasExternal ) : ?>
                <section class="yseo-external-search-evidence">
                    <?php if ( ! $this->externalReportMatchesRange( $report, $range ) ) : ?>
                        <div class="notice notice-info inline yseo-external-range-notice"><p><strong><?php echo esc_html__( 'External snapshot uses a different period.', 'yablonsky-seo' ); ?></strong> <?php echo esc_html__( 'Native analytics above already reflect the selected dates. Refresh external evidence to request Search Console data for the same reporting window.', 'yablonsky-seo' ); ?></p></div>
                    <?php endif; ?>
                    <div class="yseo-performance-range"><strong><?php echo esc_html__( 'External reporting window', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( (string) ( $report['start_date'] ?? '' ) . ' → ' . (string) ( $report['end_date'] ?? '' ) ); ?></span></div>
                    <div class="yps-kpi-grid yseo-search-performance-grid">
                        <?php $this->metric( __( 'Impressions', 'yablonsky-seo' ), $report['search_console']['impressions'] ?? null, $report['comparison']['impressions'] ?? null ); ?>
                        <?php $this->metric( __( 'Clicks', 'yablonsky-seo' ), $report['search_console']['clicks'] ?? null, $report['comparison']['clicks'] ?? null ); ?>
                        <?php $this->metric( __( 'CTR', 'yablonsky-seo' ), isset( $report['search_console']['ctr'] ) ? number_format_i18n( (float) $report['search_console']['ctr'] * 100, 1 ) . '%' : null, isset( $report['comparison']['ctr'] ) ? number_format_i18n( (float) $report['comparison']['ctr'] * 100, 1 ) . ' pp' : null ); ?>
                        <?php $this->metric( __( 'Average position', 'yablonsky-seo' ), isset( $report['search_console']['position'] ) ? number_format_i18n( (float) $report['search_console']['position'], 1 ) : null, isset( $report['comparison']['position'] ) ? number_format_i18n( (float) $report['comparison']['position'], 1 ) : null ); ?>
                        <?php $this->metric( __( 'Sessions', 'yablonsky-seo' ), $report['analytics']['sessions'] ?? null ); ?>
                        <?php $this->metric( __( 'Users', 'yablonsky-seo' ), $report['analytics']['totalUsers'] ?? null ); ?>
                    </div>
                    <?php $this->renderTrendCharts( $report['daily'] ?? array() ); ?>
                    <?php $this->renderLeaderCharts( $report['queries'] ?? array(), $report['pages'] ?? array() ); ?>
                    <div class="yseo-performance-tables">
                        <?php $this->renderSearchTable( __( 'Top search queries', 'yablonsky-seo' ), __( 'Query', 'yablonsky-seo' ), $report['queries'] ?? array() ); ?>
                        <?php $this->renderSearchTable( __( 'Top pages from search', 'yablonsky-seo' ), __( 'Page', 'yablonsky-seo' ), $report['pages'] ?? array() ); ?>
                    </div>
                    <?php $this->renderOpportunities( $report['opportunities'] ?? array() ); ?>
                </section>
            <?php else : ?>
                <section class="yseo-performance-connector-card"><div><span class="yps-card-kicker"><?php echo esc_html__( 'External connector', 'yablonsky-seo' ); ?></span><h3><?php echo esc_html__( 'External search-engine evidence is optional', 'yablonsky-seo' ); ?></h3><p><?php echo esc_html__( 'Native organic landing signals above continue to work independently of Google Site Kit. Connect an authorized provider to add search-engine-side impressions, queries, CTR and average position. Site Kit is supported today as a convenience bridge; the Search Performance architecture is provider-independent.', 'yablonsky-seo' ); ?></p></div><span class="yps-mini-status neutral"><?php echo esc_html__( 'Optional', 'yablonsky-seo' ); ?></span></section>
            <?php endif; ?>
            <details class="yseo-performance-methodology" id="yseo-search-methodology">
                <summary><strong><?php echo esc_html__( 'Data sources, privacy & methodology', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'How Search Performance collects and separates local and external evidence.', 'yablonsky-seo' ); ?></span></summary>
                <div class="yseo-performance-methodology-body">
                    <div class="yseo-performance-source-strip yseo-performance-source-strip-four">
                        <div><span><?php echo esc_html__( 'Native signals', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $nativeEnabled ? __( 'Active · local only', 'yablonsky-seo' ) : __( 'Paused', 'yablonsky-seo' ) ); ?></strong></div>
                        <div><span><?php echo esc_html__( 'External connector', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $siteKitPresent ? 'Google Search Console · ' . $connectionLabel : __( 'Optional · ready to connect', 'yablonsky-seo' ) ); ?></strong></div>
                        <div><span><?php echo esc_html__( 'Last external snapshot', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( '' !== $updated ? $updated : __( 'Ready to save', 'yablonsky-seo' ) ); ?></strong></div>
                        <div><span><?php echo esc_html__( 'Storage', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html__( 'Local aggregates · privacy-preserving', 'yablonsky-seo' ); ?></strong></div>
                    </div>
                    <div class="yseo-performance-methodology-grid">
                        <div class="yps-callout"><strong><?php echo esc_html__( 'Search Performance works independently of Site Kit', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'YSEO counts privacy-minimised organic landing entrances locally using cookie-free, IP-free local evidence. Search impressions, query text and average ranking position live on the search-engine side and therefore use an authorized connector such as Search Console or another future provider.', 'yablonsky-seo' ); ?></span></div>
                        <div class="yps-callout"><strong><?php echo esc_html__( 'Language evidence follows the resource', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'Local landing-page rows can show a proven or detected content language because they have a WordPress resource ID. External search query samples receive a language when an authoritative resource mapping is available.', 'yablonsky-seo' ); ?></span></div>
                    </div>
                    <p class="yps-muted yseo-native-retention-note"><?php echo esc_html__( 'Local daily aggregates are retained for up to 180 days. Choose a preset or an exact date range above; raw visitor logs stay outside this storage model.', 'yablonsky-seo' ); ?></p>
                </div>
            </details>
            <?php $messages = array_values( array_unique( array_merge( $refreshMessages, isset( $report['messages'] ) && is_array( $report['messages'] ) ? $report['messages'] : array() ) ) ); ?>
            <?php if ( ! empty( $messages ) ) : ?><section class="yps-card yseo-performance-notes"><h2><?php echo esc_html__( 'Connection notes', 'yablonsky-seo' ); ?></h2><ul><?php foreach ( $messages as $message ) : ?><li><?php echo esc_html( $message ); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        </div>
        <?php
    }

    /** @param array{preset:string,start:string,end:string,days:int} $range @return array<string,mixed>|null */
    private function nativePreviousPeriod( array $range ): ?array {
        $startTs = strtotime( (string) $range['start'] . ' 00:00:00 UTC' );
        $days = max( 1, (int) $range['days'] );
        if ( false === $startTs ) { return null; }
        $previousEndTs = $startTs - DAY_IN_SECONDS;
        $previousStartTs = $previousEndTs - ( ( $days - 1 ) * DAY_IN_SECONDS );
        $retentionStartTs = strtotime( gmdate( 'Y-m-d', time() - ( 179 * DAY_IN_SECONDS ) ) . ' 00:00:00 UTC' );
        if ( false === $retentionStartTs || $previousStartTs < $retentionStartTs ) { return null; }
        return $this->nativeSignals->summaryRange( gmdate( 'Y-m-d', $previousStartTs ), gmdate( 'Y-m-d', $previousEndTs ) );
    }

    /** @param array<string,mixed> $report @param array{preset:string,start:string,end:string,days:int} $range */
    private function externalReportMatchesRange( array $report, array $range ): bool {
        $yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
        $expectedEnd = min( (string) $range['end'], $yesterday );
        $expectedStart = min( (string) $range['start'], $expectedEnd );
        return $expectedStart === (string) ( $report['start_date'] ?? '' ) && $expectedEnd === (string) ( $report['end_date'] ?? '' );
    }

    /** @return array{preset:string,start:string,end:string,days:int} */
    private function reportRangeFromRequest(): array {
        $preset = sanitize_key( (string) filter_input( INPUT_GET, 'range', FILTER_UNSAFE_RAW ) );
        if ( ! in_array( $preset, array( '7d', '28d', '90d', '180d', 'custom' ), true ) ) { $preset = '28d'; }
        $end = gmdate( 'Y-m-d' );
        if ( 'custom' === $preset ) {
            $customStart = $this->normalizeDate( (string) filter_input( INPUT_GET, 'date_from', FILTER_UNSAFE_RAW ) );
            $customEnd = $this->normalizeDate( (string) filter_input( INPUT_GET, 'date_to', FILTER_UNSAFE_RAW ) );
            if ( '' !== $customStart && '' !== $customEnd && $customStart <= $customEnd ) {
                $endTs = strtotime( $customEnd . ' 00:00:00 UTC' );
                $startTs = strtotime( $customStart . ' 00:00:00 UTC' );
                if ( false !== $startTs && false !== $endTs ) {
                    $days = max( 1, (int) floor( ( $endTs - $startTs ) / DAY_IN_SECONDS ) + 1 );
                    if ( $days > 180 ) { $days = 180; $customStart = gmdate( 'Y-m-d', $endTs - ( 179 * DAY_IN_SECONDS ) ); }
                    return array( 'preset' => 'custom', 'start' => $customStart, 'end' => $customEnd, 'days' => $days );
                }
            }
            $preset = '28d';
        }
        $days = (int) rtrim( $preset, 'd' );
        $days = in_array( $days, array( 7, 28, 90, 180 ), true ) ? $days : 28;
        $start = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
        return array( 'preset' => $days . 'd', 'start' => $start, 'end' => $end, 'days' => $days );
    }

    /** @return array{preset:string,start:string,end:string,days:int} */
    private function defaultReportRange(): array {
        $days = self::REPORT_DAYS;
        return array( 'preset' => $days . 'd', 'start' => gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) ), 'end' => gmdate( 'Y-m-d' ), 'days' => $days );
    }

    /** @param array{preset:string,start:string,end:string,days:int} $range @return array<string,string> */
    private function rangeQueryArgs( array $range ): array {
        $args = array( 'range' => (string) $range['preset'] );
        if ( 'custom' === (string) $range['preset'] ) { $args['date_from'] = (string) $range['start']; $args['date_to'] = (string) $range['end']; }
        return $args;
    }

    /** @param array{preset:string,start:string,end:string,days:int} $range */
    private function renderRangeControls( array $range ): void {
        $base = admin_url( 'admin.php' );
        echo '<section class="yseo-performance-period" aria-label="' . esc_attr__( 'Reporting period', 'yablonsky-seo' ) . '"><div class="yseo-performance-period-copy"><span class="yps-card-kicker">' . esc_html__( 'Reporting period', 'yablonsky-seo' ) . '</span><strong>' . esc_html( (string) $range['start'] . ' → ' . (string) $range['end'] ) . '</strong></div><div class="yseo-performance-presets">';
        foreach ( array( '7d' => __( '7 days', 'yablonsky-seo' ), '28d' => __( '28 days', 'yablonsky-seo' ), '90d' => __( '90 days', 'yablonsky-seo' ), '180d' => __( '180 days', 'yablonsky-seo' ) ) as $key => $label ) {
            $url = add_query_arg( array( 'page' => self::PAGE, 'range' => $key ), $base );
            echo '<a class="button' . ( $key === (string) $range['preset'] ? ' button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div><details class="yseo-performance-custom-disclosure"' . ( 'custom' === (string) $range['preset'] ? ' open' : '' ) . '><summary>' . esc_html__( 'Custom dates', 'yablonsky-seo' ) . '</summary><form class="yseo-performance-custom-range" method="get" action="' . esc_url( $base ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="range" value="custom"><label><span>' . esc_html__( 'From', 'yablonsky-seo' ) . '</span><input type="date" name="date_from" value="' . esc_attr( (string) $range['start'] ) . '" max="' . esc_attr( (string) $range['end'] ) . '"></label><label><span>' . esc_html__( 'To', 'yablonsky-seo' ) . '</span><input type="date" name="date_to" value="' . esc_attr( (string) $range['end'] ) . '" max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label><button class="button" type="submit">' . esc_html__( 'Apply dates', 'yablonsky-seo' ) . '</button></form></details></section>';
    }

    private function normalizeDate( string $date ): string {
        $date = trim( sanitize_text_field( $date ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return ''; }
        $parts = array_map( 'intval', explode( '-', $date ) );
        return 3 === count( $parts ) && checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : '';
    }

    public function toggleNativeSignals(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Changing search signal collection requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( 'yseo_native_search_signals_toggle' );
        $enabled = '1' === sanitize_text_field( (string) filter_input( INPUT_POST, 'enabled', FILTER_UNSAFE_RAW ) ) ? '1' : '0';
        update_option( 'yseo_native_search_signals_enabled', $enabled, false );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public function exportNativeCsv(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Exporting search performance requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( self::NONCE . '_native_export' );
        $range = $this->reportRangeFromRequest();
        $native = $this->nativeSignals->summaryRange( (string) $range['start'], (string) $range['end'] );
        $filename = 'yablonsky-seo-local-search-performance-' . (string) $range['start'] . '-to-' . (string) $range['end'] . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is the explicit HTTP response stream, not a filesystem path.
        $stream = fopen( 'php://output', 'w' );
        if ( false === $stream ) { wp_die( esc_html__( 'Could not open the CSV export stream.', 'yablonsky-seo' ) ); }
        fputcsv( $stream, array( 'record_type', 'date', 'engine', 'resource_id', 'label', 'url', 'language', 'entrances' ), ',', '"', '' );
        foreach ( isset( $native['daily'] ) && is_array( $native['daily'] ) ? $native['daily'] : array() as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            fputcsv( $stream, array( 'daily', (string) ( $row['date'] ?? '' ), '', '', '', '', '', (int) ( $row['entrances'] ?? 0 ) ), ',', '"', '' );
        }
        foreach ( isset( $native['engines'] ) && is_array( $native['engines'] ) ? $native['engines'] : array() as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            fputcsv( $stream, array( 'engine', '', $this->csvCell( (string) ( $row['engine'] ?? '' ) ), '', '', '', '', (int) ( $row['entrances'] ?? 0 ) ), ',', '"', '' );
        }
        $languageIndex = new ResourceLanguageIndex();
        foreach ( isset( $native['pages'] ) && is_array( $native['pages'] ) ? $native['pages'] : array() as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $postId = max( 0, (int) ( $row['post_id'] ?? 0 ) );
            $language = $postId > 0 ? $languageIndex->languageForPost( $postId, true ) : array();
            fputcsv( $stream, array( 'landing_page', '', '', $postId, $this->csvCell( (string) ( $row['label'] ?? '' ) ), $this->csvCell( (string) ( $row['url'] ?? '' ) ), $this->csvCell( (string) ( $language['language_key'] ?? '' ) ), (int) ( $row['entrances'] ?? 0 ) ), ',', '"', '' );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes php://output only.
        fclose( $stream );
        exit;
    }

    public function exportCsv(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Exporting search performance requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( self::NONCE . '_export' );
        $snapshot = $this->snapshot();
        $report = isset( $snapshot['report'] ) && is_array( $snapshot['report'] ) ? $snapshot['report'] : array();
        if ( ! $this->hasExternalEvidence( $report ) ) {
            $report = $this->loadReport();
            if ( $this->hasExternalEvidence( $report ) ) { $this->saveSnapshot( $report ); }
        }
        $filename = 'yablonsky-seo-search-performance-' . gmdate( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is the explicit HTTP response stream, not a filesystem path.
        $stream = fopen( 'php://output', 'w' );
        if ( false === $stream ) { wp_die( esc_html__( 'Could not open the CSV export stream.', 'yablonsky-seo' ) ); }
        fputcsv( $stream, array( 'record_type', 'label', 'date', 'clicks', 'impressions', 'ctr', 'position', 'sessions', 'users' ), ',', '"', '' );
        $search = isset( $report['search_console'] ) && is_array( $report['search_console'] ) ? $report['search_console'] : array();
        $analytics = isset( $report['analytics'] ) && is_array( $report['analytics'] ) ? $report['analytics'] : array();
        fputcsv( $stream, array( 'summary', '', (string) ( $report['start_date'] ?? '' ) . ' → ' . (string) ( $report['end_date'] ?? '' ), $search['clicks'] ?? '', $search['impressions'] ?? '', $search['ctr'] ?? '', $search['position'] ?? '', $analytics['sessions'] ?? '', $analytics['totalUsers'] ?? '' ), ',', '"', '' );
        foreach ( (array) ( $report['daily'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            fputcsv( $stream, array( 'daily', '', (string) ( $row['date'] ?? '' ), $row['clicks'] ?? '', $row['impressions'] ?? '', $row['ctr'] ?? '', $row['position'] ?? '', '', '' ), ',', '"', '' );
        }
        foreach ( array( 'query' => (array) ( $report['queries'] ?? array() ), 'page' => (array) ( $report['pages'] ?? array() ) ) as $type => $rows ) {
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                fputcsv( $stream, array( $type, $this->csvCell( (string) ( $row['label'] ?? '' ) ), '', $row['clicks'] ?? '', $row['impressions'] ?? '', $row['ctr'] ?? '', $row['position'] ?? '', '', '' ), ',', '"', '' );
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes php://output only.
        fclose( $stream );
        exit;
    }

    /** @return array<string,mixed> */
    private function snapshot(): array {
        $snapshot = get_option( self::SNAPSHOT_OPTION, array() );
        if ( ! is_array( $snapshot ) || ! isset( $snapshot['report'] ) || ! is_array( $snapshot['report'] ) ) { return array(); }
        return $snapshot;
    }

    /** @param array<string,mixed> $report */
    private function saveSnapshot( array $report ): void {
        if ( ! $this->hasExternalEvidence( $report ) ) { return; }
        $snapshot = array(
            'updated_at_gmt' => gmdate( 'c' ),
            'provider' => 'google-site-kit',
            'report' => $report,
        );
        if ( false === get_option( self::SNAPSHOT_OPTION, false ) ) { add_option( self::SNAPSHOT_OPTION, $snapshot, '', false ); }
        else { update_option( self::SNAPSHOT_OPTION, $snapshot, false ); }
    }

    /** @param array<string,mixed> $report */
    private function hasExternalEvidence( array $report ): bool {
        if ( isset( $report['search_console'] ) && is_array( $report['search_console'] ) && ! empty( $report['search_console'] ) ) { return true; }
        foreach ( array( 'queries', 'pages', 'daily' ) as $key ) { if ( isset( $report[ $key ] ) && is_array( $report[ $key ] ) && ! empty( $report[ $key ] ) ) { return true; } }
        return isset( $report['analytics'] ) && is_array( $report['analytics'] ) && ! empty( $report['analytics'] );
    }

    /** @param array<int,array<string,mixed>> $daily @param array<int,array<string,mixed>> $engines */
    private function renderNativeTrend( array $daily, array $engines ): void {
        if ( empty( $daily ) ) { return; }
        $values = array();
        foreach ( $daily as $row ) { if ( is_array( $row ) ) { $values[] = (float) ( $row['entrances'] ?? 0 ); } }
        $total = array_sum( $values );
        if ( $total <= 0 ) { echo '<p class="yps-muted yseo-native-zero">' . esc_html__( 'Native search signals start accumulating after this release observes real organic landing entrances. Historical Search Console evidence remains in its existing source and the local counter starts from observed traffic.', 'yablonsky-seo' ) . '</p>'; return; }
        $hasEngineMix = ! empty( $engines );
        echo '<div class="yseo-native-chart-grid' . ( $hasEngineMix ? '' : ' is-single' ) . '">';
        $this->renderMetricChart( __( 'Organic entrances', 'yablonsky-seo' ), $daily, 'entrances', '', false );
        if ( $hasEngineMix ) { $this->renderEngineMixChart( $engines, $total ); }
        echo '</div>';
    }

    /** @param array<int,array<string,mixed>> $engines */
    private function renderEngineMixChart( array $engines, float $total ): void {
        echo '<article class="yseo-performance-chart yseo-native-engine-chart"><div class="yseo-performance-chart-head"><strong>' . esc_html__( 'Search engine mix', 'yablonsky-seo' ) . '</strong><span class="yseo-chart-head-value"><small>' . esc_html__( 'Observed', 'yablonsky-seo' ) . '</small><b>' . esc_html( number_format_i18n( count( $engines ) ) ) . '</b></span></div>';
        if ( empty( $engines ) || $total <= 0 ) { echo '<p class="yps-muted">' . esc_html__( 'Search-engine mix will appear when the selected period contains source data.', 'yablonsky-seo' ) . '</p></article>'; return; }
        echo '<div class="yseo-performance-bars yseo-native-engine-bars">';
        foreach ( array_slice( $engines, 0, 8 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $count = max( 0, (int) ( $row['entrances'] ?? 0 ) );
            $engine = sanitize_text_field( (string) ( $row['engine'] ?? '' ) );
            $share = $total > 0 ? min( 100, max( 0, ( $count / $total ) * 100 ) ) : 0;
            echo '<div class="yseo-performance-bar-row"><div class="yseo-performance-bar-label"><strong>' . esc_html( '' !== $engine ? ucfirst( $engine ) : __( 'Other', 'yablonsky-seo' ) ) . '</strong><span>' . esc_html( number_format_i18n( $count ) . ' · ' . number_format_i18n( $share, 1 ) . '%' ) . '</span></div><div class="yseo-performance-bar-track" aria-hidden="true"><i style="width:' . esc_attr( number_format( max( 2, $share ), 1, '.', '' ) ) . '%"></i></div></div>';
        }
        echo '</div><p class="yps-muted yseo-performance-chart-note">' . esc_html__( 'This is a coarse referrer-engine breakdown of local organic entrances. Query and user-profile analytics remain outside this local view.', 'yablonsky-seo' ) . '</p></article>';
    }

    /** @param array<int,array<string,mixed>> $pages */
    private function renderNativePages( array $pages ): void {
        if ( empty( $pages ) ) { return; }
        $languageIndex = new ResourceLanguageIndex();
        $languageCatalog = $languageIndex->catalog( true );
        echo '<div class="yseo-native-pages"><h3>' . esc_html__( 'Top local organic landing pages', 'yablonsky-seo' ) . '</h3><div class="yseo-performance-bars">';
        $max = 1;
        foreach ( $pages as $row ) { if ( is_array( $row ) ) { $max = max( $max, (int) ( $row['entrances'] ?? 0 ) ); } }
        foreach ( array_slice( $pages, 0, 6 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $count = max( 0, (int) ( $row['entrances'] ?? 0 ) );
            $width = min( 100, max( 2, ( $count / $max ) * 100 ) );
            $label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            $url = esc_url( (string) ( $row['url'] ?? '' ) );
            $postId = max( 0, (int) ( $row['post_id'] ?? 0 ) );
            $languageMarkup = '';
            if ( $postId > 0 ) {
                $language = $languageIndex->languageForPost( $postId, true );
                $languageKey = (string) ( $language['language_key'] ?? 'auto' );
                $languageMarkup = ' <span class="yseo-language-badge" title="' . esc_attr( (string) ( $language['source'] ?? '' ) ) . '">' . esc_html( $languageIndex->label( $languageKey, $languageCatalog ) ) . '</span>';
            }
            echo '<div class="yseo-performance-bar-row"><div class="yseo-performance-bar-label"><strong>';
            if ( '' !== $url ) { echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>'; } else { echo esc_html( $label ); }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- languageMarkup is assembled from esc_attr()/esc_html() only.
            echo $languageMarkup;
            /* translators: %d: organic landing entrance count. */
            echo '</strong><span>' . esc_html( sprintf( _n( '%d organic entrance', '%d organic entrances', $count, 'yablonsky-seo' ), $count ) ) . '</span></div><div class="yseo-performance-bar-track" aria-hidden="true"><i style="width:' . esc_attr( number_format( $width, 1, '.', '' ) ) . '%"></i></div></div>';
        }
        echo '</div></div>';
    }

    private function csvCell( string $value ): string {
        $value = trim( preg_replace( '/[\r\n]+/u', ' ', $value ) ?? $value );
        if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t" ), true ) ) { return "'" . $value; }
        return $value;
    }

    /** @return array<string,mixed> */
    private function loadReportWithNonce( array $range ): array {
        check_admin_referer( self::NONCE );
        return $this->loadReport( $range );
    }

    /** @return array<string,mixed> */
    private function loadReport( ?array $range = null ): array {
        $this->siteKitErrors = array();
        $moduleStates = $this->moduleStates();
        $range = is_array( $range ) ? $range : $this->defaultReportRange();
        $yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
        $end = min( (string) $range['end'], $yesterday );
        $start = min( (string) $range['start'], $end );
        $endTs = strtotime( $end . ' 00:00:00 UTC' );
        $startTs = strtotime( $start . ' 00:00:00 UTC' );
        $periodDays = ( false !== $endTs && false !== $startTs ) ? max( 1, (int) floor( ( $endTs - $startTs ) / DAY_IN_SECONDS ) + 1 ) : self::REPORT_DAYS;
        $previousEndTs = ( false !== $startTs ? $startTs : time() ) - DAY_IN_SECONDS;
        $previousStartTs = $previousEndTs - ( ( $periodDays - 1 ) * DAY_IN_SECONDS );
        $previousEnd = gmdate( 'Y-m-d', $previousEndTs );
        $previousStart = gmdate( 'Y-m-d', $previousStartTs );
        $messages = array();

        $custom = apply_filters( 'yablonsky_seo_search_performance_external_report_v1', null, $start, $end, $previousStart, $previousEnd );
        if ( is_array( $custom ) && $this->hasExternalEvidence( $custom ) ) { return $custom; }

        $siteKitPresent = defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( 'Google\\Site_Kit\\Plugin' );
        if ( ! $siteKitPresent ) {
            return array( 'search_console' => array(), 'comparison' => array(), 'queries' => array(), 'pages' => array(), 'daily' => array(), 'opportunities' => array(), 'analytics' => array(), 'messages' => array( __( 'Native local search signals remain active independently. Connect an external search provider when additional search-engine evidence is useful.', 'yablonsky-seo' ) ), 'start_date' => $start, 'end_date' => $end );
        }

        $route = '/google-site-kit/v1/modules/search-console/data/searchanalytics';
        $base = array( 'startDate' => $start, 'endDate' => $end );

        $search = $this->requestSiteKit( $route, array_merge( $base, array( 'dimensions' => array(), 'limit' => 1 ) ) );
        $searchMetrics = $this->searchMetrics( $search );
        if ( empty( $searchMetrics ) && isset( $moduleStates['search-console']['connected'] ) && ! $moduleStates['search-console']['connected'] ) { $messages[] = __( 'Site Kit Search Console is installed and requires connection for this WordPress user. Complete Search Console setup in Site Kit, then load the report again.', 'yablonsky-seo' ); } elseif ( empty( $searchMetrics ) && empty( $this->siteKitErrors ) ) { $messages[] = __( 'Search Console returned an empty summary for this reporting window.', 'yablonsky-seo' ); }
        $previousSearch = $this->requestSiteKit( $route, array( 'startDate' => $previousStart, 'endDate' => $previousEnd, 'dimensions' => array(), 'limit' => 1 ) );
        $previousMetrics = $this->searchMetrics( $previousSearch );
        $comparison = $this->comparison( $searchMetrics, $previousMetrics );

        $queriesRaw = $this->requestSiteKit( $route, array_merge( $base, array( 'dimensions' => array( 'query' ), 'limit' => self::TABLE_LIMIT ) ) );
        $pagesRaw = $this->requestSiteKit( $route, array_merge( $base, array( 'dimensions' => array( 'page' ), 'limit' => self::TABLE_LIMIT ) ) );
        $queries = $this->searchRows( $queriesRaw );
        $pages = $this->searchRows( $pagesRaw );
        if ( empty( $queries ) && ! empty( $searchMetrics ) ) { $messages[] = __( 'Search Console summary loaded; query-dimension data is empty for this reporting window.', 'yablonsky-seo' ); }
        if ( empty( $pages ) && ! empty( $searchMetrics ) ) { $messages[] = __( 'Search Console summary loaded; page-dimension data is empty for this reporting window.', 'yablonsky-seo' ); }

        $dailyRaw = $this->requestSiteKit( $route, array_merge( $base, array( 'dimensions' => array( 'date' ), 'limit' => 100 ) ) );
        $daily = $this->searchDailySeries( $dailyRaw, $start, $end );
        if ( empty( $daily ) && ! empty( $searchMetrics ) ) { $messages[] = __( 'Search Console summary loaded; chart trend data is empty for this reporting window.', 'yablonsky-seo' ); }

        $analytics = $this->requestSiteKit( '/google-site-kit/v1/modules/analytics-4/data/report', array( 'startDate' => $start, 'endDate' => $end, 'metrics' => array( array( 'name' => 'sessions' ), array( 'name' => 'totalUsers' ) ) ) );
        $analyticsMetrics = $this->analyticsMetrics( $analytics );
        if ( empty( $analyticsMetrics ) && isset( $moduleStates['analytics-4']['connected'] ) && ! $moduleStates['analytics-4']['connected'] ) { $messages[] = __( 'Site Kit Analytics: setup required. Search Console evidence remains available independently.', 'yablonsky-seo' ); } elseif ( empty( $analyticsMetrics ) && empty( $this->siteKitErrors ) ) { $messages[] = __( 'Analytics rows will appear here when data is available for this reporting window. Search Console evidence remains available independently.', 'yablonsky-seo' ); }
        $messages = array_values( array_unique( array_merge( $messages, $this->siteKitErrors ) ) );

        return array( 'search_console' => $searchMetrics, 'comparison' => $comparison, 'queries' => $queries, 'pages' => $pages, 'daily' => $daily, 'opportunities' => $this->opportunities( $queries, $searchMetrics ), 'analytics' => $analyticsMetrics, 'messages' => $messages, 'start_date' => $start, 'end_date' => $end );
    }

    /** @param array<string,mixed> $params @return mixed */
    private function requestSiteKit( string $route, array $params, bool $recordErrors = true ) {
        if ( ! function_exists( 'rest_do_request' ) ) {
            if ( $recordErrors ) { $this->siteKitErrors[] = __( 'WordPress REST dispatch is unavailable, so Site Kit data cannot be read.', 'yablonsky-seo' ); }
            return null;
        }
        $request = new \WP_REST_Request( 'GET', $route );
        foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
        $response = rest_do_request( $request );
        if ( $response instanceof \WP_Error ) {
            if ( $recordErrors ) { $this->recordSiteKitError( $route, $response->get_error_code(), $response->get_error_message(), 0 ); }
            return null;
        }
        if ( ! $response instanceof \WP_REST_Response ) {
            if ( $recordErrors ) { $this->siteKitErrors[] = __( 'Site Kit returned an unexpected internal REST response.', 'yablonsky-seo' ); }
            return null;
        }
        if ( $response->is_error() ) {
            $error = $response->as_error();
            if ( $recordErrors ) { $this->recordSiteKitError( $route, $error->get_error_code(), $error->get_error_message(), $response->get_status() ); }
            return null;
        }

        // Internal REST dispatch stops before WordPress serializes the response.
        // Site Kit datapoints can still contain Google service objects at this point.
        // Normalize through the JSON boundary a normal REST client receives.
        return $this->normalizeSiteKitData( $response->get_data() );
    }

    /** @param mixed $data @return mixed */
    private function normalizeSiteKitData( $data ) {
        if ( null === $data || is_scalar( $data ) ) { return $data; }
        $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) || '' === $json ) { return $data; }
        $decoded = json_decode( $json, true );
        return JSON_ERROR_NONE === json_last_error() ? $decoded : $data;
    }

    private function recordSiteKitError( string $route, string $code, string $message, int $status ): void {
        $module = false !== strpos( $route, '/analytics-4/' ) ? 'Analytics' : ( false !== strpos( $route, '/search-console/' ) ? 'Search Console' : 'Site Kit' );
        $safeCode = sanitize_key( $code );
        $safeMessage = sanitize_text_field( $message );
        if ( function_exists( 'mb_substr' ) ) { $safeMessage = mb_substr( $safeMessage, 0, 220, 'UTF-8' ); } else { $safeMessage = substr( $safeMessage, 0, 220 ); }
        $detail = '' !== $safeCode ? $safeCode : 'request_error';
        if ( $status > 0 ) { $detail .= ' / HTTP ' . $status; }
        $fallback = __( 'Site Kit data request failed.', 'yablonsky-seo' );
        $this->siteKitErrors[] = sprintf( '%1$s: %2$s — %3$s', $module, $detail, '' !== $safeMessage ? $safeMessage : $fallback );
    }

    /** @return array<string,array<string,mixed>> */
    private function moduleStates(): array {
        $data = $this->requestSiteKit( '/google-site-kit/v1/core/modules/data/list', array(), false );
        if ( ! is_array( $data ) ) { return array(); }
        $states = array();
        foreach ( $data as $module ) {
            if ( ! is_array( $module ) || empty( $module['slug'] ) ) { continue; }
            $slug = sanitize_key( (string) $module['slug'] );
            if ( ! in_array( $slug, array( 'search-console', 'analytics-4' ), true ) ) { continue; }
            $states[ $slug ] = array(
                'active' => ! empty( $module['active'] ),
                'connected' => ! empty( $module['connected'] ),
            );
        }
        return $states;
    }

    /** @param array<string,array<string,mixed>> $states */
    private function moduleConnectionLabel( bool $siteKitPresent, array $states ): string {
        if ( ! $siteKitPresent ) { return __( 'Site Kit integration: setup required', 'yablonsky-seo' ); }
        if ( isset( $states['search-console'] ) ) {
            if ( ! empty( $states['search-console']['connected'] ) ) { return __( 'Connected', 'yablonsky-seo' ); }
            if ( ! empty( $states['search-console']['active'] ) ) { return __( 'Active · setup required', 'yablonsky-seo' ); }
            return __( 'Inactive', 'yablonsky-seo' );
        }
        return __( 'Plugin detected · status pending', 'yablonsky-seo' );
    }

    /** @param mixed $data @return array<string,float|int> */
    private function searchMetrics( $data ): array {
        $rows = $this->rawRows( $data );
        if ( empty( $rows ) ) { return array(); }
        $row = $rows[0];
        return array( 'clicks' => (float) ( $row['clicks'] ?? 0 ), 'impressions' => (float) ( $row['impressions'] ?? 0 ), 'ctr' => (float) ( $row['ctr'] ?? 0 ), 'position' => (float) ( $row['position'] ?? 0 ) );
    }

    /** @param mixed $data @return array<int,array{label:string,clicks:float,impressions:float,ctr:float,position:float}> */
    private function searchRows( $data ): array {
        $rows = array_slice( $this->rawRows( $data ), 0, self::TABLE_LIMIT );
        $out = array();
        foreach ( $rows as $row ) {
            $keys = isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : array();
            $label = isset( $keys[0] ) && is_scalar( $keys[0] ) ? trim( (string) $keys[0] ) : '';
            if ( '' === $label ) { continue; }
            $out[] = array( 'label' => $label, 'clicks' => (float) ( $row['clicks'] ?? 0 ), 'impressions' => (float) ( $row['impressions'] ?? 0 ), 'ctr' => (float) ( $row['ctr'] ?? 0 ), 'position' => (float) ( $row['position'] ?? 0 ) );
        }
        return $out;
    }

    /** @param mixed $data @return array<int,array{date:string,clicks:float,impressions:float,ctr:float|null,position:float|null}> */
    private function searchDailySeries( $data, string $start, string $end ): array {
        $map = array();
        foreach ( $this->rawRows( $data ) as $row ) {
            $keys = isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : array();
            $date = isset( $keys[0] ) && is_scalar( $keys[0] ) ? $this->normalizeSearchDate( (string) $keys[0] ) : '';
            if ( '' === $date ) { continue; }
            $impressions = (float) ( $row['impressions'] ?? 0 );
            $map[ $date ] = array(
                'date' => $date,
                'clicks' => (float) ( $row['clicks'] ?? 0 ),
                'impressions' => $impressions,
                'ctr' => isset( $row['ctr'] ) ? (float) $row['ctr'] : null,
                'position' => isset( $row['position'] ) ? (float) $row['position'] : null,
            );
        }
        if ( empty( $map ) ) { return array(); }

        $startTs = strtotime( $start . ' 00:00:00 UTC' );
        $endTs = strtotime( $end . ' 00:00:00 UTC' );
        if ( false === $startTs || false === $endTs || $endTs < $startTs ) { ksort( $map ); return array_values( $map ); }
        $series = array();
        for ( $ts = $startTs; $ts <= $endTs; $ts += DAY_IN_SECONDS ) {
            $date = gmdate( 'Y-m-d', $ts );
            $series[] = $map[ $date ] ?? array( 'date' => $date, 'clicks' => 0.0, 'impressions' => 0.0, 'ctr' => null, 'position' => null );
            if ( count( $series ) >= 35 ) { break; }
        }
        return $series;
    }

    private function normalizeSearchDate( string $date ): string {
        $date = trim( $date );
        if ( preg_match( '/^\d{8}$/', $date ) ) { $date = substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 ); }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return ''; }
        $ts = strtotime( $date . ' 00:00:00 UTC' );
        return false === $ts ? '' : gmdate( 'Y-m-d', $ts );
    }

    /** @param mixed $data @return array<int,array<string,mixed>> */
    private function rawRows( $data ): array {
        $data = $this->normalizeSiteKitData( $data );
        if ( ! is_array( $data ) ) { return array(); }
        $rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : ( isset( $data[0] ) ? $data : array() );
        $out = array();
        foreach ( $rows as $row ) {
            $row = $this->normalizeSiteKitData( $row );
            if ( is_array( $row ) ) { $out[] = $row; }
        }
        return $out;
    }

    /** @param array<string,float|int> $current @param array<string,float|int> $previous @return array<string,float> */
    private function comparison( array $current, array $previous ): array {
        if ( empty( $current ) || empty( $previous ) ) { return array(); }
        $out = array();
        foreach ( array( 'clicks', 'impressions', 'ctr', 'position' ) as $key ) {
            if ( isset( $current[ $key ], $previous[ $key ] ) ) { $out[ $key ] = (float) $current[ $key ] - (float) $previous[ $key ]; }
        }
        return $out;
    }

    /** @param array<int,array{label:string,clicks:float,impressions:float,ctr:float,position:float}> $queries @param array<string,float|int> $summary @return array<int,array{label:string,reason:string}> */
    private function opportunities( array $queries, array $summary ): array {
        if ( empty( $queries ) || ! isset( $summary['ctr'] ) ) { return array(); }
        $siteCtr = (float) $summary['ctr'];
        $candidates = array();
        foreach ( $queries as $row ) {
            if ( $row['impressions'] < 10 || $row['position'] <= 0 || $row['position'] > 20 || $row['ctr'] >= $siteCtr ) { continue; }
            /* translators: 1: query impressions, 2: query CTR percentage, 3: average position. */
            $reason = sprintf( __( '%1$s impressions · %2$s CTR · position %3$s. Review snippet relevance before changing metadata.', 'yablonsky-seo' ), number_format_i18n( $row['impressions'] ), number_format_i18n( $row['ctr'] * 100, 1 ) . '%', number_format_i18n( $row['position'], 1 ) );
            $candidates[] = array( 'label' => $row['label'], 'reason' => $reason, 'impressions' => $row['impressions'] );
        }
        usort( $candidates, static function ( array $a, array $b ): int { return (float) $b['impressions'] <=> (float) $a['impressions']; } );
        $out = array();
        foreach ( array_slice( $candidates, 0, 5 ) as $candidate ) { $out[] = array( 'label' => (string) $candidate['label'], 'reason' => (string) $candidate['reason'] ); }
        return $out;
    }

    /** @param array<int,array{label:string,reason:string}> $opportunities */
    private function renderOpportunities( array $opportunities ): void {
        echo '<section class="yps-card yseo-performance-opportunities"><div class="yps-card-head"><div><span class="yps-card-kicker">' . esc_html__( 'Actionable evidence', 'yablonsky-seo' ) . '</span><h2>' . esc_html__( 'Snippet review opportunities', 'yablonsky-seo' ) . '</h2></div></div>';
        echo '<p class="yps-muted">' . esc_html__( 'Derived from the visible top-query Search Console sample: meaningful impressions, average position 1–20 and CTR below the site summary. Use this as a human review queue; ranking outcomes and rewrites remain outside this signal.', 'yablonsky-seo' ) . '</p>';
        if ( empty( $opportunities ) ) { echo '<p>' . esc_html__( 'The current bounded sample is clear of obvious snippet-review opportunities.', 'yablonsky-seo' ) . '</p></section>'; return; }
        echo '<div class="yseo-performance-opportunity-list">';
        foreach ( $opportunities as $item ) { echo '<div><strong>' . esc_html( $item['label'] ) . '</strong><span>' . esc_html( $item['reason'] ) . '</span></div>'; }
        echo '</div></section>';
    }

    /** @param mixed $data @return array<string,float|int> */
    private function analyticsMetrics( $data ): array {
        if ( ! is_array( $data ) ) { return array(); }
        $rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
        if ( empty( $rows ) || ! is_array( $rows[0] ) ) { return array(); }
        $values = isset( $rows[0]['metricValues'] ) && is_array( $rows[0]['metricValues'] ) ? $rows[0]['metricValues'] : array();
        $headers = isset( $data['metricHeaders'] ) && is_array( $data['metricHeaders'] ) ? $data['metricHeaders'] : array();
        $out = array();
        foreach ( $headers as $index => $header ) {
            if ( ! is_array( $header ) || empty( $header['name'] ) || ! isset( $values[ $index ] ) || ! is_array( $values[ $index ] ) ) { continue; }
            $name = sanitize_key( (string) $header['name'] );
            if ( ! in_array( $name, array( 'sessions', 'totalusers' ), true ) ) { continue; }
            $canonical = 'totalusers' === $name ? 'totalUsers' : 'sessions';
            $out[ $canonical ] = (float) ( $values[ $index ]['value'] ?? 0 );
        }
        return $out;
    }

    /** @param array<int,array{date:string,clicks:float,impressions:float,ctr:float|null,position:float|null}> $daily */
    private function renderTrendCharts( array $daily ): void {
        echo '<section class="yps-card yseo-performance-trends"><div class="yps-card-head"><div><span class="yps-card-kicker">' . esc_html__( 'Period movement', 'yablonsky-seo' ) . '</span><h2>' . esc_html__( 'Search performance trends', 'yablonsky-seo' ) . '</h2><p>' . esc_html__( 'Daily Search Console evidence. Each chart uses its own scale so movement stays readable without mixing unlike units.', 'yablonsky-seo' ) . '</p></div><span class="yps-mini-status neutral">' . esc_html__( 'Search Console', 'yablonsky-seo' ) . '</span></div>';
        if ( empty( $daily ) ) { echo '<p class="yps-muted">' . esc_html__( 'Daily trend rows will appear when the reporting window returns data.', 'yablonsky-seo' ) . '</p></section>'; return; }
        echo '<div class="yseo-performance-chart-grid">';
        $this->renderMetricChart( __( 'Impressions', 'yablonsky-seo' ), $daily, 'impressions', '', false );
        $this->renderMetricChart( __( 'Clicks', 'yablonsky-seo' ), $daily, 'clicks', '', false );
        $this->renderMetricChart( __( 'CTR', 'yablonsky-seo' ), $daily, 'ctr', '%', false, 100.0 );
        $this->renderMetricChart( __( 'Average position', 'yablonsky-seo' ), $daily, 'position', '', true );
        echo '</div><p class="yps-muted yseo-performance-chart-note">' . esc_html__( 'For average position, a lower number is better; its chart is inverted so improvement moves upward.', 'yablonsky-seo' ) . '</p></section>';
    }

    /** @param array<int,array<string,mixed>> $daily */
    private function renderMetricChart( string $label, array $daily, string $key, string $suffix = '', bool $invert = false, float $multiplier = 1.0 ): void {
        $values = array();
        foreach ( $daily as $row ) {
            if ( ! is_array( $row ) || ! array_key_exists( $key, $row ) || null === $row[ $key ] || ! is_numeric( $row[ $key ] ) ) { $values[] = null; continue; }
            $values[] = (float) $row[ $key ] * $multiplier;
        }
        $numeric = array_values( array_filter( $values, static function ( $value ): bool { return null !== $value; } ) );
        if ( empty( $numeric ) ) {
            echo '<article class="yseo-performance-chart"><div class="yseo-performance-chart-head"><strong>' . esc_html( $label ) . '</strong><span>—</span></div><p class="yps-muted">' . esc_html__( 'Daily values will appear when reporting data is available.', 'yablonsky-seo' ) . '</p></article>';
            return;
        }
        $min = min( $numeric );
        $max = max( $numeric );
        if ( abs( $max - $min ) < 0.000001 ) { $max = $min + 1.0; }
        $width = 520.0; $height = 180.0; $left = 34.0; $right = 14.0; $top = 14.0; $bottom = 28.0;
        $plotW = $width - $left - $right; $plotH = $height - $top - $bottom;
        $count = max( 1, count( $values ) - 1 );
        $points = array();
        $segments = array();
        foreach ( $values as $index => $value ) {
            if ( null === $value ) { if ( ! empty( $points ) ) { $segments[] = $points; $points = array(); } continue; }
            $ratio = ( (float) $value - $min ) / ( $max - $min );
            if ( $invert ) { $ratio = 1.0 - $ratio; }
            $x = $left + ( $plotW * ( $index / $count ) );
            $y = $top + $plotH - ( $plotH * $ratio );
            $points[] = number_format( $x, 2, '.', '' ) . ',' . number_format( $y, 2, '.', '' );
        }
        if ( ! empty( $points ) ) { $segments[] = $points; }
        $last = end( $numeric );
        $avg = array_sum( $numeric ) / max( 1, count( $numeric ) );
        $firstDate = isset( $daily[0]['date'] ) ? (string) $daily[0]['date'] : '';
        $lastRow = end( $daily );
        $lastDate = is_array( $lastRow ) && isset( $lastRow['date'] ) ? (string) $lastRow['date'] : '';
        $format = static function ( float $value, string $unit ): string {
            $decimals = '%' === $unit ? 1 : ( abs( $value ) < 10 && abs( $value - round( $value ) ) > 0.01 ? 1 : 0 );
            return number_format_i18n( $value, $decimals ) . $unit;
        };
        $periodDays = max( 1, count( $numeric ) );
        /* translators: %d: number of days represented in a chart average. */
        $averageLabel = sprintf( __( '%dd avg', 'yablonsky-seo' ), $periodDays );
        echo '<article class="yseo-performance-chart yseo-chart-data-card"><div class="yseo-performance-chart-head"><strong>' . esc_html( $label ) . '</strong><span class="yseo-chart-head-value"><small>' . esc_html__( 'Latest', 'yablonsky-seo' ) . '</small><b>' . esc_html( $format( (float) $last, $suffix ) ) . '</b></span></div><div class="yseo-performance-chart-meta"><small>' . esc_html( $averageLabel ) . ' ' . esc_html( $format( $avg, $suffix ) ) . '</small><small>' . esc_html( $firstDate . ' → ' . $lastDate ) . '</small></div>';
        // Local chart data only: no network request and no stored visitor-level records.
        // Keep the server-rendered SVG as a useful fallback when JavaScript is unavailable.
        $chartData = array();
        foreach ( $values as $index => $value ) {
            $chartData[] = array(
                'date' => isset( $daily[ $index ]['date'] ) ? (string) $daily[ $index ]['date'] : '',
                'value' => $value,
                'label' => null !== $value ? $format( (float) $value, $suffix ) : '',
            );
        }
        echo '<svg class="yseo-performance-svg" viewBox="0 0 520 180" role="group" aria-label="' . esc_attr( $label . ' ' . __( 'daily trend', 'yablonsky-seo' ) ) . '" data-yseo-series="' . esc_attr( wp_json_encode( $chartData ) ) . '" data-yseo-invert="' . ( $invert ? '1' : '0' ) . '">';
        foreach ( array( 0.0, 0.5, 1.0 ) as $grid ) { $gy = $top + $plotH * $grid; echo '<line class="yseo-chart-gridline" x1="' . esc_attr( (string) $left ) . '" x2="' . esc_attr( (string) ( $width - $right ) ) . '" y1="' . esc_attr( number_format( $gy, 2, '.', '' ) ) . '" y2="' . esc_attr( number_format( $gy, 2, '.', '' ) ) . '" />'; }
        foreach ( $segments as $segment ) { if ( count( $segment ) >= 2 ) { echo '<polyline class="yseo-chart-line" points="' . esc_attr( implode( ' ', $segment ) ) . '" />'; } }
        $upperLabel = $invert ? $format( $min, $suffix ) : $format( $max, $suffix );
        $lowerLabel = $invert ? $format( $max, $suffix ) : $format( $min, $suffix );
        echo '<text class="yseo-chart-axis" x="2" y="18">' . esc_html( $upperLabel ) . '</text><text class="yseo-chart-axis" x="2" y="151">' . esc_html( $lowerLabel ) . '</text>';
        foreach ( $values as $index => $value ) {
            if ( null === $value ) { continue; }
            $ratio = ( (float) $value - $min ) / ( $max - $min );
            if ( $invert ) { $ratio = 1.0 - $ratio; }
            $cx = $left + ( $plotW * ( $index / $count ) );
            $cy = $top + $plotH - ( $plotH * $ratio );
            $pointLabel = (string) $chartData[ $index ]['date'] . ' · ' . (string) $chartData[ $index ]['label'];
            echo '<circle class="yseo-chart-point" data-index="' . esc_attr( (string) $index ) . '" cx="' . esc_attr( number_format( $cx, 2, '.', '' ) ) . '" cy="' . esc_attr( number_format( $cy, 2, '.', '' ) ) . '" r="7" tabindex="0" aria-label="' . esc_attr( $pointLabel ) . '"><title>' . esc_html( $pointLabel ) . '</title></circle>';
        }
        echo '</svg></article>';
    }


    /** @param array<int,array{label:string,clicks:float,impressions:float,ctr:float,position:float}> $queries @param array<int,array{label:string,clicks:float,impressions:float,ctr:float,position:float}> $pages */
    private function renderLeaderCharts( array $queries, array $pages ): void {
        echo '<section class="yps-card yseo-performance-leaders"><div class="yps-card-head"><div><span class="yps-card-kicker">' . esc_html__( 'Demand leaders', 'yablonsky-seo' ) . '</span><h2>' . esc_html__( 'Where search visibility is concentrated', 'yablonsky-seo' ) . '</h2><p>' . esc_html__( 'Compact visual ranking from the same bounded Search Console sample shown below. Bars are relative inside each card, and each card keeps its metric on a separate scale.', 'yablonsky-seo' ) . '</p></div><span class="yps-mini-status neutral">' . esc_html__( 'Search Console', 'yablonsky-seo' ) . '</span></div>';
        echo '<div class="yseo-performance-leader-grid">';
        $this->renderLeaderCard( __( 'Top queries by impressions', 'yablonsky-seo' ), $queries, 'impressions', __( 'impressions', 'yablonsky-seo' ), 'clicks', __( 'clicks', 'yablonsky-seo' ) );
        $this->renderLeaderCard( __( 'Top pages by clicks', 'yablonsky-seo' ), $pages, 'clicks', __( 'clicks', 'yablonsky-seo' ), 'impressions', __( 'impressions', 'yablonsky-seo' ) );
        echo '</div></section>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function renderLeaderCard( string $title, array $rows, string $metric, string $metricLabel, string $secondaryMetric, string $secondaryLabel ): void {
        $rows = array_slice( $rows, 0, 6 );
        $max = 0.0;
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && isset( $row[ $metric ] ) && is_numeric( $row[ $metric ] ) ) { $max = max( $max, (float) $row[ $metric ] ); }
        }
        echo '<article class="yseo-performance-leader-card"><h3>' . esc_html( $title ) . '</h3>';
        if ( empty( $rows ) || $max <= 0 ) { echo '<p class="yps-muted">' . esc_html__( 'Visual ranking values will appear when this reporting window returns them.', 'yablonsky-seo' ) . '</p></article>'; return; }
        echo '<div class="yseo-performance-bars">';
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['label'] ) ) { continue; }
            $value = isset( $row[ $metric ] ) && is_numeric( $row[ $metric ] ) ? (float) $row[ $metric ] : 0.0;
            $secondary = isset( $row[ $secondaryMetric ] ) && is_numeric( $row[ $secondaryMetric ] ) ? (float) $row[ $secondaryMetric ] : 0.0;
            $width = min( 100.0, max( 2.0, ( $value / $max ) * 100.0 ) );
            $barStyle = 'width:' . number_format( $width, 1, '.', '' ) . '%';
            echo '<div class="yseo-performance-bar-row"><div class="yseo-performance-bar-label"><strong title="' . esc_attr( (string) $row['label'] ) . '">' . esc_html( (string) $row['label'] ) . '</strong><span>' . esc_html( number_format_i18n( $value ) . ' ' . $metricLabel . ' · ' . number_format_i18n( $secondary ) . ' ' . $secondaryLabel ) . '</span></div><div class="yseo-performance-bar-track" aria-hidden="true"><i style="' . esc_attr( $barStyle ) . '"></i></div></div>';
        }
        echo '</div></article>';
    }

    /** @param array<int,array{label:string,clicks:float,impressions:float,ctr:float,position:float}> $rows */
    private function renderSearchTable( string $title, string $labelHeading, array $rows ): void {
        echo '<section class="yps-card yseo-performance-table"><div class="yps-card-head"><div><h2>' . esc_html( $title ) . '</h2></div><span class="yps-mini-status neutral">' . esc_html__( 'Search Console', 'yablonsky-seo' ) . '</span></div>';
        if ( empty( $rows ) ) { echo '<p class="yps-muted">' . esc_html__( 'Reporting rows will appear when this window returns data.', 'yablonsky-seo' ) . '</p></section>'; return; }
        echo '<div class="yseo-performance-table-scroll"><table class="widefat"><thead><tr><th>' . esc_html( $labelHeading ) . '</th><th>' . esc_html__( 'Clicks', 'yablonsky-seo' ) . '</th><th>' . esc_html__( 'Impressions', 'yablonsky-seo' ) . '</th><th>' . esc_html__( 'CTR', 'yablonsky-seo' ) . '</th><th>' . esc_html__( 'Position', 'yablonsky-seo' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr><td><span class="yseo-performance-label">' . esc_html( $row['label'] ) . '</span></td><td>' . esc_html( number_format_i18n( $row['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['impressions'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['ctr'] * 100, 1 ) . '%' ) . '</td><td>' . esc_html( number_format_i18n( $row['position'], 1 ) ) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    /** @param mixed $value @param mixed $change */
    private function metric( string $label, $value, $change = null ): void {
        $display = null === $value ? '—' : ( is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value );
        $changeText = '';
        if ( null !== $change && is_numeric( $change ) ) { $changeText = ( (float) $change > 0 ? '+' : '' ) . number_format_i18n( (float) $change ); }
        elseif ( is_string( $change ) && '' !== $change ) { $changeText = ( '-' !== substr( $change, 0, 1 ) && '0' !== substr( $change, 0, 1 ) ? '+' : '' ) . $change; }
        echo '<div class="yps-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $display ) . '</strong>' . ( '' !== $changeText ? '<small>' . esc_html( $changeText ) . ' ' . esc_html__( 'vs previous period', 'yablonsky-seo' ) . '</small>' : '' ) . '</div>';
    }
}
