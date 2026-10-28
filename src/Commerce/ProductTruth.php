<?php
namespace Yablonsky\PerformanceSEO\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProductTruth {
    /** @var array<string,mixed> */ private $data;

    public function __construct( array $data ) {
        $defaults = array(
            'product_id' => 0,
            'variant_id' => 0,
            'product_type' => '',
            'variant_count' => 0,
            'varies_by' => array(),
            'title' => '',
            'brand' => '',
            'manufacturer' => '',
            'sku' => '',
            'gtin' => '',
            'mpn' => '',
            'category' => '',
            'specs' => array(),
            'price' => null,
            'currency' => '',
            'sale_price' => null,
            'sale_valid_until' => null,
            'availability' => '',
            'condition' => '',
            'images' => array(),
            'product_url' => '',
            'parent_product_id' => 0,
            'modified_at' => '',
        );
        $this->data = array_replace( $defaults, $data );
    }

    public function get( string $field ) { return $this->data[ $field ] ?? null; }
    public function toArray(): array { return $this->data; }
}
