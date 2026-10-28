<?php
namespace Yablonsky\PerformanceSEO\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use InvalidArgumentException;

final class SchemaGraph {
    /** @var array<string,array<string,mixed>> */ private $pieces = array();

    /** @param array<string,mixed> $piece */
    public function add( string $id, array $piece ): void {
        if ( isset( $this->pieces[ $id ] ) ) {
            throw new InvalidArgumentException( 'Duplicate schema graph piece.' );
        }
        $piece['@id'] = $piece['@id'] ?? $id;
        $this->pieces[ $id ] = $piece;
    }

    /** @return array<string,mixed> */
    public function toJsonLd(): array {
        return array( '@context' => 'https://schema.org', '@graph' => array_values( $this->pieces ) );
    }
}
