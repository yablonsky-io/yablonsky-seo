<?php
namespace Yablonsky\PerformanceSEO\SEO\Developer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stable root object returned by yablonsky_seo(). */
final class DeveloperSurface {
    /** @var MetaSurfaceRepository */ public $meta;
    public function __construct() { $this->meta = new MetaSurfaceRepository(); }
    public function apiVersion(): int { return 1; }
    /** @return array<string,mixed> */
    public function contract(): array { return DeveloperApiContract::manifest(); }
    public function contractFingerprint(): string { return DeveloperApiContract::fingerprint(); }
    public function api_version(): int { return $this->apiVersion(); }
    public function contract_fingerprint(): string { return $this->contractFingerprint(); }
}
