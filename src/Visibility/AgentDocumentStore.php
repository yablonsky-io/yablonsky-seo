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
            $stored = file_get_contents( $path );
            $body = is_string( $stored ) ? $this->decrypt( $path, $stored ) : null;
            if ( is_string( $body ) ) {
                return array( 'body' => $body, 'etag' => hash( 'sha256', $body ), 'mtime' => (int) filemtime( $path ), 'cache' => 'HIT' );
            }
        }

        $body = (string) call_user_func( $builder );
        $mtime = time();
        if ( '' !== $path && '' !== $body ) {
            $tmp = $path . '.tmp-' . wp_generate_password( 8, false, false );
            $stored = $this->encrypt( $path, $body );
            if ( is_string( $stored ) && false !== file_put_contents( $tmp, $stored, LOCK_EX ) ) {
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
     * Store only encrypted cache bytes in the actual plugin uploads directory.
     * Direct static-file access cannot bypass the public route's content policy.
     * If uploads or authenticated encryption is unavailable, render without cache.
     */
    private function directory(): string {
        $uploads = wp_upload_dir( null, false );
        if ( ! empty( $uploads['error'] ) || ! isset( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) || '' === trim( $uploads['basedir'] ) || ! $this->cipherAvailable() ) { return ''; }
        static $directories = array();
        static $cleaned = array();
        $uploadBase = $uploads['basedir'];
        if ( ! isset( $cleaned[ $uploadBase ] ) ) {
            $cleaned[ $uploadBase ] = true;
            // Retain the prior cleanup of obsolete plugin-owned plaintext cache files.
            $legacy = trailingslashit( $uploadBase ) . '.yablonsky-seo-agent-cache';
            if ( is_dir( $legacy ) && ! is_link( $legacy ) ) {
                foreach ( array( '*.cache', '*.cache.tmp-*' ) as $pattern ) {
                    foreach ( (array) glob( trailingslashit( $legacy ) . $pattern ) as $file ) {
                        if ( is_string( $file ) && is_file( $file ) && ! is_link( $file ) ) { @unlink( $file ); }
                    }
                }
            }
        }
        $root = trailingslashit( $uploadBase ) . 'yablonsky-seo';
        $candidate = trailingslashit( $root ) . 'agent-cache-v2';
        if ( isset( $directories[ $candidate ] ) && ! is_link( $root ) && $this->privatePermissions( $candidate, 0700 ) && is_dir( $candidate ) && is_writable( $candidate ) ) { return $candidate; }
        if ( is_link( $root ) || is_link( $candidate ) || ( ! is_dir( $candidate ) && ! wp_mkdir_p( $candidate ) ) ) { return ''; }
        if ( is_link( $root ) || is_link( $candidate ) ) { return ''; }
        @chmod( $candidate, 0700 );
        if ( ! is_dir( $candidate ) || ! is_writable( $candidate ) || ! $this->privatePermissions( $candidate, 0700 ) ) { return ''; }
        $directories[ $candidate ] = true;
        return $candidate;
    }

    private function cipherAvailable(): bool {
        return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'openssl_get_cipher_methods' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
    }

    private function cacheKey(): string {
        return hash_hmac( 'sha256', 'yablseo-agent-cache-v2|' . (int) get_current_blog_id() . '|' . home_url( '/' ), wp_salt( 'auth' ), true );
    }

    private function encrypt( string $path, string $body ): ?string {
        if ( ! $this->cipherAvailable() ) { return null; }
        try {
            $nonce = random_bytes( 12 );
            $tag = '';
            $encrypted = openssl_encrypt( $body, 'aes-256-gcm', $this->cacheKey(), OPENSSL_RAW_DATA, $nonce, $tag, basename( $path ), 16 );
            return is_string( $encrypted ) && strlen( $tag ) === 16 ? 'YAC2' . $nonce . $tag . $encrypted : null;
        } catch ( \Throwable $error ) { return null; }
    }

    private function decrypt( string $path, string $stored ): ?string {
        if ( ! $this->cipherAvailable() || strlen( $stored ) < 32 || 'YAC2' !== substr( $stored, 0, 4 ) ) { return null; }
        $body = openssl_decrypt( substr( $stored, 32 ), 'aes-256-gcm', $this->cacheKey(), OPENSSL_RAW_DATA, substr( $stored, 4, 12 ), substr( $stored, 16, 16 ), basename( $path ) );
        return is_string( $body ) ? $body : null;
    }

    /** Require confirmed owner-only file-system permissions; otherwise fall back to uncached output. */
    private function privatePermissions( string $path, int $required ): bool {
        clearstatcache( true, $path );
        if ( is_link( $path ) ) { return false; }
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
