<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private local rollback artifacts require byte-offset writes, atomic rename, permission checks and direct hashing that WP_Filesystem cannot provide as one crash-resumable primitive.

final class BackupAnchorVerifier {
    /** @param array<string,mixed> $anchor */
    public function verifyStored( array $anchor ): bool {
        try {
            $this->assertStored( $anchor );
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /** @param array<string,mixed> $anchor */
    public function verifyFreshForImport( array $anchor, string $providerVersion, string $dryRunDigest, int $maxAgeSeconds = 900 ): bool {
        try {
            $this->assertStored( $anchor );
            if ( 'yoast' !== (string) ( $anchor['provider'] ?? '' ) ) {
                return false;
            }
            if ( ! hash_equals( $providerVersion, (string) ( $anchor['provider_version'] ?? '' ) ) ) {
                return false;
            }
            if ( '' === $dryRunDigest || ! hash_equals( $dryRunDigest, (string) ( $anchor['dry_run_source_digest'] ?? '' ) ) ) {
                return false;
            }
            $verifiedAt = strtotime( (string) ( $anchor['verified_at'] ?? '' ) );
            if ( false === $verifiedAt || $verifiedAt > time() + 60 || ( time() - $verifiedAt ) > max( 60, $maxAgeSeconds ) ) {
                return false;
            }
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /** @param array<string,mixed> $anchor */
    private function assertStored( array $anchor ): void {
        $path = isset( $anchor['path'] ) ? (string) $anchor['path'] : '';
        $manifestSha = isset( $anchor['manifest_sha256'] ) ? (string) $anchor['manifest_sha256'] : '';
        if ( '' === $path || '' === $manifestSha ) {
            throw new \RuntimeException( 'Backup anchor metadata is incomplete.' );
        }
        $policy = new PrivateBackupPath();
        $policy->assertPrivateDirectory( $path );
        $manifestPath = rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR . 'manifest.json';
        $policy->assertPrivateFile( $manifestPath );
        $actualManifestSha = hash_file( 'sha256', $manifestPath );
        if ( ! is_string( $actualManifestSha ) || ! hash_equals( $manifestSha, $actualManifestSha ) ) {
            throw new \RuntimeException( 'Backup manifest hash mismatch.' );
        }
        $raw = file_get_contents( $manifestPath );
        $manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( ! is_array( $manifest ) || ! isset( $manifest['artifacts'] ) || ! is_array( $manifest['artifacts'] ) ) {
            throw new \RuntimeException( 'Backup manifest is invalid.' );
        }
        foreach ( $manifest['artifacts'] as $artifact ) {
            if ( ! is_array( $artifact ) ) {
                throw new \RuntimeException( 'Backup manifest contains an invalid artifact record.' );
            }
            $name = isset( $artifact['name'] ) ? (string) $artifact['name'] : '';
            if ( '' === $name || basename( $name ) !== $name ) {
                throw new \RuntimeException( 'Backup artifact name is unsafe.' );
            }
            $artifactPath = rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR . $name;
            $policy->assertPrivateFile( $artifactPath );
            $bytes = filesize( $artifactPath );
            $sha = hash_file( 'sha256', $artifactPath );
            if ( false === $bytes || (int) ( $artifact['bytes'] ?? -1 ) !== (int) $bytes ) {
                throw new \RuntimeException( 'Backup artifact size mismatch.' );
            }
            if ( ! is_string( $sha ) || ! hash_equals( (string) ( $artifact['sha256'] ?? '' ), $sha ) ) {
                throw new \RuntimeException( 'Backup artifact hash mismatch.' );
            }
        }
        foreach ( array( 'anchor_id', 'provider', 'provider_version', 'dry_run_source_digest' ) as $key ) {
            if ( ! isset( $manifest[ $key ] ) || ! isset( $anchor[ $key ] ) || (string) $manifest[ $key ] !== (string) $anchor[ $key ] ) {
                throw new \RuntimeException( 'Backup manifest identity mismatch.' );
            }
        }
    }
}
// phpcs:enable WordPress.WP.AlternativeFunctions
