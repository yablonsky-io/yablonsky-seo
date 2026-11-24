<?php
/**
 * Real WordPress regression. Run only on an explicitly marked disposable WP-CLI site.
 * Does not replace the end-to-end Yoast migration / HTTP access acceptance tests.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'YSEO_DISPOSABLE_TEST_SITE' ) || ! YSEO_DISPOSABLE_TEST_SITE ) { exit( 2 ); }
if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { throw new \RuntimeException( 'WP_DEBUG must be enabled.' ); }
$admin = get_user_by( 'login', 'admin' );
if ( ! $admin instanceof \WP_User ) { throw new \RuntimeException( 'Test administrator missing.' ); }
wp_set_current_user( $admin->ID );
$checks = 0;
$assert = static function ( $ok, string $message ) use ( &$checks ): void {
    ++$checks;
    if ( ! $ok ) { throw new \RuntimeException( 'FAIL #' . $checks . ': ' . $message ); }
    echo "PASS #{$checks}: {$message}\n";
};
$assert( defined( 'YSEO_VERSION' ) && '5.87.3' === YSEO_VERSION, 'Exact corrective candidate loaded' );
$policy = new \Yablonsky\PerformanceSEO\Migration\Backup\PrivateBackupPath();
$cipher = new \Yablonsky\PerformanceSEO\Migration\Backup\BackupArtifactCipher();
$writer = new \Yablonsky\PerformanceSEO\Migration\Backup\BackupArtifactWriter();
$verifier = new \Yablonsky\PerformanceSEO\Migration\Backup\BackupAnchorVerifier();
$uploads = wp_upload_dir( null, false );
if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) { throw new \RuntimeException( 'Test uploads unavailable.' ); }
$id = 'yoast-' . gmdate( 'YmdHis' ) . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
$path = $policy->createAnchorDirectory( $id );
$assert( 0 === strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'yablonsky-seo/private-backups/' ), 'Actual WP uploads path resolved' );
$cipher->initialize( $path );
$artifact = $path . '/options.jsonl';
$secret = 'fixture-only-' . wp_generate_uuid4();
$offset = $writer->writeAt( $artifact, 0, $secret );
$assert( $offset > strlen( $secret ) && $cipher->read( $artifact, true ) === $secret, 'Real WP option key and encrypted file round-trip' );
$identity = array( 'anchor_id' => $id, 'provider' => 'yoast', 'provider_version' => 'fixture', 'dry_run_source_digest' => 'fixture-digest', 'storage_format' => 'encrypted-frames-v2' );
$manifest = $identity + array( 'artifacts' => array( array( 'name' => 'options.jsonl', 'bytes' => filesize( $artifact ), 'sha256' => hash_file( 'sha256', $artifact ) ) ) );
$anchor = $identity + array( 'path' => $path, 'manifest_sha256' => $writer->writeAtomicJson( $path . '/manifest.json', $manifest ), 'verified_at' => gmdate( 'c' ) );
$assert( $verifier->verifyStored( $anchor ), 'Real WP sealed anchor verification' );
$assert( false === strpos( file_get_contents( $artifact ), $secret ), 'No plaintext artifact bytes in uploads' );
$assert( false === strpos( file_get_contents( $path . '/manifest.json' ), 'fixture-digest' ), 'No plaintext manifest bytes in uploads' );
$failedUploads = static function ( $data ) { $data['error'] = 'test upload failure'; return $data; };
add_filter( 'upload_dir', $failedUploads, 999 );
try {
    $feed = new \Yablonsky\PerformanceSEO\Discovery\CommerceFeedSnapshot( new \Yablonsky\PerformanceSEO\Discovery\ProductFeedRepository() );
    $assert( '' === $feed->path(), 'Real WP uploads error cannot trigger hard-coded fallback' );
    $failed = false;
    try { $policy->root(); } catch ( \Throwable $error ) { $failed = true; }
    $assert( $failed, 'Backup fails closed on real WP uploads error' );
} finally { remove_filter( 'upload_dir', $failedUploads, 999 ); }
$assert( $verifier->verifyStored( $anchor ), 'Original anchor remains valid after upload error clears' );
$controller = new \Yablonsky\PerformanceSEO\Admin\Bulk\BulkSeoEditorController();
$reflect = new \ReflectionClass( $controller );
$nonceAction = $reflect->getConstant( 'NONCE' );
$assert( is_string( $nonceAction ), 'Bulk nonce action resolved' );
$postId = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'T3 bulk regression fixture' ), true );
$assert( ! is_wp_error( $postId ) && $postId > 0, 'Bulk regression fixture created' );
update_post_meta( $postId, '_yablseo_seo_title', 'Old title' );
update_post_meta( $postId, '_yablseo_meta_description', 'Preserve existing description' );
update_post_meta( $postId, '_yablseo_focus_keyphrase', 'Clear this field' );
$_POST = wp_slash( array( '_wpnonce' => wp_create_nonce( $nonceAction ), 'rows' => array( $postId => array( 'seo_title' => "New O'Reilly title", 'focus_keyphrase' => '', 'meta_description' => array( 'invalid' ) ) ) ) );
$_REQUEST = $_POST;
$redirect = static function ( $location ) { throw new \RuntimeException( 'YSEO_TEST_REDIRECT' ); };
add_filter( 'wp_redirect', $redirect, 999 );
try { $controller->save(); throw new \RuntimeException( 'Expected redirect after save.' ); }
catch ( \RuntimeException $error ) { if ( 'YSEO_TEST_REDIRECT' !== $error->getMessage() ) { throw $error; } }
finally { remove_filter( 'wp_redirect', $redirect, 999 ); $_POST = array(); $_REQUEST = array(); }
$assert( 'New O\'Reilly title' === get_post_meta( $postId, '_yablseo_seo_title', true ), 'Bulk edit preserves apostrophe and saves sanitized scalar' );
$assert( 'Preserve existing description' === get_post_meta( $postId, '_yablseo_meta_description', true ), 'Malformed nested input cannot clear existing metadata' );
$assert( '' === get_post_meta( $postId, '_yablseo_focus_keyphrase', true ), 'Intentional empty input still clears field' );
echo "YSEO_T3_WORDPRESS_PASS {$checks} assertions\n";
