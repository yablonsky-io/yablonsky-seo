<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use InvalidArgumentException;

final class EngineDefinition {
    public const EVIDENCE_LEVELS = array( 'verified_direct', 'verified_backend', 'observed', 'opaque' );

    /** @var array<string,mixed> */ private $data;

    public function __construct( array $data ) {
        foreach ( array( 'engine_id', 'operator', 'product_name', 'retrieval_modes', 'evidence_level', 'last_verified_at', 'status' ) as $field ) {
            if ( ! array_key_exists( $field, $data ) ) {
                throw new InvalidArgumentException( 'Required engine registry field is missing.' );
            }
        }
        if ( ! in_array( $data['evidence_level'], self::EVIDENCE_LEVELS, true ) ) {
            throw new InvalidArgumentException( 'Invalid engine registry evidence level.' );
        }
        $this->data = $data;
    }

    public function id(): string { return (string) $this->data['engine_id']; }
    public function evidenceLevel(): string { return (string) $this->data['evidence_level']; }
    public function toArray(): array { return $this->data; }
}
