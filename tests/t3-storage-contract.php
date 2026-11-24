<?php
/**
 * Standalone filesystem/crypto contract, using only small WordPress API stubs.
 * Run with PHP CLI on the checked-out source. This is not a WordPress integration test.
 */
declare(strict_types=1);
namespace Yablonsky\PerformanceSEO\Discovery {
    final class ProductFeedRepository {
        public function isReady(): bool { return true; }
        public function revision(): int { return 1; }
        public function pageAfter( int $cursor, int $limit ): array { return array(); }
    }
}
namespace {
    if ( PHP_SAPI !== 'cli' ) { exit( 2 ); }
    $root = sys_get_temp_dir() . '/yseo-t3-' . bin2hex( random_bytes( 8 ) );
    mkdir( $root . '/wp', 0700, true );
    define( 'ABSPATH', $root . '/wp/' );
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
    $GLOBALS['uploads'] = array( 'basedir' => $root . '/custom-media', 'error' => false );
    $GLOBALS['options'] = array();
    function wp_upload_dir( $time = null, $create = true ) { return $GLOBALS['uploads']; }
    function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0700, true ); }
    function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
    function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
    function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
    function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
    function wp_generate_password( $length, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
    function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
    function add_option( $key, $value, $deprecated = '', $autoload = null ) { if ( array_key_exists( $key, $GLOBALS['options'] ) ) { return false; } $GLOBALS['options'][ $key ] = $value; return true; }
    function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
    function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
    function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }
    function get_current_blog_id() { return 1; }
    function wp_salt( $scheme = 'auth' ) { return 'test-only-site-salt'; }
    function home_url( $path = '/' ) { return 'https://example.test' . $path; }
    function get_bloginfo( $key ) { return 'Fixture'; }
    function esc_url_raw( $url, $protocols = null ) { return $url; }
    function wp_delete_file( $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
    function wp_kses_post( $value ) { return strip_tags( $value, '<p><b>' ); }
    $source = $argv[1] ?? dirname( __DIR__ );
    foreach ( array(
        'Migration/Backup/PrivateBackupPath.php', 'Migration/Backup/BackupArtifactCipher.php',
        'Migration/Backup/BackupArtifactWriter.php', 'Migration/Backup/BackupAnchorVerifier.php',
        'Discovery/CommerceFeedController.php', 'Discovery/CommerceFeedSnapshot.php',
        'Discovery/CommerceFeedSerializer.php', 'SEO/Sitemap/SitemapXml.php',
        'Visibility/AgentDocumentStore.php', 'Core/InputValidation.php',
    ) as $file ) { require $source . '/src/' . $file; }
    $checks = 0;
    $assert = static function ( $ok, $message ) use ( &$checks ): void {
        ++$checks;
        if ( ! $ok ) { throw new \RuntimeException( 'FAIL #' . $checks . ': ' . $message ); }
        echo 'PASS #' . $checks . ': ' . $message . "\n";
    };
    $reject = static function ( callable $action ): bool { try { $action(); } catch ( \Throwable $error ) { return true; } return false; };
    $clean = static function ( string $path ) use ( &$clean ): void { if ( is_dir( $path ) && ! is_link( $path ) ) { foreach ( scandir( $path ) as $name ) { if ( '.' !== $name && '..' !== $name ) { $clean( $path . '/' . $name ); } } rmdir( $path ); } elseif ( file_exists( $path ) || is_link( $path ) ) { unlink( $path ); } };
    try {
        $policy = new \Yablonsky\PerformanceSEO\Migration\Backup\PrivateBackupPath();
        $cipher = new \Yablonsky\PerformanceSEO\Migration\Backup\BackupArtifactCipher();
        $writer = new \Yablonsky\PerformanceSEO\Migration\Backup\BackupArtifactWriter();
        $verifier = new \Yablonsky\PerformanceSEO\Migration\Backup\BackupAnchorVerifier();
        $id = 'yoast-20261124000000-abcdef123456';
        $dir = $policy->createAnchorDirectory( $id );
        $assert( $dir === $GLOBALS['uploads']['basedir'] . '/yablonsky-seo/private-backups/' . $id, 'Custom uploads path and slug used' );
        $cipher->initialize( $dir );
        $p = $dir . '/options.jsonl';
        $a = str_repeat( 'private-SEO-fixture-', 5000 );
        $b = 'second-private-fixture';
        $assert( 0 === $writer->writeAt( $p, 0, '' ), 'Empty artifact initializes' );
        $offset = $writer->writeAt( $p, 0, $a );
        $assert( $offset > strlen( $a ) && $cipher->read( $p, true ) === $a, 'Multi-frame plaintext round-trip' );
        $end = $writer->writeAt( $p, $offset, $b );
        $assert( $cipher->read( $p, true ) === $a . $b, 'Append preserves both chunks' );
        $assert( $end === $writer->writeAt( $p, $offset, $b ) && $cipher->read( $p, true ) === $a . $b, 'Crash retry rewrites without duplicates' );
        $assert( false === strpos( file_get_contents( $p ), 'private-SEO-fixture' ), 'No plaintext in uploaded artifact' );
        $assert( $reject( static function () use ( $writer, $p, $end ) { $writer->writeAt( $p, $end + 100, 'gap' ); } ), 'Missing persisted offset rejected' );
        $identity = array( 'anchor_id' => $id, 'provider' => 'yoast', 'provider_version' => 'test', 'dry_run_source_digest' => 'digest', 'storage_format' => 'encrypted-frames-v2' );
        $manifest = $identity + array( 'artifacts' => array( array( 'name' => 'options.jsonl', 'bytes' => filesize( $p ), 'sha256' => hash_file( 'sha256', $p ) ) ) );
        $sha = $writer->writeAtomicJson( $dir . '/manifest.json', $manifest );
        $anchor = $identity + array( 'path' => $dir, 'manifest_sha256' => $sha, 'verified_at' => gmdate( 'c' ) );
        $assert( $verifier->verifyStored( $anchor ), 'Encrypted manifest and artifact verified' );
        $assert( $verifier->verifyFreshForImport( $anchor, 'test', 'digest' ), 'Fresh import gate preserved' );
        $assert( ! $verifier->verifyFreshForImport( $anchor, 'test', 'different' ), 'Mismatched source identity rejected' );
        $raw = file_get_contents( $p ); $raw[ 40 ] = chr( ord( $raw[ 40 ] ) ^ 1 );
        file_put_contents( $p, $raw );
        $assert( ! $verifier->verifyStored( $anchor ), 'Tampering rejected' );
        $assert( $reject( static function () use ( $cipher, $p ) { $cipher->read( $p, true ); } ), 'Authenticated frame detects corruption' );
        $oldId = 'yoast-20261123000000-abcdef123456';
        $oldDir = $root . '/yps-private-backups/' . $oldId;
        mkdir( $oldDir, 0700, true );
        file_put_contents( $oldDir . '/options.jsonl', 'legacy' ); chmod( $oldDir . '/options.jsonl', 0600 );
        $oldIdentity = array( 'anchor_id' => $oldId, 'provider' => 'yoast', 'provider_version' => 'test', 'dry_run_source_digest' => 'old' );
        $oldManifest = $oldIdentity + array( 'artifacts' => array( array( 'name' => 'options.jsonl', 'bytes' => 6, 'sha256' => hash( 'sha256', 'legacy' ) ) ) );
        file_put_contents( $oldDir . '/manifest.json', json_encode( $oldManifest ) ); chmod( $oldDir . '/manifest.json', 0600 );
        $oldAnchor = $oldIdentity + array( 'path' => $oldDir, 'manifest_sha256' => hash_file( 'sha256', $oldDir . '/manifest.json' ) );
        $assert( $verifier->verifyStored( $oldAnchor ), 'Historical plaintext anchor is still verifiable' );
        $assert( $reject( static function () use ( $writer, $oldDir ) { $writer->writeAt( $oldDir . '/options.jsonl', 6, 'new' ); } ) && file_get_contents( $oldDir . '/options.jsonl' ) === 'legacy', 'Historical external directory is read-only' );
        $snapshot = new \Yablonsky\PerformanceSEO\Discovery\CommerceFeedSnapshot( new \Yablonsky\PerformanceSEO\Discovery\ProductFeedRepository() );
        $assert( $snapshot->path() === $GLOBALS['uploads']['basedir'] . '/yablonsky-seo/commerce/yablonsky-commerce.xml', 'Commerce custom uploads path' );
        $result = $snapshot->publish();
        $assert( ! empty( $result['ok'] ) && is_file( $snapshot->path() ) && ! empty( $snapshot->validate()['ok'] ), 'Atomic feed publish and validation' );
        $store = new \Yablonsky\PerformanceSEO\Visibility\AgentDocumentStore();
        $body = 'fixture-agent-document-secret';
        $first = $store->remember( 'doc', 'revision', static function () use ( $body ) { return $body; } );
        $second = $store->remember( 'doc', 'revision', static function () { throw new \RuntimeException( 'cache hit should not rebuild' ); } );
        $assert( 'MISS' === $first['cache'] && 'HIT' === $second['cache'] && $second['body'] === $body && $first['etag'] === $second['etag'], 'Agent document cache hit and ETag parity' );
        $cache = glob( $GLOBALS['uploads']['basedir'] . '/yablonsky-seo/agent-cache-v2/*.cache' );
        $assert( count( $cache ) === 1 && false === strpos( file_get_contents( $cache[0] ), $body ), 'Agent cache does not expose plaintext' );
        $store->purgeKey( 'doc' );
        $assert( ! is_file( $cache[0] ), 'Agent cache purge retained' );
        $GLOBALS['uploads'] = array( 'error' => 'fixture uploads unavailable' );
        $assert( '' === $snapshot->path() && empty( $snapshot->publish()['ok'] ) && ! is_dir( WP_CONTENT_DIR . '/uploads' ), 'Unavailable uploads fail without fallback writes' );
        $assert( $reject( static function () use ( $policy ) { $policy->root(); } ), 'Backup rejects unavailable uploads' );
        $assert( $verifier->verifyStored( $oldAnchor ), 'Legacy verification does not depend on current uploads' );
        $assert( 'MISS' === $store->remember( 'doc', 'revision', static function () use ( $body ) { return $body; } )['cache'], 'Agent rendering survives unavailable uploads' );
        $json = '{"url":"https://example.test/a%20b","title":"quoted \\"value\\""}';
        $csv = "origin,target,type,format\n/a%20b,/new,301,plain";
        $pattern = '^/items/([0-9]+)\\.html$';
        $assert( \Yablonsky\PerformanceSEO\Core\InputValidation::json( $json ) === $json, 'JSON preserved byte-for-byte' );
        $assert( \Yablonsky\PerformanceSEO\Core\InputValidation::csv( $csv ) === $csv, 'CSV preserved byte-for-byte' );
        $assert( \Yablonsky\PerformanceSEO\Core\InputValidation::urlOrPattern( $pattern ) === $pattern, 'Regex syntax preserved' );
        $assert( null === \Yablonsky\PerformanceSEO\Core\InputValidation::requestTarget( "/a\0b" ), 'Control byte rejected' );
        $assert( null === \Yablonsky\PerformanceSEO\Core\InputValidation::structuredScalar( array( 'unexpected' ) ), 'Non-scalar structured input rejected' );
        echo "YSEO_T3_ISOLATED_PASS {$checks} assertions\n";
    } finally { $clean( $root ); }
}
