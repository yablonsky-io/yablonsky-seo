<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private local rollback artifacts require byte-offset writes, atomic rename, permission checks and direct hashing that WP_Filesystem cannot provide as one crash-resumable primitive.

/**
 * Crash-resumable artifact writer.
 *
 * Every write uses the byte offset persisted in the job state. If a request dies
 * after writing but before saving state, the retry rewrites the same range and
 * truncates at the expected end, so the artifact cannot grow duplicate records.
 */
final class BackupArtifactWriter {
    public function writeAt( string $path, int $offset, string $payload ): int {
        $offset = max( 0, $offset );
        $directory = dirname( $path );
        if ( ! is_dir( $directory ) || is_link( $directory ) ) {
            throw new \RuntimeException( 'Backup artifact directory is unavailable.' );
        }

        $handle = @fopen( $path, 'c+b' );
        if ( false === $handle ) {
            throw new \RuntimeException( 'Unable to open a backup artifact for writing.' );
        }
        try {
            if ( ! flock( $handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock a backup artifact.' );
            }
            if ( 0 !== fseek( $handle, $offset, SEEK_SET ) ) {
                throw new \RuntimeException( 'Unable to seek to the persisted backup offset.' );
            }
            $length = strlen( $payload );
            $written = 0;
            while ( $written < $length ) {
                $result = fwrite( $handle, substr( $payload, $written ) );
                if ( false === $result || 0 === $result ) {
                    throw new \RuntimeException( 'Backup artifact write was incomplete.' );
                }
                $written += $result;
            }
            $end = $offset + $written;
            if ( ! ftruncate( $handle, $end ) ) {
                throw new \RuntimeException( 'Unable to normalize backup artifact length.' );
            }
            fflush( $handle );
            if ( function_exists( 'fsync' ) ) {
                @fsync( $handle );
            }
            flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }
        @chmod( $path, 0600 );
        return $offset + strlen( $payload );
    }

    /** @param array<string,mixed> $data */
    public function writeAtomicJson( string $path, array $data ): string {
        $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            throw new \RuntimeException( 'Unable to encode the backup manifest.' );
        }
        $json .= "\n";
        $temporary = $path . '.tmp-' . wp_generate_uuid4();
        $handle = @fopen( $temporary, 'xb' );
        if ( false === $handle ) {
            throw new \RuntimeException( 'Unable to create an atomic manifest temporary file.' );
        }
        try {
            if ( strlen( $json ) !== fwrite( $handle, $json ) ) {
                throw new \RuntimeException( 'Unable to write the complete backup manifest.' );
            }
            fflush( $handle );
            if ( function_exists( 'fsync' ) ) {
                @fsync( $handle );
            }
        } finally {
            fclose( $handle );
        }
        @chmod( $temporary, 0600 );
        if ( ! @rename( $temporary, $path ) ) {
            @unlink( $temporary );
            throw new \RuntimeException( 'Unable to atomically seal the backup manifest.' );
        }
        @chmod( $path, 0600 );
        return hash_file( 'sha256', $path ) ?: '';
    }
}
// phpcs:enable WordPress.WP.AlternativeFunctions
