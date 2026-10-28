<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ComputeRequest {
    /** @var string */ private $operation;
    /** @var string */ private $model;
    /** @var array<string,mixed> */ private $payload;

    /** @param array<string,mixed> $payload */
    public function __construct( string $operation, string $model, array $payload ) {
        $this->operation = $operation;
        $this->model = $model;
        $this->payload = $payload;
    }

    public function operation(): string { return $this->operation; }
    public function model(): string { return $this->model; }
    /** @return array<string,mixed> */ public function payload(): array { return $this->payload; }
}
