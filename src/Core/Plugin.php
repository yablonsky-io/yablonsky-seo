<?php
namespace Yablonsky\PerformanceSEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Yablonsky\PerformanceSEO\Admin\AdminController;
use Yablonsky\PerformanceSEO\Admin\SeoEditorController;
use Yablonsky\PerformanceSEO\Admin\ContentListSignalsController;
use Yablonsky\PerformanceSEO\Admin\Bulk\BulkSeoEditorController;
use Yablonsky\PerformanceSEO\Runtime\OutputAuthorityGate;
use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;
use Yablonsky\PerformanceSEO\SEO\PublicSeoController;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapController;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapsAdminController;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapIndexer;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapRepository;
use Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPageCacheCompatibility;
use Yablonsky\PerformanceSEO\Visibility\CrawlerRegistry;
use Yablonsky\PerformanceSEO\Visibility\AgentContentController;
use Yablonsky\PerformanceSEO\Visibility\RobotsPolicyController;
use Yablonsky\PerformanceSEO\Visibility\PreferredSourceController;
use Yablonsky\PerformanceSEO\Discovery\IndexNowController;
use Yablonsky\PerformanceSEO\Discovery\DiscoveryAdminController;
use Yablonsky\PerformanceSEO\Discovery\DiscoverySettings;
use Yablonsky\PerformanceSEO\Discovery\ProductFeedRepository;
use Yablonsky\PerformanceSEO\Discovery\ProductFeedIndexer;
use Yablonsky\PerformanceSEO\Discovery\CommerceFeedController;
use Yablonsky\PerformanceSEO\Discovery\CommerceFeedSnapshot;
use Yablonsky\PerformanceSEO\Localization\ProductLocaleRegistry;
use Yablonsky\PerformanceSEO\Language\ResourceLanguageIndex;
use Yablonsky\PerformanceSEO\AI\Metadata\BulkMetadataRunner;
use Yablonsky\PerformanceSEO\SEO\Schema\SchemaAggregatorController;
use Yablonsky\PerformanceSEO\SEO\Headless\HeadlessSeoController;
use Yablonsky\PerformanceSEO\SEO\Redirects\RedirectRepository;
use Yablonsky\PerformanceSEO\SEO\Redirects\RedirectController;
use Yablonsky\PerformanceSEO\SEO\Redirects\RedirectAdminController;
use Yablonsky\PerformanceSEO\SEO\NotFound\NotFoundRepository;
use Yablonsky\PerformanceSEO\SEO\NotFound\NotFoundMonitor;
use Yablonsky\PerformanceSEO\SEO\NotFound\NotFoundSecurityBridge;
use Yablonsky\PerformanceSEO\SEO\NotFound\NotFoundAdminController;
use Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkRepository;
use Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkIndexer;
use Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkAdminController;
use Yablonsky\PerformanceSEO\SEO\Crawl\CrawlOptimizationController;
use Yablonsky\PerformanceSEO\SEO\Rss\RssEnhancementController;
use Yablonsky\PerformanceSEO\SEO\Media\MediaOptimizationController;
use Yablonsky\PerformanceSEO\SEO\Special\SpecialPagesController;
use Yablonsky\PerformanceSEO\SEO\Breadcrumbs\BreadcrumbsController;
use Yablonsky\PerformanceSEO\SEO\Video\VideoSeoController;
use Yablonsky\PerformanceSEO\SEO\News\NewsSeoController;
use Yablonsky\PerformanceSEO\SEO\Local\LocalSeoController;
use Yablonsky\PerformanceSEO\SEO\StructuredContent\StructuredContentController;
use Yablonsky\PerformanceSEO\Integrations\SiteKit\SearchPerformanceController;
use Yablonsky\PerformanceSEO\Analytics\Search\NativeSearchSignalsRepository;
use Yablonsky\PerformanceSEO\Analytics\Search\NativeSearchSignalsTracker;
use Yablonsky\PerformanceSEO\Integrations\YMulti\YMultiSeoBridge;
use Yablonsky\PerformanceSEO\Integrations\WordPress\AbilitiesController;
use Yablonsky\PerformanceSEO\SEO\Inspector\FrontEndSeoInspector;
use Yablonsky\PerformanceSEO\Integrations\Elementor\ElementorContentAdapter;

