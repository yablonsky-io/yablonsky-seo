<?php
namespace Yablonsky\PerformanceSEO\SEO\Developer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Immutable developer-facing projection wrapper. */
final class MetaSurface implements \JsonSerializable {
    /** @var array<string,mixed> */ private $data;
    /** @param array<string,mixed> $data */
    public function __construct( array $data ) { $this->data = $data; }
    /** @return mixed */
    public function __get( string $key ) { return $this->data[ $key ] ?? null; }
    public function __isset( string $key ): bool { return isset( $this->data[ $key ] ); }
    /** @return mixed */
    public function get( string $key, $default = null ) { return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default; }
    public function has( string $key ): bool { return array_key_exists( $key, $this->data ); }
    /** @return array<int,string> */
    public function keys(): array { return array_values( array_map( 'strval', array_keys( $this->data ) ) ); }
    /** @return array<string,mixed> */
    public function toArray(): array { return $this->data; }
    /** @return array<string,mixed> */
    public function jsonSerialize() { return $this->data; }
}
