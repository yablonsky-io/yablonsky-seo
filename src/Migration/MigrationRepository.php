<?php
namespace Yablonsky\PerformanceSEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MigrationRepository {
    private const OPTION = 'yps_migration_job';
    private const LOCK_OPTION = 'yps_migration_step_lock';

    public function load(): ?MigrationState {
        $value = get_option( self::OPTION, null );
        if ( ! is_array( $value ) ) {
            return null;
        }
        return new MigrationState( $value );
    }

    public function save( MigrationState $state ): void {
        update_option( self::OPTION, $state->toArray(), false );
    }

    public function clear(): void {
        delete_option( self::OPTION );
    }

    /**
     * Prevents overlapping browser/AJAX migration steps from multiplying origin work.
     *
     * add_option() is used as the atomic acquisition primitive. The lock is bounded
     * by a short TTL so an interrupted request cannot permanently strand the job.
     */
    public function acquireStepLock( int $ttlSeconds = 30 ): ?string {
        $ttlSeconds = max( 5, min( 120, $ttlSeconds ) );
        $now = time();
        $token = wp_generate_uuid4();
        $value = array(
            'token'      => $token,
            'expires_at' => $now + $ttlSeconds,
        );

        // Fast path: one atomic INSERT. Most requests never need to read the lock.
        if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
            return $token;
        }

        // Contention/recovery path: only inspect the existing row after add_option()
        // proves that another lock record already exists.
        $existing = get_option( self::LOCK_OPTION, null );
        if ( ! is_array( $existing ) || ! isset( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now ) {
            return null;
        }

        delete_option( self::LOCK_OPTION );
        if ( ! add_option( self::LOCK_OPTION, $value, '', false ) ) {
            return null;
        }

        return $token;
    }

    public function releaseStepLock( string $token ): void {
        if ( '' === $token ) {
            return;
        }
        $existing = get_option( self::LOCK_OPTION, null );
        if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) {
            delete_option( self::LOCK_OPTION );
        }
    }
}
