<?php
namespace Yablonsky\PerformanceSEO\Migration\Backup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BackupExportRepository {
    private const JOB_OPTION = 'yps_backup_export_job';
    private const ANCHOR_OPTION = 'yps_backup_anchor';
    private const LOCK_OPTION = 'yps_backup_export_lock';

    public function load(): ?BackupExportState {
        $value = get_option( self::JOB_OPTION, null );
        return is_array( $value ) ? new BackupExportState( $value ) : null;
    }

    public function save( BackupExportState $state ): void {
        update_option( self::JOB_OPTION, $state->toArray(), false );
    }

    public function clear(): void {
        delete_option( self::JOB_OPTION );
    }

    /** @return array<string,mixed>|null */
    public function loadAnchor(): ?array {
        $value = get_option( self::ANCHOR_OPTION, null );
        return is_array( $value ) ? $value : null;
    }

    /** @param array<string,mixed> $anchor */
    public function saveAnchor( array $anchor ): void {
        update_option( self::ANCHOR_OPTION, $anchor, false );
    }

    public function acquireLock( int $ttlSeconds = 60 ): ?string {
        $ttlSeconds = max( 10, min( 180, $ttlSeconds ) );
        $now = time();
        $token = wp_generate_uuid4();
        $value = array( 'token' => $token, 'expires_at' => $now + $ttlSeconds );
        if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
            return $token;
        }
        $existing = get_option( self::LOCK_OPTION, null );
        if ( ! is_array( $existing ) || ! isset( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now ) {
            return null;
        }
        delete_option( self::LOCK_OPTION );
        return add_option( self::LOCK_OPTION, $value, '', false ) ? $token : null;
    }

    public function releaseLock( string $token ): void {
        if ( '' === $token ) {
            return;
        }
        $existing = get_option( self::LOCK_OPTION, null );
        if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) {
            delete_option( self::LOCK_OPTION );
        }
    }
}
