<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ImageGenerationResult {
    /** @var string */ private $url;
    /** @var string */ private $model;
    /** @var int */ private $width;
    /** @var int */ private $height;
    /** @var mixed */ private $cost;
    /** @var string */ private $resourceId;

    public function __construct( string $url, string $model, int $width = 0, int $height = 0, $cost = null, string $resourceId = '' ) {
        $this->url = $url;
        $this->model = $model;
        $this->width = max( 0, $width );
        $this->height = max( 0, $height );
        $this->cost = $cost;
        $this->resourceId = $resourceId;
    }

    public function url(): string { return $this->url; }
    public function model(): string { return $this->model; }
    public function width(): int { return $this->width; }
    public function height(): int { return $this->height; }
    public function cost() { return $this->cost; }
    public function resourceId(): string { return $this->resourceId; }
}
