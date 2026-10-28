<?php
namespace Yablonsky\PerformanceSEO\Performance;

use Yablonsky\PerformanceSEO\Migration\MigrationState;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps migration/indexing work bounded and adapts the next browser-driven step
 * to the pressure observed in the previous one.
 *
 * The controller intentionally uses only request-local signals available on
 * ordinary WordPress hosts: elapsed time, database query growth and PHP memory
 * growth. It does not perform self-HTTP requests, shell probes or remote calls.
 */
final class AdaptiveWorkBudget {
    public const MIN_BATCH = 25;
    public const DEFAULT_BATCH = 200;
    public const MAX_BATCH = 400;

    public function batchSize( MigrationState $state ): int {
        $value = (int) $state->get( 'adaptive_batch_size' );
        if ( $value <= 0 ) {
            $value = self::DEFAULT_BATCH;
        }
        return $this->clamp( $value );
    }

    public function observe(
        MigrationState $state,
        int $batchSize,
        float $elapsedMs,
        int $queryDelta,
        int $memoryDelta
    ): MigrationState {
        $batchSize = $this->clamp( $batchSize );
        $elapsedMs = max( 0.0, $elapsedMs );
        $queryDelta = max( 0, $queryDelta );
        $memoryDelta = max( 0, $memoryDelta );

        $previousEwma = (float) $state->get( 'ewma_step_ms' );
        $ewma = $previousEwma > 0.0
            ? ( 0.70 * $previousEwma ) + ( 0.30 * $elapsedMs )
            : $elapsedMs;
        $signalMs = max( $elapsedMs, $ewma );

        $nextBatch = $batchSize;
        $delayMs = 75;
        $pressure = 'normal';
        $reason = 'steady';
        $pressureEvent = false;

        // Slow down well before request pressure becomes an availability problem.
        if ( $memoryDelta >= 33554432 || $signalMs >= 750.0 || $queryDelta >= 1000 ) {
            $nextBatch = (int) floor( $batchSize * 0.50 );
            $delayMs = 800;
            $pressure = 'high';
            $reason = 'hard-backpressure';
            $pressureEvent = true;
        } elseif ( $memoryDelta >= 16777216 || $signalMs >= 350.0 || $queryDelta >= 500 ) {
            $nextBatch = (int) floor( $batchSize * 0.65 );
            $delayMs = 500;
            $pressure = 'high';
            $reason = 'pressure-backoff';
            $pressureEvent = true;
        } elseif ( $memoryDelta >= 8388608 || $signalMs >= 180.0 || $queryDelta >= 250 ) {
            $nextBatch = (int) floor( $batchSize * 0.80 );
            $delayMs = 250;
            $pressure = 'elevated';
            $reason = 'soft-backoff';
            $pressureEvent = true;
        } elseif ( $signalMs <= 80.0 && $memoryDelta <= 4194304 && $queryDelta <= 30 ) {
            // Scale up slowly. Downshifts are intentionally much faster than upshifts.
            $nextBatch = $batchSize + 25;
            $delayMs = 25;
            $pressure = 'low';
            $reason = 'measured-headroom';
        }

        $nextBatch = $this->clamp( $nextBatch );
        $events = (int) $state->get( 'pressure_events' ) + ( $pressureEvent ? 1 : 0 );
        $peakMs = max( (float) $state->get( 'peak_step_ms' ), $elapsedMs );
        $peakMemory = max( (int) $state->get( 'peak_step_memory_delta' ), $memoryDelta );
        $totalQueries = (int) $state->get( 'total_step_queries' ) + $queryDelta;

        return $state->merge(
            array(
                'adaptive_batch_size'       => $nextBatch,
                'last_step_batch_size'      => $batchSize,
                'last_step_ms'              => round( $elapsedMs, 3 ),
                'ewma_step_ms'              => round( $ewma, 3 ),
                'last_step_queries'         => $queryDelta,
                'last_step_memory_delta'    => $memoryDelta,
                'peak_step_ms'              => round( $peakMs, 3 ),
                'peak_step_memory_delta'    => $peakMemory,
                'total_step_queries'        => $totalQueries,
                'recommended_delay_ms'      => $delayMs,
                'pressure_state'            => $pressure,
                'pressure_reason'           => $reason,
                'pressure_events'           => $events,
                'worker_concurrency_limit'  => 1,
            )
        );
    }

    private function clamp( int $value ): int {
        return max( self::MIN_BATCH, min( self::MAX_BATCH, $value ) );
    }
}
