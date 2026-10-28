<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class UsageResult {
    /** @var array<string,mixed> */ private $data;
    /** @param array<string,mixed> $data */
    public function __construct( array $data ) { $this->data = $data; }
    /** @return array<string,mixed> */ public function data(): array { return $this->data; }
}
