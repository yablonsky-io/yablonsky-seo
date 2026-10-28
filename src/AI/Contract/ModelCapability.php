<?php
namespace Yablonsky\PerformanceSEO\AI\Contract;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ModelCapability {
    /** @var string */ private $id;
    /** @var string */ private $label;
    /** @var array<int,string> */ private $capabilities;

    /** @param array<int,string> $capabilities */
    public function __construct( string $id, string $label, array $capabilities = array() ) {
        $this->id = $id;
        $this->label = $label;
        $this->capabilities = array_values( array_unique( array_map( 'strval', $capabilities ) ) );
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    /** @return array<int,string> */ public function capabilities(): array { return $this->capabilities; }
}
