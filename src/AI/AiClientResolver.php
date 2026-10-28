<?php
namespace Yablonsky\PerformanceSEO\AI;

use Yablonsky\PerformanceSEO\AI\Contract\YablonskyAiClientInterface;
use Yablonsky\PerformanceSEO\AI\Transport\YablonskyAiRestClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AiClientResolver {
    public function resolve(): YablonskyAiClientInterface {
        $client = new YablonskyAiRestClient();
        /**
         * Override the canonical Yablonsky AI client implementation.
         *
         * The built-in adapter uses only the verified Yablonsky AI User API surface.
         * Integrators may replace it for controlled testing or a future clean-room gateway.
         *
         * @param YablonskyAiClientInterface $client
         */
        $resolved = apply_filters( 'yablonsky_seo_ai_client', $client );
        if ( $resolved instanceof YablonskyAiClientInterface ) {
            $client = $resolved;
        }
        $legacy = apply_filters( 'yablonsky_performance_seo_ai_client', $client );
        return $legacy instanceof YablonskyAiClientInterface ? $legacy : $client;
    }
}
