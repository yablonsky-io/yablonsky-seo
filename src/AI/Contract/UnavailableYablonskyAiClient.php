<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class UnavailableYablonskyAiClient implements YablonskyAiClientInterface {
    public function isConfigured(): bool { return false; }
    public function testConnection(): bool { return false; }
    /** @return array<int,ModelCapability> */ public function models(): array { return array(); }
    /** @return array<int,ModelCapability> */ public function imageModels(): array { return array(); }
    public function compute( ComputeRequest $request ): ComputeResult {
        unset( $request );
        throw new ServiceError( esc_html__( 'Yablonsky AI compute: setup required.', 'yablonsky-seo' ) );
    }
    public function generateImage( ImageGenerationRequest $request ): ImageGenerationResult {
        unset( $request );
        throw new ServiceError( esc_html__( 'Yablonsky AI image generation: setup required.', 'yablonsky-seo' ) );
    }
    public function usage(): UsageResult { return new UsageResult( array() ); }
}
