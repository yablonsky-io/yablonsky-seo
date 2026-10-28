<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ImageGenerationRequest {
    /** @var string */ private $model;
    /** @var string */ private $prompt;
    /** @var string */ private $aspectRatio;
    /** @var string */ private $quality;

    public function __construct( string $model, string $prompt, string $aspectRatio = '16:9', string $quality = '' ) {
        $this->model = trim( $model );
        $this->prompt = trim( $prompt );
        $this->aspectRatio = trim( $aspectRatio );
        $this->quality = trim( $quality );
    }

    public function model(): string { return $this->model; }
    public function prompt(): string { return $this->prompt; }
    public function aspectRatio(): string { return $this->aspectRatio; }
    public function quality(): string { return $this->quality; }
}
