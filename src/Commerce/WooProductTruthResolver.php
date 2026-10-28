<?php
namespace Yablonsky\PerformanceSEO\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Local WooCommerce product-truth adapter.
 *
 * WooCommerce remains the commercial source of truth. This adapter only reads
 * public product state and normalizes it for YSEO's machine-readable surfaces.
 */
final class WooProductTruthResolver {
    public function resolve( int $productId ): ?ProductTruth {
        if ( $productId <= 0 || ! function_exists( 'wc_get_product' ) ) {
            return null;
        }
        $product = wc_get_product( $productId );
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return null;
        }

        $id = (int) $product->get_id();
        $parentId = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
        $post = get_post( $id );
        $title = method_exists( $product, 'get_name' ) ? trim( (string) $product->get_name() ) : '';
        if ( '' === $title && $post instanceof \WP_Post ) {
            $title = trim( wp_strip_all_tags( (string) get_the_title( $post ), true ) );
        }

        $productType = method_exists( $product, 'get_type' ) ? sanitize_key( (string) $product->get_type() ) : '';
        $price = method_exists( $product, 'get_price' ) ? trim( (string) $product->get_price() ) : '';
        $isOnSale = method_exists( $product, 'is_on_sale' ) && (bool) $product->is_on_sale();
        $salePrice = $isOnSale && method_exists( $product, 'get_sale_price' ) ? trim( (string) $product->get_sale_price() ) : '';
        $saleUntil = '';
        if ( $isOnSale && method_exists( $product, 'get_date_on_sale_to' ) ) {
            $saleDate = $product->get_date_on_sale_to();
            if ( is_object( $saleDate ) && method_exists( $saleDate, 'date' ) ) {
                $saleUntil = (string) $saleDate->date( DATE_W3C );
            }
        }

        $globalId = '';
        if ( method_exists( $product, 'get_global_unique_id' ) ) {
            $globalId = trim( (string) $product->get_global_unique_id() );
        }
        if ( '' === $globalId ) {
            $globalId = trim( (string) get_post_meta( $id, '_global_unique_id', true ) );
        }

