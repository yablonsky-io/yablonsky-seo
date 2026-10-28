<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface YablonskyAiClientInterface {
    public function isConfigured(): bool;
    public function testConnection(): bool;
    /** @return array<int,ModelCapability> */ public function models(): array;
    /** @return array<int,ModelCapability> */ public function imageModels(): array;
    public function compute( ComputeRequest $request ): ComputeResult;
    public function generateImage( ImageGenerationRequest $request ): ImageGenerationResult;
    public function usage(): UsageResult;
}
