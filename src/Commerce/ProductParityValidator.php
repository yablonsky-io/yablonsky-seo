<?php
namespace Yablonsky\PerformanceSEO\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProductParityValidator {
    private const CRITICAL_FIELDS = array( 'title', 'brand', 'sku', 'gtin', 'mpn', 'price', 'currency', 'availability', 'product_url' );

    /**
     * @param array<string,ProductTruth> $surfaces keyed page/schema/feed/etc.
     * @return array<int,array{field:string,canonical:mixed,surface:string,actual:mixed}>
     */
    public function compare( ProductTruth $canonical, array $surfaces ): array {
        $findings = array();
        foreach ( $surfaces as $surface => $truth ) {
            foreach ( self::CRITICAL_FIELDS as $field ) {
                $expected = $canonical->get( $field );
                $actual = $truth->get( $field );
                if ( $expected === null || $expected === '' ) {
                    continue;
                }
                if ( $this->normalize( $expected ) !== $this->normalize( $actual ) ) {
                    $findings[] = array( 'field' => $field, 'canonical' => $expected, 'surface' => (string) $surface, 'actual' => $actual );
                }
            }
        }
        return $findings;
    }

    private function normalize( $value ): string {
        if ( is_float( $value ) || is_int( $value ) ) {
            return number_format( (float) $value, 4, '.', '' );
        }
        return strtolower( trim( (string) $value ) );
    }
}
