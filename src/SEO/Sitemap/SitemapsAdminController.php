<?php
namespace Yablonsky\PerformanceSEO\SEO\Sitemap;

use Yablonsky\PerformanceSEO\Discovery\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Dedicated operational workspace for search sitemaps. */
final class SitemapsAdminController {
    public const PAGE = 'yablonsky-seo-sitemaps';
    private const SAVE_ACTION = 'yseo_save_sitemap_workspace';

    /** @var SitemapRepository */ private $repository;
    /** @var DiscoverySettings */ private $settings;

    public function __construct( SitemapRepository $repository ) {
        $this->repository = $repository;
        $this->settings = new DiscoverySettings();
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'registerMenu' ), 18 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
    }

    public function registerMenu(): void {
        add_submenu_page(
            'yablonsky-seo',
            esc_html__( 'Sitemaps', 'yablonsky-seo' ),
            esc_html__( 'Sitemaps', 'yablonsky-seo' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render' )
        );
    }

    public function enqueueAssets( string $hook ): void {
        if ( 'yablonsky-seo_page_' . self::PAGE !== $hook ) { return; }
        wp_enqueue_style( 'yps-admin', YPS_URL . 'assets/admin.css', array(), YPS_VERSION );
        wp_enqueue_script( 'yps-admin', YPS_URL . 'assets/admin.js', array(), YPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
    }

    public function save(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Managing sitemaps requires the corresponding WordPress capability.', 'yablonsky-seo' ) ); }
        check_admin_referer( self::SAVE_ACTION );
        $before = $this->settings->all();
        $rawPosted = filter_input( INPUT_POST, 'yseo_sitemap', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
        $posted = is_array( $rawPosted ) ? map_deep( $rawPosted, 'sanitize_text_field' ) : array();
        $this->settings->saveSitemapSection( $posted );
        $after = $this->settings->all();
        $beforeSitemap = isset( $before['sitemap'] ) && is_array( $before['sitemap'] ) ? $before['sitemap'] : array();
        $afterSitemap = isset( $after['sitemap'] ) && is_array( $after['sitemap'] ) ? $after['sitemap'] : array();
        $beforeFamilies = isset( $beforeSitemap['families'] ) && is_array( $beforeSitemap['families'] ) ? $beforeSitemap['families'] : array();
        $afterFamilies = isset( $afterSitemap['families'] ) && is_array( $afterSitemap['families'] ) ? $afterSitemap['families'] : array();
        if ( wp_json_encode( $beforeFamilies ) !== wp_json_encode( $afterFamilies ) ) {
            $this->repository->truncateAndReset();
            if ( ! wp_next_scheduled( 'yps_sitemap_backfill' ) ) { wp_schedule_single_event( time() + 5, 'yps_sitemap_backfill' ); }
        } elseif ( wp_json_encode( $beforeSitemap['payload'] ?? array() ) !== wp_json_encode( $afterSitemap['payload'] ?? array() ) ) {
            ( new SitemapPageCacheCompatibility( $this->repository ) )->flushAllKnown();
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = $this->settings->all();
        $sitemap = isset( $settings['sitemap'] ) && is_array( $settings['sitemap'] ) ? $settings['sitemap'] : array();
        $payload = isset( $sitemap['payload'] ) && is_array( $sitemap['payload'] ) ? $sitemap['payload'] : array();
        $inventory = $this->settings->familyInventory();
        $manifest = $this->repository->manifest();
        $routes = ( new SitemapRouteMap() )->build( $manifest );
        $diagnostics = $this->repository->diagnostics();
        $transport = ( new SitemapTransportProbe() )->lastResult();
        $extraSitemaps = apply_filters( 'yablonsky_seo_sitemap_index_extra_v1', array() );
        $extraSitemaps = is_array( $extraSitemaps ) ? $extraSitemaps : array();
        $counts = array();
        foreach ( $manifest as $row ) {
            $key = sanitize_key( (string) ( $row['resource_type'] ?? '' ) ) . ':' . sanitize_key( (string) ( $row['resource_subtype'] ?? '' ) );
            $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + max( 0, (int) ( $row['url_count'] ?? 0 ) );
        }
        $indexUrl = home_url( '/sitemap_index.xml' );
        $saved = (string) filter_input( INPUT_GET, 'saved', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        ?>
        <div class="wrap yps-wrap yseo-sitemaps-page">
            <header class="yps-product-header">
                <div class="yps-product-title"><h1><?php echo esc_html__( 'Sitemaps', 'yablonsky-seo' ); ?></h1><p><?php echo esc_html__( 'Publish only intentional search inventory, keep XML lean, and copy or inspect every live sitemap from one workspace.', 'yablonsky-seo' ); ?></p></div>
                <div class="yps-product-actions"><span class="yps-pill">v<?php echo esc_html( YPS_VERSION ); ?></span></div>
            </header>
            <?php if ( '1' === $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Sitemap settings saved. Membership rebuild is queued when required.', 'yablonsky-seo' ); ?></p></div><?php endif; ?>

            <article class="yps-card yps-card-wide yseo-sitemap-endpoints">
                <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Search XML', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Sitemap endpoints', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status <?php echo ! empty( $diagnostics['ready'] ) ? 'good' : 'attention'; ?>"><?php echo esc_html( ! empty( $diagnostics['ready'] ) ? __( 'READY', 'yablonsky-seo' ) : __( 'REBUILDING', 'yablonsky-seo' ) ); ?></span></div>
                <div class="yseo-endpoint-primary">
                    <div><strong><?php echo esc_html__( 'Sitemap index', 'yablonsky-seo' ); ?></strong><code><?php echo esc_html( $indexUrl ); ?></code></div>
                    <div class="yps-inline-actions"><button type="button" class="button" data-yseo-copy-value="<?php echo esc_attr( $indexUrl ); ?>"><?php echo esc_html__( 'Copy URL', 'yablonsky-seo' ); ?></button><a class="button button-primary" href="<?php echo esc_url( $indexUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open', 'yablonsky-seo' ); ?></a></div>
                </div>
                <div class="yseo-sitemap-endpoint-table-wrap"><table class="widefat striped yseo-sitemap-endpoint-table"><thead><tr><th><?php echo esc_html__( 'Sitemap', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'URLs', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Endpoint', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Actions', 'yablonsky-seo' ); ?></th></tr></thead><tbody>
                <?php if ( empty( $manifest ) && empty( $extraSitemaps ) ) : ?>
                    <tr><td colspan="4"><span class="yps-muted"><?php echo esc_html__( 'Child sitemap publication is pending the bounded background rebuild. This list will populate as the rebuild completes.', 'yablonsky-seo' ); ?></span></td></tr>
                <?php else : foreach ( $manifest as $row ) :
                    $resourceKey = sanitize_key( (string) $row['resource_type'] ) . ':' . sanitize_key( (string) $row['resource_subtype'] );
                    if ( ! isset( $routes['by_resource'][ $resourceKey ] ) ) { continue; }
                    $slug = (string) $routes['by_resource'][ $resourceKey ];
                    $bucket = max( 1, (int) $row['bucket_no'] );
                    $url = home_url( '/' . $slug . '-sitemap' . ( $bucket > 1 ? $bucket : '' ) . '.xml' );
                    $label = isset( $inventory[ $resourceKey ]['label'] ) ? (string) $inventory[ $resourceKey ]['label'] : (string) $row['resource_subtype'];
                    ?>
                    <tr><td><strong><?php echo esc_html( $label ); ?></strong><?php if ( $bucket > 1 ) : ?><small><?php echo esc_html( sprintf( /* translators: %d: sitemap bucket number. */ __( 'Part %d', 'yablonsky-seo' ), $bucket ) ); ?></small><?php endif; ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['url_count'] ) ); ?></td><td><code><?php echo esc_html( $url ); ?></code></td><td><div class="yps-inline-actions"><button type="button" class="button button-small" data-yseo-copy-value="<?php echo esc_attr( $url ); ?>"><?php echo esc_html__( 'Copy', 'yablonsky-seo' ); ?></button><a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open', 'yablonsky-seo' ); ?></a></div></td></tr>
                <?php endforeach; foreach ( $extraSitemaps as $extra ) :
                    if ( ! is_array( $extra ) || empty( $extra['loc'] ) ) { continue; }
                    $url = esc_url_raw( (string) $extra['loc'], array( 'http', 'https' ) );
                    if ( '' === $url ) { continue; }
                    $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
                    $label = $path ? ucwords( str_replace( array( '-', '_', '.xml' ), array( ' ', ' ', '' ), $path ) ) : __( 'Extension sitemap', 'yablonsky-seo' );
                    ?>
                    <tr><td><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html__( 'Extension sitemap', 'yablonsky-seo' ); ?></small></td><td>—</td><td><code><?php echo esc_html( $url ); ?></code></td><td><div class="yps-inline-actions"><button type="button" class="button button-small" data-yseo-copy-value="<?php echo esc_attr( $url ); ?>"><?php echo esc_html__( 'Copy', 'yablonsky-seo' ); ?></button><a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open', 'yablonsky-seo' ); ?></a></div></td></tr>
                <?php endforeach; endif; ?>
                </tbody></table></div>
            </article>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" /><?php wp_nonce_field( self::SAVE_ACTION ); ?>
                <div class="yps-section-grid yseo-sitemap-settings-grid">
                    <article class="yps-card yps-card-wide">
                        <div class="yps-card-head"><div><span class="yps-card-kicker"><?php echo esc_html__( 'Search inventory', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'What belongs in the sitemap?', 'yablonsky-seo' ); ?></h2></div><span class="yps-mini-status good"><?php echo esc_html__( 'CURATED', 'yablonsky-seo' ); ?></span></div>
                        <p class="yps-muted"><?php echo esc_html__( 'Exclude low-value archives while robots indexability stays unchanged. System and service routes remain hard-excluded before this layer.', 'yablonsky-seo' ); ?></p>
                        <p class="yseo-sitemap-mode-help"><?php echo esc_html__( 'Auto follows the YSEO recommendation shown in the previous column. Always include / Always exclude are explicit overrides. A family can be effectively included but absent from the published list until it has at least one public URL.', 'yablonsky-seo' ); ?></p>
                        <div class="yseo-discovery-table-wrap"><table class="widefat striped yseo-discovery-table"><thead><tr><th><?php echo esc_html__( 'Content family', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Published URLs', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'YSEO recommendation', 'yablonsky-seo' ); ?></th><th><?php echo esc_html__( 'Publishing mode', 'yablonsky-seo' ); ?></th></tr></thead><tbody>
                        <?php foreach ( $inventory as $key => $definition ) :
                            $mode = $this->settings->familyMode( (string) $definition['resource_type'], (string) $definition['resource_subtype'] );
                            $enabled = $this->settings->familyEnabled( (string) $definition['resource_type'], (string) $definition['resource_subtype'] );
                            ?>
                            <?php
                            $recommended = ! empty( $definition['recommended'] );
                            $publishedCount = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
                            $effectiveLabel = $enabled ? __( 'Include', 'yablonsky-seo' ) : __( 'Exclude', 'yablonsky-seo' );
                            $effectiveDetail = $enabled && $publishedCount < 1 ? __( 'Effective: Include · waiting for a published URL', 'yablonsky-seo' ) : sprintf( /* translators: %s: Include or Exclude. */ __( 'Effective: %s', 'yablonsky-seo' ), $effectiveLabel );
                            $autoLabel = $recommended ? __( 'Auto — Include', 'yablonsky-seo' ) : __( 'Auto — Exclude', 'yablonsky-seo' );
                            ?>
                            <tr><td><strong><?php echo esc_html( (string) $definition['label'] ); ?></strong><code><?php echo esc_html( (string) $definition['machine'] ); ?></code></td><td><?php echo esc_html( $publishedCount > 0 ? number_format_i18n( $publishedCount ) : '—' ); ?></td><td><span class="yps-mini-status <?php echo $recommended ? 'good' : 'neutral'; ?>"><?php echo esc_html( $recommended ? __( 'INCLUDE', 'yablonsky-seo' ) : __( 'EXCLUDE', 'yablonsky-seo' ) ); ?></span><small><?php echo esc_html( (string) $definition['reason'] ); ?></small></td><td><select name="yseo_sitemap[families][<?php echo esc_attr( $key ); ?>]" data-yps-search-select data-yps-search-placeholder="<?php echo esc_attr__( 'Search publishing modes…', 'yablonsky-seo' ); ?>"><option value="recommended" <?php selected( $mode, 'recommended' ); ?>><?php echo esc_html( $autoLabel ); ?></option><option value="include" <?php selected( $mode, 'include' ); ?>><?php echo esc_html__( 'Always include', 'yablonsky-seo' ); ?></option><option value="exclude" <?php selected( $mode, 'exclude' ); ?>><?php echo esc_html__( 'Always exclude', 'yablonsky-seo' ); ?></option></select><small class="yseo-sitemap-effective <?php echo $enabled ? 'is-on' : 'is-off'; ?>"><?php echo esc_html( $effectiveDetail ); ?></small></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    </article>
                    <article class="yps-card">
                        <span class="yps-card-kicker"><?php echo esc_html__( 'XML payload', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Keep crawler output lean', 'yablonsky-seo' ); ?></h2>
                        <p class="yps-muted"><?php echo esc_html__( 'The URL is mandatory. Last-modified and image discovery are optional machine data. Output remains pure XML.', 'yablonsky-seo' ); ?></p>
                        <label class="yseo-discovery-check"><input type="checkbox" name="yseo_sitemap[payload][lastmod]" value="1" <?php checked( ! empty( $payload['lastmod'] ) ); ?> /><span><strong><?php echo esc_html__( 'Last modified', 'yablonsky-seo' ); ?></strong><small><?php echo esc_html__( 'Recommended when a trustworthy semantic modification time is available.', 'yablonsky-seo' ); ?></small></span></label>
                        <label class="yseo-discovery-field"><span><?php echo esc_html__( 'Images', 'yablonsky-seo' ); ?></span><select name="yseo_sitemap[payload][images]" data-yps-search-select><option value="off" <?php selected( (string) ( $payload['images'] ?? 'primary' ), 'off' ); ?>><?php echo esc_html__( 'Off', 'yablonsky-seo' ); ?></option><option value="primary" <?php selected( (string) ( $payload['images'] ?? 'primary' ), 'primary' ); ?>><?php echo esc_html__( 'Primary only (recommended)', 'yablonsky-seo' ); ?></option><option value="all" <?php selected( (string) ( $payload['images'] ?? 'primary' ), 'all' ); ?>><?php echo esc_html__( 'All relevant images', 'yablonsky-seo' ); ?></option></select></label>
                        <label class="yseo-discovery-field"><span><?php echo esc_html__( 'Maximum images per URL', 'yablonsky-seo' ); ?></span><select name="yseo_sitemap[payload][max_images]" data-yps-search-select><?php foreach ( array( 1,3,5,10,20 ) as $max ) : ?><option value="<?php echo esc_attr( (string) $max ); ?>" <?php selected( (int) ( $payload['max_images'] ?? 1 ), $max ); ?>><?php echo esc_html( (string) $max ); ?></option><?php endforeach; ?></select></label>
                        <div class="yseo-workspace-actions"><button type="submit" class="button button-primary"><?php echo esc_html__( 'Save sitemap settings', 'yablonsky-seo' ); ?></button></div>
                    </article>
                    <article class="yps-card">
                        <span class="yps-card-kicker"><?php echo esc_html__( 'Health', 'yablonsky-seo' ); ?></span><h2><?php echo esc_html__( 'Sitemap diagnostics', 'yablonsky-seo' ); ?></h2>
                        <div class="yseo-compact-metrics"><div><span><?php echo esc_html__( 'URLs', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) ( $diagnostics['urls'] ?? 0 ) ) ); ?></strong></div><div><span><?php echo esc_html__( 'XML files', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) ( $diagnostics['chunks'] ?? 0 ) + 1 ) ); ?></strong></div><div><span><?php echo esc_html__( 'Transport', 'yablonsky-seo' ); ?></span><strong><?php echo esc_html( strtoupper( (string) ( $transport['status'] ?? 'not tested' ) ) ); ?></strong></div></div>
                        <?php $transportUrl = wp_nonce_url( admin_url( 'admin-post.php?action=yseo_sitemap_transport_probe' ), 'yseo_sitemap_transport_probe' ); $purgeUrl = wp_nonce_url( admin_url( 'admin-post.php?action=yseo_sitemap_purge_transport' ), 'yseo_sitemap_purge_transport' ); ?>
                        <div class="yps-inline-actions yseo-workspace-actions"><a class="button button-primary" href="<?php echo esc_url( $transportUrl ); ?>"><?php echo esc_html__( 'Test transport', 'yablonsky-seo' ); ?></a><a class="button" href="<?php echo esc_url( $purgeUrl ); ?>"><?php echo esc_html__( 'Purge sitemap caches', 'yablonsky-seo' ); ?></a></div>
                    </article>
                </div>
            </form>
        </div>
        <?php
    }
}
