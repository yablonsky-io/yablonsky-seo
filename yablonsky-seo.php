<?php
/**
 * Plugin Name: Yablonsky SEO — GEO & AEO
 * Plugin URI: https://yablonsky.io/
 * Description: SEO, GEO & AEO for search and AI discovery. Prepare your site for Google Search, AI Overviews, AI Mode and answer engines.
 * Version: 5.86.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Author: Yablonsky.io
 * Author URI: https://yablonsky.io/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yablonsky-seo
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'YSEO_VERSION', '5.86.0' );
define( 'YSEO_FILE', __FILE__ );
define( 'YSEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'YSEO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation bridge for the historical plugin basename.
 *
 * WordPress loads already-active plugins before it includes a plugin being activated.
 * When the legacy YPS plugin is active, its classes/constants are therefore already in
 * memory and cannot be redeclared safely in the same request. This bridge performs only
 * the technical-identity handoff, then the normal Yablonsky SEO boot occurs on the next
 * request from the new canonical directory.
 */
function yseo_activate_technical_identity_bridge(): void {
    $legacy = 'yablonsky-performance-seo/yablonsky-performance-seo.php';
    update_option(
        'yseo_technical_identity_migration',
        array(
            'from'        => $legacy,
            'to'          => 'yablonsky-seo/yablonsky-seo.php',
            'migrated_at' => time(),
            'status'      => 'awaiting_manual_legacy_deactivation',
        ),
        false
    );
}

$yseo_legacy_loaded = defined( 'YPS_FILE' ) && wp_normalize_path( (string) YPS_FILE ) !== wp_normalize_path( __FILE__ );
if ( $yseo_legacy_loaded ) {
    register_activation_hook( YSEO_FILE, 'yseo_activate_technical_identity_bridge' );
    return;
}

/**
 * Persist a boot-time failure so WordPress remains reachable and the admin sees
 * a precise corrective notice instead of a generic critical-error screen.
 */
function yseo_handle_boot_failure( \Throwable $error ): void {
    $payload = array(
        'time'    => time(),
        'class'   => get_class( $error ),
        'message' => $error->getMessage(),
        'file'    => wp_normalize_path( $error->getFile() ),
        'line'    => (int) $error->getLine(),
        'version' => YSEO_VERSION,
    );
    update_option( 'yseo_boot_failure', $payload, false );
    if ( is_admin() ) {
        add_action( 'admin_notices', 'yseo_render_boot_failure_notice' );
    }
}

/**
 * Admin notice for a trapped boot-time failure.
 */
function yseo_render_boot_failure_notice(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $failure = get_option( 'yseo_boot_failure', array() );
    if ( ! is_array( $failure ) || empty( $failure['message'] ) ) { return; }
    $summary = sprintf(
        /* translators: 1: error class, 2: message, 3: file path, 4: line number. */
        __( '%1$s: %2$s in %3$s on line %4$d', 'yablonsky-seo' ),
        (string) ( $failure['class'] ?? 'Error' ),
        (string) $failure['message'],
        (string) ( $failure['file'] ?? '' ),
        (int) ( $failure['line'] ?? 0 )
    );
    echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Yablonsky SEO trapped a boot-time error and paused part of its startup so the site stays reachable.', 'yablonsky-seo' ) . '</strong></p><p>' . esc_html( $summary ) . '</p></div>';
}

// Backward-compatible internal aliases. Stored data/hooks/meta remain YPS-prefixed.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Historical YPS compatibility alias retained for stored hooks/data.
if ( ! defined( 'YPS_VERSION' ) ) { define( 'YPS_VERSION', YSEO_VERSION ); }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Historical YPS compatibility alias retained for stored hooks/data.
if ( ! defined( 'YPS_FILE' ) ) { define( 'YPS_FILE', YSEO_FILE ); }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Historical YPS compatibility alias retained for stored hooks/data.
if ( ! defined( 'YPS_DIR' ) ) { define( 'YPS_DIR', YSEO_DIR ); }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Historical YPS compatibility alias retained for stored hooks/data.
if ( ! defined( 'YPS_URL' ) ) { define( 'YPS_URL', YSEO_URL ); }

try {
    require_once YSEO_DIR . 'src/Autoloader.php';

    \Yablonsky\PerformanceSEO\Autoloader::register( YSEO_DIR . 'src/' );
    \Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapPresentation::registerTransportGuards();
    require_once YSEO_DIR . 'src/SEO/Developer/functions.php';

    register_activation_hook( YSEO_FILE, array( '\Yablonsky\PerformanceSEO\Core\Plugin', 'activate' ) );
    register_deactivation_hook( YSEO_FILE, array( '\Yablonsky\PerformanceSEO\Core\Plugin', 'deactivate' ) );
    \Yablonsky\PerformanceSEO\Core\Plugin::boot();
    delete_option( 'yseo_boot_failure' );
} catch ( \Throwable $error ) {
    yseo_handle_boot_failure( $error );
}
