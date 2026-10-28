<?php
namespace Yablonsky\PerformanceSEO\Performance;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical first-discovery budget values used by future crawl/indexing modules.
 *
 * This class remains a passive acceptance contract in 1.0.0: it declares acceptance targets without adding
 * frontend probes, remote requests or request-path work.
 */
final class FirstHitPerformanceBudget {
    public const ZERO_5XX_REQUIRED = true;
    public const SAFE_UNPROVEN_WORKERS_PER_JOB = 1;
    public const TARGET_BATCH_MS = 100;
    public const SOFT_BACKPRESSURE_MS = 180;
    public const HARD_BACKPRESSURE_MS = 750;

    /** @return array<string,int|bool> */
    public static function contract(): array {
        return array(
            'zero_5xx_required'              => self::ZERO_5XX_REQUIRED,
            'safe_unproven_workers_per_job'   => self::SAFE_UNPROVEN_WORKERS_PER_JOB,
            'target_batch_ms'                => self::TARGET_BATCH_MS,
            'soft_backpressure_ms'           => self::SOFT_BACKPRESSURE_MS,
            'hard_backpressure_ms'           => self::HARD_BACKPRESSURE_MS,
        );
    }
}
