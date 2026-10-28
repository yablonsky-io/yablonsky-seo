<?php
namespace Yablonsky\PerformanceSEO\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use InvalidArgumentException;
use RuntimeException;

final class EngineRegistry {
    /** @var array<string,EngineDefinition> */ private $engines = array();
    /** @var string */ private $registryVersion;

    /** @param array<int,array<string,mixed>> $definitions */
    public function __construct( string $registryVersion, array $definitions ) {
        $this->registryVersion = $registryVersion;
        foreach ( $definitions as $definition ) {
            $engine = new EngineDefinition( $definition );
            if ( isset( $this->engines[ $engine->id() ] ) ) {
                throw new InvalidArgumentException( 'Duplicate engine ID in registry.' );
            }
            $this->engines[ $engine->id() ] = $engine;
        }
    }

    public static function fromJsonFile( string $file ): self {
        if ( ! is_readable( $file ) ) {
            throw new RuntimeException( 'Engine registry file is not readable.' );
        }
        $decoded = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['registry_version'], $decoded['engines'] ) || ! is_array( $decoded['engines'] ) ) {
            throw new RuntimeException( 'Invalid engine registry JSON.' );
        }
        return new self( (string) $decoded['registry_version'], $decoded['engines'] );
    }

    public function version(): string { return $this->registryVersion; }
    public function count(): int { return count( $this->engines ); }
    public function has( string $engineId ): bool { return isset( $this->engines[ $engineId ] ); }
    public function get( string $engineId ): ?EngineDefinition { return $this->engines[ $engineId ] ?? null; }
    /** @return array<string,EngineDefinition> */ public function all(): array { return $this->engines; }
}
