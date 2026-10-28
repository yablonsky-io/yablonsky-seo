<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class VisibilityPipeline {
    public const STAGES = array( 'discover', 'retrieve', 'extract', 'understand', 'trust', 'cite', 'refresh' );

    /** @return array<int,string> */
    public function stages(): array {
        return self::STAGES;
    }

    public function isValidStage( string $stage ): bool {
        return in_array( $stage, self::STAGES, true );
    }
}