        $images = array();
        if ( method_exists( $product, 'get_image_id' ) ) {
            $this->appendImage( $images, (int) $product->get_image_id() );
        }
        if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
            $gallery = $product->get_gallery_image_ids();
            foreach ( is_array( $gallery ) ? array_slice( $gallery, 0, 7 ) : array() as $imageId ) {
                $this->appendImage( $images, (int) $imageId );
            }
        }

        $modified = $post instanceof \WP_Post ? trim( (string) $post->post_modified_gmt ) : '';
        $url = get_permalink( $id );
        $url = is_string( $url ) ? $url : '';

        $variantCount = 0;
        $variesBy = array();
        if ( 'variable' === $productType ) {
            if ( method_exists( $product, 'get_children' ) ) {
                $children = $product->get_children();
                $variantCount = is_array( $children ) ? count( $children ) : 0;
            }
            if ( method_exists( $product, 'get_variation_attributes' ) ) {
                $variationAttributes = $product->get_variation_attributes();
                if ( is_array( $variationAttributes ) ) {
                    foreach ( array_keys( $variationAttributes ) as $attribute ) {
                        $label = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( (string) $attribute, $product ) : (string) $attribute;
                        $label = trim( wp_strip_all_tags( $label, true ) );
                        if ( '' !== $label ) { $variesBy[] = $label; }
                    }
                }
            }
        }

        return new ProductTruth(
            array(
                'product_id' => $parentId > 0 ? $parentId : $id,
                'variant_id' => $parentId > 0 ? $id : 0,
                'product_type' => $productType,
                'variant_count' => $variantCount,
                'varies_by' => array_values( array_unique( $variesBy ) ),
                'title' => $title,
                'brand' => $this->brand( $id, $parentId, $product ),
                'manufacturer' => '',
                'sku' => method_exists( $product, 'get_sku' ) ? trim( (string) $product->get_sku() ) : '',
                'gtin' => $globalId,
                'mpn' => trim( (string) get_post_meta( $id, '_mpn', true ) ),
                'category' => $this->categories( $parentId > 0 ? $parentId : $id ),
                'specs' => $this->specs( $product ),
                'price' => '' !== $price ? $price : null,
                'currency' => function_exists( 'get_woocommerce_currency' ) ? strtoupper( trim( (string) get_woocommerce_currency() ) ) : '',
                'sale_price' => '' !== $salePrice ? $salePrice : null,
                'sale_valid_until' => '' !== $saleUntil ? $saleUntil : null,
                'availability' => $this->availability( $product ),
                'condition' => '',
                'images' => array_values( array_unique( $images ) ),
                'product_url' => $url,
                'parent_product_id' => $parentId,
                'modified_at' => $modified,
            )
        );
    }

    /** @param array<int,string> $images */
    private function appendImage( array &$images, int $attachmentId ): void {
        if ( $attachmentId <= 0 ) { return; }
        $url = wp_get_attachment_url( $attachmentId );
        if ( is_string( $url ) && '' !== $url ) { $images[] = $url; }
    }

    private function brand( int $id, int $parentId, $product ): string {
        $sourceId = $parentId > 0 ? $parentId : $id;
        if ( taxonomy_exists( 'product_brand' ) ) {
            $terms = wp_get_post_terms( $sourceId, 'product_brand', array( 'fields' => 'names' ) );
            if ( is_array( $terms ) && ! empty( $terms ) ) {
                return trim( wp_strip_all_tags( (string) $terms[0], true ) );
            }
        }
        if ( is_object( $product ) && method_exists( $product, 'get_attribute' ) ) {
            foreach ( array( 'pa_brand', 'brand' ) as $attribute ) {
                $value = trim( wp_strip_all_tags( (string) $product->get_attribute( $attribute ), true ) );
                if ( '' !== $value ) { return $value; }
            }
        }
        return '';
    }

    private function categories( int $productId ): string {
        if ( $productId <= 0 || ! taxonomy_exists( 'product_cat' ) ) { return ''; }
        $terms = wp_get_post_terms( $productId, 'product_cat', array( 'fields' => 'names' ) );
        if ( ! is_array( $terms ) ) { return ''; }
        $names = array();
        foreach ( array_slice( $terms, 0, 5 ) as $termName ) {
            $name = trim( wp_strip_all_tags( (string) $termName, true ) );
            if ( '' !== $name ) { $names[] = $name; }
        }
        return implode( ', ', array_values( array_unique( $names ) ) );
    }

    /** @return array<string,string> */
    private function specs( $product ): array {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) { return array(); }
        $attributes = $product->get_attributes();
        if ( ! is_array( $attributes ) ) { return array(); }
        $out = array();
        foreach ( array_slice( $attributes, 0, 16, true ) as $attribute ) {
            if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) { continue; }
            $name = (string) $attribute->get_name();
            $label = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $name, $product ) : $name;
            $label = trim( wp_strip_all_tags( $label, true ) );
            if ( '' === $label ) { continue; }

            $values = array();
            if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && taxonomy_exists( $name ) ) {
                $terms = wp_get_post_terms( (int) $product->get_id(), $name, array( 'fields' => 'names' ) );
                if ( is_array( $terms ) ) { $values = $terms; }
            } elseif ( method_exists( $attribute, 'get_options' ) ) {
                $options = $attribute->get_options();
                if ( is_array( $options ) ) { $values = $options; }
            }
            $clean = array();
            foreach ( array_slice( $values, 0, 12 ) as $value ) {
                $value = trim( wp_strip_all_tags( (string) $value, true ) );
                if ( '' !== $value ) { $clean[] = $value; }
            }
            if ( ! empty( $clean ) ) { $out[ $label ] = implode( ', ', array_values( array_unique( $clean ) ) ); }
        }
        return $out;
    }

    private function availability( $product ): string {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_stock_status' ) ) { return ''; }
        $status = sanitize_key( (string) $product->get_stock_status() );
        $map = array(
            'instock' => 'https://schema.org/InStock',
            'outofstock' => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/BackOrder',
        );
        return $map[ $status ] ?? '';
    }
}