final class Plugin {
    /** @var bool */
    private static $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;
        add_action( 'init', array( __CLASS__, 'loadTranslations' ), 1 );
        add_action( 'plugins_loaded', array( __CLASS__, 'initialize' ), 50 );
    }

    public static function loadTranslations(): void {
        $locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : ( function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale() );
        $definition = ProductLocaleRegistry::resolveWordPressLocale( $locale );
        if ( ! is_array( $definition ) || ! empty( $definition['source'] ) ) { return; }
        $status = ProductLocaleRegistry::translationStatusForCode( isset( $definition['code'] ) ? (string) $definition['code'] : '' );
        if ( ! is_array( $status ) ) { return; }
        $pack = isset( $status['pack'] ) ? sanitize_file_name( (string) $status['pack'] ) : '';
        if ( '' === $pack ) { return; }

        // Complete packs remain release-gated by ProductLocalePackAudit. A reviewed
        // bundled-partial pack may also load: untranslated keys fall back to the
        // English source catalog, while the audit continues to report it as partial
        // rather than falsely counting it as complete.
        $state = isset( $status['status'] ) ? (string) $status['status'] : '';
        $loadablePartial = 'bundled-partial' === $state;
        if ( ! $loadablePartial && ( empty( $status['complete'] ) || ! ProductLocaleRegistry::hasBundledPack( $locale ) ) ) { return; }

        $mofile = YPS_DIR . 'languages/yablonsky-seo-' . $pack . '.mo';
        if ( is_readable( $mofile ) ) { load_textdomain( 'yablonsky-seo', $mofile ); }
    }

    public static function activate(): void {
        $providers = ( new ProviderDetector() )->detectActiveProviders();
        add_option( 'yps_output_authority', empty( $providers ) ? 'active' : 'shadow', '', false );
        add_option( 'yps_installed_version', YPS_VERSION, '', false );
        update_option( 'yps_installed_version', YPS_VERSION, false );
        add_option( 'yps_public_settings', array(), '', false );
        ( new SitemapRepository() )->ensureSchema();
        ( new RedirectRepository() )->ensureSchema();
        ( new NotFoundRepository() )->ensureSchema();
        ( new InternalLinkRepository() )->ensureSchema();
        ( new LocalSeoController() )->registerPostType();
        ( new NativeSearchSignalsRepository() )->ensureSchema();
        ( new ProductFeedRepository() )->ensureSchema();
        add_option( 'yseo_native_search_signals_enabled', '1', '', false );
        flush_rewrite_rules( false );
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'yps_sitemap_backfill' );
        IndexNowController::clearSchedule();
        wp_clear_scheduled_hook( 'yseo_internal_link_backfill' );
        ResourceLanguageIndex::clearSchedule();
        BulkMetadataRunner::clearSchedule();
        wp_clear_scheduled_hook( 'yseo_video_backfill' );
        NativeSearchSignalsTracker::clearSchedule();
        wp_clear_scheduled_hook( ProductFeedIndexer::CRON_HOOK );
        NotFoundMonitor::clearSchedule();
        NotFoundRepository::clearMaintenanceSchedule();
        flush_rewrite_rules( false );
    }

    public static function initialize(): void {
        $detector = new ProviderDetector();
        $providers = $detector->detectActiveProviders();

        if ( false === get_option( 'yps_output_authority', false ) ) {
            add_option( 'yps_output_authority', empty( $providers ) ? 'active' : 'shadow', '', false );
        }
        if ( false === get_option( 'yps_public_settings', false ) ) {
            add_option( 'yps_public_settings', array(), '', false );
        }
        $installedVersion = (string) get_option( 'yps_installed_version', '' );
        if ( $installedVersion !== YPS_VERSION ) {
            if ( '' !== $installedVersion ) {
                $history = get_option( 'yseo_upgrade_history', array() );
                $history = is_array( $history ) ? $history : array();
                $history[] = array( 'from' => sanitize_text_field( $installedVersion ), 'to' => YPS_VERSION, 'at' => gmdate( 'c' ) );
                update_option( 'yseo_upgrade_history', array_slice( $history, -10 ), false );
            }
            update_option( 'yps_installed_version', YPS_VERSION, false );
        }

        $sitemapRepository = new SitemapRepository();
        $sitemapRepository->ensureSchema();
        $sitemapCacheCompatibility = new SitemapPageCacheCompatibility( $sitemapRepository );
        $sitemapCacheCompatibility->register();
        $sitemapCacheCompatibility->maybeFlushForUpgrade();
        $redirectRepository = new RedirectRepository();
        $redirectRepository->ensureSchema();
        $notFoundRepository = new NotFoundRepository();
        $notFoundRepository->ensureSchema();
        $notFoundRepository->registerMaintenance();
        ( new NotFoundMonitor( $notFoundRepository ) )->register();
        ( new NotFoundSecurityBridge( $notFoundRepository ) )->register();
        $internalLinkRepository = new InternalLinkRepository();
        $internalLinkRepository->ensureSchema();
        ( new ResourceLanguageIndex() )->register();
        ( new ElementorContentAdapter() )->register();
        ( new BulkMetadataRunner() )->register();
        $nativeSearchSignals = new NativeSearchSignalsRepository();
        $nativeSearchSignals->ensureSchema();
        if ( false === get_option( 'yseo_native_search_signals_enabled', false ) ) { add_option( 'yseo_native_search_signals_enabled', '1', '', false ); }
        ( new NativeSearchSignalsTracker( $nativeSearchSignals ) )->register();
        $productFeedRepository = new ProductFeedRepository();
        $productFeedRepository->ensureSchema();
        if ( ( new DiscoverySettings() )->commerceEnabled() ) {
            $commerceSnapshot = new CommerceFeedSnapshot( $productFeedRepository );
            $commerceSnapshot->register();
            ( new ProductFeedIndexer( $productFeedRepository, $commerceSnapshot ) )->register();
            ( new CommerceFeedController( $productFeedRepository ) )->register();
        }
        $internalLinkIndexer = new InternalLinkIndexer( $internalLinkRepository );
        $internalLinkIndexer->register();
        $gate = new OutputAuthorityGate( $providers );
        $canEmitPublicSeo = $gate->canEmitPublicSeo();
        ( new YMultiSeoBridge( $sitemapRepository, $canEmitPublicSeo ) )->register();
        ( new AbilitiesController() )->register();
        $localSeo = new LocalSeoController( $canEmitPublicSeo );
        $localSeo->register();
        $videoSeo = new VideoSeoController( $canEmitPublicSeo );
        $videoSeo->register();
        $newsSeo = new NewsSeoController( $canEmitPublicSeo );
        $newsSeo->register();
        ( new StructuredContentController( $canEmitPublicSeo ) )->register();
        ( new FrontEndSeoInspector() )->register();
        if ( $canEmitPublicSeo ) {
            ( new AgentContentController() )->register();
        }

        // Sitemap materialization is authority-scoped. While another SEO provider (or
        // explicit manual Shadow mode) owns the public surface, YSEO does no background
        // sitemap warm-up work. A later takeover starts from a fresh ledger under the
        // existing WordPress-core sitemap fallback.
        if ( $canEmitPublicSeo ) {
            $sitemapRepository->resumeAfterShadowIfNeeded();
            ( new SitemapIndexer( $sitemapRepository ) )->register();
            ( new IndexNowController() )->register();
        } else {
            $sitemapRepository->pauseForShadow();
            if ( wp_next_scheduled( 'yps_sitemap_backfill' ) ) { wp_clear_scheduled_hook( 'yps_sitemap_backfill' ); }
        }

        if ( $canEmitPublicSeo ) {
            ( new RedirectController( $redirectRepository ) )->register();
        }

        if ( is_admin() ) {
            ( new AdminController( $detector ) )->register();
            ( new SitemapsAdminController( $sitemapRepository ) )->register();
            ( new DiscoveryAdminController() )->register();
            ( new SeoEditorController( $detector ) )->register();
            ( new ContentListSignalsController() )->register();
            ( new BulkSeoEditorController() )->register();
            ( new SearchPerformanceController( $nativeSearchSignals ) )->register();
            ( new RedirectAdminController( $redirectRepository ) )->register();
            ( new NotFoundAdminController( $notFoundRepository ) )->register();
            ( new InternalLinkAdminController( $internalLinkRepository, $internalLinkIndexer ) )->register();
            return;
        }

        // The Preferred Sources shortcode is explicit author content rather than
        // automatic SEO output. Keep it available in Shadow mode so an installed
        // shortcode never leaks as literal text while another SEO provider owns
        // canonical/meta authority. It still makes no request until a reader clicks.
        ( new PreferredSourceController() )->register();
        ( new BreadcrumbsController() )->register();

        // Fail closed around competing providers. Automatic public SEO output is
        // registered only when Yablonsky SEO explicitly owns authority.
        if ( ! $canEmitPublicSeo ) {
            return;
        }

        ( new SitemapController( $sitemapRepository ) )->register();
        ( new PublicSeoController() )->register();
        ( new CrawlOptimizationController() )->register();
        ( new RssEnhancementController() )->register();
        ( new MediaOptimizationController() )->register();
        ( new SpecialPagesController() )->register();
        ( new SchemaAggregatorController() )->register();
        ( new HeadlessSeoController() )->register();
        try {
            $crawlerRegistry = CrawlerRegistry::fromJsonFile( YPS_DIR . 'data/ai-crawlers.json' );
            ( new RobotsPolicyController( $crawlerRegistry ) )->register();
        } catch ( \RuntimeException $error ) {
            // Fail closed: SEO output remains valid even if the optional local crawler registry is unreadable.
            unset( $error );
        }
        do_action( 'yablonsky_seo_public_authority_ready' );
        do_action( 'yablonsky_performance_seo_public_authority_ready' );
    }
}
