<?php
namespace Yablonsky\PerformanceSEO\AI\Metadata;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BulkMetadataRepository {
    private const OPTION = 'yseo_ai_metadata_job';
    private const LOCK_OPTION = 'yseo_ai_metadata_job_lock';
    public function load(): ?BulkMetadataState { $value = get_option( self::OPTION, null ); return is_array( $value ) ? new BulkMetadataState( $value ) : null; }
    public function save( BulkMetadataState $state ): void { update_option( self::OPTION, $state->toArray(), false ); }
    public function clear(): void { delete_option( self::OPTION ); }
    public function acquireLock( int $ttl = 60 ): ?string {
        $ttl = max( 10, min( 600, $ttl ) ); $now = time(); $token = wp_generate_uuid4(); $value = array( 'token' => $token, 'expires_at' => $now + $ttl );
        if ( add_option( self::LOCK_OPTION, $value, '', false ) ) { return $token; }
        $existing = get_option( self::LOCK_OPTION, null );
        if ( ! is_array( $existing ) || ! isset( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now ) { return null; }
        delete_option( self::LOCK_OPTION );
        return add_option( self::LOCK_OPTION, $value, '', false ) ? $token : null;
    }
    public function releaseLock( string $token ): void { if ( '' === $token ) { return; } $existing = get_option( self::LOCK_OPTION, null ); if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) { delete_option( self::LOCK_OPTION ); } }
}
