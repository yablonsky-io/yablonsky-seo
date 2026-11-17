<?php
/**
 * Ephemeral, isolated WordPress regression checks for the published ZIP.
 * This test runs only in GitHub Actions on an empty test database.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 2 ); }
$checks = 0;
$yseo_assert = static function ( $condition, $message ) use ( &$checks ) {
    ++$checks;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL #{$checks}: {$message}\n" );
        exit( 2 );
    }
    echo "PASS #{$checks}: {$message}\n";
};
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$wpdb->hide_errors();
$admin = get_user_by( 'login', 'admin' );
$yseo_assert( $admin instanceof WP_User, 'Test administrator exists' );
wp_set_current_user( $admin->ID );

$yseo_assert( defined( 'YSEO_VERSION' ) && YSEO_VERSION === '5.87.2', 'Installed version matches GitHub release' );
$yseo_assert( is_plugin_active( 'yablonsky-seo/yablonsky-seo.php' ), 'Plugin active' );
$yseo_assert( get_post_meta( (int ) get_option( 'yseo_ci_seed_post_id' ), '_yps_seo_title', true ) === 'Preserved SEO Title 5.86.6', 'Pre-upgrade YPS SEO title survived' );
$yseo_assert( get_option( 'yps_ci_legacy_preservation_marker' ) === 'preserve-me', 'Pre-upgrade YPS option survived' );

$published = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'YSEO audit public source', 'post_content' => 'Visible and linked content.' ), true );
$target = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'YSEO audit linked target', 'post_content' => 'Linked target.' ), true );
$secret = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'YSEO audit unpublished private', 'post_content' => 'Secret draft text.' ), true );
$yseo_assert( ! is_wp_error( $published ) && ! is_wp_error( $target ) && ! is_wp_error( $secret ), 'Sample published and unpublished posts created' );

$links = new \Yablonsky\PerformanceSEO\SEO\InternalLinks\InternalLinkRepository();
$links->ensureSchema();
$links->replaceSource( (int) $published, 'audit-sha-v1', array( (int) $target => 2 ), 'post', false, gmdate( 'Y-m-d H:i:s' ), 'auto' );
$yseo_assert( $links->sourceHash( (int) $published ) === 'audit-sha-v1', 'Internal-link source ledger stored' );
$counts = $links->statsForPost( (int) $published );
$yseo_assert( is_array( $counts ), 'Internal-link stats returned' );
foreach ( array( 'modified_desc', 'modified_asc', 'title_asc', 'title_desc', 'bogus-sort' ) as $sort ) {
    $wpdb->last_error = '';
    $page = $links->inventoryPage( 1, 25, 'YSEO audit public source', 'post', 'auto', '', '', $sort );
    $pass = '' === (string) $wpdb->last_error && (int) $page['total'] >= 1 && count( $page['items'] ) >= 1;
    if ( ! $pass ) {
        echo 'INTERNAL_LINK_QUERY_DIAGNOSTIC ' . wp_json_encode( array( 'sort' => $sort, 'total' => $page['total'], 'items' => count( $page['items'] ), 'error' => $wpdb->last_error, 'query' => $wpdb->last_query, 'post_status' => get_post_status( (int) $published ) ) ) . "\\n";
    }
    $yseo_assert( $pass, 'Internal links inventory query with sort ' . $sort );
}
$wpdb->last_error = '';
$orphaned = $links->orphanedPage( 1, 25 );
$yseo_assert( '' === (string) $wpdb->last_error && is_array( $orphaned['items'] ), 'Internal links orphaned query executes' );
$links->removePost( (int) $published );
$yseo_assert( $links->sourceHash( (int) $published ) === '', 'Internal-link source cleanup works' );

$missing = new \Yablonsky\PerformanceSEO\SEO\NotFound\NotFoundRepository();
$missing->ensureSchema();
$missing->record( '/yseo-ci-missing-public-page' );
$missing->record( '/yseo-ci-missing-public-page' );
$missing->record( '/phpmyadmin/' );
foreach ( array( 'hits', 'recent', 'oldest', 'first_seen', 'invalid-sort' ) as $sort ) {
    $wpdb->last_error = '';
    $page = $missing->page( 1, 25, 'yseo-ci-missing-public-page', '', '', '', 0, $sort, 'seo' );
    $yseo_assert( '' === (string) $wpdb->last_error && count( $page['items'] ) >= 1 && (int) $page['total'] >= 1, '404 monitor query with sort ' . $sort );
}
$wpdb->last_error = '';
$all = $missing->page( 1, 25, '', '', '', '', 0, 'hits', 'all' );
$yseo_assert( '' === (string) $wpdb->last_error && is_array( $all['items'] ), '404 monitor all signal filter executes' );
$counts = $missing->signalCounts();
$yseo_assert( is_array( $counts ) && isset( $counts['seo'] ), '404 counts returned' );
$diag = $missing->diagnostics();
$yseo_assert( is_array( $diag ) && isset( $diag['total_rows'] ), '404 diagnostics returned' );
$missing->clear();

$local = new \Yablonsky\PerformanceSEO\SEO\Local\LocalSeoController();
$location = wp_insert_post( array( 'post_type' => 'yseo_location', 'post_status' => 'draft', 'post_title' => 'YSEO audit location' ), true );
$yseo_assert( ! is_wp_error( $location ) && get_post_type( (int) $location ) === 'yseo_location', 'Location post type registered' );
update_post_meta( (int) $location, '_yps_local_business_name', 'Before Nonce' );
$yseo_assert( get_post_meta( (int) $location, '_yablseo_local_business_name', true ) === 'Before Nonce', 'Legacy location metadata fallback works' );
$_POST = array( 'yseo_local_business_name' => 'Unauthorized Overwrite' );
$local->saveMeta( (int) $location, get_post( (int) $location ) );
$yseo_assert( get_post_meta( (int) $location, '_yablseo_local_business_name', true ) === 'Before Nonce', 'Local SEO write denied without nonce' );
$_POST['yseo_location_nonce'] = wp_create_nonce( 'yseo_location_meta_save' );
wp_set_current_user( 0 );
$local->saveMeta( (int) $location, get_post( (int) $location ) );
$yseo_assert( get_post_meta( (int) $location, '_yablseo_local_business_name', true ) === 'Before Nonce', 'Local SEO write denied without capability' );
wp_set_current_user( $admin->ID );
$_POST['yseo_location_nonce'] = wp_create_nonce( 'yseo_location_meta_save' );
$_POST['yseo_local_business_name'] = 'Authorized Location';
$local->saveMeta( (int) $location, get_post( (int) $location ) );
$yseo_assert( get_post_meta( (int) $location, '_yablseo_local_business_name', true ) === 'Authorized Location', 'Local SEO authorized save writes canonical meta without overwriting legacy' );
$yseo_assert( get_post_meta( (int) $location, '_yps_local_business_name', true ) === 'Before Nonce', 'Legacy location data remains intact for rollback' );
$_POST = array();

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.org/?a=1&amp;b=2</loc></url></urlset>';
$xmlClass = '\Yablonsky\PerformanceSEO\SEO\Sitemap\SitemapXml';
$yseo_assert( $xmlClass::verifiedDocument( $xml ) === $xml, 'Valid sitemap XML is retained exactly' );
$yseo_assert( $xmlClass::verifiedDocument( $xml . '<!DOCTYPE x>' ) === '', 'Unsafe/malformed XML is rejected' );
$yseo_assert( $xmlClass::text( 'A & B' ) === 'A &amp; B', 'Sitemap XML escaping preserves content meaning' );

$faq = do_shortcode( '[yseo_faq][yseo_qa question="Safe question"]<script>alert(1)</script><strong>Safe answer</strong>[/yseo_qa][/yseo_faq]' );
$yseo_assert( false !== strpos( $faq, 'Safe answer' ) && false === stripos( $faq, '<script' ), 'FAQ nested shortcode output sanitized without losing safe HTML' );

wp_set_current_user( 0 );
$health = rest_do_request( new WP_REST_Request( 'GET', '/yablonsky-seo/v1/health' ) );
$yseo_assert( $health instanceof WP_REST_Response && $health->get_status() === 200, 'Public read-only REST health route responds' );
if ( $health instanceof WP_REST_Response ) {
    $payload = $health->get_data();
    $yseo_assert( isset( $payload['plugin_version'] ) && $payload['plugin_version'] === '5.87.2', 'REST health advertises correct version' );
}
$contract = rest_do_request( new WP_REST_Request( 'GET', '/yablonsky-seo/v1/contract' ) );
$yseo_assert( $contract instanceof WP_REST_Response && $contract->get_status() === 200, 'Public API contract route responds' );
$external = new WP_REST_Request( 'GET', '/yablonsky-seo/v1/head' );
$external->set_param( 'url', 'https://example.invalid/foreign' );
$blocked = rest_do_request( $external );
$yseo_assert( $blocked instanceof WP_REST_Response && $blocked->get_status() === 404, 'Public head route rejects cross-origin URL' );
$private = new WP_REST_Request( 'GET', '/yablonsky-seo/v1/surface' );
$private->set_param( 'type', 'post' );
$private->set_param( 'id', (int) $secret );
$hidden = rest_do_request( $private );
$yseo_assert( $hidden instanceof WP_REST_Response && $hidden->get_status() === 404, 'Public typed surface does not disclose draft posts' );

$yseo_assert( (string) get_option( 'yps_ci_legacy_preservation_marker' ) === 'preserve-me', 'Legacy option is still intact after smoke tests' );
echo "YSEO_CI_SUITE_PASS {$checks} assertions\n";
