<?php
namespace Yablonsky\PerformanceSEO\Migration\Parity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SemanticParityComparator {
    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $projected
     * @return array<string,mixed>
     */
    public function compare( array $source, array $projected ): array {
        $fields = array(
            'title',
            'description',
            'canonical',
            'robots',
            'open_graph',
            'twitter',
            'schema_identity',
            'hreflang',
            'sitemap_membership',
            'redirect',
        );
        $differences = array();
        foreach ( $fields as $field ) {
            $left = $this->normalize( $source[ $field ] ?? null );
            $right = $this->normalize( $projected[ $field ] ?? null );
            if ( $left !== $right ) {
                $differences[ $field ] = array( 'source' => $left, 'projected' => $right );
            }
        }
        return array(
            'equal'       => empty( $differences ),
            'differences' => $differences,
            'checked'     => count( $fields ),
        );
    }

    /** @param mixed $value @return mixed */
    private function normalize( $value ) {
        if ( is_string( $value ) ) {
            return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
        }
        if ( is_array( $value ) ) {
            $normalized = array();
            foreach ( $value as $key => $item ) {
                $normalized[ (string) $key ] = $this->normalize( $item );
            }
            ksort( $normalized, SORT_STRING );
            return $normalized;
        }
        return $value;
    }
}
