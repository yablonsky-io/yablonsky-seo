<?php
namespace Yablonsky\PerformanceSEO\Migration\Portability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PortableMirrorRepository {
    private const OPTION = 'yseo_portable_mirror_job';
    private const LOCK_OPTION = 'yseo_portable_mirror_lock';

    public function load(): ?PortableMirrorState {
        $value = get_option( self::OPTION, null );
        return is_array( $value ) ? new PortableMirrorState( $value ) : null;
    }
    public function save( PortableMirrorState $state ): void { update_option( self::OPTION, $state->toArray(), false ); }
    public function clear(): void { delete_option( self::OPTION ); }
    public function acquireLock( int $ttlSeconds = 30 ): ?string {
        $ttlSeconds = max( 5, min( 120, $ttlSeconds ) );
        $now = time();
        $token = wp_generate_uuid4();
        $value = array( 'token' => $token, 'expires_at' => $now + $ttlSeconds );
        if ( add_option( self::LOCK_OPTION, $value, '', false ) ) { return $token; }
        $existing = get_option( self::LOCK_OPTION, null );
        if ( ! is_array( $existing ) || ! isset( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now ) { return null; }
        delete_option( self::LOCK_OPTION );
        return add_option( self::LOCK_OPTION, $value, '', false ) ? $token : null;
    }
    public function releaseLock( string $token ): void {
        if ( '' === $token ) { return; }
        $existing = get_option( self::LOCK_OPTION, null );
        if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) { delete_option( self::LOCK_OPTION ); }
    }
}
