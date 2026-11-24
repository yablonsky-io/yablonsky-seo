<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Authenticated, bounded encrypted frames for private artifacts stored in uploads.
 * The per-anchor key is stored in a non-autoloaded site option, not in uploads.
 * Ciphertext offsets, random nonces, and authenticated file identity preserve
 * the writer's existing crash/resume behavior without exposing plaintext files.
 */
final class BackupArtifactCipher {
    private const CIPHER = 'aes-256-gcm';
    private const FRAME_BYTES = 65536;
    private const OPTION_PREFIX = 'yablseo_backup_key_v2_';

    public function initialize( string $directory ): void {
        ( new PrivateBackupPath() )->assertWritableDirectory( $directory );
        $this->assertAvailable();
        if ( ! add_option( self::OPTION_PREFIX . basename( $directory ), bin2hex( random_bytes( 32 ) ), '', false ) ) {
            throw new \RuntimeException( 'backup-encryption-key-create-failed' );
        }
    }

    public function encrypt( string $path, int $offset, string $payload ): string {
        $key = $this->key( $path );
        $result = '';
        for ( $i = 0, $length = strlen( $payload ); $i < $length; $i += self::FRAME_BYTES ) {
            $plain = substr( $payload, $i, self::FRAME_BYTES );
            $nonce = random_bytes( 12 );
            $tag = '';
            $position = $offset + strlen( $result );
            $cipher = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad( $path, $position ), 16 );
            if ( ! is_string( $cipher ) || strlen( $tag ) !== 16 || strlen( $cipher ) !== strlen( $plain ) ) {
                throw new \RuntimeException( 'backup-encryption-failed' );
            }
            $result .= pack( 'N', strlen( $cipher ) ) . $nonce . $tag . $cipher;
        }
        return $result;
    }

    /** Verify each authenticated frame; materialize only the small manifest. */
    public function read( string $path, bool $collect = false ): string {
        ( new PrivateBackupPath() )->assertPrivateFile( $path );
        $key = $this->key( $path );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded streaming authenticated verification of local artifacts.
        $handle = fopen( $path, 'rb' );
        if ( false === $handle ) { throw new \RuntimeException( 'backup-cipher-read-failed' ); }
        $result = '';
        $offset = 0;
        try {
            while ( ! feof( $handle ) ) {
                $header = fread( $handle, 4 );
                if ( '' === $header && feof( $handle ) ) { break; }
                if ( ! is_string( $header ) || strlen( $header ) !== 4 ) { throw new \RuntimeException( 'backup-frame-header-invalid' ); }
                $length = (int) unpack( 'Nlength', $header )['length'];
                if ( $length <= 0 || $length > self::FRAME_BYTES ) { throw new \RuntimeException( 'backup-frame-size-invalid' ); }
                $frame = $this->readExact( $handle, 28 + $length );
                $plain = openssl_decrypt( substr( $frame, 28 ), self::CIPHER, $key, OPENSSL_RAW_DATA, substr( $frame, 0, 12 ), substr( $frame, 12, 16 ), $this->aad( $path, $offset ) );
                if ( ! is_string( $plain ) || strlen( $plain ) !== $length ) { throw new \RuntimeException( 'backup-frame-authentication-failed' ); }
                if ( $collect ) {
                    if ( strlen( $result ) + $length > 1048576 ) { throw new \RuntimeException( 'backup-manifest-too-large' ); }
                    $result .= $plain;
                }
                $offset += 32 + $length;
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close bounded local verification stream.
            fclose( $handle );
        }
        return $result;
    }

    private function readExact( $handle, int $length ): string {
        $data = '';
        while ( strlen( $data ) < $length ) {
            $part = fread( $handle, $length - strlen( $data ) );
            if ( ! is_string( $part ) || '' === $part ) { throw new \RuntimeException( 'backup-frame-truncated' ); }
            $data .= $part;
        }
        return $data;
    }

    private function aad( string $path, int $offset ): string {
        return 'yablseo-backup-v2|' . basename( dirname( $path ) ) . '|' . basename( $path ) . '|' . $offset;
    }

    private function key( string $path ): string {
        $this->assertAvailable();
        $key = get_option( self::OPTION_PREFIX . basename( dirname( $path ) ), '' );
        if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $key ) ) { throw new \RuntimeException( 'backup-encryption-key-unavailable' ); }
        return hex2bin( $key );
    }

    private function assertAvailable(): void {
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) || ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
            throw new \RuntimeException( 'backup-authenticated-encryption-unavailable' );
        }
    }
}
