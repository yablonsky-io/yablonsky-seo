<?php
namespace Yablonsky\PerformanceSEO\AI\Metadata;

use Yablonsky\PerformanceSEO\Runtime\ProviderDetector;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WP-Cron safety net for durable bulk metadata work. Browser-driven steps may run in parallel; repository lock serializes them. */
final class BulkMetadataRunner {
    private const HOOK = 'yseo_ai_metadata_continue';

    public function register(): void { add_action( self::HOOK, array( $this, 'run' ) ); }

    public static function schedule( int $afterSeconds = 5 ): void {
        $afterSeconds = min( 300, max( 2, $afterSeconds ) );
        if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_single_event( time() + $afterSeconds, self::HOOK ); }
    }

    public static function clearSchedule(): void { wp_clear_scheduled_hook( self::HOOK ); }

    public function run(): void {
        $repository = new BulkMetadataRepository();
        $state = $repository->load();
        if ( null === $state || BulkMetadataState::STATUS_RUNNING !== $state->get( 'status' ) ) { return; }
        $token = $repository->acquireLock( 300 );
        if ( null === $token ) { self::schedule( 10 ); return; }
        try {
            $next = ( new BulkMetadataService( $repository, null, new ProviderDetector() ) )->step();
        } finally {
            $repository->releaseLock( $token );
        }
        if ( BulkMetadataState::STATUS_RUNNING === $next->get( 'status' ) ) {
            $delayMs = max( 250, (int) $next->get( 'recommended_delay_ms' ) );
            self::schedule( (int) max( 2, ceil( $delayMs / 1000 ) ) );
        }
    }
}
