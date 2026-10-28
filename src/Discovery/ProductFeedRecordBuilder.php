<?php
namespace Yablonsky\PerformanceSEO\Discovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical local product-distribution projection.
 *
 * This is intentionally not a Google-shaped record. Adapters (Merchant today,
 * partner/price-comparison feeds later) map this neutral model to their own
 * contracts without re-reading WooCommerce on every external fetch.
 */
final class ProductFeedRecordBuilder {
    /** @return array<string,mixed>|null */
    public function build( int $productId ): ?array {
        if ( $productId <= 0 || 'publish' !== get_post_status( $productId ) || ! function_exists( 'wc_get_product' ) ) { return null; }
        $product = wc_get_product( $productId );
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) { return null; }

        $link = (string) get_permalink( $productId );
        $title = $this->limit( (string) $product->get_name(), 150 );
        $description = (string) $product->get_short_description();
        if ( '' === trim( wp_strip_all_tags( $description ) ) ) { $description = (string) $product->get_description(); }
        $description = $this->limit( $this->plainText( $description ), 5000 );
        $imageId = (int) $product->get_image_id();
        $image = $imageId > 0 ? (string) wp_get_attachment_image_url( $imageId, 'full' ) : '';
        $currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
        $regular = (string) $product->get_regular_price();
        $current = (string) $product->get_price();
        $sale = (string) $product->get_sale_price();
        $priceNumber = '' !== $regular ? $regular : $current;
        $availability = $this->availability( (string) $product->get_stock_status() );
        if ( '' === $link || '' === $title || '' === $description || '' === $image || '' === $currency || '' === $priceNumber || '' === $availability ) { return null; }

        $additional = array();
        foreach ( array_slice( (array) $product->get_gallery_image_ids(), 0, 10 ) as $galleryId ) {
            $url = (string) wp_get_attachment_image_url( (int) $galleryId, 'full' );
            if ( '' !== $url && $url !== $image ) { $additional[ $url ] = true; }
        }

        $sku = trim( (string) $product->get_sku() );
        $record = array(
            'identity' => array(
                'id' => '' !== $sku ? $sku : 'wp-' . $productId,
                'sku' => $sku,
                'gtin' => $this->gtin( $product, $productId ),
                'mpn' => $this->firstMeta( $productId, array( '_mpn', 'mpn' ) ),
            ),
            'content' => array(
                'title' => $title,
                'description' => $description,
                'link' => $link,
            ),
            'media' => array(
                'primary_image' => $image,
                'additional_images' => array_keys( $additional ),
            ),
            'commerce' => array(
                'price' => number_format( (float) $priceNumber, 2, '.', '' ),
                'sale_price' => ( '' !== $sale && (float) $sale > 0 && (float) $sale < (float) $priceNumber ) ? number_format( (float) $sale, 2, '.', '' ) : '',
                'currency' => $currency,
                'availability' => $availability,
                'condition' => 'new',
            ),
            'classification' => array(
                'brand' => $this->brand( $productId ),
                'product_type' => $this->productType( $productId ),
            ),
        );
        $filtered = apply_filters( 'yablonsky_seo_product_distribution_record_v1', $record, $productId, $product );
        return is_array( $filtered ) ? $filtered : $record;
    }

    private function availability( string $stockStatus ): string {
        if ( 'instock' === $stockStatus ) { return 'in_stock'; }
        if ( 'outofstock' === $stockStatus ) { return 'out_of_stock'; }
        if ( 'onbackorder' === $stockStatus ) { return 'backorder'; }
        return '';
    }

    private function brand( int $productId ): string {
        foreach ( array( 'product_brand', 'pa_brand' ) as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) { continue; }
            $terms = wp_get_post_terms( $productId, $taxonomy, array( 'fields' => 'names' ) );
            if ( is_array( $terms ) && ! empty( $terms[0] ) ) { return $this->limit( (string) $terms[0], 70 ); }
        }
        return $this->limit( $this->firstMeta( $productId, array( '_brand', 'brand' ) ), 70 );
    }

    private function gtin( $product, int $productId ): string {
        if ( is_object( $product ) && method_exists( $product, 'get_global_unique_id' ) ) {
            $value = trim( (string) $product->get_global_unique_id() ); if ( '' !== $value ) { return $value; }
        }
        return $this->firstMeta( $productId, array( '_global_unique_id', '_gtin', 'gtin' ) );
    }

    private function productType( int $productId ): string {
        if ( ! taxonomy_exists( 'product_cat' ) ) { return ''; }
        $terms = wp_get_post_terms( $productId, 'product_cat', array( 'fields' => 'names' ) );
        if ( ! is_array( $terms ) || empty( $terms ) ) { return ''; }
        return $this->limit( implode( ' > ', array_map( 'strval', array_slice( $terms, 0, 3 ) ) ), 750 );
    }

    /** @param array<int,string> $keys */
    private function firstMeta( int $postId, array $keys ): string {
        foreach ( $keys as $key ) { $value = trim( (string) get_post_meta( $postId, $key, true ) ); if ( '' !== $value ) { return $value; } }
        return '';
    }

    private function plainText( string $text ): string { return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' ); }
    private function limit( string $text, int $length ): string { $text = trim( $text ); return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length ); }
}
