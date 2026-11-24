<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.WP.AlternativeFunctions -- Local owner-only permissions and canonical path checks for resumable backup artifacts.
/** New encrypted backup storage lives under this site's WordPress uploads. Historical anchors remain read-only. */
final class PrivateBackupPath {
    private const LEGACY_DIRECTORY = 'yps-private-backups';

    private function currentRoot(): string {
        $uploads = wp_upload_dir( null, false );
        if ( ! empty( $uploads['error'] ) || ! isset( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) || '' === trim( $uploads['basedir'] ) ) {
            throw new \RuntimeException( 'backup-uploads-unavailable' );
        }
        return rtrim( $uploads['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . 'yablonsky-seo' . DIRECTORY_SEPARATOR . 'private-backups';
    }

    private function legacyRoot(): string {
        $webroot = realpath( rtrim( ABSPATH, '/\\' ) );
        if ( false === $webroot ) { throw new \RuntimeException( 'backup-webroot-unavailable' ); }
        return dirname( $webroot ) . DIRECTORY_SEPARATOR . self::LEGACY_DIRECTORY;
    }

    public function root(): string {
        $root = $this->currentRoot();
        if ( is_link( $root ) || is_link( dirname( $root ) ) ) { throw new \RuntimeException( 'backup-directory-link' ); }
        if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) { throw new \RuntimeException( 'backup-directory-unavailable' ); }
        if ( is_link( $root ) || is_link( dirname( $root ) ) ) { throw new \RuntimeException( 'backup-directory-link' ); }
        @chmod( $root, 0700 );
        $this->assertOwnerDirectory( $root );
        return $root;
    }

    public function createAnchorDirectory( string $anchorId ): string {
        $anchorId = sanitize_key( $anchorId );
        if ( ! $this->validAnchorId( $anchorId ) ) { throw new \InvalidArgumentException( 'backup-anchor-id-invalid' ); }
        $path = $this->root() . DIRECTORY_SEPARATOR . $anchorId;
        if ( file_exists( $path ) || is_link( $path ) ) { throw new \RuntimeException( 'backup-anchor-already-exists' ); }
        if ( ! wp_mkdir_p( $path ) ) { throw new \RuntimeException( 'backup-anchor-directory-unavailable' ); }
        @chmod( $path, 0700 );
        $this->assertWritableDirectory( $path );
        return $path;
    }

    public function assertWritableDirectory( string $path ): void {
        $this->assertOwnerDirectory( $path );
        $root = $this->currentRoot();
        if ( ! $this->isAnchorUnder( $path, $root ) ) { throw new \RuntimeException( 'backup-current-directory-required' ); }
    }

    /** True only for a valid historical anchor directory. No creation or writes. */
    public function isLegacyDirectory( string $path ): bool {
        return $this->isAnchorUnder( $path, $this->legacyRoot() );
    }

    public function assertPrivateDirectory( string $path ): void {
        $this->assertOwnerDirectory( $path );
        if ( $this->isLegacyDirectory( $path ) ) { return; }
        $this->assertWritableDirectory( $path );
    }

    public function assertPrivateFile( string $path ): void {
        $this->assertPrivateDirectory( dirname( $path ) );
        if ( is_link( $path ) || ! is_file( $path ) ) { throw new \RuntimeException( 'backup-artifact-missing-or-unsafe' ); }
        $mode = fileperms( $path );
        if ( false === $mode || 0600 !== ( $mode & 0777 ) ) { throw new \RuntimeException( 'backup-artifact-permissions' ); }
    }

    public function assertWritePath( string $path ): void {
        $this->assertWritableDirectory( dirname( $path ) );
        if ( ! in_array( basename( $path ), array( 'options.jsonl', 'postmeta.jsonl', 'usermeta.jsonl', 'primary-term.jsonl', 'manifest.json' ), true ) || is_link( $path ) ) {
            throw new \RuntimeException( 'backup-artifact-path-unsafe' );
        }
        if ( file_exists( $path ) ) { $this->assertPrivateFile( $path ); }
    }

    private function validAnchorId( string $id ): bool {
        return 1 === preg_match( '/^yoast-[0-9]{14}-[a-f0-9]{12}$/D', $id );
    }

    private function assertOwnerDirectory( string $path ): void {
        if ( is_link( $path ) || ! is_dir( $path ) || false === realpath( $path ) ) { throw new \RuntimeException( 'backup-directory-missing-or-unsafe' ); }
        $mode = fileperms( $path );
        if ( false === $mode || 0700 !== ( $mode & 0777 ) ) { throw new \RuntimeException( 'backup-directory-permissions' ); }
    }

    private function isAnchorUnder( string $path, string $root ): bool {
        $path = rtrim( $path, '/\\' );
        if ( ! $this->validAnchorId( basename( $path ) ) || is_link( $path ) || is_link( $root ) || is_link( dirname( $root ) ) ) { return false; }
        $actual = realpath( $path );
        $base = realpath( $root );
        return false !== $actual && false !== $base && dirname( $actual ) === $base && $actual === $base . DIRECTORY_SEPARATOR . basename( $path );
    }
}
// phpcs:enable WordPress.WP.AlternativeFunctions
