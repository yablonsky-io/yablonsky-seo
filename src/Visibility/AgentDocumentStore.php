<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Public agent documents use atomic local file caching so repeated crawler reads avoid expensive content rendering.

/**
 * Small filesystem-backed cache for public agent documents.
 *
 * Cache files are internal implementation details. Public URLs remain the clean
 * /llms.txt and *.md routes; WordPress still controls authority and headers.
 */
final class AgentDocumentStore {
    /** @return array{body:string,etag:string,mtime:int,cache:string} */
    public function remember( string $key, string $fingerprint, callable $builder, int $ttl = 0 ): array {
        $keyHash = hash( 'sha256', $key );
        $fingerprintHash = hash( 'sha256', $fingerprint );
        $directory = $this->directory();
        $path = '' !== $directory ? trailingslashit( $directory ) . $keyHash . '-' . $fingerprintHash . '.cache' : '';

        // Cached bytes are used only when the file still has private permissions.
        if ( '' !== $path && is_file( $path ) && ! $this->privatePermissions( $path, 0600 ) ) { @unlink( $path ); }
        if ( '' !== $path && is_readable( $path ) && ( $ttl <= 0 || (int) filemtime( $path ) + $ttl >= time() ) ) {
            $body = file_get_contents( $path );
            if ( is_string( $body ) ) {
                return array( 'body' => $body, 'etag' => hash( 'sha256', $body ), 'mtime' => (int) filemtime( $path ), 'cache' => 'HIT' );
            }
        }

        $body = (string) call_user_func( $builder );
        $mtime = time();
        if ( '' !== $path && '' !== $body ) {
            $tmp = $path . '.tmp-' . wp_generate_password( 8, false, false );
            if ( false !== file_put_contents( $tmp, $body, LOCK_EX ) ) {
                @chmod( $tmp, 0600 );
                // On shared hosts a failed chmod must not publish a world-readable cache.
                if ( ! $this->privatePermissions( $tmp, 0600 ) ) { @unlink( $tmp ); }
                elseif ( @rename( $tmp, $path ) ) {
                    $mtime = (int) filemtime( $path );
                    $this->cleanupOldVersions( $directory, $keyHash, $path );
                } else {
                    @unlink( $tmp );
                }
            }
        }
        return array( 'body' => $body, 'etag' => hash( 'sha256', $body ), 'mtime' => $mtime, 'cache' => 'MISS' );
    }

    public function purgeKey( string $key ): void {
        $directory = $this->directory();
        if ( '' === $directory ) { return; }
        $keyHash = hash( 'sha256', $key );
        foreach ( (array) glob( trailingslashit( $directory ) . $keyHash . '-*.cache' ) as $path ) {
            if ( is_string( $path ) && is_file( $path ) ) { @unlink( $path ); }
        }
    }

    public function purgeAll(): void {
        $directory = $this->directory();
        if ( '' === $directory ) { return; }
        foreach ( (array) glob( trailingslashit( $directory ) . '*.cache' ) as $path ) {
            if ( is_string( $path ) && is_file( $path ) ) { @unlink( $path ); }
        }
    }

    /**
     * Keep internal AI-document cache bytes outside the HTTP document root.
     * Apache-only .htaccess rules do not protect uploads on Nginx or some CDNs.
     * If private temporary storage is unavailable, continue without disk cache.
     */
    private function directory(): string {
        static $directories = array();
        static $cleanedUploads = array();
        $uploads = wp_upload_dir( null, false );
        if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
            $uploadBase = (string) $uploads['basedir'];
            if ( ! isset( $cleanedUploads[ $uploadBase ] ) ) {
                $cleanedUploads[ $uploadBase ] = true;
                // Delete obsolete cache payloads from the historically web-accessible
                // uploads directory. A .htaccess file alone is not a portable guard.
                $legacy = trailingslashit( $uploadBase ) . '.yablonsky-seo-agent-cache';
                if ( is_dir( $legacy ) && ! is_link( $legacy ) ) {
                    foreach ( array( '*.cache', '*.cache.tmp-*' ) as $pattern ) {
                        foreach ( (array) glob( trailingslashit( $legacy ) . $pattern ) as $file ) {
                            if ( is_string( $file ) && is_file( $file ) ) { @unlink( $file ); }
                        }
                    }
                }
            }
        }

        $temp = realpath( sys_get_temp_dir() );
        if ( false === $temp || ! is_dir( $temp ) || ! is_writable( $temp ) ) { return ''; }
        // Some restricted hosting environments place PHP's temp folder inside
        // the public webroot. Never create the cache in that configuration.
        $documentRoot = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) )
            : '';
        foreach ( array( ABSPATH, $documentRoot ) as $root ) {
            $publicRoot = '' !== $root ? realpath( $root ) : false;
            if ( false !== $publicRoot && ( $temp === $publicRoot || 0 === strpos( $temp, trailingslashit( $publicRoot ) ) ) ) {
                return '';
            }
        }
        // The key isolates Multisite and distinct WordPress installations
        // sharing a host's OS temp directory.
        $siteKey = hash( 'sha256', (string) wp_salt( 'auth' ) . '|' . (int) get_current_blog_id() . '|' . home_url( '/' ) );
        if ( isset( $directories[ $siteKey ] ) ) {
            if ( $this->privatePermissions( $directories[ $siteKey ], 0700 ) ) { return $directories[ $siteKey ]; }
            unset( $directories[ $siteKey ] );
        }
        $candidate = trailingslashit( $temp ) . 'yseo-private-agent-' . $siteKey;
        if ( is_link( $candidate ) || ( ! is_dir( $candidate ) && ! wp_mkdir_p( $candidate ) ) ) { return ''; }
        @chmod( $candidate, 0700 );
        if ( ! is_dir( $candidate ) || ! is_writable( $candidate ) || ! $this->privatePermissions( $candidate, 0700 ) ) { return ''; }
        $directories[ $siteKey ] = $candidate;
        return $candidate;
    }

    /** Require confirmed owner-only file-system permissions; otherwise fall back to uncached output. */
    private function privatePermissions( string $path, int $required ): bool {
        clearstatcache( true, $path );
        $actual = @fileperms( $path );
        return false !== $actual && ( $actual & 0777 ) === $required;
    }

    private function cleanupOldVersions( string $directory, string $keyHash, string $current ): void {
        if ( '' === $directory ) { return; }
        foreach ( (array) glob( trailingslashit( $directory ) . $keyHash . '-*.cache' ) as $path ) {
            if ( is_string( $path ) && $path !== $current && is_file( $path ) ) { @unlink( $path ); }
        }
    }
}
// phpcs:enable WordPress.WP.AlternativeFunctions
