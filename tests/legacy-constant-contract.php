<?php
/**
 * Isolated YSEO 5.87.2 constant and historical-identity compatibility contract.
 *
 * This deliberately simulates the old plugin already being loaded, using safe
 * WordPress stubs and an in-memory option store. It never accesses a real site.
 */
declare(strict_types=1);

define( 'ABSPATH', '/isolated-wordpress/' );
define( 'YPS_FILE', '/isolated-wordpress/wp-content/plugins/yablonsky-performance-seo/yablonsky-performance-seo.php' );
$GLOBALS['yseo_ci_activation'] = array();
$GLOBALS['yseo_ci_options'] = array();

function plugin_dir_path( $file ) {
    return dirname( $file ) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url( $file ) {
    return 'https://unit.example/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function wp_normalize_path( $file ) {
    return str_replace( '\\', '/', (string) $file );
}

function register_activation_hook( $file, $callback ) {
    $GLOBALS['yseo_ci_activation'][] = array( 'file' => $file, 'callback' => $callback );
}

function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['yseo_ci_options'][ $key ] = $value;
    return true;
}

$checks = 0;
$verify = static function ( bool $ok, string $message ) use ( &$checks ): void {
    ++$checks;
    if ( ! $ok ) {
        fwrite( STDERR, "FAIL_CONSTANT #{$checks}: {$message}\n" );
        exit( 1 );
    }
    echo "PASS_CONSTANT #{$checks}: {$message}\n";
};

$file = dirname( __DIR__ ) . '/yablonsky-seo.php';
require $file;

$verify( defined( 'YSEO_VERSION' ) && '5.87.2' === YSEO_VERSION, 'Canonical product version is declared' );
$verify( defined( 'YSEO_FILE' ) && YSEO_FILE === $file, 'Canonical main plugin file is declared' );
$verify( defined( 'YSEO_DIR' ) && YSEO_DIR === dirname( $file ) . DIRECTORY_SEPARATOR, 'Canonical plugin directory is declared' );
$verify( defined( 'YSEO_URL' ) && YSEO_URL === 'https://unit.example/wp-content/plugins/yablonsky-seo/', 'Canonical plugin URL is declared' );
$verify( defined( 'YPS_FILE' ) && YPS_FILE === '/isolated-wordpress/wp-content/plugins/yablonsky-performance-seo/yablonsky-performance-seo.php', 'Historical file identity is not overwritten' );

foreach ( array( 'YPS_VERSION', 'YPS_DIR', 'YPS_URL' ) as $legacyAlias ) {
    $verify( ! defined( $legacyAlias ), "No short-prefix global is reintroduced: {$legacyAlias}" );
}

$activation = $GLOBALS['yseo_ci_activation'];
$verify( 1 === count( $activation ), 'Legacy collision registers exactly one technical handoff activation' );
$verify( YSEO_FILE === $activation[0]['file'], 'Legacy handoff activation remains bound to canonical plugin file' );
$verify( 'yseo_activate_technical_identity_bridge' === $activation[0]['callback'], 'Legacy handoff activation callback is preserved' );
$verify( ! class_exists( '\\Yablonsky\\PerformanceSEO\\Autoloader', false ), 'Legacy loaded case avoids duplicate autoloader boot' );

call_user_func( $activation[0]['callback'] );
$migration = $GLOBALS['yseo_ci_options']['yseo_technical_identity_migration'] ?? array();
$verify(
    is_array( $migration )
    && 'yablonsky-performance-seo/yablonsky-performance-seo.php' === ( $migration['from'] ?? '' )
    && 'yablonsky-seo/yablonsky-seo.php' === ( $migration['to'] ?? '' )
    && 'awaiting_manual_legacy_deactivation' === ( $migration['status'] ?? '' ),
    'Original installation identity handoff metadata remains intact'
);
echo "YSEO_CONSTANT_CONTRACT_PASS {$checks} assertions\n";
