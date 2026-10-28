<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Commerce feed operations and compatibility workspace. Historical slug retained for bookmark continuity. */
final class DiscoveryAdminController {
    public const PAGE = 'yablonsky-seo-discovery';
    private const SAVE_ACTION = 'yseo_save_commerce_feed_settings';
    private const REBUILD_ACTION = 'yseo_rebuild_commerce_feed';
    private const VALIDATE_ACTION = 'yseo_validate_commerce_feed';

    /** @var DiscoverySettings */ private $settings;

    public function __construct() { $this->settings = new DiscoverySettings(); }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'registerMenu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
        add_action( 'admin_post_' . self::REBUILD_ACTION, array( $this, 'rebuildCommerceFeed' ) );
        add_action( 'admin_post_' . self::VALIDATE_ACTION, array( $this, 'validateCommerceFeed' ) );
    }

    public function registerMenu(): void {
        add_submenu_page(
            'yablonsky-seo',
            esc_html__( 'Commerce Feeds', 'yablonsky-seo' ),
            esc_html__( 'Commerce Feeds', 'yablonsky-seo' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render' )
        );
    }

    public function enqueueAssets( string $hook ): void {
        if ( 'yablonsky-seo_page_' . self::PAGE !== $hook ) { return; }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
        wp_localize_script(
            'yps-admin',
            'YSEO_COMMERCE',
            array(
                'labels' => array(
                    'copied'                  => __( 'Copied', 'yablonsky-seo' ),
                    'allMarkets'              => __( 'All markets', 'yablonsky-seo' ),
                    'localRegionalChannels'   => __( 'Local & regional channels', 'yablonsky-seo' ),
                    'globalChannels'          => __( 'Global channels', 'yablonsky-seo' ),
                    'marketAvailability'      => __( 'Market availability still applies', 'yablonsky-seo' ),
                    'match'                   => __( 'match', 'yablonsky-seo' ),
                    'matches'                 => __( 'matches', 'yablonsky-seo' ),
                    'localRegionalShort'      => __( 'local/regional', 'yablonsky-seo' ),
                    'globalShort'             => __( 'global', 'yablonsky-seo' ),
                    'reusablePaths'           => __( 'reusable paths', 'yablonsky-seo' ),
                    'registryEntries'         => __( 'registry entries', 'yablonsky-seo' ),
                    'localOrRegionalChannels' => __( 'local or regional channels', 'yablonsky-seo' ),
                ),
            )
        );
    }

    public function save(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Managing commerce feeds requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( self::SAVE_ACTION );
        $before = $this->settings->all();
        $rawPosted = filter_input( INPUT_POST, 'yseo_commerce', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
        $posted = is_array( $rawPosted ) ? map_deep( $rawPosted, 'sanitize_text_field' ) : array();
        $this->settings->saveCommerceSection( $posted );
        $after = $this->settings->all();
        $beforeCommerce = isset( $before['feeds']['commerce'] ) && is_array( $before['feeds']['commerce'] ) ? $before['feeds']['commerce'] : array();
        $afterCommerce = isset( $after['feeds']['commerce'] ) && is_array( $after['feeds']['commerce'] ) ? $after['feeds']['commerce'] : array();
        if ( wp_json_encode( $beforeCommerce ) !== wp_json_encode( $afterCommerce ) ) {
            $repository = new ProductFeedRepository();
            $repository->ensureSchema();
            $snapshot = new CommerceFeedSnapshot( $repository );
            $indexer = new ProductFeedIndexer( $repository, $snapshot );
            $wasEnabled = ! empty( $beforeCommerce['enabled'] );
            $isEnabled = ! empty( $afterCommerce['enabled'] );
            if ( $isEnabled && ! $wasEnabled ) { $indexer->requestFullRebuild( 'feed-enabled' ); }
            elseif ( $isEnabled ) { $repository->touchRevision(); $snapshot->markDirty( 'settings-change' ); }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
        exit;
    }

    public function rebuildCommerceFeed(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Rebuilding product feeds requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( self::REBUILD_ACTION );
        if ( $this->settings->commerceEnabled() ) {
            $repository = new ProductFeedRepository(); $repository->ensureSchema();
            $snapshot = new CommerceFeedSnapshot( $repository );
            ( new ProductFeedIndexer( $repository, $snapshot ) )->requestFullRebuild( 'manual-rebuild' );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&rebuilt=1' ) );
        exit;
    }

    public function validateCommerceFeed(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Validating product feeds requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( self::VALIDATE_ACTION );
        $repository = new ProductFeedRepository(); $repository->ensureSchema();
        $result = ( new CommerceFeedSnapshot( $repository ) )->validate();
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&validated=' . ( ! empty( $result['ok'] ) ? 'pass' : 'fail' ) ) );
        exit;
    }

    /** @return array<string,array{label:string,hint:string,tone:string}> */
    private function commerceStatusMeta(): array {
        return array(
            'native' => array(
                'label' => __( 'Native', 'yablonsky-seo' ),
                'hint'  => __( 'Direct feed', 'yablonsky-seo' ),
                'tone'  => 'good',
            ),
            'verified' => array(
                'label' => __( 'Verified', 'yablonsky-seo' ),
                'hint'  => __( 'Verified direct', 'yablonsky-seo' ),
                'tone'  => 'good',
            ),
            'compatible' => array(
                'label' => __( 'Compatible', 'yablonsky-seo' ),
                'hint'  => __( 'Compatible path', 'yablonsky-seo' ),
                'tone'  => 'attention',
            ),
            'import' => array(
                'label' => __( 'Import', 'yablonsky-seo' ),
                'hint'  => __( 'Import path', 'yablonsky-seo' ),
                'tone'  => 'attention',
            ),
            'bridge' => array(
                'label' => __( 'Bridge', 'yablonsky-seo' ),
                'hint'  => __( 'Bridge path', 'yablonsky-seo' ),
                'tone'  => 'attention',
            ),
            'mapping' => array(
                'label' => __( 'Mapping', 'yablonsky-seo' ),
                'hint'  => __( 'Separate schema', 'yablonsky-seo' ),
                'tone'  => 'neutral',
            ),
            'different' => array(
                'label' => __( 'Different', 'yablonsky-seo' ),
                'hint'  => __( 'Different schema', 'yablonsky-seo' ),
                'tone'  => 'neutral',
            ),
        );
    }

    /**
     * Return locale-independent health tone with a localized human label.
     * @return array{label:string,tone:string}
     */
    private function freshnessPresentation( bool $enabled, array $backfill, array $state ): array {
        if ( ! $enabled ) { return array( 'label' => __( 'Standby', 'yablonsky-seo' ), 'tone' => 'neutral' ); }
        if ( empty( $backfill['complete'] ) ) { return array( 'label' => __( 'Rebuilding', 'yablonsky-seo' ), 'tone' => 'attention' ); }
        if ( ! empty( $state['dirty'] ) ) { return array( 'label' => __( 'Update queued', 'yablonsky-seo' ), 'tone' => 'attention' ); }
        return array( 'label' => __( 'Current', 'yablonsky-seo' ), 'tone' => 'good' );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $values = $this->settings->all();
        $commerce = isset( $values['feeds']['commerce'] ) && is_array( $values['feeds']['commerce'] ) ? $values['feeds']['commerce'] : array();
        $enabled = ! empty( $commerce['enabled'] );
        $repository = new ProductFeedRepository(); $repository->ensureSchema();
        $snapshot = new CommerceFeedSnapshot( $repository );
        $state = $snapshot->state();
        $logs = array_reverse( $snapshot->logs() );
        $snapshotExists = is_file( $snapshot->path() );
        $feedUrl = home_url( '/' . CommerceFeedController::PATH );
        $products = $snapshotExists ? max( 0, (int) ( $state['products'] ?? 0 ) ) : max( 0, $repository->count() );
        $generatedAt = max( 0, (int) ( $state['generated_at'] ?? 0 ) );
        $duration = isset( $state['duration_ms'] ) ? max( 0, (int) $state['duration_ms'] ) : 0;
        $bytes = isset( $state['bytes'] ) ? max( 0, (int) $state['bytes'] ) : 0;
        $backfill = $repository->backfillState();
        $indexedProducts = max( 0, $repository->count() );
        $productCounts = wp_count_posts( 'product' );
        $catalogProducts = is_object( $productCounts ) && isset( $productCounts->publish ) ? max( 0, (int) $productCounts->publish ) : $indexedProducts;
        // Presentation tone follows the underlying state, not its translated label.
        $freshnessInfo = $this->freshnessPresentation( $enabled, $backfill, $state );
        $freshness = $freshnessInfo['label'];
        $freshTone = $freshnessInfo['tone'];
        $atlas = new CommerceCompatibilityAtlas();
        $platforms = $atlas->platforms();
        $countries = $atlas->countries();
        $statusMeta = $this->commerceStatusMeta();
        $statusCounts = array();
        foreach ( $platforms as $platform ) {
            $platformStatus = sanitize_key( (string) ( $platform['status'] ?? '' ) );
            if ( '' !== $platformStatus ) {
                $statusCounts[ $platformStatus ] = (int) ( $statusCounts[ $platformStatus ] ?? 0 ) + 1;
            }
        }
        $saved = (string) filter_input( INPUT_GET, 'saved', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $rebuilt = (string) filter_input( INPUT_GET, 'rebuilt', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $validated = sanitize_key( (string) filter_input( INPUT_GET, 'validated', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
        ?>
        <div class="wrap yps-wrap yseo-commerce-page">
            <header class="yps-product-header">
                <div class="yps-product-title"><h1><?php echo esc_html__( 'Commerce Feeds', 'yablonsky-seo' ); ?></h1><p><?php echo esc_html__( 'Publish one lightweight product feed, keep prices and stock fresh in the background, and see where the same URL can be reused.', 'yablonsky-seo' ); ?></p></div>
                <div class="yps-product-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yablonsky-seo-sitemaps' ) ); ?>"><?php echo esc_html__( 'Search Sitemaps', 'yablonsky-seo' ); ?></a><span class="yps-pill">v<?php echo esc_html( YPS_VERSION ); ?></span></div>
            </header>
            <?php if ( '1' === $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Commerce feed settings saved.', 'yablonsky-seo' ); ?></p></div><?php endif; ?>
            <?php if ( '1' === $rebuilt ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Background catalog rebuild queued. The last valid snapshot remains public until the replacement is ready.', 'yablonsky-seo' ); ?></p></div><?php endif; ?>
            <?php if ( in_array( $validated, array( 'pass','fail' ), true ) ) : ?><div class="notice <?php echo 'pass' === $validated ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( 'pass' === $validated ? __( 'Published commerce XML validation passed.', 'yablonsky-seo' ) : __( 'Published commerce XML validation failed. Regenerate the feed and review the activity journal.', 'yablonsky-seo' ) ); ?></p></div><?php endif; ?>

            <article class="yps-card yps-card-wide yseo-commerce-hero">
                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Commerce distribution', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Universal Commerce Feed', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status <?php echo $enabled ? 'good' : 'neutral'; ?>"><?php echo esc_html( $enabled ? __( 'ON', 'yablonsky-seo' ) : __( 'OFF', 'yablonsky-seo' ) ); ?></span></div>
                <div class="yseo-feed-commandbar">
                    <div class="yseo-feed-url"><code><?php echo esc_html( $feedUrl ); ?></code><div class="yps-inline-actions"><button type="button" class="button" data-yseo-copy-value="<?php echo esc_attr( $feedUrl ); ?>"><?php echo esc_html__( 'Copy URL', 'yablonsky-seo' ); ?></button><?php if ( $enabled && $snapshotExists ) : ?><a class="button" href="<?php echo esc_url( $feedUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open XML', 'yablonsky-seo' ); ?></a><?php endif; ?></div></div>
                    <div class="yseo-feed-operations"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::REBUILD_ACTION ); ?>" /><?php wp_nonce_field( self::REBUILD_ACTION ); ?><button type="submit" class="button button-primary" <?php disabled( ! $enabled ); ?>><?php echo esc_html__( 'Generate now', 'yablonsky-seo' ); ?></button></form><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::VALIDATE_ACTION ); ?>" /><?php wp_nonce_field( self::VALIDATE_ACTION ); ?><button type="submit" class="button" <?php disabled( ! $snapshotExists ); ?>><?php echo esc_html__( 'Validate XML', 'yablonsky-seo' ); ?></button></form></div>
                </div>
                <div class="yseo-feed-metrics yseo-feed-metrics-five"><div><span><?php echo esc_html__( 'Products', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( $products ) ); ?></strong><?php if ( empty( $backfill['complete'] ) ) : ?><small><?php echo esc_html( sprintf( /* translators: 1: indexed product count, 2: published WooCommerce product count. */ __( '%1$s / %2$s indexed in background', 'yablonsky-seo' ), number_format_i18n( $indexedProducts ), number_format_i18n( $catalogProducts ) ) ); ?></small><?php endif; ?></div><div><span><?php echo esc_html__( 'Last generated', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $generatedAt > 0 ? wp_date( 'Y-m-d H:i', $generatedAt ) : '—' ); ?></strong><?php if ( ! $snapshotExists && empty( $backfill['complete'] ) ) : ?><small><?php echo esc_html__( 'Appears after the first complete snapshot is published.', 'yablonsky-seo' ); ?></small><?php endif; ?></div><div><span><?php echo esc_html__( 'Execution time', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $duration > 0 ? number_format_i18n( $duration ) . ' ms' : '—' ); ?></strong></div><div><span><?php echo esc_html__( 'File size', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( $bytes > 0 ? size_format( $bytes, 1 ) : '—' ); ?></strong></div><div><span><?php echo esc_html__( 'Freshness', 'yablonsky-seo' ); ?></span><strong class="yseo-feed-freshness <?php echo esc_attr( $freshTone ); ?>"><?php echo esc_html( $freshness ); ?></strong><?php if ( empty( $backfill['complete'] ) ) : ?><small><?php echo esc_html__( 'Generate now queues a bounded background rebuild; the metrics finalize when publication completes.', 'yablonsky-seo' ); ?></small><?php endif; ?></div></div>
            </article>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yseo-commerce-settings-form">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" /><?php wp_nonce_field( self::SAVE_ACTION ); ?>
                <div class="yps-section-grid yseo-commerce-settings-grid">
                    <article class="yps-card">
                        <span class="yps-card-kicker"><?php echo esc_html__( 'Feed settings', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Publishing & freshness', 'yablonsky-seo' ); ?></h2>
                        <label class="yseo-discovery-check"><input type="checkbox" name="yseo_commerce[enabled]" value="1" <?php checked( $enabled ); ?> /><span><strong><?php echo esc_html__( 'Enable Universal Commerce XML', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'External consumers always read the last valid materialized snapshot; WooCommerce product rebuilds stay outside public requests.', 'yablonsky-seo' ); ?></small></span></label>
                        <label class="yseo-discovery-field"><span><?php echo esc_html__( 'Safety reconciliation', 'yablonsky-seo' ); ?></span><select name="yseo_commerce[refresh]" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search intervals…', 'yablonsky-seo' ); ?>"><option value="fifteen_minutes" <?php selected( (string) ( $commerce['refresh'] ?? '' ), 'fifteen_minutes' ); ?>><?php echo esc_html__( 'Every 15 minutes', 'yablonsky-seo' ); ?></option><option value="hourly" <?php selected( (string) ( $commerce['refresh'] ?? '' ), 'hourly' ); ?>><?php echo esc_html__( 'Hourly', 'yablonsky-seo' ); ?></option><option value="six_hours" <?php selected( (string) ( $commerce['refresh'] ?? '' ), 'six_hours' ); ?>><?php echo esc_html__( 'Every 6 hours (recommended)', 'yablonsky-seo' ); ?></option><option value="daily" <?php selected( (string) ( $commerce['refresh'] ?? '' ), 'daily' ); ?>><?php echo esc_html__( 'Daily', 'yablonsky-seo' ); ?></option></select><small><?php echo esc_html__( 'Price, stock and product changes are queued automatically; this schedule only catches changes made by importers that bypass normal WooCommerce hooks.', 'yablonsky-seo' ); ?></small></label>
                    </article>
                    <article class="yps-card">
                        <span class="yps-card-kicker"><?php echo esc_html__( 'Feed payload', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Optional commerce fields', 'yablonsky-seo' ); ?></h2>
                        <p class="yps-muted"><?php echo esc_html__( 'Core fields required by the canonical preset stay locked. Optional fields can be removed to keep the feed smaller.', 'yablonsky-seo' ); ?></p>
                        <div class="yps-chip-row"><span class="yps-chip">id</span><span class="yps-chip">title</span><span class="yps-chip">description</span><span class="yps-chip">link</span><span class="yps-chip">image_link</span><span class="yps-chip">availability</span><span class="yps-chip">price</span><span class="yps-chip">condition</span></div>
                        <div class="yseo-feed-options"><?php foreach ( array( 'additional_images' => __( 'Additional images', 'yablonsky-seo' ), 'brand' => __( 'Brand', 'yablonsky-seo' ), 'gtin' => 'GTIN', 'mpn' => 'MPN', 'sale_price' => __( 'Sale price', 'yablonsky-seo' ), 'product_type' => __( 'Product type', 'yablonsky-seo' ) ) as $field => $label ) : ?><label><input type="checkbox" name="yseo_commerce[<?php echo esc_attr( $field ); ?>]" value="1" <?php checked( ! empty( $commerce[ $field ] ) ); ?> /><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></div>
                        <p class="yseo-field-help"><strong>MPN</strong> — <?php echo esc_html__( 'Manufacturer Part Number: the manufacturer-assigned product identifier, useful when a GTIN is unavailable or when a platform uses brand + MPN for product matching.', 'yablonsky-seo' ); ?></p>
                        <div class="yseo-workspace-actions"><button type="submit" class="button button-primary"><?php echo esc_html__( 'Save feed settings', 'yablonsky-seo' ); ?></button></div>
                    </article>
                </div>
            </form>

            <article class="yps-card yps-card-wide yseo-compatibility-atlas" data-yseo-commerce-atlas>
                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'GEO compatibility', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Where can this one feed be used?', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status good"><?php echo esc_html__( 'ONE FEED', 'yablonsky-seo' ); ?></span></div>
                <p class="yps-muted"><?php echo esc_html__( 'Click a country or search by market. Map, selector and platform list stay synchronized; the feed URL remains stable.', 'yablonsky-seo' ); ?></p>
                <div class="yseo-atlas-controls"><div class="yseo-atlas-regions" role="group" aria-label="<?php echo esc_attr__( 'Regions', 'yablonsky-seo' ); ?>"><button type="button" class="button is-active" data-yseo-atlas-region="ALL"><?php echo esc_html__( 'All', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-atlas-region="AMERICAS"><?php echo esc_html__( 'Americas', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-atlas-region="EUROPE"><?php echo esc_html__( 'Europe', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-atlas-region="AFRICA"><?php echo esc_html__( 'Africa', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-atlas-region="ASIA"><?php echo esc_html__( 'Asia', 'yablonsky-seo' ); ?></button><button type="button" class="button" data-yseo-atlas-region="OCEANIA"><?php echo esc_html__( 'Oceania', 'yablonsky-seo' ); ?></button></div><label class="yseo-atlas-country"><span><?php echo esc_html__( 'Market', 'yablonsky-seo' ); ?></span><select id="yseo-commerce-country" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search countries…', 'yablonsky-seo' ); ?>"><option value="ALL"><?php echo esc_html__( 'All markets', 'yablonsky-seo' ); ?></option><?php foreach ( $countries as $code => $name ) : ?><option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label></div>
                <div class="yseo-atlas-layout"><div class="yseo-atlas-map-panel"><div class="yseo-atlas-map-head"><div><strong data-yseo-atlas-selection><?php echo esc_html__( 'All markets', 'yablonsky-seo' ); ?></strong><span data-yseo-atlas-platform-count></span></div><div class="yseo-atlas-map-controls" role="group" aria-label="<?php echo esc_attr__( 'Map zoom', 'yablonsky-seo' ); ?>"><button type="button" class="button button-small" data-yseo-map-zoom="in" aria-label="<?php echo esc_attr__( 'Zoom in', 'yablonsky-seo' ); ?>">+</button><button type="button" class="button button-small" data-yseo-map-zoom="out" aria-label="<?php echo esc_attr__( 'Zoom out', 'yablonsky-seo' ); ?>">−</button><button type="button" class="button button-small" data-yseo-map-reset><?php echo esc_html__( 'Reset', 'yablonsky-seo' ); ?></button></div></div><div class="yseo-atlas-map-coverage"><span><i class="is-covered" aria-hidden="true"></i><?php echo esc_html__( 'Local & regional channels', 'yablonsky-seo' ); ?></span><span><i class="is-uncovered" aria-hidden="true"></i><?php echo esc_html__( 'Global channels remain available for this market under their own market rules. A country-specific channel will appear here when recorded.', 'yablonsky-seo' ); ?></span></div><?php require YPS_DIR . 'src/Discovery/views/commerce-world-map.php'; ?><div class="yseo-atlas-feed-line"><code><?php echo esc_html( $feedUrl ); ?></code><button type="button" class="button button-small" data-yseo-copy-value="<?php echo esc_attr( $feedUrl ); ?>"><?php echo esc_html__( 'Copy feed', 'yablonsky-seo' ); ?></button></div></div>
                <div class="yseo-atlas-platform-panel"><div class="yseo-atlas-grid" id="yseo-commerce-atlas-grid"><?php foreach ( $platforms as $id => $platform ) : $status = sanitize_key( (string) $platform['status'] ); $meta = $statusMeta[ $status ] ?? array( 'label' => ucfirst( $status ), 'hint' => '', 'tone' => 'neutral' ); $tone = (string) $meta['tone']; $regions = $atlas->regionsForPlatform( $platform ); $platformCountries = (array) ( $platform['countries'] ?? array() ); $global = 'global' === (string) ( $platform['scope'] ?? '' ) || in_array( '*', $platformCountries, true ); $siteUrl = esc_url( (string) ( $platform['site_url'] ?? '' ) ); $setupUrl = esc_url( (string) ( $platform['setup_url'] ?? '' ) ); $docsUrl = esc_url( (string) ( $platform['docs_url'] ?? '' ) ); ?><article class="yseo-atlas-platform" data-platform="<?php echo esc_attr( $id ); ?>" data-status="<?php echo esc_attr( $status ); ?>" data-kind="<?php echo esc_attr( sanitize_key( (string) ( $platform['kind'] ?? '' ) ) ); ?>" data-global="<?php echo $global ? '1' : '0'; ?>" data-featured="<?php echo ! empty( $platform['featured'] ) ? '1' : '0'; ?>" data-priority="<?php echo esc_attr( (string) (int) ( $platform['priority'] ?? 0 ) ); ?>" data-country-count="<?php echo esc_attr( $global ? '999' : (string) count( $platformCountries ) ); ?>" data-countries="<?php echo esc_attr( implode( ',', $platformCountries ) ); ?>" data-regions="<?php echo esc_attr( implode( ',', $regions ) ); ?>"><div class="yseo-atlas-platform-head"><strong><?php echo esc_html( (string) $platform['name'] ); ?></strong><span class="yps-mini-status <?php echo esc_attr( $tone ); ?> yseo-commerce-status yseo-commerce-status--<?php echo esc_attr( $status ); ?>" title="<?php echo esc_attr( (string) $meta['hint'] ); ?>"><?php echo esc_html( (string) $meta['label'] ); ?></span></div><p><?php echo esc_html( (string) $platform['note'] ); ?></p><?php if ( $siteUrl || $setupUrl || $docsUrl ) : ?><div class="yseo-atlas-links"><?php if ( $siteUrl ) : ?><a class="button button-small" href="<?php echo esc_url( $siteUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Platform', 'yablonsky-seo' ); ?> ↗</a><?php endif; ?><?php if ( $setupUrl ) : ?><a class="button button-small" href="<?php echo esc_url( $setupUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Merchant / setup', 'yablonsky-seo' ); ?> ↗</a><?php endif; ?><?php if ( $docsUrl ) : ?><a class="button button-small" href="<?php echo esc_url( $docsUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'XML docs', 'yablonsky-seo' ); ?> ↗</a><?php endif; ?></div><?php endif; ?></article><?php endforeach; ?></div><div class="yseo-atlas-empty" data-yseo-atlas-empty hidden><?php echo esc_html__( 'Global channels remain available for this market under their own market rules. A country-specific channel will appear here when recorded.', 'yablonsky-seo' ); ?></div></div></div>
                <div class="yseo-atlas-legend" aria-label="<?php echo esc_attr__( 'Compatibility status legend', 'yablonsky-seo' ); ?>"><?php foreach ( $statusMeta as $statusKey => $statusInfo ) : if ( empty( $statusCounts[ $statusKey ] ) ) { continue; } ?><span title="<?php echo esc_attr( (string) $statusInfo['hint'] ); ?>"><i class="yseo-commerce-status--<?php echo esc_attr( $statusKey ); ?>" aria-hidden="true"></i><strong><?php echo esc_html( (string) $statusInfo['label'] ); ?></strong><small><?php echo esc_html( (string) $statusInfo['hint'] ); ?></small></span><?php endforeach; ?></div>
            </article>

            <article class="yps-card yps-card-wide yseo-feed-activity-card"><details><summary><strong><?php echo esc_html__( 'Recent feed activity', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html( sprintf( /* translators: %d: number of log entries. */ __( '%d events', 'yablonsky-seo' ), count( $logs ) ) ); ?></span></summary><div class="yseo-feed-log"><?php if ( empty( $logs ) ) : ?><p class="yps-muted"><?php echo esc_html__( 'Feed publication events will appear here after the first publication event.', 'yablonsky-seo' ); ?></p><?php else : foreach ( $logs as $entry ) : ?><div><time><?php echo esc_html( ! empty( $entry['at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $entry['at'] ) : '—' ); ?></time><strong><?php echo esc_html( (string) ( $entry['event'] ?? '' ) ); ?></strong><span><?php echo esc_html( wp_json_encode( $entry['context'] ?? array(), JSON_UNESCAPED_SLASHES ) ?: '' ); ?></span></div><?php endforeach; endif; ?></div></details></article>

            <?php if ( $products >= 5000 || $bytes >= 10 * MB_IN_BYTES ) : ?><aside class="yps-callout yseo-yperf-suggestion"><strong><?php echo esc_html__( 'Delivery acceleration available', 'yablonsky-seo' ); ?></strong><span><?php echo esc_html__( 'This catalog is large enough that cache/origin delivery matters. Yablonsky Performance can add acceleration, while Commerce Feeds works independently.', 'yablonsky-seo' ); ?></span></aside><?php endif; ?>
        </div>
        <?php
    }
}
