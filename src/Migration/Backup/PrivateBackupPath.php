<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private local rollback artifacts require byte-offset writes, atomic rename, permission checks and direct hashing that WP_Filesystem cannot provide as one crash-resumable primitive.

/**
 * Resolves private migration backup storage outside the WordPress document root.
 */
final class PrivateBackupPath {
    private const DIRECTORY_NAME = 'yps-private-backups';

    public function root(): string {
        $webroot = $this->canonicalWebroot();
        $parent = dirname( $webroot );
        $root = $parent . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME;

        if ( ! is_dir( $root ) ) {
            if ( ! wp_mkdir_p( $root ) && ! is_dir( $root ) ) {
                throw new \RuntimeException( 'Unable to create the private Yablonsky SEO backup directory.' );
            }
        }
        @chmod( $root, 0700 );
        $this->assertPrivateDirectory( $root );
        return $root;
    }

    public function createAnchorDirectory( string $anchorId ): string {
        $anchorId = sanitize_key( $anchorId );
        if ( '' === $anchorId ) {
            throw new \InvalidArgumentException( 'A valid backup anchor ID is required.' );
        }
        $path = $this->root() . DIRECTORY_SEPARATOR . $anchorId;
        if ( file_exists( $path ) && ! is_dir( $path ) ) {
            throw new \RuntimeException( 'Backup anchor path is not a directory.' );
        }
        if ( ! is_dir( $path ) ) {
            if ( ! wp_mkdir_p( $path ) && ! is_dir( $path ) ) {
                throw new \RuntimeException( 'Unable to create the backup anchor directory.' );
            }
        }
        @chmod( $path, 0700 );
        $this->assertPrivateDirectory( $path );
        return $path;
    }

    public function assertPrivateDirectory( string $path ): void {
        if ( is_link( $path ) || ! is_dir( $path ) ) {
            throw new \RuntimeException( 'Backup directory is missing or unsafe.' );
        }
        $real = realpath( $path );
        if ( false === $real ) {
            throw new \RuntimeException( 'Backup directory cannot be resolved.' );
        }
        $webroot = $this->canonicalWebroot();
        if ( $this->isWithin( $real, $webroot ) ) {
            throw new \RuntimeException( 'Backup directory must be outside the WordPress webroot.' );
        }
        $mode = fileperms( $real );
        if ( false === $mode || 0700 !== ( $mode & 0777 ) ) {
            throw new \RuntimeException( 'Backup directory permissions must be owner-only (0700).' );
        }
    }

    public function assertPrivateFile( string $path ): void {
        if ( is_link( $path ) || ! is_file( $path ) ) {
            throw new \RuntimeException( 'Backup artifact is missing or unsafe.' );
        }
        $real = realpath( $path );
        if ( false === $real ) {
            throw new \RuntimeException( 'Backup artifact cannot be resolved.' );
        }
        $webroot = $this->canonicalWebroot();
        if ( $this->isWithin( $real, $webroot ) ) {
            throw new \RuntimeException( 'Backup artifact must be outside the WordPress webroot.' );
        }
        $mode = fileperms( $real );
        if ( false === $mode || 0600 !== ( $mode & 0777 ) ) {
            throw new \RuntimeException( 'Backup artifact permissions must be owner-only (0600).' );
        }
    }

    private function canonicalWebroot(): string {
        $webroot = realpath( rtrim( ABSPATH, '/\\' ) );
        if ( false === $webroot ) {
            throw new \RuntimeException( 'WordPress webroot cannot be resolved.' );
        }
        return rtrim( $webroot, DIRECTORY_SEPARATOR );
    }

    private function isWithin( string $path, string $parent ): bool {
        $path = rtrim( $path, DIRECTORY_SEPARATOR );
        $parent = rtrim( $parent, DIRECTORY_SEPARATOR );
        return $path === $parent || 0 === strpos( $path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR );
    }
}
// phpcs:enable WordPress.WP.AlternativeFunctions
